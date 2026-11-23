// Integration tests against the real image: a container with role `all` (screenshots and sessions in one process),
// two caller classes, and the fixture pages of fixtures.mjs served from this process. The fixture host names reach the
// host through Docker's host-gateway; only those `.test` names pass the guard (RENDER_TEST_FIXTURE_HOSTS). The
// gateway's own IP is a private address the guard must refuse, and it reaches the fixture server: a request that gets
// through the guard is recorded as a hit, so "no hit" proves the guard held.
//
//   docker build -t ddd-browser-renderer:test .
//   npm run test:integration                         (RENDER_IMAGE=… to test another image)
import { test, before, after, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import zlib from 'node:zlib';
import { startFixtureServer } from './fixtures.mjs';

const IMAGE = process.env.RENDER_IMAGE ?? 'ddd-browser-renderer:test';
const TOKENS = { prod: 'integration-prod-token', dev: 'integration-dev-token' };
const VERSION = JSON.parse(fs.readFileSync(new URL('../../package.json', import.meta.url), 'utf8')).version;
const PNG_SIGNATURE = '89504e470d0a1a0a';

let fixtures;
let containerId;
let endpoint;
let site;

const docker = (...args) => execFileSync('docker', args, { encoding: 'utf8' }).trim();
const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

const call = async (path, body, token = TOKENS.prod) => {
  const response = await fetch(endpoint + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...(token ? { 'X-Render-Token': token } : {}) },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(120_000),
  });
  return { status: response.status, json: await response.json() };
};
const session = async (action, body, token = TOKENS.prod) => {
  const { status, json } = await call(`/browser/session/${action}`, body, token);
  assert.equal(status, 200, `${action}: HTTP ${status} ${JSON.stringify(json)}`);
  if (json.data?.sessionKey) openSessions.set(json.data.sessionKey, token);
  return json.data;
};
const openSessions = new Map();
/** A minimal PNG reader (8-bit RGB/RGBA, no interlace): colour type and an RGBA pixel getter. */
const readPng = buffer => {
  const width = buffer.readUInt32BE(16);
  const height = buffer.readUInt32BE(20);
  const colourType = buffer[25];
  const channels = colourType === 6 ? 4 : 3;
  const chunks = [];
  for (let offset = 8; offset < buffer.length;) {
    const length = buffer.readUInt32BE(offset);
    if (buffer.toString('ascii', offset + 4, offset + 8) === 'IDAT') chunks.push(buffer.subarray(offset + 8, offset + 8 + length));
    offset += length + 12;
  }
  const data = zlib.inflateSync(Buffer.concat(chunks));
  const stride = width * channels;
  const pixels = Buffer.alloc(height * stride);
  for (let row = 0; row < height; row++) {
    const filter = data[row * (stride + 1)];
    for (let index = 0; index < stride; index++) {
      const raw = data[row * (stride + 1) + 1 + index];
      const left = index >= channels ? pixels[row * stride + index - channels] : 0;
      const up = row > 0 ? pixels[(row - 1) * stride + index] : 0;
      const upLeft = row > 0 && index >= channels ? pixels[(row - 1) * stride + index - channels] : 0;
      const paeth = () => {
        const estimate = left + up - upLeft;
        const [a, b, c] = [Math.abs(estimate - left), Math.abs(estimate - up), Math.abs(estimate - upLeft)];
        return a <= b && a <= c ? left : b <= c ? up : upLeft;
      };
      const predictor = [0, left, up, Math.floor((left + up) / 2), paeth()][filter];
      pixels[row * stride + index] = (raw + predictor) & 0xff;
    }
  }
  const pixel = (x, y) => {
    const start = y * stride + x * channels;
    return [pixels[start], pixels[start + 1], pixels[start + 2], channels === 4 ? pixels[start + 3] : 255];
  };
  return { width, height, colourType, pixel };
};

const hitsFor = prefix => fixtures.hits.filter(hit => hit.path.startsWith(prefix));
const refOf = (text, pattern) => {
  const match = text.match(new RegExp(`${pattern}[^\\n]*\\[ref=((?:f\\d+)?e\\d+)\\]`));
  assert.ok(match, `no ref for ${pattern} in:\n${text}`);
  return match[1];
};

