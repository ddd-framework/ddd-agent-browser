// ddd-browser renderer: renders public web pages and runs browser sessions (README.md has the contract).
//
//   node server.mjs        (in the image: docker-entrypoint.sh starts Xvfb first)
//
// RENDER_ROLE decides what the process serves, so one image runs as two deployments:
//   stateless (default)   POST /webPageRenderer/screenshot
//   session               POST /browser/session/<action>   (open, navigate, snapshot, act, screenshot, tabs, analyse, log, close)
//   all                   both (local runs and tests)
// GET /healthz                 {status, contractVersion, chromeVersion, role}: 200 once Chrome runs, else 503 (no auth)
// Every page outcome is HTTP 200 {status: 200, data: {reason, …}}; a renderer fault is {status: <code>, error}.
import http from 'node:http';
import fs from 'node:fs';
import { validateScreenshotJob } from './lib/job.mjs';
import { PageRenderer } from './lib/renderer.mjs';
import { HostPacer } from './lib/pacer.mjs';
import { getCookieFiltersEngine } from './lib/consent.mjs';
import { CallerRegistry } from './lib/callers.mjs';
import { FIXTURE_HOSTS } from './lib/guard.mjs';
import { launchChrome } from './lib/chrome.mjs';
import { SessionManager } from './lib/session/manager.mjs';
import { SESSION_ACTIONS, validateSessionRequest } from './lib/session/requests.mjs';

const integerFromEnv = (name, fallback) => {
  const value = Number.parseInt(process.env[name] ?? '', 10);
  return Number.isFinite(value) && value > 0 ? value : fallback;
};

/** The contract version is the package version; the release workflow sets it to the release tag. */
export const CONTRACT_VERSION = JSON.parse(fs.readFileSync(new URL('./package.json', import.meta.url), 'utf8')).version;

const ROLE = process.env.RENDER_ROLE ?? 'stateless';
if (!['stateless', 'session', 'all'].includes(ROLE)) throw new Error(`RENDER_ROLE must be stateless, session or all, not ${ROLE}`);
const SERVES_SCREENSHOTS = ROLE === 'stateless' || ROLE === 'all';
const SERVES_SESSIONS = ROLE === 'session' || ROLE === 'all';

const PORT = integerFromEnv('PORT', 8080);
const MAX_BODY_BYTES = 64 * 1024;
const REQUEST_TIMEOUT_MS = integerFromEnv('RENDER_REQUEST_TIMEOUT_MS', 60_000);
const FALLBACK_EXTRA_MS = 30_000;
const SCREENSHOT_ROUTE = '/webPageRenderer/screenshot';
const SESSION_PREFIX = '/browser/session/';
const SESSION_ACTION_TIMEOUT_MS = { navigate: 45_000, analyse: 60_000, tabs: 45_000, screenshot: 40_000 };

const log = (level, message, fields = {}) =>
  process.stdout.write(JSON.stringify({ time: new Date().toISOString(), level, message, ...fields }) + '\n');

const callers = new CallerRegistry();

// ---- stateless: per class concurrent pages and a bounded queue (beyond it: 429 + Retry-After)
const QUEUE_MAX = integerFromEnv('RENDER_QUEUE_MAX', 10);
const lanes = new Map();
const laneFor = callerClass => {
  if (!lanes.has(callerClass)) lanes.set(callerClass, { maxActive: callers.limit(callerClass, 'MAX_PAGES', 2, 1), active: 0, waiting: [] });
  return lanes.get(callerClass);
};

const acquireSlot = (lane, signal) =>
  new Promise((resolve, reject) => {
    if (lane.active < lane.maxActive) {
      lane.active++;
      resolve();
      return;
    }
    const waiter = () => {
      lane.active++;
      resolve();
    };
    lane.waiting.push(waiter);
    signal.addEventListener('abort', () => {
      const index = lane.waiting.indexOf(waiter);
      if (index >= 0) lane.waiting.splice(index, 1);
      reject(new Error('timed out while queued'));
    }, { once: true });
  });

const releaseSlot = lane => {
  lane.active--;
  lane.waiting.shift()?.();
};

