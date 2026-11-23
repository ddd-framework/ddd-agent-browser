// Renders one public web page to an image (the stateless screenshot job).
// Primary engine: real Google Chrome (channel "chrome"), headed in Xvfb, driven by Patchright (the Playwright API
// without its CDP leaks). Fallback: Camoufox (Firefox with a consistent fingerprint), tried ONCE after a bot challenge.
// One browser per engine and process, a fresh context per render (no cookies or storage between renders).
import { launchChrome, LOCALE, TIMEZONE } from './chrome.mjs';
import { AddressGuard } from './guard.mjs';
import { detectBotChallenge } from './challenge.mjs';
import { ConsentHandler } from './consent.mjs';

const MAX_IMAGE_BYTES = 15_000_000;
const MAX_TRANSFER_BYTES = 20_000_000;
const CONSENT_BUDGET_MS = 4000;
const NETWORK_IDLE_MS = 500;

const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

/** A page outcome without an image. */
const failure = (reason, fields = {}) => ({ reason, ...fields });

/**
 * @param {string} message A Playwright navigation error message
 * @returns {string} The page reason
 */
export const reasonForNavigationError = message => {
  if (/ERR_NAME_NOT_RESOLVED|NS_ERROR_UNKNOWN_HOST/i.test(message)) return 'UNRESOLVABLE';
  if (/ERR_TOO_MANY_REDIRECTS|NS_ERROR_REDIRECT_LOOP/i.test(message)) return 'TOO_MANY_REDIRECTS';
  if (/Download is starting|ERR_ABORTED.*download|NS_BINDING_ABORTED/i.test(message)) return 'UNSUPPORTED_TYPE';
  if (/Timeout .*exceeded|TimeoutError/i.test(message)) return 'TIMEOUT';
  return 'FETCH_FAILED';
};

/**
 * @param {string} contentType The Content-Type of the main document
 * @returns {boolean} Whether it is a page we render (HTML, XHTML, plain text)
 */
export const isRenderableContentType = contentType => {
  if (!contentType) return true;
  return /^(text\/html|application\/xhtml\+xml|text\/plain)/i.test(contentType.trim());
};

/**
 * @param {string} chromeVersion e.g. "158.0.8127.36"
 * @returns {{userAgent: string, userAgentMetadata: object}} Chrome's own Android user agent with matching client hints
 */
export const buildMobileUserAgent = chromeVersion => {
  const majorVersion = chromeVersion.split('.')[0];
  return {
    userAgent: `Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${majorVersion}.0.0.0 Mobile Safari/537.36`,
    userAgentMetadata: {
      brands: [
        { brand: 'Google Chrome', version: majorVersion },
        { brand: 'Chromium', version: majorVersion },
        { brand: 'Not_A Brand', version: '24' },
      ],
      fullVersionList: [
        { brand: 'Google Chrome', version: chromeVersion },
        { brand: 'Chromium', version: chromeVersion },
        { brand: 'Not_A Brand', version: '24.0.0.0' },
      ],
      fullVersion: chromeVersion,
      platform: 'Android',
      platformVersion: '10.0.0',
      architecture: '',
      model: 'K',
      mobile: true,
      bitness: '',
      wow64: false,
    },
  };
};

export class PageRenderer {
  /**
   * @param {{log: Function, noSandbox: boolean, camoufoxEnabled: boolean}} options
   */
  constructor({ log, noSandbox, camoufoxEnabled }) {
    this.log = log;
    this.noSandbox = noSandbox;
    this.camoufoxEnabled = camoufoxEnabled;
    this.chrome = null;
    this.camoufox = null;
    this.camoufoxStarting = null;
  }

  /**
   * @param {import('playwright-core').Browser|null} chrome A running Chrome to share (session role), or null to launch one
   */
  async start(chrome = null) {
    this.chrome = chrome ?? await launchChrome({ noSandbox: this.noSandbox });
    return this.chrome;
  }

  isReady() {
    return this.chrome?.isConnected() === true;
  }

  async getCamoufox() {
    if (!this.camoufoxEnabled) return null;
    if (this.camoufox?.isConnected()) return this.camoufox;
    this.camoufoxStarting ??= (async () => {
      const { Camoufox } = await import('camoufox-js');
      this.camoufox = await Camoufox({ headless: true, locale: LOCALE, block_webrtc: true, main_world_eval: true, os: ['windows', 'macos'] });
      this.log('info', 'camoufox ready', { version: this.camoufox.version() });
      return this.camoufox;
    })().finally(() => {
      this.camoufoxStarting = null;
    });
    return this.camoufoxStarting;
  }

