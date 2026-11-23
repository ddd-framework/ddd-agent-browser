// exportAssets: files of the session's current page, as bytes plus facts. The host's file sink checks and stores them
// (type from the bytes, nothing executable). Most bytes come from what the page already loaded (the network buffer of
// the page's CDP session, stylesheet texts from CSS), so the export adds no requests; anything else goes through the
// guarded fetch.
import { collectDesignInPage } from './design-page.mjs';
import { exportLogoInPage } from './logo-page.mjs';
import { CssInspector, parseFontFaces } from './css.mjs';
import { guardedFetch } from '../fetch.mjs';
import { CONSENT_STYLE_MARKER } from '../consent.mjs';

export const ASSET_KINDS = ['stylesheets', 'logo', 'screenshots', 'fonts'];
const MAX_STYLESHEETS = 30;
const MAX_FONT_FILES = 12;
const MAX_TOTAL_BYTES = 60_000_000;
const FONT_NOTE = 'Web fonts are licensed to their website. Keep them for analysis; use the free alternative in anything you make.';

/**
 * Removes everything executable or loading from an SVG: scripts, event handlers, foreignObject, external references,
 * javascript: URLs and @import. What remains is drawing markup.
 *
 * @param {string} svg
 * @returns {string}
 */
export const sanitizeSvg = svg => {
  let clean = svg
    .replace(/<\?xml[^>]*>/gi, '')
    .replace(/<!DOCTYPE[^>]*>/gi, '')
    .replace(/<script\b[\s\S]*?<\/script\s*>/gi, '')
    .replace(/<script\b[^>]*\/>/gi, '')
    .replace(/<foreignObject\b[\s\S]*?<\/foreignObject\s*>/gi, '')
    .replace(/<(iframe|embed|object|audio|video|animate|set|handler|listener)\b[\s\S]*?(<\/\1\s*>|\/>)/gi, '')
    .replace(/\s+on[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
    .replace(/\s+(xlink:)?href\s*=\s*("|')\s*(?!#|data:image\/(png|jpeg|gif|webp);)[^"']*\2/gi, '')
    .replace(/@import[^;]*;?/gi, '')
    .replace(/url\(\s*["']?(?!#|data:image\/(png|jpeg|gif|webp);)[^)"']*["']?\s*\)/gi, 'none')
    .replace(/javascript:/gi, '');
  if (!/xmlns=/.test(clean)) clean = clean.replace(/<svg\b/i, '<svg xmlns="http://www.w3.org/2000/svg"');
  return clean.trim();
};

const fileName = (prefix, url, fallbackExtension) => {
  let base = '';
  try {
    base = new URL(url).pathname.split('/').filter(Boolean).pop() ?? '';
  } catch {
    // a name from the prefix only
  }
  base = base.replace(/[^\w.-]+/g, '-').slice(0, 60);
  if (!base) base = `${prefix}.${fallbackExtension}`;
  if (!/\.[a-z0-9]{2,5}$/i.test(base)) base += `.${fallbackExtension}`;
  return `${prefix}-${base}`;
};

const file = (kind, name, mimeType, sourceUrl, bytes, extra = {}) => ({ kind, name, mimeType, sourceUrl, size: bytes.length, content: bytes.toString('base64'), ...extra });

/**
 * @param {{page: import('playwright-core').Page, cdp: import('playwright-core').CDPSession, mainFrameId: string,
 *   responseBody: (url: string) => Promise<{body: Buffer, mimeType: string}|null>, kinds: string[], userAgent: string}} target
 * @returns {Promise<{reason: string, files: object[], skipped: object[], note?: string}>}
 */
export const exportAssets = async ({ page, cdp, mainFrameId, responseBody, kinds, userAgent }) => {
  const files = [];
  const skipped = [];
  let totalBytes = 0;
  const add = entry => {
    if (totalBytes + entry.size > MAX_TOTAL_BYTES) {
      skipped.push({ kind: entry.kind, sourceUrl: entry.sourceUrl, reason: 'TOTAL_LIMIT' });
      return;
    }
    totalBytes += entry.size;
    files.push(entry);
  };
  const fetchBytes = async url => {
    const buffered = await responseBody(url);
    if (buffered) return buffered;
    const fetched = await guardedFetch(url, { headers: { 'User-Agent': userAgent, 'Referer': page.url() } });
    if (fetched.reason !== 'OK') return { reason: fetched.reason };
    return { body: fetched.body, mimeType: (fetched.contentType ?? 'application/octet-stream').split(';')[0] };
  };
  const pageUrl = page.url();
  const css = new CssInspector(cdp);
  const needsCss = kinds.includes('stylesheets') || kinds.includes('fonts');
  let raw = null;
  try {
    if (needsCss) await css.enable();
    const sheets = needsCss ? css.pageSheets(mainFrameId).slice(0, MAX_STYLESHEETS) : [];
    const sheetTexts = [];
    for (const header of sheets) {
      const text = await css.sheetText(header.styleSheetId);
      // the consent fallback's own style tag is ours, not the site's
      if (!text.startsWith(CONSENT_STYLE_MARKER)) sheetTexts.push({ header, text });
    }

    if (kinds.includes('stylesheets')) {
      let inlineIndex = 0;
      for (const [index, { header, text }] of sheetTexts.entries()) {
        if (!text) continue;
        const isInline = header.isInline || !header.sourceURL || header.sourceURL === pageUrl;
        const name = isInline ? `stylesheet-${String(index + 1).padStart(2, '0')}-inline-${++inlineIndex}.css` : fileName(`stylesheet-${String(index + 1).padStart(2, '0')}`, header.sourceURL, 'css');
        add(file('stylesheet', name, 'text/css', isInline ? pageUrl : header.sourceURL, Buffer.from(text, 'utf8'), { inline: isInline }));
      }
    }

    if (kinds.includes('logo')) {
      raw = await page.evaluate(collectDesignInPage, { scopePath: null, viewports: 1 });
      const logo = raw.logoCandidates?.[0] ?? null;
      if (!logo) {
        skipped.push({ kind: 'logo', reason: 'NOT_FOUND' });
      } else {
        // the logo as seen: drawing plus its surface (a wrapper's background and radius), PNG on a transparent canvas
        let rasterDataUrl = null;
        if (logo.type !== 'svg' && logo.url) {
          const fetched = await fetchBytes(logo.url);
          if (fetched.body) {
            const isSvg = /svg/.test(fetched.mimeType ?? '') || /\.svg(\?|$)/i.test(logo.url);
            const bytes = isSvg ? Buffer.from(sanitizeSvg(fetched.body.toString('utf8')), 'utf8') : fetched.body;
            const extension = isSvg ? 'svg' : (fileName('x', logo.url, 'img').match(/\.([a-z0-9]{2,5})$/i)?.[1] ?? 'img').toLowerCase();
            add(file('logo', isSvg ? 'logo.svg' : `logo-original.${extension}`, isSvg ? 'image/svg+xml' : fetched.mimeType, logo.url, bytes, isSvg ? { sanitized: true } : {}));
            if (bytes.length <= 5_000_000) rasterDataUrl = `data:${isSvg ? 'image/svg+xml' : (fetched.mimeType || 'image/png')};base64,${bytes.toString('base64')}`;
          } else {
            skipped.push({ kind: 'logo', sourceUrl: logo.url, reason: fetched.reason ?? 'FETCH_FAILED' });
          }
        }
        const exported = await page.evaluate(exportLogoInPage, { path: logo.path, rasterDataUrl, scale: 2 }).catch(error => ({ error: String(error.message ?? error) }));
        const surface = exported.background ? { background: exported.background, radius: exported.radius } : {};
        if (exported.svg) add(file('logo', 'logo.svg', 'image/svg+xml', pageUrl, Buffer.from(sanitizeSvg(exported.svg), 'utf8'), { sanitized: true, selfContained: true, ...surface }));
        if (exported.svgOnDark) add(file('logo', 'logo-on-dark.svg', 'image/svg+xml', pageUrl, Buffer.from(sanitizeSvg(exported.svgOnDark), 'utf8'), { sanitized: true, selfContained: true, note: 'the light drawing without its surface, for dark backgrounds' }));
        if (exported.png) {
          add(file('logo', 'logo.png', 'image/png', pageUrl, Buffer.from(exported.png.split(',')[1], 'base64'), { transparent: true, scale: 2, ...surface }));
        } else {
          // the canvas could not draw it: the element as rendered, without the page background
          const picture = await page.locator(`css=${logo.path}`).first().screenshot({ type: 'png', omitBackground: true, timeout: 8000, animations: 'disabled' }).catch(() => null);
          if (picture) add(file('logo', 'logo.png', 'image/png', pageUrl, picture, { rendered: true, transparent: false, note: exported.pngError ?? exported.error ?? null }));
        }
      }
    }

    if (kinds.includes('screenshots')) {
      const viewport = await page.screenshot({ type: 'png', timeout: 15000, animations: 'disabled', caret: 'hide' });
      add(file('screenshot', 'screenshot-viewport.png', 'image/png', pageUrl, viewport));
      const fullHeight = await page.evaluate(() => Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0)).catch(() => 0);
      const viewportSize = page.viewportSize();
      if (viewportSize && fullHeight > viewportSize.height) {
        const height = Math.min(fullHeight, 8000);
        const full = await page.screenshot({ type: 'jpeg', quality: 70, fullPage: true, clip: { x: 0, y: 0, width: viewportSize.width, height }, timeout: 30000, animations: 'disabled', caret: 'hide' }).catch(() => null);
        if (full) add(file('screenshot', 'screenshot-page.jpg', 'image/jpeg', pageUrl, full, { truncated: fullHeight > height }));
      }
    }

    let note;
    if (kinds.includes('fonts')) {
      note = FONT_NOTE;
      const usedFamilies = new Set((await page.evaluate(() => (document.fonts ? Array.from(document.fonts).filter(face => face.status === 'loaded').map(face => face.family.replace(/^["']|["']$/g, '').toLowerCase()) : []))));
      const urls = [];
      for (const { header, text } of sheetTexts) {
        for (const face of parseFontFaces(text, header.sourceURL || pageUrl)) {
          if (usedFamilies.size && !usedFamilies.has(face.family.toLowerCase())) continue;
          const source = face.sources.find(entry => /woff2/.test(entry.format ?? entry.url)) ?? face.sources[0];
          if (source && !source.url.startsWith('data:') && !urls.some(entry => entry.url === source.url)) urls.push({ url: source.url, family: face.family, weight: face.weight, style: face.style });
        }
      }
      for (const font of urls.slice(0, MAX_FONT_FILES)) {
        const fetched = await fetchBytes(font.url);
        if (fetched.body) add(file('font', fileName('font', font.url, 'woff2'), fetched.mimeType || 'font/woff2', font.url, fetched.body, { family: font.family, weight: font.weight, style: font.style }));
        else skipped.push({ kind: 'font', sourceUrl: font.url, reason: fetched.reason ?? 'FETCH_FAILED' });
      }
      if (urls.length > MAX_FONT_FILES) skipped.push({ kind: 'font', reason: `LIMIT (${urls.length - MAX_FONT_FILES} more files)` });
    }
    return { reason: 'OK', files, skipped, ...(note ? { note } : {}) };
  } finally {
    if (needsCss) await css.disable();
  }
};