const pacer = new HostPacer({ minimumGapMs: integerFromEnv('RENDER_HOST_GAP_MS', 5000) });
const renderer = new PageRenderer({
  log,
  noSandbox: process.env.RENDER_CHROME_NO_SANDBOX !== '0',
  camoufoxEnabled: SERVES_SCREENSHOTS && process.env.RENDER_CAMOUFOX !== '0',
});
const sessions = SERVES_SESSIONS
  ? new SessionManager({ getBrowser: () => renderer.chrome, maxSessionsFor: callerClass => callers.limit(callerClass, 'MAX_SESSIONS', 3, 1), log })
  : null;

// ---- HTTP
const sendJson = (response, statusCode, body, headers = {}) => {
  const payload = JSON.stringify(body);
  response.writeHead(statusCode, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload), ...headers });
  response.end(payload);
};
const sendError = (response, statusCode, error, headers = {}) => sendJson(response, statusCode, { status: statusCode, error }, headers);

const readBody = request =>
  new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    request.on('data', chunk => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(Object.assign(new Error(`body larger than ${MAX_BODY_BYTES} bytes`), { statusCode: 413 }));
        request.destroy();
        return;
      }
      chunks.push(chunk);
    });
    request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    request.on('error', reject);
  });

const readJson = async request => {
  try {
    const text = await readBody(request);
    return text.trim() === '' ? {} : JSON.parse(text);
  } catch (error) {
    throw Object.assign(error, { statusCode: error.statusCode ?? 400 });
  }
};

const handleScreenshot = async (request, response) => {
  const callerClass = callers.resolve(request);
  if (!callerClass) return sendError(response, 401, 'missing or unknown X-Render-Token');
  const lane = laneFor(callerClass);
  if (lane.waiting.length >= QUEUE_MAX) {
    log('warn', 'queue full', { callerClass, queued: lane.waiting.length });
    return sendError(response, 429, 'renderer busy', { 'Retry-After': '30' });
  }
  const startedAt = Date.now();
  const abortController = new AbortController();
  // the Camoufox fallback after a challenge gets extra time; callers wait 100 s
  const timer = setTimeout(() => abortController.abort(), REQUEST_TIMEOUT_MS + FALLBACK_EXTRA_MS);
  let hasSlot = false;
  let releaseHost = null;
  let job = null;
  try {
    job = validateScreenshotJob(await readJson(request));
    await acquireSlot(lane, abortController.signal);
    hasSlot = true;
    releaseHost = await pacer.acquire(new URL(job.url).hostname, abortController.signal);
    const queuedMs = Date.now() - startedAt;
    const data = await renderer.render(job, abortController.signal);
    const { content, ...logged } = data;
    sendJson(response, 200, { status: 200, data });
    log('info', 'rendered', { callerClass, queuedMs, totalMs: Date.now() - startedAt, host: new URL(job.url).hostname, bytes: content ? Math.round(content.length * 0.75) : 0, ...logged, title: undefined });
  } catch (error) {
    const statusCode = abortController.signal.aborted ? 504 : (error.statusCode ?? 500);
    log(statusCode >= 500 ? 'error' : 'warn', 'render failed', { callerClass, statusCode, host: job ? new URL(job.url).hostname : null, error: String(error.message ?? error) });
    if (!response.headersSent) sendError(response, statusCode, String(error.message ?? error));
  } finally {
    clearTimeout(timer);
    releaseHost?.();
    if (hasSlot) releaseSlot(lane);
  }
};

const hostOf = url => {
  try {
    return new URL(url).hostname;
  } catch {
    return null;
  }
};