before(async () => {
  fixtures = await startFixtureServer();
  containerId = docker('run', '-d', '--rm', '-p', '127.0.0.1::8080', '--shm-size=1g',
    '--add-host', 'fixtures.test:host-gateway', '--add-host', 'other.test:host-gateway',
    '-e', 'RENDER_ROLE=all', '-e', `RENDER_TOKENS_PROD=${TOKENS.prod}`, '-e', `RENDER_TOKENS_DEV=${TOKENS.dev}`,
    '-e', 'RENDER_MAX_SESSIONS_PROD=2', '-e', 'RENDER_MAX_SESSIONS_DEV=1',
    '-e', 'RENDER_TEST_FIXTURE_HOSTS=fixtures.test,other.test', '-e', 'RENDER_HOST_GAP_MS=0', '-e', 'RENDER_CAMOUFOX=0', IMAGE);
  endpoint = `http://${docker('port', containerId, '8080/tcp').split('\n')[0]}`;
  // the IPv4 address (`hosts` may answer the IPv6 one first)
  const gateway = docker('exec', containerId, 'getent', 'ahostsv4', 'fixtures.test').split(/\s+/)[0];
  fixtures.setPrivateHost(gateway);
  site = `http://fixtures.test:${fixtures.port}`;
  for (let attempt = 0; attempt < 120; attempt++) {
    const health = await fetch(`${endpoint}/healthz`).then(response => (response.ok ? response.json() : null)).catch(() => null);
    if (health?.status === 'ok') return;
    await sleep(500);
  }
  throw new Error(`the renderer did not get ready:\n${docker('logs', containerId)}`);
});

// a failed test must not leave its session open (the next test would meet the class limit)
afterEach(async () => {
  for (const [sessionKey, token] of openSessions) await call('/browser/session/close', { sessionKey }, token).catch(() => {});
  openSessions.clear();
});

after(async () => {
  if (containerId) {
    if (process.env.RENDER_KEEP_LOGS) fs.writeFileSync(process.env.RENDER_KEEP_LOGS, execFileSync('docker', ['logs', containerId], { encoding: 'utf8' }));
    try { docker('rm', '-f', containerId); } catch { /* already gone */ }
  }
  fixtures?.server.close();
});

test('healthz reports the contract version from package.json, Chrome and the role', async () => {
  const health = await (await fetch(`${endpoint}/healthz`)).json();
  assert.equal(health.contractVersion, VERSION);
  assert.equal(health.role, 'all');
  assert.match(health.chromeVersion, /^\d+\.\d+\.\d+\.\d+$/);
});

test('drop-in: the screenshot route renders as before and refuses unknown callers and private redirects', async () => {
  const { status, json } = await call('/webPageRenderer/screenshot', { url: `${site}/design`, fullPage: true, viewportWidth: 1024, viewportHeight: 768 });
  assert.equal(status, 200);
  assert.equal(json.data.reason, 'OK');
  assert.equal(Buffer.from(json.data.content, 'base64').subarray(0, 8).toString('hex'), PNG_SIGNATURE);
  assert.equal(json.data.title, 'Fixture Design');
  assert.equal((await call('/webPageRenderer/screenshot', { url: `${site}/` }, null)).status, 401);
  const redirect = await call('/webPageRenderer/screenshot', { url: `${site}/redirect-private` });
  assert.equal(redirect.json.data.reason, 'BLOCKED');
  assert.equal(hitsFor('/private-hit').length, 0);
});

test('sessions are filed under their caller class: dev never reaches prod and the other way round; limits per class', async () => {
  const devSession = await session('navigate', { url: `${site}/` }, TOKENS.dev);
  assert.equal(devSession.reason, 'OK');
  assert.match(devSession.sessionKey, /^[0-9a-f]{32}$/);
  assert.match(devSession.snapshot.text, /heading 1 "Willkommen"/);
  const fromProd = await session('snapshot', { sessionKey: devSession.sessionKey }, TOKENS.prod);
  assert.equal(fromProd.reason, 'SESSION_GONE');
  assert.equal((await session('snapshot', { sessionKey: devSession.sessionKey }, TOKENS.dev)).reason, 'OK');
  const secondDev = await call('/browser/session/open', {}, TOKENS.dev);
  assert.equal(secondDev.status, 429);
  const prodSession = await session('open', {}, TOKENS.prod);
  assert.equal((await session('log', { sessionKey: prodSession.sessionKey }, TOKENS.dev)).reason, 'SESSION_GONE');
  assert.equal((await session('close', { sessionKey: devSession.sessionKey }, TOKENS.dev)).reason, 'CLOSED');
  const afterClose = await session('snapshot', { sessionKey: devSession.sessionKey }, TOKENS.dev);
  assert.equal(afterClose.reason, 'SESSION_GONE');
  assert.equal(afterClose.cause, 'closed');
  await session('close', { sessionKey: prodSession.sessionKey });
  assert.equal((await call('/browser/session/snapshot', { sessionKey: 'a'.repeat(32) }, 'wrong-token')).status, 401);
});

