// Unit tests of the renderer's pure parts: address guard, challenge detection, job validation, host pacing, error
// mapping. `npm test` (also run in the image build).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { AddressGuard, isPublicAddress } from '../lib/guard.mjs';
import { detectBotChallenge } from '../lib/challenge.mjs';
import { OWN_CONSENT_RULES } from '../lib/consent-rules.mjs';
import { ConsentHandler } from '../lib/consent.mjs';
import { validateScreenshotJob } from '../lib/job.mjs';
import { HostPacer } from '../lib/pacer.mjs';
import { buildMobileUserAgent, isRenderableContentType, reasonForNavigationError } from '../lib/renderer.mjs';

test('public and non-public addresses', () => {
  for (const address of ['93.184.215.14', '37.27.119.232', '2a01:4f9:c010::1', '::ffff:8.8.8.8']) assert.equal(isPublicAddress(address), true, address);
  for (const address of ['127.0.0.1', '10.43.0.1', '172.20.1.1', '192.168.1.1', '169.254.169.254', '100.64.0.6', '0.0.0.0', '::1', 'fe80::1', 'fd00::5', '::ffff:10.0.0.1', '64:ff9b::a00:1', 'not-an-ip']) {
    assert.equal(isPublicAddress(address), false, address);
  }
});

test('guard classifies URLs and caches DNS per render', async () => {
  let lookups = 0;
  const guard = new AddressGuard(async host => {
    lookups++;
    if (host === 'public.example.org') return [{ address: '93.184.215.14', family: 4 }];
    if (host === 'mixed.example.org') return [{ address: '93.184.215.14', family: 4 }, { address: '10.0.0.5', family: 4 }];
    throw Object.assign(new Error('not found'), { code: 'ENOTFOUND' });
  });
  assert.equal(await guard.classifyUrl('https://public.example.org/a'), 'PUBLIC');
  assert.equal(await guard.classifyUrl('https://public.example.org/b'), 'PUBLIC');
  assert.equal(lookups, 1);
  assert.equal(await guard.classifyUrl('https://mixed.example.org/'), 'BLOCKED');
  assert.equal(await guard.classifyUrl('https://missing.example.org/'), 'UNRESOLVABLE');
  assert.equal(await guard.classifyUrl('http://169.254.169.254/latest/meta-data/'), 'BLOCKED');
  assert.equal(await guard.classifyUrl('http://[::1]:8080/'), 'BLOCKED');
  assert.equal(await guard.classifyUrl('http://web-page-renderer.web-page-renderer.svc.cluster.local:8080/'), 'BLOCKED');
  assert.equal(await guard.classifyUrl('http://localhost/'), 'BLOCKED');
  assert.equal(await guard.classifyUrl('file:///etc/passwd'), 'BLOCKED');
  assert.equal(await guard.classifyUrl('data:image/png;base64,AAAA'), 'LOCAL');
});

test('bot challenges are detected, normal pages with vendor scripts are not', () => {
  assert.equal(detectBotChallenge({ statusCode: 403, headers: { 'cf-mitigated': 'challenge' }, title: '', html: '' }), 'cloudflare');
  assert.equal(detectBotChallenge({ statusCode: 200, headers: {}, title: 'Just a moment...', html: '' }), 'cloudflare');
  assert.equal(detectBotChallenge({ statusCode: 403, headers: { 'x-datadome': 'protected' }, title: '', html: '' }), 'datadome');
  assert.equal(detectBotChallenge({ statusCode: 403, headers: { server: 'AkamaiGHost' }, title: 'Access Denied', html: 'Reference #18.6a2e3b17.1700000000.1a2b3c' }), 'akamai');
  assert.equal(detectBotChallenge({ statusCode: 403, headers: {}, title: 'Access to this page has been denied', html: '<div id="px-captcha"></div>' }), 'perimeterx');
  assert.equal(detectBotChallenge({ statusCode: 200, headers: {}, title: 'BARMER', html: '<script src="https://js.datadome.co/tags.js"></script>' }), null);
  assert.equal(detectBotChallenge({ statusCode: 404, headers: {}, title: 'Seite nicht gefunden', html: '<h1>404</h1>' }), null);
  assert.equal(detectBotChallenge({ statusCode: 403, headers: {}, title: 'Da ist etwas schiefgelaufen', html: '<p>Ihre Anfrage wurde blockiert.</p><p>Your request was blocked.</p>' }), 'site');
  assert.equal(detectBotChallenge({ statusCode: 200, headers: {}, title: 'Wenn die Anfrage blockiert wird', html: '<p>Ihre Anfrage wurde blockiert, schreibt der Leser</p>' }), null);
});

test('job validation takes the normalized body and refuses bad values', () => {
  const job = validateScreenshotJob({ url: 'https://example.com/', device: 'mobile', viewportWidth: 390, viewportHeight: 844, fullPage: true, cookieBanners: 'hide' });
  assert.equal(job.device, 'mobile');
  assert.equal(job.maxRedirectHops, 5);
  assert.equal(job.waitUntil, 'domcontentloaded');
  assert.throws(() => validateScreenshotJob({ url: 'https://example.com/', viewportWidth: 5000 }), /viewportWidth/);
  assert.throws(() => validateScreenshotJob({ url: 'https://example.com/', format: 'webp' }), /format/);
  assert.throws(() => validateScreenshotJob({ url: '' }), /url/);
  assert.throws(() => validateScreenshotJob([]), /JSON object/);
});