const handleSession = async (request, response, action) => {
  const callerClass = callers.resolve(request);
  if (!callerClass) return sendError(response, 401, 'missing or unknown X-Render-Token');
  if (!renderer.isReady()) return sendError(response, 503, 'chrome is not running yet', { 'Retry-After': '5' });
  const startedAt = Date.now();
  let reason = null;
  try {
    const body = validateSessionRequest(action, await readJson(request));
    let session;
    if (action === 'open' || (action === 'navigate' && !body.sessionKey)) {
      session = await sessions.open(callerClass, body);
    } else {
      const found = sessions.get(callerClass, body.sessionKey);
      if (!found.session) {
        reason = 'SESSION_GONE';
        return sendJson(response, 200, { status: 200, data: { reason, sessionKey: body.sessionKey, cause: found.cause ?? 'unknown', message: 'the session is closed or unknown: navigate again' } });
      }
      session = found.session;
    }
    const timeoutMs = SESSION_ACTION_TIMEOUT_MS[action] ?? 30_000;
    const work = {
      open: async () => ({ reason: 'OK', limits: session.limitsView(), tabs: await session.tabList() }),
      navigate: () => session.navigateAction(body),
      snapshot: () => session.snapshotAction(body),
      act: () => session.act(body),
      screenshot: () => session.screenshotAction(body),
      tabs: () => session.tabsAction(body),
      analyse: () => session.analyseAction(body),
      log: async () => session.logAction(body),
      close: async () => {
        await sessions.closeSession(session, 'closed');
        return { reason: 'CLOSED' };
      },
    }[action];
    const data = action === 'close' ? await work() : await session.run(work, timeoutMs);
    reason = data.reason;
    sendJson(response, 200, { status: 200, data: { sessionKey: session.key, ...data, ...(action === 'navigate' && !body.sessionKey ? { limits: session.limitsView() } : {}) } });
  } catch (error) {
    const statusCode = error.statusCode ?? 500;
    reason = `HTTP_${statusCode}`;
    log(statusCode >= 500 ? 'error' : 'warn', 'session action failed', { callerClass, action, statusCode, error: String(error.message ?? error).split('\n')[0] });
    if (!response.headersSent) sendError(response, statusCode, String(error.message ?? error).split('\n')[0], error.retryAfter ? { 'Retry-After': String(error.retryAfter) } : {});
  } finally {
    log('info', 'session action', { callerClass, action, reason, totalMs: Date.now() - startedAt });
  }
};

const health = () => ({
  status: renderer.isReady() ? 'ok' : 'starting',
  contractVersion: CONTRACT_VERSION,
  chromeVersion: renderer.isReady() ? renderer.chrome.version() : null,
  role: ROLE,
  ...(sessions ? { sessions: sessions.openByClass() } : {}),
});

const server = http.createServer((request, response) => {
  const url = new URL(request.url ?? '/', 'http://renderer');
  if (request.method === 'GET' && url.pathname === '/healthz') {
    return sendJson(response, renderer.isReady() ? 200 : 503, health());
  }
  if (SERVES_SCREENSHOTS && request.method === 'POST' && (url.pathname === SCREENSHOT_ROUTE || url.pathname === '/api/batch' + SCREENSHOT_ROUTE)) {
    return void handleScreenshot(request, response);
  }
  if (SERVES_SESSIONS && request.method === 'POST' && url.pathname.startsWith(SESSION_PREFIX)) {
    const action = url.pathname.slice(SESSION_PREFIX.length);
    if (SESSION_ACTIONS.includes(action)) return void handleSession(request, response, action);
  }
  sendError(response, 404, `not found (this process serves the role ${ROLE})`);
});
server.requestTimeout = REQUEST_TIMEOUT_MS + FALLBACK_EXTRA_MS + 10_000;

(async () => {
  server.listen(PORT, () => log('info', 'listening', {
    port: PORT,
    role: ROLE,
    contractVersion: CONTRACT_VERSION,
    auth: callers.isAuthEnabled ? 'on' : 'off (no RENDER_TOKENS_*)',
    callerClasses: callers.classes,
    pages: SERVES_SCREENSHOTS ? Object.fromEntries(callers.classes.map(callerClass => [callerClass, laneFor(callerClass).maxActive])) : undefined,
    sessions: SERVES_SESSIONS ? Object.fromEntries(callers.classes.map(callerClass => [callerClass, callers.limit(callerClass, 'MAX_SESSIONS', 3, 1)])) : undefined,
    queueMax: QUEUE_MAX,
  }));
  if (FIXTURE_HOSTS.size > 0) log('warn', 'test fixture hosts pass the address guard', { hosts: [...FIXTURE_HOSTS] });
  const chrome = await renderer.start(await launchChrome({ noSandbox: process.env.RENDER_CHROME_NO_SANDBOX !== '0' }));
  chrome.on('disconnected', () => {
    log('error', 'chrome disconnected — exiting so the orchestrator restarts the service');
    process.exit(1);
  });
  log('info', 'chrome ready', { version: chrome.version(), cookieList: getCookieFiltersEngine() !== null });
})().catch(error => {
  log('error', 'startup failed', { error: String(error.message ?? error) });
  process.exit(1);
});

const shutdown = async signal => {
  log('info', 'shutting down', { signal });
  server.close();
  await sessions?.closeAll('shutdown');
  await renderer.close();
  process.exit(0);
};
process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));
