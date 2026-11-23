// CSS and DOM access over the page's CDP session: stylesheets with their text, elements found again by their CSS
// path, the font Chrome really rendered, computed styles under a forced :hover, and the matched rules of an element.
// Read-only: forcing :hover is undone right after reading.

const MAX_SHEET_CHARS = 2_000_000;

export class CssInspector {
  /**
   * @param {import('playwright-core').CDPSession} cdpSession The page's CDP session
   */
  constructor(cdpSession) {
    this.cdp = cdpSession;
    this.sheets = new Map();
    this.enabled = false;
    this.rootNodeId = null;
    this.onSheetAdded = event => this.sheets.set(event.header.styleSheetId, event.header);
    this.onSheetRemoved = event => this.sheets.delete(event.styleSheetId);
  }

  async enable() {
    if (this.enabled) return;
    this.cdp.on('CSS.styleSheetAdded', this.onSheetAdded);
    this.cdp.on('CSS.styleSheetRemoved', this.onSheetRemoved);
    await this.cdp.send('DOM.enable');
    // CSS.enable reports every existing stylesheet as styleSheetAdded before it answers
    await this.cdp.send('CSS.enable');
    this.enabled = true;
  }

  async disable() {
    if (!this.enabled) return;
    this.cdp.off('CSS.styleSheetAdded', this.onSheetAdded);
    this.cdp.off('CSS.styleSheetRemoved', this.onSheetRemoved);
    await this.cdp.send('CSS.disable').catch(() => {});
    await this.cdp.send('DOM.disable').catch(() => {});
    this.sheets.clear();
    this.enabled = false;
    this.rootNodeId = null;
  }

  /**
   * @param {string} mainFrameId Only the main frame's sheets
   * @returns {object[]} The stylesheet headers of the page (author origin)
   */
  pageSheets(mainFrameId) {
    return [...this.sheets.values()].filter(header => header.origin === 'regular' && (!mainFrameId || header.frameId === mainFrameId));
  }

  /**
   * @param {string} styleSheetId
   * @returns {Promise<string>}
   */
  async sheetText(styleSheetId) {
    const { text } = await this.cdp.send('CSS.getStyleSheetText', { styleSheetId }).catch(() => ({ text: '' }));
    return (text ?? '').slice(0, MAX_SHEET_CHARS);
  }

  /**
   * @param {string} cssPath From the page script (html > body > …)
   * @returns {Promise<number|null>} The DOM nodeId
   */
  async nodeIdForPath(cssPath) {
    if (!cssPath) return null;
    if (this.rootNodeId === null) this.rootNodeId = (await this.cdp.send('DOM.getDocument', { depth: 0 })).root.nodeId;
    const { nodeId } = await this.cdp.send('DOM.querySelector', { nodeId: this.rootNodeId, selector: cssPath }).catch(() => ({ nodeId: 0 }));
    if (nodeId) return nodeId;
    // the document changed since getDocument: once more from a fresh root
    this.rootNodeId = (await this.cdp.send('DOM.getDocument', { depth: 0 })).root.nodeId;
    const retry = await this.cdp.send('DOM.querySelector', { nodeId: this.rootNodeId, selector: cssPath }).catch(() => ({ nodeId: 0 }));
    return retry.nodeId || null;
  }

  /**
   * @param {number} nodeId
   * @returns {Promise<{familyName: string, postScriptName: string, isCustomFont: boolean, glyphCount: number}[]>}
   */
  async platformFonts(nodeId) {
    const { fonts } = await this.cdp.send('CSS.getPlatformFontsForNode', { nodeId }).catch(() => ({ fonts: [] }));
    return (fonts ?? []).sort((first, second) => second.glyphCount - first.glyphCount);
  }

  /**
   * @param {number} nodeId
   * @returns {Promise<Record<string, string>>}
   */
  async computed(nodeId) {
    const { computedStyle } = await this.cdp.send('CSS.getComputedStyleForNode', { nodeId });
    return Object.fromEntries(computedStyle.map(entry => [entry.name, entry.value]));
  }

  /**
   * @param {number} nodeId
   * @param {string[]} names
   * @returns {Promise<Record<string, string>>} The values that differ under :hover
   */
  async hoverDifferences(nodeId, names) {
    const before = await this.computed(nodeId);
    await this.cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: ['hover'] });
    let after;
    try {
      after = await this.computed(nodeId);
    } finally {
      await this.cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] }).catch(() => {});
    }
    const differences = {};
    for (const name of names) {
      if (before[name] !== after[name]) differences[name] = after[name];
    }
    return differences;
  }

  /**
   * @param {number} nodeId
   */
  async matched(nodeId) {
    return this.cdp.send('CSS.getMatchedStylesForNode', { nodeId });
  }

  /**
   * @param {string} styleSheetId
   * @returns {string} The sheet's URL, or a name for an inline sheet
   */
  sheetName(styleSheetId) {
    const header = this.sheets.get(styleSheetId);
    if (!header) return 'unknown stylesheet';
    if (header.sourceURL && !header.isInline) return header.sourceURL;
    if (header.isInline) return `inline <style> in ${header.sourceURL || 'the page'} (line ${header.startLine + 1})`;
    if (header.isConstructed) return 'constructed stylesheet (script)';
    return header.sourceURL || 'stylesheet without URL';
  }
}

/**
 * @param {string} cssText
 * @param {string} baseUrl The sheet's URL (relative font URLs resolve against it)
 * @returns {{family: string, weight: string, style: string, sources: {url: string, format: string|null}[]}[]}
 */
export const parseFontFaces = (cssText, baseUrl) => {
  const faces = [];
  for (const block of cssText.matchAll(/@font-face\s*{([^}]*)}/gi)) {
    const body = block[1];
    const property = name => body.match(new RegExp(`(?:^|;|\\s)${name}\\s*:\\s*([^;]+)`, 'i'))?.[1]?.trim() ?? null;
    const family = property('font-family')?.replace(/^["']|["']$/g, '');
    if (!family) continue;
    const sources = [];
    for (const source of (property('src') ?? '').matchAll(/url\(\s*["']?([^"')]+)["']?\s*\)(?:\s*format\(\s*["']?([^"')]+)["']?\s*\))?/gi)) {
      if (source[1].startsWith('data:')) {
        sources.push({ url: 'data:(embedded)', format: source[2] ?? null });
        continue;
      }
      try {
        sources.push({ url: new URL(source[1], baseUrl).href, format: source[2] ?? null });
      } catch {
        // an unparsable URL: left out
      }
    }
    faces.push({ family, weight: property('font-weight') ?? '400', style: property('font-style') ?? 'normal', sources });
  }
  return faces;
};

/**
 * @param {string[]} cssTexts
 * @returns {Map<string, number>} How often each custom property is read with var()
 */
export const countVariableUses = cssTexts => {
  const uses = new Map();
  for (const text of cssTexts) {
    for (const match of text.matchAll(/var\(\s*(--[\w-]+)/g)) uses.set(match[1], (uses.get(match[1]) ?? 0) + 1);
  }
  return uses;
};
