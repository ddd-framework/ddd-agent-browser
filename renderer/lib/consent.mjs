// Consent banners: removed by default, never "accept all".
//   reject: @duckduckgo/autoconsent opts out through the consent platform (rules + heuristics), then the EasyList
//           Cookie cosmetic CSS hides what is left and the scroll lock is released
//   hide:   only the cosmetic CSS and the scroll lock
//   off:    the page as it comes
// autoconsent talks to its "background" through window.autoconsentSendMessage. We give it an outbox in the page
// (no exposed binding: a binding is one more automation trace) and empty it from Node by polling every frame.
import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { FiltersEngine } from '@ghostery/adblocker';
import { getDomain } from 'tldts-experimental';
import { OWN_CONSENT_RULES } from './consent-rules.mjs';

const require = createRequire(import.meta.url);
// the package exports only its entry points and the rules: its root is two levels above rules/rules.json
const autoconsentDirectory = path.resolve(path.dirname(require.resolve('@duckduckgo/autoconsent/rules/rules.json')), '..');
const AUTOCONSENT_SCRIPT = fs.readFileSync(path.join(autoconsentDirectory, 'dist/autoconsent.playwright.js'), 'utf8');
const UPSTREAM_RULES = JSON.parse(fs.readFileSync(path.join(autoconsentDirectory, 'rules/rules.json'), 'utf8'));
/** The upstream rules plus our own (lib/consent-rules.mjs); ours are site-specific and so tried first. */
export const AUTOCONSENT_RULES = { ...UPSTREAM_RULES, autoconsent: [...OWN_CONSENT_RULES, ...UPSTREAM_RULES.autoconsent] };

/** The cookie filter list, baked into the image at build time (Dockerfile); no download at runtime. */
const COOKIE_LIST_PATH = process.env.RENDER_COOKIE_LIST ?? path.join(path.dirname(new URL(import.meta.url).pathname), '../lists/fanboy-cookiemonster.txt');

/** First line of the style tag the cosmetic fallback adds, so analyses can tell it from the site's own CSS. */
export const CONSENT_STYLE_MARKER = '/* renderer: consent fallback */';

/** Releases the scroll lock consent layers put on the document. */
const SCROLL_UNLOCK_CSS = 'html, body { overflow: visible !important; overflow-y: auto !important; }';

const OUTBOX_SHIM = `(() => {
  if (window.autoconsentSendMessage) return;
  const outbox = [];
  Object.defineProperty(window, '__rbConsentOutbox', { value: outbox, enumerable: false });
  window.autoconsentSendMessage = message => { outbox.push(message); return Promise.resolve(); };
})();`;

let cookieFiltersEngine = null;

/**
 * @returns {FiltersEngine|null} The cosmetic filters of the cookie list (parsed once per process), null without the list
 */
export const getCookieFiltersEngine = () => {
  if (cookieFiltersEngine === null && fs.existsSync(COOKIE_LIST_PATH)) {
    cookieFiltersEngine = FiltersEngine.parse(fs.readFileSync(COOKIE_LIST_PATH, 'utf8'), {
      loadNetworkFilters: false,
      loadCosmeticFilters: true,
      loadGenericCosmeticsFilters: true,
    });
  }
  return cookieFiltersEngine;
};

/**
 * @param {'reject'|'hide'} mode
 * @returns {object} The autoconsent config (only `reject` clicks, and only to opt out)
 */
const buildAutoconsentConfig = mode => ({
  enabled: true,
  autoAction: mode === 'reject' ? 'optOut' : null,
  disabledCmps: [],
  enablePrehide: true,
  enableCosmeticRules: true,
  enableGeneratedRules: true,
  enableHeuristicDetection: true,
  heuristicMode: 'tier2',
  detectRetries: 20,
  isMainWorld: true,
  prehideTimeout: 2000,
  logs: { lifecycle: false, rulesteps: false, detectionsteps: false, evals: false, errors: false, messages: false, waits: false },
});

/**
 * Consent handling of one page. `install()` before the navigation, `settle()` after it.
 */
export class ConsentHandler {
  /**
   * @param {import('playwright-core').Page} page
   * @param {'reject'|'hide'|'off'} mode
   * @param {(target: any, code: string|Function, argument?: any) => Promise<any>} evaluateInMainWorld engine-specific evaluation in the world the consent scripts live in
   * @param {boolean} injectByEvaluate true: the scripts are evaluated into each frame after the navigation instead of an
   *   init script. Camoufox (Firefox) runs init scripts in a sandbox: autoconsent there cannot write into the objects
   *   it gets ("Not allowed to define cross-origin object as property … XrayWrapper"), so it runs in the main world
   */
  constructor(page, mode, evaluateInMainWorld, injectByEvaluate = false) {
    this.page = page;
    this.mode = mode;
    this.evaluateInMainWorld = evaluateInMainWorld;
    this.injectByEvaluate = injectByEvaluate;
    this.injectedFrames = new WeakSet();
    this.cmp = null;
    this.popupFound = false;
    this.optOutSucceeded = false;
    this.done = false;
    this.cosmeticApplied = false;
    // some opt-outs reload the page (tk.de): the old document never reports its result. A new main document after a
    // found popup that shows no popup again counts as the opt-out having worked (we only ever click opt-out).
    this.reloadedAfterPopupAt = null;
    this.popupInCurrentDocument = false;
  }

