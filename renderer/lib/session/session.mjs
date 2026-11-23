// One browser session: a fresh browser context (no profile, no logins, cookies only until the session ends), up to 3
// tabs, driven by refs from accessibility snapshots. Every request of every tab, frame, worker and redirect hop passes
// the address guard and the submission rule (CDP Fetch per tab; site isolation is off, see chrome.mjs). A Playwright
// route on the context is the second layer: it holds back every request of a page the CDP guard has not adopted yet
// (a popup), and checks the first URL of every request once more.
import crypto from 'node:crypto';
import { AddressGuard } from '../guard.mjs';
import { LOCALE, TIMEZONE } from '../chrome.mjs';
import { ConsentHandler } from '../consent.mjs';
import { detectBotChallenge } from '../challenge.mjs';
import { buildMobileUserAgent, reasonForNavigationError } from '../renderer.mjs';
import { judgeRequest, submissionSignature, targetOf } from './submission.mjs';
import { changedLines, collectRefs, findByRef, flattenLines, interactiveView, outlineView, textView } from './snapshot.mjs';
import { extractDesign } from '../analysis/design.mjs';
import { inspectElement } from '../analysis/inspect.mjs';
import { exportAssets } from '../analysis/assets.mjs';

export const SESSION_LIMITS = {
  maxTabs: 3,
  idleMs: 5 * 60_000,
  idleWithConsentMs: 15 * 60_000,
  maxAgeMs: 30 * 60_000,
  maxNavigations: 60,
  maxTransferBytes: 200_000_000,
  releaseTtlMs: 15 * 60_000,
  navigationGapMs: 1000,
  maxRedirectHops: 5,
  maxEvents: 1000,
};

const MAX_IMAGE_BYTES = 15_000_000;
const CONSENT_BUDGET_MS = 4000;
const ACTION_TIMEOUT_MS = 10_000;
const PAYMENT_HOSTS = /(^|\.)(stripe\.com|stripe\.network|adyen\.com|adyenpayments\.com|paypal\.com|paypalobjects\.com|braintreegateway\.com|klarna\.com|checkout\.com|mollie\.com|unzer\.com|computop\.com|worldpay\.com|payone\.com|sumup\.com)$/i;

const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const clip = (value, limit = 300) => {
  const text = String(value ?? '');
  return text.length > limit ? text.slice(0, limit) + '…' : text;
};
const randomKey = () => crypto.randomBytes(16).toString('hex');

/**
 * Chrome (Patchright): evaluation in the page's main world, where the consent scripts live. A session injects the
 * consent scripts by evaluation after the navigation instead of an init script: Patchright delivers init scripts
 * through its own request interception, and that together with the session's two interception layers fails every
 * subresource (net::ERR_FAILED).
 */
const evaluateInMainWorld = (target, code, argument) => target.evaluate(code, argument, undefined, false);

/**
 * The field check before typing: password and payment fields are refused, as is anything that is no text field.
 * Runs in the element's frame.
 */
const fieldRefusal = element => {
  const tag = element.localName;
  const type = (element.getAttribute('type') ?? '').toLowerCase();
  const autocomplete = (element.getAttribute('autocomplete') ?? '').toLowerCase();
  const labelText = element.labels && element.labels.length ? element.labels[0].innerText : '';
  const words = [element.getAttribute('name'), element.id, element.getAttribute('aria-label'), element.getAttribute('placeholder'), labelText]
    .filter(Boolean).join(' ').toLowerCase();
  if (type === 'password') return { reason: 'FIELD_REFUSED', why: 'password field' };
  if (/(^|\s)cc-|current-password|new-password|one-time-code/.test(autocomplete)) return { reason: 'FIELD_REFUSED', why: `autocomplete ${autocomplete}` };
  if (/passw|kennwort|\bpin\b|\btan\b|cvc|cvv|card.?num|kartennummer|kreditkarte|credit.?card|\biban\b|security.?code|sicherheitscode|prüfnummer/.test(words)) return { reason: 'FIELD_REFUSED', why: 'password or payment field' };
  const role = element.getAttribute('role');
  const isTextInput = (tag === 'input' && !['file', 'hidden', 'checkbox', 'radio', 'submit', 'button', 'image', 'range', 'color', 'reset'].includes(type))
    || tag === 'textarea' || element.isContentEditable || role === 'textbox' || role === 'searchbox' || role === 'combobox';
  if (!isTextInput) return { reason: 'NOT_A_FIELD', why: `${tag}${type ? `[type=${type}]` : ''} takes no text` };
  return { reason: null, host: location.hostname, name: element.getAttribute('name') || element.id || element.getAttribute('aria-label') || labelText || null };
};

const outcome = (reason, fields = {}) => ({ reason, ...fields });