test('host pacer serializes a host and keeps the gap', async () => {
  let now = 0;
  const pacer = new HostPacer({ minimumGapMs: 50, now: () => now });
  const signal = new AbortController().signal;
  const releaseFirst = await pacer.acquire('www.example.com', signal);
  let secondAcquired = false;
  const second = pacer.acquire('example.com', signal).then(release => { secondAcquired = true; return release; });
  await new Promise(resolve => setTimeout(resolve, 20));
  assert.equal(secondAcquired, false);
  now = 10;
  releaseFirst();
  now = 100;
  (await second)();
  assert.equal(secondAcquired, true);
  const otherHostRelease = await pacer.acquire('other.example', signal);
  otherHostRelease();
});

test('navigation errors, content types and the mobile user agent', () => {
  assert.equal(reasonForNavigationError('net::ERR_NAME_NOT_RESOLVED at https://x.invalid/'), 'UNRESOLVABLE');
  assert.equal(reasonForNavigationError('page.goto: Timeout 20000ms exceeded.'), 'TIMEOUT');
  assert.equal(reasonForNavigationError('net::ERR_TOO_MANY_REDIRECTS'), 'TOO_MANY_REDIRECTS');
  assert.equal(reasonForNavigationError('page.goto: Download is starting'), 'UNSUPPORTED_TYPE');
  assert.equal(reasonForNavigationError('net::ERR_CONNECTION_REFUSED'), 'FETCH_FAILED');
  assert.equal(isRenderableContentType('text/html; charset=utf-8'), true);
  assert.equal(isRenderableContentType('application/pdf'), false);
  const mobile = buildMobileUserAgent('158.0.8127.36');
  assert.match(mobile.userAgent, /Chrome\/158\.0\.0\.0 Mobile Safari/);
  assert.equal(mobile.userAgentMetadata.mobile, true);
  assert.equal(mobile.userAgentMetadata.platform, 'Android');
});

test('own consent rules are site-specific, complete and match only their pages', () => {
  const names = OWN_CONSENT_RULES.map(rule => rule.name);
  assert.equal(new Set(names).size, names.length);
  for (const rule of OWN_CONSENT_RULES) {
    assert.match(rule.name, / \(own\)$/);
    assert.ok(rule.runContext?.urlPattern, `${rule.name} must be site-specific`);
    for (const step of ['detectCmp', 'detectPopup', 'optIn', 'optOut']) assert.ok(Array.isArray(rule[step]) && rule[step].length > 0, `${rule.name}.${step}`);
    assert.ok(!JSON.stringify(rule.optOut).match(/akzeptier|approve-all|Einverstanden/i), `${rule.name} opt-out must never accept`);
  }
  const matching = url => OWN_CONSENT_RULES.filter(rule => new RegExp(rule.runContext.urlPattern).test(url)).map(rule => rule.name);
  assert.deepEqual(matching('https://www.tk.de/techniker'), ['tk.de (own)']);
  assert.deepEqual(matching('https://www.kleinanzeigen.de/gdpr?redirectTo=https%3A%2F%2Fwww.kleinanzeigen.de%2F'), ['kleinanzeigen.de (own)']);
  assert.deepEqual(matching('https://www.kleinanzeigen.de/s-fahrraeder/k0'), []);
  assert.deepEqual(matching('https://www.tk.de.example.com/'), []);
  assert.deepEqual(matching('https://www.barmer.de/'), []);
});

/**
 * A page whose main frame hands out the queued autoconsent messages one poll at a time.
 *
 * @param {object[][]} pollAnswers The outbox contents of the consecutive polls
 */
const buildFakeConsentPage = pollAnswers => {
  const mainFrame = { name: 'main' };
  const page = {
    mainFrame: () => mainFrame,
    frames: () => [mainFrame],
    url: () => 'https://www.example.de/',
    addStyleTag: async () => {},
    waitForLoadState: async () => {},
  };
  const evaluate = async (frame, code) => (typeof code === 'string' && code.includes('__rbConsentOutbox') ? (pollAnswers.shift() ?? []) : undefined);
  return { page, evaluate };
};

test('consent: an opt-out that reloads the page counts as rejected once the new document shows no popup', async () => {
  const { page, evaluate } = buildFakeConsentPage([
    [{ type: 'init' }, { type: 'cmpDetected', cmp: 'tk.de (own)' }, { type: 'popupFound', cmp: 'tk.de (own)' }],
    [{ type: 'init' }, { type: 'cmpDetected', cmp: 'tk.de (own)' }],
  ]);
  assert.deepEqual(await new ConsentHandler(page, 'reject', evaluate).settle(6000), { cmp: 'tk.de (own)', action: 'REJECTED' });
});

test('consent: a popup that comes back after the reload is not rejected', async () => {
  const { page, evaluate } = buildFakeConsentPage([
    [{ type: 'init' }, { type: 'popupFound', cmp: 'Example' }],
    [{ type: 'init' }, { type: 'popupFound', cmp: 'Example' }],
  ]);
  assert.deepEqual(await new ConsentHandler(page, 'reject', evaluate).settle(1500), { cmp: 'Example', action: 'HIDDEN' });
});

test('consent: a reported opt-out without a reload is rejected', async () => {
  const { page, evaluate } = buildFakeConsentPage([
    [{ type: 'init' }, { type: 'popupFound', cmp: 'Example' }, { type: 'optOutResult', cmp: 'Example', result: true }, { type: 'autoconsentDone', cmp: 'Example' }],
  ]);
  assert.deepEqual(await new ConsentHandler(page, 'reject', evaluate).settle(3000), { cmp: 'Example', action: 'REJECTED' });
});
