// Bot challenge and block pages. A challenge is reported,
// never solved: no solver services, no clicks on challenges.

const VENDOR_SIGNS = [
  {
    vendor: 'cloudflare',
    header: headers => headers['cf-mitigated'] === 'challenge',
    title: /^(just a moment|attention required|nur einen moment|einen moment bitte)/i,
    html: /\/cdn-cgi\/challenge-platform\/|cf-turnstile|cf_chl_opt|challenges\.cloudflare\.com/i,
  },
  {
    vendor: 'datadome',
    header: headers => 'x-datadome' in headers || 'x-dd-b' in headers,
    title: null,
    html: /captcha-delivery\.com|geo\.captcha-delivery|datadome/i,
  },
  {
    vendor: 'akamai',
    header: headers => /akamaighost/i.test(headers.server ?? ''),
    title: /^access denied$/i,
    html: /reference&#32;&#35;|reference #\d+\.[0-9a-f]+|errors\.edgesuite\.net/i,
  },
  {
    vendor: 'perimeterx',
    header: () => false,
    title: /access to this page has been denied/i,
    html: /px-captcha|_pxhd|perimeterx|human security/i,
  },
  {
    vendor: 'imperva',
    header: headers => 'x-iinfo' in headers,
    title: null,
    html: /_incapsula_resource|incapsula incident id/i,
  },
  {
    // a site's own block page (measured 2026-11-22: zeit.de answers datacenter addresses 403 "Ihre Anfrage wurde
    // blockiert" without a vendor sign); only on a challenge status, so a 200 article about blocking stays a page
    vendor: 'site',
    header: () => false,
    title: null,
    html: /(your )?request (has been |was )?blocked|ihre anfrage wurde blockiert|zugriff (wurde )?(verweigert|blockiert)|automated (access|traffic) (is )?(detected|blocked)/i,
  },
  {
    vendor: 'kasada',
    header: headers => Object.keys(headers).some(name => name.startsWith('x-kpsdk')),
    title: null,
    html: /ips\.js\?|kpsdk/i,
  },
];

/** Status codes bot protections answer with on a challenge or a block. */
const CHALLENGE_STATUS_CODES = new Set([401, 403, 405, 429, 503]);

/**
 * @param {{statusCode: number|null, headers: Record<string, string>, title: string, html: string}} page The main document
 * @returns {string|null} The bot protection whose challenge or block page this is, null for a normal page
 */
export const detectBotChallenge = ({ statusCode, headers, title, html }) => {
  const lowerHeaders = Object.fromEntries(Object.entries(headers ?? {}).map(([name, value]) => [name.toLowerCase(), String(value)]));
  const pageTitle = (title ?? '').trim();
  const pageHtml = (html ?? '').slice(0, 200_000);
  for (const sign of VENDOR_SIGNS) {
    if (sign.vendor === 'cloudflare' && lowerHeaders['cf-mitigated'] === 'challenge') return sign.vendor;
    const isChallengeStatus = statusCode !== null && CHALLENGE_STATUS_CODES.has(statusCode);
    const titleMatches = sign.title !== null && sign.title.test(pageTitle);
    // a normal page may embed a vendor script (DataDome, Kasada run on every page); a sign counts only on a
    // challenge status, or when the title itself is the challenge
    if (titleMatches && (isChallengeStatus || sign.vendor === 'cloudflare')) return sign.vendor;
    if (isChallengeStatus && (sign.header(lowerHeaders) || sign.html.test(pageHtml))) return sign.vendor;
  }
  return null;
};