test('guard: private targets, a redirect into a private address, and redirect hops inside a cross-site iframe are refused', async () => {
  const opened = await session('navigate', { url: 'http://10.0.0.5/' });
  assert.equal(opened.reason, 'BLOCKED');
  const key = opened.sessionKey;
  assert.equal((await session('navigate', { sessionKey: key, url: `${site}/redirect-private` })).reason, 'BLOCKED');
  assert.equal((await session('navigate', { sessionKey: key, url: `${site}/redirect-loop` })).reason, 'TOO_MANY_REDIRECTS');
  const framed = await session('navigate', { sessionKey: key, url: `${site}/subframe` });
  assert.equal(framed.reason, 'OK');
  await sleep(1500);
  assert.ok(hitsFor('/frame').some(hit => hit.host === 'other.test'), 'the cross-site frame loaded');
  assert.ok(hitsFor('/hop').length >= 2, 'the frame asked for both redirecting resources');
  assert.deepEqual(hitsFor('/private-hit').map(hit => hit.path), []);
  const { events } = await session('log', { sessionKey: key });
  const blocked = events.filter(entry => entry.type === 'blocked').map(entry => new URL(entry.url).pathname);
  for (const path of ['/private-hit', '/private-hit-iframe', '/private-hit-frame-image', '/private-hit-frame-fetch']) assert.ok(blocked.includes(path), `${path} blocked: ${blocked}`);
  await session('close', { sessionKey: key });
});

test('submissions: a fetch POST with typed text is aborted, released once with its token, never twice; form posts and cross-site leaks stop; password and card fields are refused', async () => {
  const opened = await session('navigate', { url: `${site}/form` });
  const key = opened.sessionKey;
  const view = await session('snapshot', { sessionKey: key, mode: 'interactive' });
  const message = refOf(view.text, 'textbox "Nachricht"');
  const send = refOf(view.text, 'button "Senden"');
  assert.equal((await session('act', { sessionKey: key, action: 'type', ref: refOf(view.text, 'textbox "Passwort"'), text: 'geheim123' })).reason, 'FIELD_REFUSED');
  assert.equal((await session('act', { sessionKey: key, action: 'type', ref: refOf(view.text, 'textbox "Kartennummer"'), text: '4111111111111111' })).reason, 'FIELD_REFUSED');
  const typed = await session('act', { sessionKey: key, action: 'type', ref: message, text: 'Hallo Welt 123' });
  assert.equal(typed.reason, 'OK');
  assert.match(typed.changed, /value="Hallo Welt 123"/);

  const aborted = await session('act', { sessionKey: key, action: 'click', ref: send });
  assert.equal(aborted.reason, 'CONSENT_REQUIRED');
  const [submission] = aborted.submissions;
  assert.equal(submission.method, 'POST');
  assert.equal(submission.target, `${site}/api/send`);
  assert.deepEqual(submission.fields.find(field => field.name === 'message'), { name: 'message', value: 'Hallo Welt 123', typed: true });
  assert.match(submission.releaseToken, /^[0-9a-f]{32}$/);
  await sleep(500);
  assert.equal(hitsFor('/api/send').length, 0);

  const released = await session('act', { sessionKey: key, action: 'click', ref: send, releaseToken: submission.releaseToken });
  assert.equal(released.reason, 'OK');
  assert.equal(released.releaseUsed, true);
  await sleep(500);
  assert.equal(hitsFor('/api/send').length, 1);
  assert.equal(JSON.parse(hitsFor('/api/send')[0].body).message, 'Hallo Welt 123');

  assert.equal((await session('act', { sessionKey: key, action: 'click', ref: send, releaseToken: submission.releaseToken })).reason, 'RELEASE_INVALID');
  const again = await session('act', { sessionKey: key, action: 'click', ref: send });
  assert.equal(again.reason, 'CONSENT_REQUIRED');
  await sleep(500);
  assert.equal(hitsFor('/api/send').length, 1);

  const leak = await session('act', { sessionKey: key, action: 'click', ref: refOf(view.text, 'button "Teilen"') });
  assert.equal(leak.reason, 'CONSENT_REQUIRED');
  assert.equal(leak.submissions[0].kind, 'dropped');
  assert.equal(leak.submissions[0].releaseToken, null);
  const formPost = await session('act', { sessionKey: key, action: 'click', ref: refOf(view.text, 'button "Formular abschicken"') });
  assert.equal(formPost.reason, 'CONSENT_REQUIRED');
  assert.equal(formPost.submissions[0].target, `${site}/api/form`);
  assert.ok(formPost.submissions[0].fields.some(field => field.name === 'csrf'));
  await sleep(500);
  assert.equal(hitsFor('/collect').length, 0);
  assert.equal(hitsFor('/api/form').length, 0);
  assert.match(formPost.url, /\/form$/, 'the aborted post leaves the form page as it was');
  const formReleased = await session('act', { sessionKey: key, action: 'click', ref: refOf(view.text, 'button "Formular abschicken"'), releaseToken: formPost.submissions[0].releaseToken });
  assert.equal(formReleased.reason, 'OK');
  assert.equal(formReleased.navigated, true);
  assert.equal(hitsFor('/api/form').length, 1);
  await session('navigate', { sessionKey: key, url: `${site}/form` });
  const freshView = await session('snapshot', { sessionKey: key, mode: 'interactive' });
  const selected = await session('act', { sessionKey: key, action: 'select', ref: refOf(freshView.text, 'combobox "Thema"'), values: ['Beitrag'] });
  assert.equal(selected.reason, 'OK');
  assert.equal((await session('act', { sessionKey: key, action: 'select', ref: refOf(freshView.text, 'combobox "Thema"'), values: ['Kündigung'] })).reason, 'INVALID_OPTION');
  await session('close', { sessionKey: key });
});