  async install() {
    if (this.mode !== 'reject' || this.injectByEvaluate) return;
    await this.page.addInitScript(OUTBOX_SHIM + '\n' + AUTOCONSENT_SCRIPT);
  }

  /**
   * Runs the opt-out (reject) for up to `budgetMs`, then applies the cosmetic fallback.
   *
   * @param {number} budgetMs
   * @returns {Promise<{cmp: string|null, action: 'REJECTED'|'HIDDEN'|'NONE'|'OFF'}>}
   */
  async settle(budgetMs) {
    if (this.mode === 'off') return { cmp: null, action: 'OFF' };
    const urlBefore = this.page.url();
    if (this.mode === 'reject') await this.pumpAutoconsent(budgetMs);
    // an opt-out may navigate (a consent page that redirects to the target) or reload: the screenshot belongs to the
    // new document
    if (this.page.url() !== urlBefore || this.reloadedAfterPopupAt !== null) {
      await this.page.waitForLoadState('load', { timeout: 10_000 }).catch(() => {});
    }
    await this.applyCosmeticFallback();
    if (this.optOutSucceeded || (this.reloadedAfterPopupAt !== null && !this.popupInCurrentDocument)) return { cmp: this.cmp, action: 'REJECTED' };
    if (this.mode === 'hide' || this.popupFound) return { cmp: this.cmp, action: 'HIDDEN' };
    return { cmp: this.cmp, action: 'NONE' };
  }

  async pumpAutoconsent(budgetMs) {
    const startedAt = Date.now();
    // no consent platform within 2.5 s of the page being ready: there is none (autoconsent reports early)
    while (!this.done && Date.now() - startedAt < budgetMs) {
      for (const frame of this.page.frames()) {
        if (this.injectByEvaluate && !this.injectedFrames.has(frame)) {
          this.injectedFrames.add(frame);
          await this.evaluateInMainWorld(frame, `if (!window.autoconsentSendMessage) { ${OUTBOX_SHIM}\n${AUTOCONSENT_SCRIPT}\n }`).catch(() => {});
        }
        const messages = await this.evaluateInMainWorld(frame, '(() => { const outbox = window.__rbConsentOutbox; return outbox ? outbox.splice(0) : []; })()').catch(() => []);
        for (const message of messages ?? []) await this.handleMessage(frame, message);
      }
      if (!this.cmp && !this.popupFound && Date.now() - startedAt > 2500) break;
      if (this.reloadedAfterPopupAt !== null && !this.popupInCurrentDocument && Date.now() - this.reloadedAfterPopupAt > 2500) break;
      await new Promise(resolve => setTimeout(resolve, 150));
    }
  }

  async reply(frame, message) {
    // handed over as JSON text and parsed in the page: an object argument would belong to the automation's
    // compartment in Firefox, and autoconsent may not write into it there
    await this.evaluateInMainWorld(frame, `window.autoconsentReceiveMessage?.(JSON.parse(${JSON.stringify(JSON.stringify(message))}))`).catch(() => {});
  }

  async handleMessage(frame, message) {
    if (!message || typeof message !== 'object') return;
    switch (message.type) {
      case 'init':
        if (frame === this.page.mainFrame()) {
          if (this.popupFound && !this.optOutSucceeded) this.reloadedAfterPopupAt = Date.now();
          this.popupInCurrentDocument = false;
        }
        await this.reply(frame, { type: 'initResp', config: buildAutoconsentConfig(this.mode), rules: AUTOCONSENT_RULES });
        break;
      case 'eval': {
        const result = await this.evaluateInMainWorld(frame, message.code).catch(() => false);
        await this.reply(frame, { type: 'evalResp', id: message.id, result });
        break;
      }
      case 'cmpDetected':
        this.cmp ??= message.cmp ?? null;
        break;
      case 'popupFound':
        this.popupFound = true;
        this.popupInCurrentDocument = true;
        this.cmp = message.cmp ?? this.cmp;
        break;
      case 'optOutResult':
        if (message.result) this.optOutSucceeded = true;
        this.cmp = message.cmp ?? this.cmp;
        break;
      case 'autoconsentDone':
        this.done = true;
        this.cmp = message.cmp ?? this.cmp;
        break;
      case 'autoconsentError':
        this.done = true;
        break;
      default:
        break;
    }
  }

  async applyCosmeticFallback() {
    const pageUrl = this.page.url();
    let hostname = '';
    try {
      hostname = new URL(pageUrl).hostname;
    } catch {
      return;
    }
    const filtersEngine = getCookieFiltersEngine();
    let styles = '';
    if (filtersEngine) {
      ({ styles } = filtersEngine.getCosmeticsFilters({
        url: pageUrl,
        hostname,
        domain: getDomain(hostname) ?? hostname,
        getBaseRules: true,
        getInjectionRules: false,
        getExtendedRules: false,
        getRulesFromHostname: true,
        getRulesFromDOM: false,
      }));
    }
    await this.page.addStyleTag({ content: `${CONSENT_STYLE_MARKER}\n${styles}\n${SCROLL_UNLOCK_CSS}` }).catch(() => {});
    this.cosmeticApplied = true;
  }
}
