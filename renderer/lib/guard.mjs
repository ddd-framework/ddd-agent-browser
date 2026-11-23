// Address guard of the renderer: every request a page makes (and every redirect hop) must go to the PUBLIC internet.
// Defense in depth next to the NetworkPolicy (which has a startup window while a pod starts) and the URL guard of the
// calling application. Note: Chrome resolves the host again itself, so a DNS answer that changes
// between this check and Chrome's connect (rebinding) is stopped by the NetworkPolicy, not here.
import net from 'node:net';
import dns from 'node:dns/promises';

const blockedRanges = new net.BlockList();
for (const [network, prefix] of [
  ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
  ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
  ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
]) {
  blockedRanges.addSubnet(network, prefix, 'ipv4');
}
for (const [network, prefix] of [
  ['::', 128], ['::1', 128], ['fc00::', 7], ['fe80::', 10], ['ff00::', 8], ['2001:db8::', 32], ['100::', 64], ['64:ff9b::', 96],
]) {
  blockedRanges.addSubnet(network, prefix, 'ipv6');
}

/** Extra blocked addresses from the environment (the cluster's own public IPs), comma-separated IPs or CIDRs. */
for (const entry of (process.env.RENDER_BLOCKED_ADDRESSES ?? '').split(',').map(value => value.trim()).filter(Boolean)) {
  const [address, prefix] = entry.split('/');
  const family = net.isIPv6(address) ? 'ipv6' : 'ipv4';
  if (prefix) blockedRanges.addSubnet(address, Number(prefix), family);
  else blockedRanges.addAddress(address, family);
}

/**
 * Host names the integration tests serve their fixture pages under (RENDER_TEST_FIXTURE_HOSTS). Only names under the
 * reserved top-level domain `.test` are honoured: they never exist on the public internet, so the switch cannot open
 * a real address. Everything else, including the fixture server's own IP, stays guarded.
 */
export const FIXTURE_HOSTS = new Set((process.env.RENDER_TEST_FIXTURE_HOSTS ?? '')
  .split(',').map(value => value.trim().toLowerCase()).filter(value => /^[a-z0-9.-]+\.test$/.test(value)));

/** The schemes a page may load besides http(s) (they never reach the network). */
const LOCAL_SCHEMES = new Set(['data:', 'blob:', 'about:']);

/**
 * @param {string} address An IPv4 or IPv6 address
 * @returns {boolean} Whether the address is routable on the public internet
 */
export const isPublicAddress = address => {
  const family = net.isIP(address);
  if (family === 0) return false;
  if (family === 6) {
    const lower = address.toLowerCase();
    // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d): judge the embedded IPv4 address
    const embedded = lower.match(/^(?:::ffff:)(\d+\.\d+\.\d+\.\d+)$/);
    if (embedded) return isPublicAddress(embedded[1]);
    if (lower.startsWith('::ffff:')) return false;
    return !blockedRanges.check(address, 'ipv6');
  }
  return !blockedRanges.check(address, 'ipv4');
};

/**
 * One guard per render: DNS answers are cached for the render (a page asks for the same hosts again and again).
 */
export class AddressGuard {
  constructor(lookup = dns.lookup) {
    this.lookup = lookup;
    this.verdictsByHost = new Map();
  }

  /**
   * @param {string} url Any URL a page loads
   * @returns {Promise<'PUBLIC'|'LOCAL'|'BLOCKED'|'UNRESOLVABLE'>} PUBLIC/LOCAL may load, the others are refused
   */
  async classifyUrl(url) {
    let parsedUrl;
    try {
      parsedUrl = new URL(url);
    } catch {
      return 'BLOCKED';
    }
    if (LOCAL_SCHEMES.has(parsedUrl.protocol)) return 'LOCAL';
    if (parsedUrl.protocol !== 'http:' && parsedUrl.protocol !== 'https:') return 'BLOCKED';
    const host = parsedUrl.hostname.replace(/^\[|\]$/g, '').replace(/\.$/, '');
    if (host === '') return 'BLOCKED';
    if (!this.verdictsByHost.has(host)) {
      this.verdictsByHost.set(host, this.classifyHost(host));
    }
    return this.verdictsByHost.get(host);
  }

  /**
   * @param {string} host A host name or IP literal
   * @returns {Promise<'PUBLIC'|'BLOCKED'|'UNRESOLVABLE'>}
   */
  async classifyHost(host) {
    if (net.isIP(host)) return isPublicAddress(host) ? 'PUBLIC' : 'BLOCKED';
    const lowerHost = host.toLowerCase();
    if (FIXTURE_HOSTS.has(lowerHost)) return 'PUBLIC';
    if (lowerHost === 'localhost' || lowerHost.endsWith('.localhost') || lowerHost.endsWith('.local')
      || lowerHost.endsWith('.internal') || lowerHost.endsWith('.cluster.local') || !lowerHost.includes('.')) {
      return 'BLOCKED';
    }
    let addresses;
    try {
      addresses = await this.lookup(host, { all: true, verbatim: true });
    } catch (error) {
      return error?.code === 'ENOTFOUND' || error?.code === 'ENODATA' ? 'UNRESOLVABLE' : 'BLOCKED';
    }
    if (!Array.isArray(addresses) || addresses.length === 0) return 'UNRESOLVABLE';
    return addresses.every(entry => isPublicAddress(entry.address)) ? 'PUBLIC' : 'BLOCKED';
  }
}

/**
 * A `lookup` for node:http(s) that refuses non-public answers at connect time: the address that is checked is the
 * address that is connected to, so a DNS answer cannot change between the check and the connect.
 *
 * @param {string} hostname
 * @param {object} options
 * @param {Function} callback
 */
export const publicOnlyLookup = (hostname, options, callback) => {
  const wantsAll = options?.all === true;
  dns.lookup(hostname, { ...options, all: true, verbatim: true })
    .then(addresses => {
      const allowed = FIXTURE_HOSTS.has(String(hostname).toLowerCase())
        ? addresses
        : addresses.filter(entry => isPublicAddress(entry.address));
      if (allowed.length === 0 || allowed.length !== addresses.length) {
        callback(Object.assign(new Error(`${hostname} resolves to a non-public address`), { code: 'EBLOCKED' }));
        return;
      }
      if (wantsAll) callback(null, allowed);
      else callback(null, allowed[0].address, allowed[0].family);
    })
    .catch(error => callback(error));
};