export class BrowserSession {
  /**
   * @param {{key: string, callerClass: string, browser: import('playwright-core').Browser, options: object, log: Function, limits?: object}} setup
   */
  constructor({ key, callerClass, browser, options, log, limits = SESSION_LIMITS }) {
    this.key = key;
    this.callerClass = callerClass;
    this.browser = browser;
    this.options = options;
    this.log = log;
    this.limits = limits;
    this.guard = new AddressGuard();
    this.tabs = new Map();
    this.adopting = new Map();
    this.pendingPopupUrls = new WeakMap();
    this.tabCounter = 0;
    this.activeTab = null;
    this.events = [];
    this.eventSeq = 0;
    this.typedTexts = new Set();
    this.submissions = [];
    this.submissionSeq = 0;
    this.actionStartedAt = 0;
    this.releases = new Map();
    this.navigationCount = 0;
    this.transferBytes = 0;
    this.createdAt = Date.now();
    this.lastActivity = Date.now();
    this.consentPendingUntil = 0;
    this.lastNavigationAt = 0;
    this.closed = null;
    this.queue = Promise.resolve();
    this.queued = 0;
    this.context = null;
  }

  // ---- lifecycle

  async start() {
    const { device, viewportWidth, viewportHeight } = this.options;
    const contextOptions = {
      viewport: { width: viewportWidth, height: viewportHeight },
      deviceScaleFactor: 1,
      locale: LOCALE,
      timezoneId: TIMEZONE,
      ignoreHTTPSErrors: true,
      acceptDownloads: false,
      serviceWorkers: 'block',
      permissions: [],
    };
    if (device === 'mobile') Object.assign(contextOptions, { isMobile: true, hasTouch: true });
    this.context = await this.browser.newContext(contextOptions);
    await this.context.route('**/*', route => this.routeLayer(route));
    this.context.on('page', page => { void this.adoptPage(page); });
    await this.openTab();
    this.event('open', { device, viewport: `${viewportWidth}x${viewportHeight}`, cookieBanners: this.options.cookieBanners });
  }

  get isClosed() {
    return this.closed !== null;
  }

  get consentPending() {
    return Date.now() < this.consentPendingUntil;
  }

  /**
   * @param {string} cause idle, maxAge, closed, transferLimit, shutdown, crash
   */
  async close(cause) {
    if (this.closed) return;
    this.closed = { cause, at: Date.now() };
    this.event('close', { cause });
    this.log('info', 'session closed', { callerClass: this.callerClass, cause, ageSeconds: Math.round((Date.now() - this.createdAt) / 1000), navigations: this.navigationCount, transferMb: Math.round(this.transferBytes / 1e5) / 10 });
    await this.context?.close().catch(() => {});
  }

  /**
   * Runs one action at a time per session, with a deadline.
   *
   * @param {() => Promise<object>} work
   * @param {number} timeoutMs
   */
  run(work, timeoutMs) {
    if (this.queued >= 3) return Promise.resolve(outcome('BUSY', { message: 'the session is still working on earlier actions' }));
    this.queued++;
    const task = this.queue.then(async () => {
      if (this.closed) return outcome('SESSION_GONE', { cause: this.closed.cause });
      this.lastActivity = Date.now();
      let timer;
      try {
        return await Promise.race([
          work(),
          new Promise(resolve => { timer = setTimeout(() => resolve(outcome('TIMEOUT', { message: `no result within ${timeoutMs / 1000} s` })), timeoutMs); }),
        ]);
      } finally {
        clearTimeout(timer);
        this.lastActivity = Date.now();
      }
    });
    this.queue = task.catch(() => {}).finally(() => { this.queued--; });
    return task;
  }

  event(type, fields = {}) {
    this.events.push({ seq: ++this.eventSeq, time: new Date().toISOString(), type, ...fields });
    if (this.events.length > this.limits.maxEvents) this.events.splice(0, this.events.length - this.limits.maxEvents);
  }

  // ---- tabs and their guards

  async openTab() {
    if (this.tabs.size >= this.limits.maxTabs) return null;
    const page = await this.context.newPage();
    const tab = await (this.adopting.get(page) ?? this.adoptPage(page));
    if (tab) this.activeTab = tab.id;
    return tab;
  }