test('snapshots stay within their size, page with a cursor, and refs of an older page are stale', async () => {
  const opened = await session('navigate', { url: `${site}/big` });
  const key = opened.sessionKey;
  assert.ok(opened.snapshot.text.length <= 4000);
  const first = await session('snapshot', { sessionKey: key, mode: 'interactive' });
  assert.ok(first.text.length <= 6000, `${first.text.length}`);
  assert.ok(first.nextCursor > 0);
  const second = await session('snapshot', { sessionKey: key, mode: 'interactive', cursor: first.nextCursor, maxChars: 1000 });
  assert.ok(second.text.length <= 1000);
  assert.ok(!first.text.includes(second.text.split('\n')[0]));
  const text = await session('snapshot', { sessionKey: key, mode: 'text', maxChars: 2000 });
  assert.ok(text.text.length <= 2000);
  assert.match(text.text, /^# Große Seite/);
  const found = await session('snapshot', { sessionKey: key, mode: 'interactive', find: 'Nummer 1499' });
  const ref = refOf(found.text, 'link "Eintrag Nummer 1499');
  await session('navigate', { sessionKey: key, url: `${site}/` });
  assert.equal((await session('act', { sessionKey: key, action: 'click', ref })).reason, 'STALE_REF');
  await session('close', { sessionKey: key });
});

test('design: extractDesign finds the fixture tokens, inspect names the rule and variable, assets come back sanitized', async () => {
  const opened = await session('navigate', { url: `${site}/design` });
  const key = opened.sessionKey;
  const design = await session('analyse', { sessionKey: key, name: 'design' });
  assert.equal(design.reason, 'OK');
  const { tokens } = design;
  assert.equal(tokens.palette.primary, '#0a7d5c');
  assert.equal(tokens.palette.background, '#ffffff');
  assert.equal(tokens.palette.text, '#1a1a2e');
  assert.equal(tokens.palette.surface, '#f2f4f3');
  assert.equal(tokens.typography.h1.family, 'Fixture Sans');
  assert.equal(tokens.typography.h1.size, '48px');
  assert.equal(tokens.typography.h1.category, 'sans-serif');
  assert.equal(tokens.typography.h1.fallback, 'Inter');
  assert.match(tokens.typography.h1.fontFiles[0].url, /\/fonts\/fixture-sans\.woff2$/);
  assert.deepEqual(tokens.scales.radius.map(entry => entry.value).sort(), ['16px', '8px', '999px']);
  assert.equal(tokens.components.primaryButton.radius, '8px');
  assert.equal(tokens.components.primaryButton.hover['background-color'], '#075e45');
  assert.equal(tokens.components.secondaryButton.border, '2px solid #0a7d5c');
  assert.equal(tokens.components.card.radius, '16px');
  assert.equal(tokens.logo.type, 'svg');
  assert.equal(tokens.logo.svgFile, 'logo.svg');
  assert.equal(tokens.logo.linksToStartPage, true);
  assert.equal(tokens.logo.logoBackground, '#1fa35a');
  assert.equal(tokens.logo.lightDrawing, true);
  assert.equal(tokens.logo.onDarkFile, 'logo-on-dark.svg');
  assert.ok(tokens.customProperties.top.find(entry => entry.name === '--brand-primary').uses >= 3);
  assert.equal(tokens.jsonLdLogo, 'https://fixtures.test/logo.png');
  assert.ok(design.summary.length <= 5000);

  const button = await session('snapshot', { sessionKey: key, mode: 'interactive', find: 'Jetzt starten' });
  const inspected = await session('analyse', { sessionKey: key, name: 'inspect', ref: refOf(button.text, 'link "Jetzt starten"') });
  assert.equal(inspected.reason, 'OK');
  const background = inspected.rules.find(rule => rule.property === 'background-color');
  assert.equal(background.selector, '.btn-primary');
  assert.equal(background.variable, '--brand-primary');
  assert.match(background.stylesheet, /\/design\.css$/);
  assert.ok(inspected.summary.length <= 5000);

  const assets = await session('analyse', { sessionKey: key, name: 'assets', kinds: ['stylesheets', 'logo', 'screenshots', 'fonts'] });
  const byName = Object.fromEntries(assets.files.map(file => [file.name, file]));
  assert.match(Buffer.from(byName['stylesheet-01-design.css'].content, 'base64').toString(), /--brand-primary: #0a7d5c/);
  const svg = Buffer.from(byName['logo.svg'].content, 'base64').toString();
  assert.doesNotMatch(svg, /<script|onclick|currentColor|class=|ff0000/);
  assert.match(svg, /^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" width="172" height="48" viewBox="0 0 172 48"><rect width="172" height="48" rx="24" fill="#1fa35a"\/>/);
  assert.equal((svg.match(/fill="#ffffff"/g) ?? []).length, 2, 'both shapes carry the resolved currentColor');
  const onDark = Buffer.from(byName['logo-on-dark.svg'].content, 'base64').toString();
  assert.doesNotMatch(onDark, /<rect|1fa35a/);
  assert.match(onDark, /fill="#ffffff"/);
  const png = readPng(Buffer.from(byName['logo.png'].content, 'base64'));
  assert.equal(png.colourType, 6, 'RGBA');
  assert.deepEqual([png.width, png.height], [344, 96]);
  assert.equal(png.pixel(0, 0)[3], 0, 'the rounded corner is transparent');
  assert.equal(png.pixel(png.width - 1, png.height - 1)[3], 0);
  assert.deepEqual(png.pixel(172, 4), [0x1f, 0xa3, 0x5a, 255], 'the pill');
  assert.deepEqual(png.pixel(64, 48), [255, 255, 255, 255], 'the drawing');
  for (let y = 0; y < png.height; y += 2) {
    for (let x = 0; x < png.width; x += 2) {
      const [red, green, blue, alpha] = png.pixel(x, y);
      assert.ok(!(alpha > 0 && Math.abs(red - 0x4a) < 12 && Math.abs(green - 0x4a) < 12 && Math.abs(blue - 0x20) < 12), `page pixel at ${x},${y}`);
    }
  }
  assert.ok(byName['screenshot-viewport.png']);
  assert.equal(byName['font-fixture-sans.woff2'].family, 'Fixture Sans');
  assert.ok(!assets.files.some(file => /inline/.test(file.name) && Buffer.from(file.content, 'base64').toString().includes('consent fallback')));
  const shot = await session('screenshot', { sessionKey: key, target: 'fullPage' });
  assert.equal(Buffer.from(shot.content, 'base64').subarray(0, 8).toString('hex'), PNG_SIGNATURE);
  await session('close', { sessionKey: key });
});

test('tabs: a page that opens a window gets a guarded tab, listed and closable', async () => {
  const opened = await session('navigate', { url: `${site}/` });
  const key = opened.sessionKey;
  const view = await session('snapshot', { sessionKey: key, mode: 'interactive' });
  const clicked = await session('act', { sessionKey: key, action: 'click', ref: refOf(view.text, 'link "Neues Fenster"') });
  assert.equal(clicked.newTabs.length, 1);
  const tabs = await session('tabs', { sessionKey: key, action: 'list' });
  assert.equal(tabs.tabs.length, 2);
  assert.match(tabs.tabs[1].url, /\/popup-target$/);
  const closed = await session('tabs', { sessionKey: key, action: 'close', tab: tabs.tabs[1].tab });
  assert.equal(closed.tabs.length, 1);
  await session('close', { sessionKey: key });
});
