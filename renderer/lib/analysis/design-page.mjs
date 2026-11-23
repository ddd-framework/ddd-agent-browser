// The fixed design script that runs inside the page (an isolated world: the page's own scripts cannot see it). It only
// reads: no element is added, no attribute set, no global defined. It scrolls through the first two viewports to
// sample them and returns to where it was. Everything it returns is raw measurement; lib/analysis/design.mjs turns it
// into tokens. The function must stay self-contained (it is serialized into the page).

/**
 * @param {{scopePath: string|null, viewports: number}} options scopePath: CSS path of a subtree to analyse instead of the page
 */
export async function collectDesignInPage({ scopePath = null, viewports = 2 }) {
  const viewportWidth = window.innerWidth;
  const viewportHeight = window.innerHeight;
  const scope = scopePath ? document.querySelector(scopePath) : document.body;
  if (!scope) return { error: 'SCOPE_NOT_FOUND' };

  // ---- colours: computed values to #rrggbb + alpha, through a 1×1 canvas for every syntax (oklch, color(), …)
  const canvas = document.createElement('canvas');
  canvas.width = 1;
  canvas.height = 1;
  const context2d = canvas.getContext('2d', { willReadFrequently: true });
  const colourCache = new Map();
  const toColour = value => {
    if (!value || value === 'transparent' || value === 'none') return null;
    if (colourCache.has(value)) return colourCache.get(value);
    let result = null;
    const rgb = value.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/);
    if (rgb) {
      const alphaText = rgb[4];
      const alpha = alphaText === undefined ? 1 : (alphaText.endsWith('%') ? parseFloat(alphaText) / 100 : parseFloat(alphaText));
      result = { hex: '#' + [rgb[1], rgb[2], rgb[3]].map(part => Math.round(parseFloat(part)).toString(16).padStart(2, '0')).join(''), alpha };
    } else if (context2d) {
      context2d.clearRect(0, 0, 1, 1);
      context2d.fillStyle = '#000000';
      context2d.fillStyle = value;
      context2d.fillRect(0, 0, 1, 1);
      const [red, green, blue, alpha] = context2d.getImageData(0, 0, 1, 1).data;
      result = { hex: '#' + [red, green, blue].map(part => part.toString(16).padStart(2, '0')).join(''), alpha: alpha / 255 };
    }
    colourCache.set(value, result);
    return result;
  };
  const opaque = value => {
    const colour = toColour(value);
    return colour && colour.alpha >= 0.5 ? colour.hex : null;
  };
  const addWeight = (map, key, weight) => { if (key) map[key] = (map[key] ?? 0) + weight; };

  const isVisible = (element, style = getComputedStyle(element)) => {
    if (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity) === 0) return false;
    const rect = element.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  };

  /** CSS path from the document root: html > body > div:nth-child(2) > … (to find the element again over CDP) */
  const cssPath = element => {
    const parts = [];
    let current = element;
    while (current && current.nodeType === 1 && current !== document.documentElement) {
      const parent = current.parentElement;
      if (!parent) break;
      const index = Array.prototype.indexOf.call(parent.children, current) + 1;
      parts.unshift(`${current.localName}:nth-child(${index})`);
      current = parent;
    }
    parts.unshift('html');
    return parts.join(' > ');
  };

  const pageBackground = opaque(getComputedStyle(document.body).backgroundColor)
    ?? opaque(getComputedStyle(document.documentElement).backgroundColor) ?? '#ffffff';
  const effectiveBackground = element => {
    for (let current = element; current && current.nodeType === 1; current = current.parentElement) {
      const colour = opaque(getComputedStyle(current).backgroundColor);
      if (colour) return colour;
    }
    return pageBackground;
  };

  // ---- area-weighted backgrounds: a grid of points over the first viewports, the topmost opaque background at each
  const startScrollX = window.scrollX;
  const startScrollY = window.scrollY;
  const backgroundSamples = {};
  const imageSamples = { count: 0 };
  const step = 24;
  const scopeRect = scope.getBoundingClientRect();
  const sampleViewport = () => {
    for (let y = step / 2; y < viewportHeight; y += step) {
      for (let x = step / 2; x < viewportWidth; x += step) {
        const stack = document.elementsFromPoint(x, y);
        if (scopePath && !stack.some(element => scope.contains(element))) continue;
        let colour = null;
        for (const element of stack) {
          const style = getComputedStyle(element);
          if (style.backgroundImage && style.backgroundImage !== 'none' && /url\(/.test(style.backgroundImage)) {
            colour = 'image';
            break;
          }
          if (element.localName === 'img' || element.localName === 'video' || element.localName === 'picture') {
            colour = 'image';
            break;
          }
          colour = opaque(style.backgroundColor);
          if (colour) break;
        }
        if (colour === 'image') imageSamples.count++;
        else addWeight(backgroundSamples, colour ?? pageBackground, 1);
      }
    }
  };
  const pageHeight = Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0);
  for (let index = 0; index < viewports; index++) {
    const targetY = scopePath ? Math.max(0, window.scrollY + scopeRect.top) + index * viewportHeight : index * viewportHeight;
    if (index > 0 && targetY >= pageHeight - 10) break;
    window.scrollTo(0, targetY);
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    sampleViewport();
  }
  window.scrollTo(startScrollX, startScrollY);
  await new Promise(resolve => requestAnimationFrame(resolve));

  // ---- elements of the analysed region (first viewports by document position), at most 6,000
  const regionBottom = (scopePath ? scopeRect.top + window.scrollY + scopeRect.height : viewports * viewportHeight);
  const elements = [];
  const walker = document.createTreeWalker(scope, NodeFilter.SHOW_ELEMENT);
  for (let node = scope; node && elements.length < 6000; node = walker.nextNode()) {
    if (['script', 'style', 'noscript', 'template', 'head', 'meta', 'link'].includes(node.localName)) continue;
    elements.push(node);
  }
  const textColours = {};
  const linkColours = {};
  const borderColours = {};
  const radii = {};
  const shadows = {};
  const spacings = {};
  const pairs = {};
  const buttonRecords = [];
  const cardRecords = [];
  for (const element of elements) {
    const style = getComputedStyle(element);
    if (!isVisible(element, style)) continue;
    const rect = element.getBoundingClientRect();
    const top = rect.top + window.scrollY;
    const inRegion = top < regionBottom;
    if (!inRegion) continue;
    const ownText = Array.from(element.childNodes).filter(child => child.nodeType === 3).map(child => child.textContent).join('').replace(/\s+/g, ' ').trim();
    const colour = opaque(style.color);
    if (ownText.length > 0 && colour) {
      const weight = ownText.length * Math.min(3, parseFloat(style.fontSize) / 16);
      if (element.closest('a')) addWeight(linkColours, colour, weight);
      else addWeight(textColours, colour, weight);
      addWeight(pairs, `${colour}|${effectiveBackground(element)}`, weight);
    }
    for (const side of ['Top', 'Right', 'Bottom', 'Left']) {
      if (parseFloat(style[`border${side}Width`]) >= 1 && style[`border${side}Style`] !== 'none') {
        addWeight(borderColours, opaque(style[`border${side}Color`]), (side === 'Top' || side === 'Bottom' ? rect.width : rect.height) / 100);
      }
    }
    if (style.borderTopLeftRadius && parseFloat(style.borderTopLeftRadius) > 0) addWeight(radii, style.borderTopLeftRadius.split(' ')[0], 1);
    if (style.boxShadow && style.boxShadow !== 'none') addWeight(shadows, style.boxShadow, 1);
    for (const property of ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'marginTop', 'marginBottom', 'rowGap', 'columnGap']) {
      const value = style[property];
      if (value && value !== 'normal' && parseFloat(value) > 0) addWeight(spacings, value, 1);
    }
    // buttons: real buttons, and links that look like one (own background or a rounded border, padding, button height)
    const isRealButton = element.localName === 'button' || element.getAttribute('role') === 'button'
      || (element.localName === 'input' && ['submit', 'button'].includes(element.type));
    const background = opaque(style.backgroundColor);
    const parentBackground = element.parentElement ? effectiveBackground(element.parentElement) : pageBackground;
    const hasBorder = parseFloat(style.borderTopWidth) >= 1 && style.borderTopStyle !== 'none';
    const looksLikeButton = element.localName === 'a'
      && ((background && background !== parentBackground) || (hasBorder && parseFloat(style.borderTopLeftRadius) > 0))
      && parseFloat(style.paddingLeft) >= 8 && rect.height >= 24 && rect.height <= 80 && rect.width <= 480;
    if ((isRealButton || looksLikeButton) && rect.height >= 20 && rect.height <= 100 && rect.width >= 30 && rect.width <= 600) {
      const text = (element.innerText || element.value || element.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 40);
      if (text) {
        buttonRecords.push({
          path: cssPath(element), tag: element.localName, text, area: rect.width * rect.height,
          background, filled: Boolean(background && background !== parentBackground), parentBackground,
          color: opaque(style.color), borderColor: hasBorder ? opaque(style.borderTopColor) : null,
          borderWidth: hasBorder ? style.borderTopWidth : '0px', radius: style.borderTopLeftRadius,
          padding: `${style.paddingTop} ${style.paddingRight} ${style.paddingBottom} ${style.paddingLeft}`,
          fontFamily: style.fontFamily, fontSize: style.fontSize, fontWeight: style.fontWeight,
          textTransform: style.textTransform, letterSpacing: style.letterSpacing, shadow: style.boxShadow,
          height: Math.round(rect.height), width: Math.round(rect.width),
        });
      }
    }
    // cards: a rounded or bordered or shadowed box with its own background, of card size, holding text
    if (background && (parseFloat(style.borderTopLeftRadius) > 0 || style.boxShadow !== 'none' || hasBorder)
      && rect.width >= 150 && rect.width <= 760 && rect.height >= 80 && rect.height <= 900 && (element.innerText ?? '').trim().length > 20) {
      cardRecords.push({
        path: cssPath(element), background, radius: style.borderTopLeftRadius, shadow: style.boxShadow,
        border: hasBorder ? `${style.borderTopWidth} ${style.borderTopStyle} ${opaque(style.borderTopColor) ?? ''}`.trim() : 'none',
        padding: `${style.paddingTop} ${style.paddingRight} ${style.paddingBottom} ${style.paddingLeft}`,
        width: Math.round(rect.width), height: Math.round(rect.height),
      });
    }
  }

  // ---- typography: the first visible H1–H3, body text, the most common button
  const typeRecord = element => {
    if (!element) return null;
    const style = getComputedStyle(element);
    return {
      path: cssPath(element), tag: element.localName, sample: (element.innerText ?? '').replace(/\s+/g, ' ').trim().slice(0, 60),
      fontFamily: style.fontFamily, fontSize: style.fontSize, fontWeight: style.fontWeight, lineHeight: style.lineHeight,
      letterSpacing: style.letterSpacing, textTransform: style.textTransform, fontStyle: style.fontStyle, color: opaque(style.color),
    };
  };
  const firstVisible = selector => Array.from(scope.querySelectorAll(selector)).find(element => isVisible(element) && (element.innerText ?? '').trim().length > 0) ?? null;
  const bodyText = Array.from(scope.querySelectorAll('p, li, td, dd')).find(element => isVisible(element) && (element.innerText ?? '').trim().length >= 40)
    ?? firstVisible('p') ?? document.body;
  const typography = {
    h1: typeRecord(firstVisible('h1')),
    h2: typeRecord(firstVisible('h2')),
    h3: typeRecord(firstVisible('h3')),
    body: typeRecord(bodyText),
  };

  // ---- navigation: the first nav in the header (or the first nav at all)
  const header = document.querySelector('header, [role="banner"]');
  const navigationElement = (header && header.querySelector('nav, [role="navigation"]')) ?? document.querySelector('nav, [role="navigation"]');
  let navigation = null;
  if (navigationElement && isVisible(navigationElement)) {
    const style = getComputedStyle(navigationElement);
    const link = Array.from(navigationElement.querySelectorAll('a')).find(element => isVisible(element) && element.innerText.trim());
    const linkStyle = link ? getComputedStyle(link) : null;
    navigation = {
      path: cssPath(navigationElement), linkPath: link ? cssPath(link) : null, background: effectiveBackground(navigationElement),
      height: Math.round(navigationElement.getBoundingClientRect().height), position: getComputedStyle(header ?? navigationElement).position,
      linkColour: linkStyle ? opaque(linkStyle.color) : null, linkFontSize: linkStyle?.fontSize ?? null, linkFontWeight: linkStyle?.fontWeight ?? null,
      linkTextTransform: linkStyle?.textTransform ?? null, linkFontFamily: linkStyle?.fontFamily ?? null,
      linkPadding: linkStyle ? `${linkStyle.paddingTop} ${linkStyle.paddingRight} ${linkStyle.paddingBottom} ${linkStyle.paddingLeft}` : null,
      gap: style.columnGap !== 'normal' ? style.columnGap : null, links: navigationElement.querySelectorAll('a').length,
    };
  }

  // ---- :root custom properties, resolved (Chrome lists custom properties in the computed style)
  const rootStyle = getComputedStyle(document.documentElement);
  const rootVariables = {};
  for (let index = 0; index < rootStyle.length && Object.keys(rootVariables).length < 600; index++) {
    const name = rootStyle[index];
    if (name.startsWith('--')) rootVariables[name] = rootStyle.getPropertyValue(name).trim().slice(0, 200);
  }

  // ---- logo: the header image or inline SVG that links to the start page, scored
  const origin = location.origin;
  const isStartLink = anchor => {
    if (!anchor) return false;
    try {
      const target = new URL(anchor.href, location.href);
      return target.origin === origin && /^\/([a-z]{2}(-[a-z]{2})?\/?)?(index\.[a-z]+)?$/i.test(target.pathname);
    } catch {
      return false;
    }
  };
  const logoWords = element => [element.id, element.getAttribute('class'), element.getAttribute('alt'), element.getAttribute('aria-label'), element.getAttribute('title'), element.querySelector?.('title')?.textContent]
    .filter(Boolean).join(' ').toLowerCase();
  const logoCandidates = [];
  for (const element of document.querySelectorAll('img, svg, [style*="background-image"], picture')) {
    if (element.closest('svg') && element.localName !== 'svg') continue;
    if (element.localName === 'svg' && element.parentElement?.closest('svg')) continue;
    if (!isVisible(element)) continue;
    const rect = element.getBoundingClientRect();
    if (rect.top + window.scrollY > viewportHeight * 1.2 || rect.width < 16 || rect.height < 10) continue;
    const anchor = element.closest('a');
    let score = 0;
    if (header && header.contains(element)) score += 3;
    if (isStartLink(anchor)) score += 3;
    const words = logoWords(element) + ' ' + [element.parentElement, element.parentElement?.parentElement].filter(Boolean).map(logoWords).join(' ') + ' ' + (anchor ? logoWords(anchor) : '');
    if (/logo|brand|marke/.test(words)) score += 3;
    if (rect.left < viewportWidth / 2 && rect.top + window.scrollY < 200) score += 1;
    if (rect.width >= 40 && rect.width <= 420 && rect.height <= 160) score += 1;
    if (score < 4) continue;
    const record = { score, path: cssPath(element), width: Math.round(rect.width), height: Math.round(rect.height), alt: element.getAttribute('alt') ?? element.getAttribute('aria-label') ?? null, linksToStart: isStartLink(anchor) };
    if (element.localName === 'svg') {
      record.type = 'svg';
      const markup = element.outerHTML;
      record.svg = markup.length <= 200_000 ? markup : null;
      record.svgTooLarge = markup.length > 200_000;
    } else if (element.localName === 'img') {
      record.type = 'img';
      record.url = element.currentSrc || element.src;
    } else if (element.localName === 'picture') {
      const image = element.querySelector('img');
      if (!image) continue;
      record.type = 'img';
      record.url = image.currentSrc || image.src;
    } else {
      const match = getComputedStyle(element).backgroundImage.match(/url\(["']?([^"')]+)["']?\)/);
      if (!match) continue;
      record.type = 'img';
      record.url = new URL(match[1], location.href).href;
    }
    logoCandidates.push(record);
  }
  logoCandidates.sort((first, second) => second.score - first.score);

  const icons = Array.from(document.querySelectorAll('link[rel~="icon"], link[rel="apple-touch-icon"], link[rel="mask-icon"]'))
    .slice(0, 8).map(link => ({ rel: link.getAttribute('rel'), url: link.href, sizes: link.getAttribute('sizes') }));
  const ogImage = document.querySelector('meta[property="og:image"], meta[name="og:image"]')?.content ?? null;
  let jsonLdLogo = null;
  const findLogo = value => {
    if (!value || typeof value !== 'object' || jsonLdLogo) return;
    if (Array.isArray(value)) { value.forEach(findLogo); return; }
    const types = [].concat(value['@type'] ?? []);
    if (value.logo && types.some(type => /Organization|Corporation|InsuranceAgency|LocalBusiness/i.test(type))) {
      jsonLdLogo = typeof value.logo === 'string' ? value.logo : (value.logo.url ?? value.logo.contentUrl ?? null);
      return;
    }
    Object.values(value).forEach(findLogo);
  };
  for (const script of document.querySelectorAll('script[type="application/ld+json"]')) {
    try { findLogo(JSON.parse(script.textContent)); } catch { /* invalid JSON-LD: an SEO finding, not ours */ }
  }

  const fontFaces = [];
  if (document.fonts) {
    for (const face of document.fonts) {
      if (fontFaces.length >= 80) break;
      fontFaces.push({ family: face.family.replace(/^["']|["']$/g, ''), weight: face.weight, style: face.style, status: face.status });
    }
  }

  return {
    url: location.href, title: document.title, viewport: { width: viewportWidth, height: viewportHeight }, pageHeight,
    pageBackground, backgroundSamples, imageSamples: imageSamples.count, textColours, linkColours, borderColours, pairs,
    radii, shadows, spacings, buttons: buttonRecords.slice(0, 300), cards: cardRecords.slice(0, 200), typography, navigation,
    rootVariables, logoCandidates: logoCandidates.slice(0, 5), icons, ogImage: ogImage ? new URL(ogImage, location.href).href : null,
    jsonLdLogo: jsonLdLogo ? new URL(jsonLdLogo, location.href).href : null, fontFaces,
  };
}