  adoptPage(page) {
    if (this.adopting.has(page)) return this.adopting.get(page);
    const promise = (async () => {
      if (this.closed) return null;
      if (this.tabs.size >= this.limits.maxTabs) {
        this.event('tabRefused', { why: `at most ${this.limits.maxTabs} tabs` });
        await page.close().catch(() => {});
        return null;
      }
      const tab = await this.setupTab(page);
      const pendingUrl = this.pendingPopupUrls.get(page);
      if (pendingUrl) {
        this.event('tabOpened', { tab: tab.id, url: clip(pendingUrl), byPage: true });
        this.popupsDuringAction?.push(tab.id);
        await page.goto(pendingUrl, { waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => {});
      } else if (page.url() !== 'about:blank') {
        this.event('tabOpened', { tab: tab.id, url: clip(page.url()), byPage: true });
        this.popupsDuringAction?.push(tab.id);
      }
      return tab;
    })().catch(error => {
      this.log('warn', 'tab setup failed', { error: String(error.message ?? error) });
      page.close().catch(() => {});
      return null;
    });
    this.adopting.set(page, promise);
    return promise;
  }

  async setupTab(page) {
    const id = `t${++this.tabCounter}`;
    const cdp = await this.context.newCDPSession(page);
    if (this.options.device === 'mobile') await cdp.send('Network.setUserAgentOverride', buildMobileUserAgent(this.browser.version()));
    const tab = {
      id, page, cdp, mainFrameId: null, guarded: false, navigationId: 0, snapshot: null, dialogs: [], responses: new Map(),
      nav: { blocked: false, unresolvable: false, hops: 0, tooManyRedirects: false },
    };
    // a network buffer, so exported assets come from what the page loaded instead of new requests
    await cdp.send('Network.enable', { maxTotalBufferSize: 30_000_000, maxResourceBufferSize: 10_000_000 });
    cdp.on('Network.dataReceived', event => this.countTransfer(event.encodedDataLength ?? 0));
    cdp.on('Network.responseReceived', event => {
      tab.responses.set(event.response.url, { requestId: event.requestId, mimeType: event.response.mimeType, type: event.type });
      if (tab.responses.size > 1500) tab.responses.delete(tab.responses.keys().next().value);
    });
    tab.mainFrameId = (await cdp.send('Page.getFrameTree')).frameTree.frame.id;
    await cdp.send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
    cdp.on('Fetch.requestPaused', event => { void this.onRequestPaused(tab, event); });
    page.on('framenavigated', frame => {
      if (frame !== page.mainFrame()) return;
      tab.navigationId++;
      this.navigationCount++;
    });
    page.on('dialog', dialog => {
      tab.dialogs.push({ type: dialog.type(), message: clip(dialog.message(), 200) });
      this.event('dialog', { tab: id, type: dialog.type(), message: clip(dialog.message(), 200) });
      dialog.dismiss().catch(() => {});
    });
    page.on('download', download => {
      this.event('downloadRefused', { tab: id, url: clip(download.url()) });
      download.cancel().catch(() => {});
    });
    // a listener means no native file dialog opens; nothing is ever chosen
    page.on('filechooser', () => this.event('fileUploadRefused', { tab: id }));
    page.on('close', () => {
      this.tabs.delete(id);
      if (this.activeTab === id) this.activeTab = this.tabs.keys().next().value ?? null;
    });
    tab.guarded = true;
    this.tabs.set(id, tab);
    this.activeTab ??= id;
    return tab;
  }

  /**
   * @param {string|null} tabId
   * @returns {object|null}
   */
  tab(tabId) {
    return this.tabs.get(tabId ?? this.activeTab) ?? null;
  }

  tabByPage(page) {
    for (const tab of this.tabs.values()) if (tab.page === page) return tab;
    return null;
  }

  countTransfer(bytes) {
    this.transferBytes += bytes;
    if (this.transferBytes > this.limits.maxTransferBytes && !this.closed) {
      this.log('warn', 'session transfer limit', { callerClass: this.callerClass });
      void this.close('transferLimit');
    }
  }

  /** The context-level layer: pages without the CDP guard send nothing; every first URL is checked once more. */
  async routeLayer(route) {
    const request = route.request();
    let page = null;
    try {
      page = request.frame().page();
    } catch {
      // a worker's request has no frame: the address check below still applies
    }
    if (page) {
      const tab = this.tabByPage(page);
      if (!tab || !tab.guarded) {
        try {
          if (request.isNavigationRequest() && request.frame() === page.mainFrame() && request.method() === 'GET') this.pendingPopupUrls.set(page, request.url());
        } catch {
          // the frame is gone
        }
        return route.abort('blockedbyclient').catch(() => {});
      }
    }
    const verdict = await this.guard.classifyUrl(request.url());
    if (verdict === 'BLOCKED' || verdict === 'UNRESOLVABLE') {
      this.event('blocked', { url: clip(request.url()), verdict, layer: 'context' });
      return route.abort('blockedbyclient').catch(() => {});
    }
    return route.continue().catch(() => {});
  }

  /** The tab's CDP layer: address guard on every request and hop, redirect limit, submission rule. */
  async onRequestPaused(tab, event) {
    const { request, resourceType, frameId, requestId } = event;
    const proceed = () => tab.cdp.send('Fetch.continueRequest', { requestId }).catch(() => {});
    const refuse = () => tab.cdp.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }).catch(() => {});
    const isMainDocument = resourceType === 'Document' && frameId === tab.mainFrameId;
    const verdict = await this.guard.classifyUrl(request.url);
    if (verdict === 'BLOCKED' || verdict === 'UNRESOLVABLE') {
      if (isMainDocument) {
        if (verdict === 'BLOCKED') tab.nav.blocked = true;
        else tab.nav.unresolvable = true;
      }
      this.event('blocked', { tab: tab.id, url: clip(request.url), verdict, resourceType, frame: isMainDocument ? 'main' : (resourceType === 'Document' ? 'sub' : 'resource') });
      return refuse();
    }
    if (isMainDocument) {
      tab.nav.hops++;
      if (tab.nav.hops > this.limits.maxRedirectHops + 1) {
        tab.nav.tooManyRedirects = true;
        return refuse();
      }
    }
    let body = request.postData ?? null;
    let bodyUnreadable = false;
    if (body === null && Array.isArray(request.postDataEntries) && request.postDataEntries.length) {
      body = request.postDataEntries.map(entry => (entry.bytes ? Buffer.from(entry.bytes, 'base64').toString('utf8') : '')).join('');
    }
    if (body === null && request.hasPostData) {
      try {
        body = (await tab.cdp.send('Network.getRequestPostData', { requestId: event.networkId })).postData ?? null;
      } catch {
        bodyUnreadable = true;
      }
    }
    const contentType = Object.entries(request.headers ?? {}).find(([name]) => name.toLowerCase() === 'content-type')?.[1] ?? null;
    const judgement = judgeRequest({
      method: request.method, url: request.url, isNavigation: resourceType === 'Document', body, bodyUnreadable, contentType,
      topUrl: tab.page.url(), typedTexts: this.typedTexts,
    });
    if (judgement.kind === 'allow') return proceed();
    const signature = submissionSignature({ method: request.method, url: request.url, fields: judgement.fields, typed: judgement.typed });
    if (judgement.kind === 'submission') {
      const release = this.consumeRelease(signature);
      if (release) {
        this.event('released', { tab: tab.id, method: request.method, target: targetOf(request.url) });
        this.releasedDuringAction?.push({ method: request.method, target: targetOf(request.url) });
        return proceed();
      }
    }
    // a page that retries the same request within one action is reported once
    const recent = this.submissions.find(entry => entry.signature === signature && entry.at >= this.actionStartedAt && Date.now() - entry.at < 3000);
    if (!recent) {
      const submission = {
        seq: ++this.submissionSeq, at: Date.now(), signature, kind: judgement.kind, tab: tab.id, method: request.method,
        target: targetOf(request.url), url: clip(request.url), resourceType, frame: frameId === tab.mainFrameId ? 'main' : 'sub',
        fields: judgement.fields, typed: judgement.typed,
        releaseToken: judgement.kind === 'submission' ? this.issueRelease(signature) : null,
        expiresAt: judgement.kind === 'submission' ? new Date(Date.now() + this.limits.releaseTtlMs).toISOString() : null,
      };
      this.submissions.push(submission);
      if (this.submissions.length > 50) this.submissions.shift();
      this.consentPendingUntil = Date.now() + this.limits.idleWithConsentMs;
      this.event(judgement.kind === 'submission' ? 'submissionAborted' : 'requestDropped', {
        tab: tab.id, method: request.method, target: submission.target, resourceType, fields: judgement.fields.map(field => field.name), typed: judgement.typed,
      });
    }
    // Aborted, not BlockedByClient: a blocked navigation would commit Chrome's error page and lose the form; an aborted
    // one leaves the page as it was, so the same action can be repeated with the release
    return tab.cdp.send('Fetch.failRequest', { requestId, errorReason: 'Aborted' }).catch(() => {});
  }

  issueRelease(signature) {
    const token = randomKey();
    this.releases.set(token, { signature, expiresAt: Date.now() + this.limits.releaseTtlMs, armed: false });
    return token;
  }

  armRelease(token) {
    const release = this.releases.get(token);
    if (!release || release.expiresAt < Date.now()) {
      this.releases.delete(token);
      return false;
    }
    release.armed = true;
    return true;
  }

  consumeRelease(signature) {
    for (const [token, release] of this.releases) {
      if (release.armed && release.signature === signature && release.expiresAt >= Date.now()) {
        this.releases.delete(token);
        return release;
      }
    }
    return null;
  }

  disarmReleases() {
    for (const [token, release] of this.releases) {
      if (release.expiresAt < Date.now()) this.releases.delete(token);
      else release.armed = false;
    }
  }

  // ---- snapshots and refs

  async takeSnapshot(tab) {
    const tree = await tab.page.ariaSnapshotJSON({ mode: 'ai', boxes: true, timeout: 15_000 });
    const roots = Array.isArray(tree) ? tree : [tree];
    tab.snapshot = { tree: roots, refs: collectRefs(roots), navigationId: tab.navigationId, lines: flattenLines(roots, tab.page.url()) };
    return tab.snapshot;
  }

  /**
   * @returns {Promise<{locator?: import('playwright-core').Locator, reason?: string}>}
   */
  async resolveRef(tab, ref) {
    const snapshot = tab.snapshot;
    if (!snapshot || snapshot.navigationId !== tab.navigationId || !snapshot.refs.has(ref)) return { reason: 'STALE_REF' };
    const locator = tab.page.locator(`aria-ref=${ref}`);
    const count = await locator.count().catch(() => 0);
    if (count === 0) return { reason: 'STALE_REF' };
    return { locator };
  }

  async pageFacts(tab) {
    const viewportHeight = tab.page.viewportSize()?.height ?? this.options.viewportHeight;
    const hiddenTextChars = await tab.page.evaluate(() => {
      if (!document.body) return 0;
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      let all = 0;
      for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        const parent = node.parentElement?.localName;
        if (parent === 'script' || parent === 'style' || parent === 'noscript' || parent === 'template') continue;
        all += node.textContent.replace(/\s+/g, ' ').trim().length;
      }
      const shown = (document.body.innerText ?? '').replace(/\s+/g, ' ').trim().length;
      return Math.max(0, all - shown);
    }).catch(() => 0);
    return { url: tab.page.url(), title: await tab.page.title().catch(() => ''), viewportHeight, hiddenTextChars };
  }

  async view(tab, { mode, ref = null, find = '', cursor = 0, maxChars = 6000 }) {
    const snapshot = await this.takeSnapshot(tab);
    const facts = await this.pageFacts(tab);
    let root = snapshot.tree;
    if (ref) {
      const node = findByRef(snapshot.tree, ref);
      if (!node) return outcome('STALE_REF');
      root = [node];
    }
    const options = { ...facts, find, cursor, maxChars };
    const result = mode === 'interactive' ? interactiveView(root, options) : mode === 'text' ? textView(ref ? root[0] : (findMain(root) ?? root), options) : outlineView(root, options);
    return outcome('OK', { tab: tab.id, url: facts.url, title: facts.title, ...result });
  }

  // ---- actions (each returns a page outcome)

  async navigateAction({ url, tab: tabId, newTab }) {
    let tab;
    if (newTab || this.tabs.size === 0) {
      tab = await this.openTab();
      if (!tab) return outcome('TAB_LIMIT', { message: `at most ${this.limits.maxTabs} tabs` });
    } else {
      tab = this.tab(tabId);
      if (!tab) return outcome('NO_SUCH_TAB');
      this.activeTab = tab.id;
    }
    return this.navigateTab(tab, url);
  }

  async navigateTab(tab, url) {
    if (this.navigationCount >= this.limits.maxNavigations) return outcome('LIMIT_REACHED', { message: `at most ${this.limits.maxNavigations} navigations per session` });
    const waitMs = this.lastNavigationAt + this.limits.navigationGapMs - Date.now();
    if (waitMs > 0) await sleep(waitMs);
    this.lastNavigationAt = Date.now();
    tab.nav = { blocked: false, unresolvable: false, hops: 0, tooManyRedirects: false };
    const submissionsBefore = this.submissionSeq;
    this.actionStartedAt = Date.now();
    const page = tab.page;
    let response;
    try {
      response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    } catch (error) {
      const reason = this.navigationStateReason(tab) ?? (this.submissionSeq > submissionsBefore ? 'CONSENT_REQUIRED' : reasonForNavigationError(String(error.message ?? error)));
      this.event('navigate', { tab: tab.id, url: clip(url), reason });
      return outcome(reason, { tab: tab.id, url, ...(reason === 'CONSENT_REQUIRED' ? { submissions: this.submissionsSince(submissionsBefore) } : {}) });
    }
    const stateReason = this.navigationStateReason(tab);
    if (stateReason) {
      this.event('navigate', { tab: tab.id, url: clip(url), reason: stateReason });
      return outcome(stateReason, { tab: tab.id, url });
    }
    await page.waitForLoadState('load', { timeout: 8000 }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {});
    const statusCode = response?.status() ?? null;
    const title = await page.title().catch(() => '');
    const html = await page.content().catch(() => '');
    const redirects = [];
    for (let request = response?.request().redirectedFrom(); request; request = request.redirectedFrom()) redirects.unshift(request.url());
    const challengeVendor = detectBotChallenge({ statusCode, headers: response?.headers() ?? {}, title, html });
    if (challengeVendor) {
      this.event('navigate', { tab: tab.id, url: clip(url), finalUrl: clip(page.url()), statusCode, reason: 'BOT_CHALLENGE', challengeVendor });
      return outcome('BOT_CHALLENGE', { tab: tab.id, url, finalUrl: page.url(), statusCode, title, challengeVendor });
    }
    const consent = await new ConsentHandler(page, this.options.cookieBanners, evaluateInMainWorld, true).settle(CONSENT_BUDGET_MS);
    if (consent.action !== 'OFF' && consent.action !== 'NONE') this.event('consent', { tab: tab.id, ...consent });
    this.event('navigate', { tab: tab.id, url: clip(url), finalUrl: clip(page.url()), statusCode, redirects: redirects.map(entry => clip(entry, 200)), reason: 'OK' });
    const snapshot = await this.view(tab, { mode: 'outline', maxChars: 4000 });
    return outcome('OK', { tab: tab.id, url, finalUrl: page.url(), statusCode, title, redirects, consent, snapshot: { mode: snapshot.mode, text: snapshot.text, nextCursor: snapshot.nextCursor } });
  }

  navigationStateReason(tab) {
    if (tab.nav.blocked) return 'BLOCKED';
    if (tab.nav.unresolvable) return 'UNRESOLVABLE';
    if (tab.nav.tooManyRedirects) return 'TOO_MANY_REDIRECTS';
    return null;
  }

  submissionsSince(seq) {
    return this.submissions.filter(entry => entry.seq > seq).map(({ signature, at, ...visible }) => visible);
  }

  async snapshotAction({ tab: tabId, mode, ref, find, cursor, maxChars }) {
    const tab = this.tab(tabId);
    if (!tab) return outcome('NO_SUCH_TAB');
    return this.view(tab, { mode, ref, find, cursor, maxChars });
  }

  async act(request) {
    const tab = this.tab(request.tab);
    if (!tab) return outcome('NO_SUCH_TAB');
    const page = tab.page;
    let locator = null;
    if (request.ref) {
      const resolved = await this.resolveRef(tab, request.ref);
      if (resolved.reason) return outcome(resolved.reason, { tab: tab.id, ref: request.ref });
      locator = resolved.locator;
    }
    if (request.releaseToken && !this.armRelease(request.releaseToken)) return outcome('RELEASE_INVALID', { message: 'the release token is unknown, used or expired' });
    const before = tab.snapshot?.lines ?? (await this.takeSnapshot(tab)).lines;
    const urlBefore = page.url();
    const navigationBefore = tab.navigationId;
    const submissionsBefore = this.submissionSeq;
    this.actionStartedAt = Date.now();
    const dialogsBefore = tab.dialogs.length;
    this.popupsDuringAction = [];
    this.releasedDuringAction = [];
    try {
      switch (request.action) {
        case 'click':
          this.event('click', { tab: tab.id, ref: request.ref });
          await locator.click({ timeout: ACTION_TIMEOUT_MS });
          break;
        case 'type': {
          const check = await locator.evaluate(fieldRefusal);
          if (check.reason || PAYMENT_HOSTS.test(check.host ?? '')) {
            const why = check.why ?? `payment provider frame ${check.host}`;
            this.event('typeRefused', { tab: tab.id, ref: request.ref, why });
            return outcome(check.reason ?? 'FIELD_REFUSED', { tab: tab.id, ref: request.ref, message: why });
          }
          this.typedTexts.add(request.text);
          this.event('typed', { tab: tab.id, ref: request.ref, field: clip(check.name, 80), text: request.text });
          await locator.fill(request.text, { timeout: ACTION_TIMEOUT_MS });
          if (request.submit) await locator.press('Enter', { timeout: ACTION_TIMEOUT_MS });
          break;
        }
        case 'select': {
          const offered = await locator.evaluate(element => (element.options ? Array.from(element.options).map(option => ({ value: option.value, label: option.label || option.text })) : null));
          if (!offered) return outcome('NOT_A_FIELD', { tab: tab.id, ref: request.ref, message: 'not a select element' });
          const unknown = request.values.filter(value => !offered.some(option => option.value === value || option.label.trim() === value.trim()));
          if (unknown.length) return outcome('INVALID_OPTION', { tab: tab.id, ref: request.ref, message: `not offered: ${unknown.join(', ')}`, options: offered.slice(0, 50).map(option => option.label) });
          const values = request.values.map(value => offered.find(option => option.value === value || option.label.trim() === value.trim()).value);
          this.event('select', { tab: tab.id, ref: request.ref, values });
          await locator.selectOption(values, { timeout: ACTION_TIMEOUT_MS });
          break;
        }
        case 'press': {
          const key = request.key === 'Space' ? ' ' : request.key;
          this.event('press', { tab: tab.id, ref: request.ref, key: request.key });
          if (locator) await locator.press(key, { timeout: ACTION_TIMEOUT_MS });
          else await page.keyboard.press(key);
          break;
        }
        case 'scroll':
          this.event('scroll', { tab: tab.id, ref: request.ref, direction: request.direction });
          if (locator) await locator.scrollIntoViewIfNeeded({ timeout: ACTION_TIMEOUT_MS });
          else await page.evaluate(direction => {
            if (direction === 'top') window.scrollTo(0, 0);
            else if (direction === 'bottom') window.scrollTo(0, document.documentElement.scrollHeight);
            else window.scrollBy(0, (direction === 'up' ? -0.85 : 0.85) * window.innerHeight);
          }, request.direction);
          break;
        default:
          return outcome('UNKNOWN_ACTION');
      }
    } catch (error) {
      const message = String(error.message ?? error).split('\n')[0];
      if (this.submissionSeq === submissionsBefore) {
        this.disarmReleases();
        return outcome(/Timeout/i.test(message) ? 'ACTION_TIMEOUT' : 'ACTION_FAILED', { tab: tab.id, ref: request.ref, message: clip(message, 200) });
      }
    }
    await this.settleAfterAction(tab, urlBefore, navigationBefore);
    this.disarmReleases();
    const navigated = tab.navigationId !== navigationBefore;
    const submissions = this.submissionsSince(submissionsBefore);
    const facts = await this.pageFacts(tab);
    let changed = '';
    if (request.action === 'scroll') {
      const snapshot = await this.takeSnapshot(tab);
      changed = interactiveView(snapshot.tree, { url: facts.url, viewportHeight: facts.viewportHeight, onlyInView: true, maxChars: 1500 }).text;
    } else {
      const snapshot = await this.takeSnapshot(tab).catch(() => null);
      changed = snapshot ? changedLines(navigated ? [] : before, snapshot.lines, 1500) : '';
    }
    const result = {
      tab: tab.id, url: facts.url, title: facts.title, navigated, changed,
      newTabs: this.popupsDuringAction, dialogs: tab.dialogs.slice(dialogsBefore), released: this.releasedDuringAction,
      ...(request.releaseToken ? { releaseUsed: this.releasedDuringAction.length > 0 } : {}),
    };
    this.popupsDuringAction = null;
    this.releasedDuringAction = null;
    if (submissions.length) return outcome('CONSENT_REQUIRED', { ...result, submissions });
    return outcome('OK', result);
  }

  async settleAfterAction(tab, urlBefore, navigationBefore) {
    await sleep(350);
    const page = tab.page;
    if (tab.navigationId !== navigationBefore || page.url() !== urlBefore) {
      await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});
      await page.waitForLoadState('load', { timeout: 5000 }).catch(() => {});
      await new ConsentHandler(page, this.options.cookieBanners, evaluateInMainWorld, true).settle(2500).catch(() => {});
    } else {
      await page.waitForLoadState('networkidle', { timeout: 1200 }).catch(() => {});
    }
  }

  async screenshotAction({ tab: tabId, target, ref, fromY, height, format, quality }) {
    const tab = this.tab(tabId);
    if (!tab) return outcome('NO_SUCH_TAB');
    const page = tab.page;
    const options = { type: format, animations: 'disabled', caret: 'hide', timeout: 30_000 };
    if (format === 'jpeg') options.quality = quality;
    const viewport = page.viewportSize();
    let image;
    let width = viewport.width;
    let capturedHeight = viewport.height;
    let truncated = false;
    let pageHeight = null;
    if (target === 'element') {
      const resolved = await this.resolveRef(tab, ref);
      if (resolved.reason) return outcome(resolved.reason, { tab: tab.id, ref });
      const box = await resolved.locator.boundingBox().catch(() => null);
      image = await resolved.locator.screenshot(options);
      width = Math.round(box?.width ?? 0);
      capturedHeight = Math.round(box?.height ?? 0);
    } else if (target === 'fullPage') {
      const scrollY = await page.evaluate(() => window.scrollY).catch(() => 0);
      await loadLazyContent(page, Math.min(20_000, fromY + height));
      pageHeight = await page.evaluate(() => Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0)).catch(() => viewport.height);
      if (fromY >= pageHeight) return outcome('OUT_OF_RANGE', { tab: tab.id, pageHeight });
      capturedHeight = Math.min(height, pageHeight - fromY);
      truncated = fromY + capturedHeight < pageHeight;
      image = await page.screenshot({ ...options, fullPage: true, clip: { x: 0, y: fromY, width: viewport.width, height: capturedHeight } });
      await page.evaluate(y => window.scrollTo(0, y), scrollY).catch(() => {});
    } else {
      image = await page.screenshot(options);
    }
    if (!image || image.length === 0) return outcome('NO_CONTENT', { tab: tab.id });
    if (image.length > MAX_IMAGE_BYTES) return outcome('TOO_LARGE', { tab: tab.id });
    return outcome('OK', { tab: tab.id, url: page.url(), content: image.toString('base64'), format, width, height: capturedHeight, fromY: target === 'fullPage' ? fromY : 0, truncated, pageHeight });
  }

  async tabsAction({ action, tab: tabId, url }) {
    if (action === 'open') {
      const tab = await this.openTab();
      if (!tab) return outcome('TAB_LIMIT', { message: `at most ${this.limits.maxTabs} tabs`, tabs: await this.tabList() });
      this.event('tabOpened', { tab: tab.id, byPage: false });
      if (url) {
        const navigation = await this.navigateTab(tab, url);
        return { ...navigation, tabs: await this.tabList() };
      }
    } else if (action === 'select') {
      const tab = this.tab(tabId);
      if (!tab) return outcome('NO_SUCH_TAB', { tabs: await this.tabList() });
      this.activeTab = tab.id;
      await tab.page.bringToFront().catch(() => {});
    } else if (action === 'close') {
      const tab = this.tab(tabId);
      if (!tab) return outcome('NO_SUCH_TAB', { tabs: await this.tabList() });
      this.event('tabClosed', { tab: tab.id });
      await tab.page.close().catch(() => {});
      this.tabs.delete(tab.id);
      if (this.activeTab === tab.id) this.activeTab = this.tabs.keys().next().value ?? null;
    }
    return outcome('OK', { tabs: await this.tabList() });
  }

  async tabList() {
    const list = [];
    for (const tab of this.tabs.values()) list.push({ tab: tab.id, url: tab.page.url(), title: clip(await tab.page.title().catch(() => ''), 120), active: tab.id === this.activeTab });
    return list;
  }

  async analyseAction({ tab: tabId, name, ref, properties, kinds }) {
    const tab = this.tab(tabId);
    if (!tab) return outcome('NO_SUCH_TAB');
    if (tab.page.url() === 'about:blank') return outcome('NO_PAGE', { message: 'navigate first' });
    let locator = null;
    if (ref) {
      const resolved = await this.resolveRef(tab, ref);
      if (resolved.reason) return outcome(resolved.reason, { tab: tab.id, ref });
      locator = resolved.locator;
    }
    this.event('analyse', { tab: tab.id, name, ref });
    if (name === 'design') {
      const scopePath = locator ? await locator.evaluate(element => {
        const parts = [];
        for (let current = element; current && current !== document.documentElement; current = current.parentElement) {
          const parent = current.parentElement;
          if (!parent) break;
          parts.unshift(`${current.localName}:nth-child(${Array.prototype.indexOf.call(parent.children, current) + 1})`);
        }
        return ['html', ...parts].join(' > ');
      }) : null;
      const result = await extractDesign({ page: tab.page, cdp: tab.cdp, mainFrameId: tab.mainFrameId, scopePath });
      if (result.reason !== 'OK') return outcome(result.reason, { tab: tab.id });
      return outcome('OK', { tab: tab.id, url: tab.page.url(), tokens: result.tokens, summary: result.summary });
    }
    if (name === 'inspect') {
      const result = await inspectElement({ page: tab.page, cdp: tab.cdp, locator, properties });
      return { tab: tab.id, url: tab.page.url(), ...result };
    }
    const userAgent = await tab.page.evaluate(() => navigator.userAgent).catch(() => '');
    const result = await exportAssets({
      page: tab.page, cdp: tab.cdp, mainFrameId: tab.mainFrameId, kinds, userAgent,
      responseBody: async url => {
        const entry = tab.responses.get(url);
        if (!entry) return null;
        const body = await tab.cdp.send('Network.getResponseBody', { requestId: entry.requestId }).catch(() => null);
        if (!body) return null;
        return { body: Buffer.from(body.body, body.base64Encoded ? 'base64' : 'utf8'), mimeType: entry.mimeType };
      },
    });
    this.event('assetsExported', { tab: tab.id, files: result.files.map(file => ({ kind: file.kind, name: file.name, size: file.size, sourceUrl: clip(file.sourceUrl, 200) })) });
    return { tab: tab.id, url: tab.page.url(), ...result };
  }

  logAction({ since }) {
    const events = this.events.filter(entry => entry.seq > since);
    return outcome('OK', { events, nextSeq: this.eventSeq, open: !this.closed, navigations: this.navigationCount, transferBytes: this.transferBytes, createdAt: new Date(this.createdAt).toISOString() });
  }

  limitsView() {
    return {
      maxTabs: this.limits.maxTabs, idleSeconds: this.limits.idleMs / 1000, idleWithConsentSeconds: this.limits.idleWithConsentMs / 1000,
      maxSeconds: this.limits.maxAgeMs / 1000, maxNavigations: this.limits.maxNavigations, maxTransferBytes: this.limits.maxTransferBytes,
      maxTypedChars: 200,
    };
  }
}

const findMain = tree => {
  let main = null;
  const visit = node => {
    if (main || !node || typeof node !== 'object') return;
    if (node.role === 'main') { main = node; return; }
    (node.children ?? []).forEach(visit);
  };
  tree.forEach(visit);
  return main;
};

/** Scrolls down once so lazy images load, then back. */
const loadLazyContent = async (page, limit) => {
  await page.evaluate(async maximum => {
    const step = Math.max(200, Math.floor(window.innerHeight * 0.9));
    const bottom = Math.min(maximum, Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0));
    for (let offset = 0; offset < bottom; offset += step) {
      window.scrollTo(0, offset);
      await new Promise(resolve => setTimeout(resolve, 120));
    }
    window.scrollTo(0, 0);
    await new Promise(resolve => setTimeout(resolve, 200));
  }, limit).catch(() => {});
};