  async close() {
    await this.chrome?.close().catch(() => {});
    await this.camoufox?.close().catch(() => {});
  }

  /**
   * @param {object} job From validateScreenshotJob()
   * @param {AbortSignal} signal Aborted at the request deadline
   * @returns {Promise<object>} The page outcome (reason OK with content, or a failure reason)
   */
  async render(job, signal) {
    if (job.engine === 'camoufox') {
      const camoufox = await this.getCamoufox();
      if (!camoufox) throw Object.assign(new Error('camoufox is disabled'), { statusCode: 400 });
      return this.renderWithEngine('camoufox', camoufox, job, signal);
    }
    const chromeOutcome = await this.renderWithEngine('chrome', this.chrome, job, signal);
    if (job.engine === 'chrome' || chromeOutcome.reason !== 'BOT_CHALLENGE' || job.device === 'mobile' || signal.aborted) return chromeOutcome;
    const camoufox = await this.getCamoufox().catch(error => {
      this.log('error', 'camoufox did not start', { error: String(error.message ?? error) });
      return null;
    });
    if (!camoufox || signal.aborted) return chromeOutcome;
    const camoufoxOutcome = await this.renderWithEngine('camoufox', camoufox, job, signal);
    return camoufoxOutcome.reason === 'OK' ? camoufoxOutcome : { ...chromeOutcome, fallbackReason: camoufoxOutcome.reason };
  }

