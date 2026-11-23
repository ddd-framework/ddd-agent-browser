// Guarded plain fetch of one file (an asset the page did not load itself, e.g. an og:image). Every hop is checked by
// the address guard, the connect itself goes only to the public address that was checked (publicOnlyLookup), at most
// 5 redirects, a byte cap and a deadline. No cookies, no credentials.
import http from 'node:http';
import https from 'node:https';
import { AddressGuard, publicOnlyLookup } from './guard.mjs';

export const MAX_FETCH_BYTES = 25_000_000;

/**
 * @param {string} url
 * @param {{maxBytes?: number, timeoutMs?: number, maxRedirects?: number, headers?: Record<string, string>, guard?: AddressGuard}} options
 * @returns {Promise<{reason: string, url: string, finalUrl?: string, statusCode?: number, contentType?: string, body?: Buffer}>}
 */
export const guardedFetch = async (url, { maxBytes = MAX_FETCH_BYTES, timeoutMs = 20_000, maxRedirects = 5, headers = {}, guard = new AddressGuard() } = {}) => {
  const deadline = Date.now() + timeoutMs;
  let currentUrl = url;
  for (let hop = 0; hop <= maxRedirects; hop++) {
    const verdict = await guard.classifyUrl(currentUrl);
    if (verdict === 'LOCAL' || verdict === 'BLOCKED') return { reason: 'BLOCKED', url, finalUrl: currentUrl };
    if (verdict === 'UNRESOLVABLE') return { reason: 'UNRESOLVABLE', url, finalUrl: currentUrl };
    const answer = await requestOnce(currentUrl, { maxBytes, headers, remainingMs: deadline - Date.now() });
    if (answer.reason !== 'OK') return { ...answer, url, finalUrl: currentUrl };
    if (answer.statusCode >= 300 && answer.statusCode < 400 && answer.location) {
      currentUrl = new URL(answer.location, currentUrl).href;
      continue;
    }
    if (answer.statusCode >= 400) return { reason: 'HTTP_ERROR', url, finalUrl: currentUrl, statusCode: answer.statusCode };
    return { reason: 'OK', url, finalUrl: currentUrl, statusCode: answer.statusCode, contentType: answer.contentType, body: answer.body };
  }
  return { reason: 'TOO_MANY_REDIRECTS', url, finalUrl: currentUrl };
};

const requestOnce = (url, { maxBytes, headers, remainingMs }) => new Promise(resolve => {
  if (remainingMs <= 0) {
    resolve({ reason: 'TIMEOUT' });
    return;
  }
  const parsed = new URL(url);
  const client = parsed.protocol === 'https:' ? https : http;
  const request = client.request(parsed, {
    method: 'GET',
    headers: { 'Accept': '*/*', 'Accept-Language': 'de-DE,de;q=0.9,en;q=0.8', ...headers },
    lookup: publicOnlyLookup,
    timeout: remainingMs,
    rejectUnauthorized: false,
  }, response => {
    const statusCode = response.statusCode ?? 0;
    if (statusCode >= 300 && statusCode < 400) {
      response.resume();
      resolve({ reason: 'OK', statusCode, location: response.headers.location });
      return;
    }
    const declared = Number.parseInt(response.headers['content-length'] ?? '', 10);
    if (Number.isFinite(declared) && declared > maxBytes) {
      response.destroy();
      resolve({ reason: 'TOO_LARGE', statusCode });
      return;
    }
    const chunks = [];
    let size = 0;
    response.on('data', chunk => {
      size += chunk.length;
      if (size > maxBytes) {
        response.destroy();
        resolve({ reason: 'TOO_LARGE', statusCode });
        return;
      }
      chunks.push(chunk);
    });
    response.on('end', () => resolve({ reason: 'OK', statusCode, contentType: response.headers['content-type'] ?? null, body: Buffer.concat(chunks) }));
    response.on('error', () => resolve({ reason: 'FETCH_FAILED', statusCode }));
  });
  request.on('timeout', () => {
    request.destroy();
    resolve({ reason: 'TIMEOUT' });
  });
  request.on('error', error => resolve({ reason: error.code === 'EBLOCKED' ? 'BLOCKED' : (error.code === 'ENOTFOUND' ? 'UNRESOLVABLE' : 'FETCH_FAILED') }));
  request.end();
});