  /**
   * @param {'chrome'|'camoufox'} engine
   * @param {import('playwright-core').Browser} browser
   * @param {object} job
   * @param {AbortSignal} signal
   */
  async renderWithEngine(engine, browser, job, signal) {
    const isMobile = job.device === 'mobile' && engine === 'chrome';
    const contextOptions = {
      viewport: { width: job.viewportWidth, height: job.viewportHeight },
      deviceScaleFactor: job.density,
      locale: LOCALE,
      timezoneId: TIMEZONE,
      ignoreHTTPSErrors: true,
      javaScriptEnabled: job.javascriptEnabled,
      acceptDownloads: false,
      serviceWorkers: 'block',
      permissions: [],
    };
    if (isMobile) Object.assign(contextOptions, { isMobile: true, hasTouch: true });
    const context = await browser.newContext(contextOptions);
    const state = { blocked: false, unresolvable: false, redirectBlocked: false, tooManyRedirects: false, tooLarge: false, mainDocumentRequests: 0, transferBytes: 0, inflight: 0, lastNetworkActivity: Date.now() };
    const abortRender = () => context.close().catch(() => {});
    signal.addEventListener('abort', abortRender, { once: true });
    try {
      const page = await context.newPage();
      const guard = new AddressGuard();
      if (engine === 'chrome') {
        await this.installChromeGuards(context, page, job, guard, state, isMobile);
      } else {
        await this.installRouteGuard(context, page, job, guard, state);
      }
      page.on('request', () => { state.inflight++; state.lastNetworkActivity = Date.now(); });
      const settleRequest = () => { state.inflight = Math.max(0, state.inflight - 1); state.lastNetworkActivity = Date.now(); };
      page.on('requestfinished', settleRequest);
      page.on('requestfailed', settleRequest);

      // Chrome (Patchright): init scripts run in the page's main world, evaluate defaults to an isolated world, so the
      // 4th argument (isolatedContext=false) puts the evaluation next to the consent scripts. Camoufox: init scripts
      // and evaluate live in Firefox sandboxes whose objects autoconsent may not write into (XrayWrapper), so the
      // scripts are evaluated into each frame's main world with `mw:` (launch option main_world_eval) instead.
      const evaluateInMainWorld = engine === 'chrome'
        ? (target, code, argument) => target.evaluate(code, argument, undefined, false)
        : (target, code, argument) => target.evaluate(typeof code === 'function'
          ? `mw:(${code.toString()})(${JSON.stringify(argument ?? null)})`
          : `mw:${code}`);
      const consentHandler = new ConsentHandler(page, job.cookieBanners, evaluateInMainWorld, engine !== 'chrome');
      await consentHandler.install();

      const domTimeoutMs = job.domTimeoutSeconds * 1000;
      const navigationStartedAt = Date.now();
      let response;
      try {
        response = await page.goto(job.url, { waitUntil: 'domcontentloaded', timeout: domTimeoutMs });
      } catch (error) {
        return failure(this.reasonForState(state) ?? reasonForNavigationError(String(error.message ?? error)), { engine });
      }
      const stateReason = this.reasonForState(state);
      if (stateReason) return failure(stateReason, { engine });
      const statusCode = response?.status() ?? null;
      const headers = response?.headers() ?? {};
      if (!isRenderableContentType(headers['content-type'])) return failure('UNSUPPORTED_TYPE', { engine, statusCode, finalUrl: page.url() });

      await this.waitForCondition(page, job, state, Math.max(1000, domTimeoutMs - (Date.now() - navigationStartedAt)));
      const title = await page.title().catch(() => '');
      const html = await page.content().catch(() => '');
      const challengeVendor = detectBotChallenge({ statusCode, headers, title, html });
      if (challengeVendor) return failure('BOT_CHALLENGE', { engine, statusCode, finalUrl: page.url(), title, challengeVendor });

      if (job.waitForSelector) {
        try {
          await page.waitForSelector(job.waitForSelector, { state: 'visible', timeout: domTimeoutMs });
        } catch {
          return failure('SELECTOR_NOT_FOUND', { engine, statusCode, finalUrl: page.url(), title });
        }
      }
      const consent = await consentHandler.settle(CONSENT_BUDGET_MS);
      await sleep(job.minWaitSeconds * 1000);
      if (job.fullPage) await this.loadLazyContent(page, job);
      const lateReason = this.reasonForState(state);
      if (lateReason) return failure(lateReason, { engine, statusCode, finalUrl: page.url(), title });

      const pageHeight = job.fullPage
        ? await page.evaluate(() => Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0)).catch(() => job.viewportHeight)
        : job.viewportHeight;
      const capturedHeight = Math.max(job.viewportHeight, Math.min(pageHeight, job.maxPageHeight));
      const screenshotOptions = {
        type: job.format,
        omitBackground: job.omitBackground,
        animations: 'disabled',
        caret: 'hide',
        timeout: 30_000,
      };
      if (job.format === 'jpeg') screenshotOptions.quality = job.quality;
      if (job.fullPage) {
        screenshotOptions.fullPage = true;
        screenshotOptions.clip = { x: 0, y: 0, width: job.viewportWidth, height: capturedHeight };
      }
      const image = await page.screenshot(screenshotOptions);
      if (!image || image.length === 0) return failure('NO_CONTENT', { engine, statusCode, finalUrl: page.url(), title });
      if (image.length > MAX_IMAGE_BYTES) return failure('TOO_LARGE', { engine, statusCode, finalUrl: page.url(), title });
      return {
        reason: 'OK',
        content: image.toString('base64'),
        format: job.format,
        finalUrl: page.url(),
        title,
        statusCode,
        width: job.viewportWidth * job.density,
        height: capturedHeight * job.density,
        truncated: job.fullPage && pageHeight > job.maxPageHeight,
        engine,
        consent,
      };
    } catch (error) {
      if (signal.aborted) return failure('TIMEOUT', { engine });
      throw error;
    } finally {
      signal.removeEventListener('abort', abortRender);
      await context.close().catch(() => {});
    }
  }

  reasonForState(state) {
    if (state.blocked) return 'BLOCKED';
    if (state.unresolvable) return 'UNRESOLVABLE';
    if (state.redirectBlocked) return 'REDIRECT_BLOCKED';
    if (state.tooManyRedirects) return 'TOO_MANY_REDIRECTS';
    if (state.tooLarge) return 'TOO_LARGE';
    return null;
  }

  /**
   * Chrome: CDP Fetch interception sees EVERY request including each redirect hop (a Playwright route only sees the
   * first URL of a redirect chain), so a hop into a private address is failed before it is sent.
   */
  async installChromeGuards(context, page, job, guard, state, isMobile) {
    const cdpSession = await context.newCDPSession(page);
    if (isMobile) {
      await cdpSession.send('Network.setUserAgentOverride', buildMobileUserAgent(this.chrome.version()));
    }
    await cdpSession.send('Network.enable');
    cdpSession.on('Network.dataReceived', event => {
      state.transferBytes += event.encodedDataLength ?? 0;
      if (state.transferBytes > MAX_TRANSFER_BYTES && !state.tooLarge) {
        state.tooLarge = true;
        page.evaluate(() => window.stop()).catch(() => {});
      }
    });
    const mainFrameId = (await cdpSession.send('Page.getFrameTree')).frameTree.frame.id;
    const originalUrl = new URL(job.url).href;
    await cdpSession.send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
    cdpSession.on('Fetch.requestPaused', event => {
      void (async () => {
        const verdict = await guard.classifyUrl(event.request.url);
        const isMainDocument = event.resourceType === 'Document' && event.frameId === mainFrameId;
        let refusal = null;
        if (verdict === 'BLOCKED' || verdict === 'UNRESOLVABLE') {
          refusal = 'BlockedByClient';
          if (isMainDocument) {
            if (verdict === 'BLOCKED') state.blocked = true;
            else state.unresolvable = true;
          }
        } else if (isMainDocument) {
          state.mainDocumentRequests++;
          if (!job.redirectEnabled && state.mainDocumentRequests > 1 && new URL(event.request.url).href !== originalUrl) {
            state.redirectBlocked = true;
            refusal = 'BlockedByClient';
          } else if (state.mainDocumentRequests > job.maxRedirectHops + 1) {
            state.tooManyRedirects = true;
            refusal = 'BlockedByClient';
          }
        }
        if (refusal) {
          await cdpSession.send('Fetch.failRequest', { requestId: event.requestId, errorReason: refusal }).catch(() => {});
          return;
        }
        await cdpSession.send('Fetch.continueRequest', { requestId: event.requestId }).catch(() => {});
      })();
    });
  }

  /**
   * Camoufox (Firefox, no CDP): a route sees the first URL of each request; redirect hops are checked afterwards on
   * the request events, and the NetworkPolicy blocks private targets at the network level.
   */
  async installRouteGuard(context, page, job, guard, state) {
    await context.route('**/*', async route => {
      const verdict = await guard.classifyUrl(route.request().url());
      if (verdict === 'BLOCKED' || verdict === 'UNRESOLVABLE') {
        if (route.request().isNavigationRequest() && route.request().frame() === page.mainFrame()) {
          if (verdict === 'BLOCKED') state.blocked = true;
          else state.unresolvable = true;
        }
        return route.abort('blockedbyclient');
      }
      return route.continue();
    });
    page.on('request', request => {
      if (!request.isNavigationRequest() || request.frame() !== page.mainFrame()) return;
      state.mainDocumentRequests++;
      if (state.mainDocumentRequests > job.maxRedirectHops + 1) state.tooManyRedirects = true;
      if (!job.redirectEnabled && state.mainDocumentRequests > 1) state.redirectBlocked = true;
      void guard.classifyUrl(request.url()).then(verdict => {
        if (verdict === 'BLOCKED') state.blocked = true;
      });
    });
  }

  /**
   * The wait condition after DOMContentLoaded. A page that never becomes idle (ads, trackers) is still captured when
   * the time is up: the condition only decides how long to wait, as at rc-headless.
   */
  async waitForCondition(page, job, state, remainingMs) {
    if (job.waitUntil === 'domcontentloaded') return;
    if (job.waitUntil === 'load') {
      await page.waitForLoadState('load', { timeout: remainingMs }).catch(() => {});
      return;
    }
    await page.waitForLoadState('load', { timeout: remainingMs }).catch(() => {});
    const allowedInflight = job.waitUntil === 'networkidle0' ? 0 : 2;
    const deadline = Date.now() + remainingMs;
    while (Date.now() < deadline) {
      if (state.inflight <= allowedInflight && Date.now() - state.lastNetworkActivity >= NETWORK_IDLE_MS) return;
      await sleep(100);
    }
  }

  /** Scrolls through the page once so lazy images load, then back to the top. */
  async loadLazyContent(page, job) {
    await page.evaluate(async ({ maxPageHeight }) => {
      const step = Math.max(200, Math.floor(window.innerHeight * 0.9));
      const limit = Math.min(maxPageHeight, Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0));
      for (let offset = 0; offset < limit; offset += step) {
        window.scrollTo(0, offset);
        await new Promise(resolve => setTimeout(resolve, 120));
      }
      window.scrollTo(0, 0);
      await new Promise(resolve => setTimeout(resolve, 200));
    }, { maxPageHeight: job.maxPageHeight }).catch(() => {});
  }
}
