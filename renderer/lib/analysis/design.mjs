// extractDesign: the design tokens of the session's current page. The page script (design-page.mjs) measures, CDP adds
// what only the browser knows (stylesheet texts and @font-face sources, the font really rendered, :hover styles), and
// buildDesignTokens() turns it into tokens: palette by role with contrast, :root custom properties, typography, scales,
// components and the logo. designSummary() is the compact markdown (about 5,000 characters) for the model.
import { collectDesignInPage } from './design-page.mjs';
import { CssInspector, countVariableUses, parseFontFaces } from './css.mjs';
import { colourDistance, contrastGrade, contrastRatio, isBrandLike, mergeSimilar, saturation } from './colour.mjs';
import { CONSENT_STYLE_MARKER } from '../consent.mjs';

const MAX_TOTAL_SHEET_CHARS = 8_000_000;
const HOVER_PROPERTIES = ['background-color', 'color', 'border-top-color', 'text-decoration-line', 'box-shadow', 'opacity', 'transform'];

/** Fonts on Google Fonts (SIL OFL or Apache): the free choice is the font itself. */
const FREE_FONTS = ['inter', 'roboto', 'open sans', 'lato', 'montserrat', 'poppins', 'source sans 3', 'source sans pro', 'noto sans', 'nunito',
  'nunito sans', 'raleway', 'work sans', 'ibm plex sans', 'fira sans', 'pt sans', 'merriweather', 'playfair display', 'lora', 'roboto slab',
  'ubuntu', 'barlow', 'rubik', 'mulish', 'dm sans', 'manrope', 'karla', 'libre franklin', 'oswald', 'titillium web', 'source serif 4',
  'source serif pro', 'noto serif', 'arimo', 'cabin', 'heebo', 'hind', 'josefin sans', 'jost', 'outfit', 'plus jakarta sans', 'figtree',
  'lexend', 'public sans', 'red hat display', 'red hat text', 'eb garamond', 'jetbrains mono', 'roboto mono', 'space grotesk', 'archivo',
  'asap', 'exo 2', 'quicksand', 'sora', 'urbanist', 'overpass', 'kanit', 'pt serif', 'crimson pro', 'libre baskerville', 'bitter'];

/** Well-known commercial or system fonts and a free font of the same build. */
const FREE_ALTERNATIVES = [
  [/helvetica|neue haas|arial|nimbus sans|liberation sans/, 'Inter', 'neo-grotesque sans'],
  [/frutiger|myriad|segoe|univers|syntax|thesans|the sans/, 'Source Sans 3', 'humanist sans'],
  [/futura|century gothic|avant ?garde/, 'Jost', 'geometric sans'],
  [/avenir|sofia pro|museo sans/, 'Nunito Sans', 'geometric humanist sans'],
  [/gotham|proxima|montserrat|gilroy|brandon/, 'Montserrat', 'geometric sans'],
  [/circular|graphik|aeonik|gt walsheim|cera/, 'DM Sans', 'geometric grotesque'],
  [/\bdin\b|din next|din pro|bahnschrift/, 'Barlow', 'technical sans'],
  [/gill sans|johnston|calibri/, 'Lato', 'humanist sans'],
  [/garamond|minion|sabon|caslon/, 'EB Garamond', 'old-style serif'],
  [/times|georgia|tiempos|charter|cambria|publico/, 'Source Serif 4', 'text serif'],
  [/rockwell|clarendon|museo slab|archer/, 'Roboto Slab', 'slab serif'],
  [/courier|consolas|menlo|monaco|sf mono|monospace/, 'JetBrains Mono', 'monospace'],
  [/sf pro|-apple-system|system-ui|blinkmacsystemfont/, 'Inter', 'system sans'],
];

/**
 * @param {string|null} stack
 * @param {string|null} fallbackFamily
 * @returns {'serif'|'sans-serif'|'monospace'|'display'}
 */
export const fontCategory = (stack, fallbackFamily = null) => {
  const lower = (stack ?? '').toLowerCase();
  if (/monospace|mono\b|courier|consolas/.test(lower)) return 'monospace';
  if (/\bserif\b/.test(lower.replace(/sans-serif/g, '')) || /garamond|times|georgia|merriweather|playfair|lora/.test(lower)) return 'serif';
  if (/cursive|fantasy/.test(lower)) return 'display';
  if (fallbackFamily && /serif 4|garamond|slab/i.test(fallbackFamily) && !/sans/.test(lower)) return 'serif';
  return 'sans-serif';
};

const firstFamily = stack => (stack ?? '').split(',')[0].trim().replace(/^["']|["']$/g, '');

/**
 * @param {string|null} family The first family of the stack (or the rendered font)
 * @param {string|null} stack The whole font-family value
 * @returns {{family: string, reason: string}}
 */
export const suggestFreeFont = (family, stack) => {
  const lower = (family ?? '').toLowerCase();
  if (FREE_FONTS.includes(lower)) return { family, reason: 'free font (Google Fonts)' };
  for (const [pattern, alternative, build] of FREE_ALTERNATIVES) {
    if (pattern.test(lower)) return { family: alternative, reason: `closest free ${build}` };
  }
  const lowerStack = (stack ?? '').toLowerCase();
  for (const candidate of lowerStack.split(',').slice(1).map(entry => entry.trim().replace(/^["']|["']$/g, ''))) {
    for (const [pattern, alternative, build] of FREE_ALTERNATIVES) {
      if (pattern.test(candidate) && !/^(sans-serif|serif|monospace|system-ui)$/.test(candidate)) return { family: alternative, reason: `closest free ${build} (the stack falls back to ${candidate})` };
    }
  }
  for (const candidate of lowerStack.split(',').map(entry => entry.trim().replace(/^["']|["']$/g, ''))) {
    const free = FREE_FONTS.find(name => name === candidate);
    if (free) return { family: candidate.replace(/\b\w/g, letter => letter.toUpperCase()), reason: 'free font named in the stack' };
  }
  if (/\bserif\b/.test(lowerStack) && !/sans-serif/.test(lowerStack)) return { family: 'Source Serif 4', reason: 'free serif (no closer match known)' };
  if (/monospace/.test(lowerStack)) return { family: 'JetBrains Mono', reason: 'free monospace (no closer match known)' };
  return { family: 'Inter', reason: 'free sans (no closer match known)' };
};

const topEntries = (weights, limit) => Object.entries(weights ?? {}).sort((first, second) => second[1] - first[1]).slice(0, limit)
  .map(([value, count]) => ({ value, count: Math.round(count) }));

const rgbToHex = value => {
  const match = String(value ?? '').match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+))?\s*\)$/);
  if (!match) return value;
  if (match[4] !== undefined && Number(match[4]) === 0) return 'transparent';
  return '#' + [match[1], match[2], match[3]].map(part => Math.round(Number(part)).toString(16).padStart(2, '0')).join('');
};

/**
 * @param {object[]} buttons Button records of the page script
 * @returns {{primary: object|null, secondary: object|null, groups: object[]}}
 */
export const pickButtons = buttons => {
  const groups = new Map();
  for (const button of buttons) {
    const key = [button.filled ? button.background : 'none', button.color, button.radius, button.borderColor ?? 'none'].join('|');
    const group = groups.get(key) ?? { ...button, count: 0, totalArea: 0 };
    group.count++;
    group.totalArea += button.area;
    groups.set(key, group);
  }
  const all = [...groups.values()].map(group => ({ ...group, score: group.count * 2 + group.totalArea / 15000 }));
  const filled = all.filter(group => group.filled && group.background).sort((first, second) => {
    const saturatedFirst = saturation(first.background) >= 0.2 ? 1 : 0;
    const saturatedSecond = saturation(second.background) >= 0.2 ? 1 : 0;
    return saturatedSecond - saturatedFirst || second.score - first.score;
  });
  const primary = filled[0] ?? null;
  const rest = all.filter(group => group !== primary).sort((first, second) => {
    const outlineFirst = !first.filled && first.borderColor ? 1 : 0;
    const outlineSecond = !second.filled && second.borderColor ? 1 : 0;
    return outlineSecond - outlineFirst || second.score - first.score;
  });
  const secondary = rest.find(group => (group.filled && primary && colourDistance(group.background, primary.background) > 40) || (!group.filled && group.borderColor)) ?? null;
  return { primary, secondary, groups: all };
};

/**
 * @param {object} raw The page script's measurement
 * @param {{fontFaces: object[], variableUses: Map<string, number>, renderedFonts: Record<string, object[]>, hover: Record<string, object>, capturedAt?: string}} extras
 * @returns {object} The design tokens (design-tokens.json)
 */
export const buildDesignTokens = (raw, { fontFaces = [], variableUses = new Map(), renderedFonts = {}, hover = {}, capturedAt = new Date().toISOString() } = {}) => {
  const sampleTotal = Object.values(raw.backgroundSamples ?? {}).reduce((sum, value) => sum + value, 0) + (raw.imageSamples ?? 0);
  const share = weight => (sampleTotal > 0 ? Math.round((weight / sampleTotal) * 1000) / 10 : 0);
  const backgrounds = mergeSimilar(raw.backgroundSamples ?? {});
  const texts = mergeSimilar(raw.textColours ?? {});
  const links = mergeSimilar(raw.linkColours ?? {});
  const borders = mergeSimilar(raw.borderColours ?? {});
  const buttons = pickButtons(raw.buttons ?? []);

  const background = backgrounds[0]?.hex ?? raw.pageBackground ?? '#ffffff';
  const surface = backgrounds.slice(1).find(entry => share(entry.weight) >= 1.5 && saturation(entry.hex) <= 0.3 && colourDistance(entry.hex, background) >= 6)?.hex ?? null;
  const text = texts[0]?.hex ?? null;
  const textMuted = texts.slice(1).find(entry => text && colourDistance(entry.hex, text) >= 25 && saturation(entry.hex) <= 0.3 && colourDistance(entry.hex, background) >= 60)?.hex ?? null;

  // brand colours: filled buttons weigh most, then saturated areas, link colours and borders
  const brandWeights = {};
  const addBrand = (hex, weight) => {
    if (hex && isBrandLike(hex) && colourDistance(hex, background) > 30) brandWeights[hex] = (brandWeights[hex] ?? 0) + weight;
  };
  for (const group of buttons.groups) if (group.filled) addBrand(group.background, group.count * 40 + group.totalArea / 500);
  for (const entry of backgrounds) addBrand(entry.hex, entry.weight);
  for (const entry of links) addBrand(entry.hex, entry.weight / 4);
  for (const entry of borders) addBrand(entry.hex, entry.weight);
  for (const entry of texts) addBrand(entry.hex, entry.weight / 8);
  const brand = mergeSimilar(brandWeights, 20);
  const primary = buttons.primary && saturation(buttons.primary.background) >= 0.2 ? buttons.primary.background : (brand[0]?.hex ?? null);
  const accent = brand.find(entry => primary && colourDistance(entry.hex, primary) >= 80)?.hex ?? null;
  // a border colour is the neutral line colour of the page; a brand-coloured border (outline buttons) only without one
  const border = borders.find(entry => !isBrandLike(entry.hex))?.hex ?? borders[0]?.hex ?? null;

  const chosen = new Set([background, surface, text, textMuted, primary, accent, border].filter(Boolean));
  const others = mergeSimilar({ ...Object.fromEntries(backgrounds.map(entry => [entry.hex, entry.weight])), ...Object.fromEntries(texts.map(entry => [entry.hex, entry.weight / 10])) })
    .filter(entry => !chosen.has(entry.hex) && [...chosen].every(hex => colourDistance(hex, entry.hex) > 12)).slice(0, 8).map(entry => entry.hex);

  const backgroundWeight = hex => backgrounds.find(entry => entry.hex === hex)?.weight ?? 0;
  // the palette is plain hex by role (what a brand kit reads); the measurements behind it are in paletteDetails
  const palette = Object.fromEntries(Object.entries({ primary, accent, text, textMuted, surface, background, border, link: links[0]?.hex ?? null }).filter(([, value]) => value));
  const paletteDetails = {
    primarySource: primary ? (buttons.primary && primary === buttons.primary.background ? 'primary button' : 'most used brand colour') : null,
    areaShare: Object.fromEntries([background, surface].filter(Boolean).map(hex => [hex, share(backgroundWeight(hex))])),
    others,
    imageAreaShare: share(raw.imageSamples ?? 0),
  };
  const chart = [primary, accent, ...brand.map(entry => entry.hex)].filter(Boolean)
    .filter((hex, index, all) => all.findIndex(other => colourDistance(other, hex) < 40) === index).slice(0, 6);

  const contrast = [];
  if (text) contrast.push({ pair: 'text on background', foreground: text, background, ratio: contrastRatio(text, background) });
  if (buttons.primary?.color && buttons.primary?.background) contrast.push({ pair: 'primary button text', foreground: buttons.primary.color, background: buttons.primary.background, ratio: contrastRatio(buttons.primary.color, buttons.primary.background) });
  if (palette.link && palette.link !== text) contrast.push({ pair: 'link on background', foreground: palette.link, background, ratio: contrastRatio(palette.link, background) });
  const seenPairs = new Set(contrast.map(entry => `${entry.foreground}|${entry.background}`));
  for (const { value } of topEntries(raw.pairs, 12)) {
    const [foreground, behind] = value.split('|');
    if (seenPairs.has(value) || !/^#/.test(foreground) || !/^#/.test(behind) || contrast.length >= 8) continue;
    seenPairs.add(value);
    contrast.push({ pair: 'text pair', foreground, background: behind, ratio: contrastRatio(foreground, behind) });
  }
  for (const entry of contrast) entry.grade = contrastGrade(entry.ratio);

  const facesByFamily = new Map();
  for (const face of fontFaces) {
    const key = face.family.toLowerCase();
    if (!facesByFamily.has(key)) facesByFamily.set(key, []);
    facesByFamily.get(key).push(face);
  }
  const filesFor = family => {
    const files = [];
    const seen = new Set();
    for (const face of facesByFamily.get((family ?? '').toLowerCase()) ?? []) {
      const best = face.sources.find(source => /woff2/.test(source.format ?? source.url)) ?? face.sources[0];
      if (!best || seen.has(best.url)) continue;
      seen.add(best.url);
      let host = null;
      try { host = best.url.startsWith('data:') ? 'embedded' : new URL(best.url).host; } catch { /* left without host */ }
      files.push({ url: best.url, host, format: best.format, weight: face.weight, style: face.style });
    }
    return files.slice(0, 8);
  };
  const typeToken = (record, key) => {
    if (!record) return null;
    const rendered = (renderedFonts[key] ?? [])[0] ?? null;
    const declared = firstFamily(record.fontFamily);
    const fallback = suggestFreeFont(declared || rendered?.familyName, record.fontFamily);
    return {
      family: declared || rendered?.familyName || null, category: fontCategory(record.fontFamily, fallback.family), fallback: fallback.family,
      sample: record.sample, element: record.tag, renderedFont: rendered ? rendered.familyName : null, renderedIsWebFont: rendered ? rendered.isCustomFont : null,
      stack: record.fontFamily, size: record.fontSize, weight: record.fontWeight, lineHeight: record.lineHeight, letterSpacing: record.letterSpacing,
      textTransform: record.textTransform, colour: record.color, fontFiles: filesFor(declared), freeAlternative: fallback,
    };
  };
  const primaryButtonRecord = buttons.primary ? { ...buttons.primary, tag: buttons.primary.tag, sample: buttons.primary.text, lineHeight: null, color: buttons.primary.color } : null;
  const typography = {
    h1: typeToken(raw.typography?.h1, 'h1'),
    h2: typeToken(raw.typography?.h2, 'h2'),
    h3: typeToken(raw.typography?.h3, 'h3'),
    body: typeToken(raw.typography?.body, 'body'),
    button: typeToken(primaryButtonRecord, 'button'),
  };
  const fontFamilies = new Map();
  for (const token of Object.values(typography)) {
    if (!token) continue;
    const family = firstFamily(token.stack);
    if (!family || fontFamilies.has(family.toLowerCase())) continue;
    fontFamilies.set(family.toLowerCase(), { family, rendered: token.renderedFont, files: filesFor(family), hosts: [...new Set(filesFor(family).map(file => file.host).filter(Boolean))], freeAlternative: token.freeAlternative });
  }

  const rootVariables = Object.entries(raw.rootVariables ?? {})
    .map(([name, value]) => ({ name, value, uses: variableUses.get(name) ?? 0 }))
    .sort((first, second) => second.uses - first.uses || first.name.localeCompare(second.name));

  const buttonToken = (group, key) => group ? {
    text: group.text, background: group.filled ? group.background : 'transparent', colour: group.color, border: group.borderColor ? `${group.borderWidth} solid ${group.borderColor}` : 'none',
    radius: group.radius, padding: group.padding, fontSize: group.fontSize, fontWeight: group.fontWeight, textTransform: group.textTransform,
    letterSpacing: group.letterSpacing, shadow: group.shadow, height: group.height, seen: group.count,
    hover: Object.fromEntries(Object.entries(hover[key] ?? {}).map(([name, value]) => [name, rgbToHex(value)])),
  } : null;
  const cardGroups = new Map();
  for (const card of raw.cards ?? []) {
    const key = [card.background, card.radius, card.shadow, card.border].join('|');
    const group = cardGroups.get(key) ?? { ...card, count: 0 };
    group.count++;
    cardGroups.set(key, group);
  }
  const card = [...cardGroups.values()].sort((first, second) => second.count - first.count)[0] ?? null;
  const navigation = raw.navigation ? { ...raw.navigation, hover: Object.fromEntries(Object.entries(hover.navigation ?? {}).map(([name, value]) => [name, rgbToHex(value)])) } : null;
  if (navigation) { delete navigation.path; delete navigation.linkPath; }

  const logoCandidate = (raw.logoCandidates ?? [])[0] ?? null;
  // file names are the ones exportAssets(kinds: logo) writes next to the token file
  const isSvgLogo = logoCandidate && (logoCandidate.type === 'svg' || /\.svg(\?|#|$)/i.test(logoCandidate.url ?? ''));
  const logo = logoCandidate ? {
    file: 'logo.png', svgFile: isSvgLogo ? 'logo.svg' : null, onDarkFile: null,
    type: logoCandidate.type, url: logoCandidate.url ?? null, svgChars: logoCandidate.svg ? logoCandidate.svg.length : null,
    width: logoCandidate.width, height: logoCandidate.height, alt: logoCandidate.alt, linksToStartPage: logoCandidate.linksToStart, score: logoCandidate.score,
  } : null;

  return {
    source: { url: raw.url, title: raw.title, fetchedAt: capturedAt, viewport: raw.viewport, analysedHeight: Math.min(raw.pageHeight ?? 0, (raw.viewport?.height ?? 0) * 2) },
    palette,
    paletteDetails,
    chart,
    contrast,
    customProperties: { total: rootVariables.length, top: rootVariables.slice(0, 40) },
    typography,
    fonts: [...fontFamilies.values()],
    scales: { radius: topEntries(raw.radii, 8), shadow: topEntries(raw.shadows, 6), spacing: topEntries(raw.spacings, 10) },
    components: {
      primaryButton: buttonToken(buttons.primary, 'primaryButton'),
      secondaryButton: buttonToken(buttons.secondary, 'secondaryButton'),
      card: card ? { background: card.background, radius: card.radius, shadow: card.shadow, border: card.border, padding: card.padding, seen: card.count } : null,
      navigation,
    },
    logo,
    icons: raw.icons ?? [],
    ogImage: raw.ogImage ?? null,
    jsonLdLogo: raw.jsonLdLogo ?? null,
  };
};

const line = (label, value) => (value ? `- ${label}: ${value}` : null);

/**
 * @param {object} tokens From buildDesignTokens()
 * @param {number} maxChars
 * @returns {string} Compact markdown for the model
 */
export const designSummary = (tokens, maxChars = 5000) => {
  const palette = tokens.palette;
  const details = tokens.paletteDetails;
  const parts = [`## Design of ${tokens.source.title || tokens.source.url}`, `${tokens.source.url} · viewport ${tokens.source.viewport?.width}×${tokens.source.viewport?.height}, first ${tokens.source.analysedHeight}px analysed`, '', '### Palette'];
  parts.push(...[
    line('background', palette.background && `${palette.background} (${details.areaShare[palette.background] ?? 0}% of the area)`),
    line('surface', palette.surface && `${palette.surface} (${details.areaShare[palette.surface] ?? 0}%)`),
    line('text', palette.text),
    line('muted text', palette.textMuted),
    line('primary', palette.primary && `${palette.primary} (${details.primarySource})`),
    line('accent', palette.accent),
    line('link', palette.link),
    line('border', palette.border),
    line('chart series', tokens.chart.join(', ')),
    line('further colours', details.others.join(', ')),
    line('images', details.imageAreaShare > 0 ? `${details.imageAreaShare}% of the area` : null),
  ].filter(Boolean));
  if (tokens.contrast.length) {
    parts.push('', '### Contrast');
    for (const entry of tokens.contrast.slice(0, 6)) parts.push(`- ${entry.pair}: ${entry.foreground} on ${entry.background} = ${entry.ratio}:1 (${entry.grade})`);
  }
  parts.push('', '### Typography');
  for (const [key, token] of Object.entries(tokens.typography)) {
    if (!token) continue;
    const family = token.renderedFont ?? firstFamily(token.stack);
    parts.push(`- ${key}: ${family}${token.renderedIsWebFont ? ' (web font)' : ''}, ${token.size}/${token.lineHeight ?? 'normal'}, weight ${token.weight}${token.textTransform !== 'none' && token.textTransform ? `, ${token.textTransform}` : ''}${token.letterSpacing && token.letterSpacing !== 'normal' ? `, tracking ${token.letterSpacing}` : ''}, ${token.colour ?? ''} → free: ${token.freeAlternative.family}`);
  }
  if (tokens.fonts.length) {
    parts.push('', '### Font files');
    for (const font of tokens.fonts) parts.push(`- ${font.family}: ${font.files.length ? `${font.files.length} file(s) from ${font.hosts.join(', ') || 'the page'}` : 'no @font-face (system or unknown)'}; free alternative ${font.freeAlternative.family} (${font.freeAlternative.reason})`);
    parts.push('- A web font is licensed for its website; use the free alternative in documents and say so.');
  }
  const scale = entries => entries.map(entry => `${entry.value} ×${entry.count}`).join(', ');
  parts.push('', '### Scales');
  parts.push(...[line('radius', scale(tokens.scales.radius)), line('spacing', scale(tokens.scales.spacing.slice(0, 8))), line('shadow', tokens.scales.shadow.slice(0, 3).map(entry => `\`${entry.value.slice(0, 90)}\` ×${entry.count}`).join('; '))].filter(Boolean));
  parts.push('', '### Components');
  const describeButton = button => `${button.background} / ${button.colour}, radius ${button.radius}, padding ${button.padding}, ${button.fontSize} ${button.fontWeight}${button.border !== 'none' ? `, border ${button.border}` : ''}${Object.keys(button.hover).length ? `; hover ${Object.entries(button.hover).map(([name, value]) => `${name} ${value}`).join(', ')}` : ''} (e.g. "${button.text}")`;
  if (tokens.components.primaryButton) parts.push(`- primary button: ${describeButton(tokens.components.primaryButton)}`);
  if (tokens.components.secondaryButton) parts.push(`- secondary button: ${describeButton(tokens.components.secondaryButton)}`);
  const card = tokens.components.card;
  if (card) parts.push(`- card: ${card.background}, radius ${card.radius}, ${card.shadow !== 'none' ? `shadow \`${card.shadow.slice(0, 80)}\`` : `border ${card.border}`}, padding ${card.padding}`);
  const navigation = tokens.components.navigation;
  if (navigation) parts.push(`- navigation: ${navigation.links} links on ${navigation.background}, ${navigation.linkColour} ${navigation.linkFontSize} ${navigation.linkFontWeight}, height ${navigation.height}px${navigation.position === 'sticky' || navigation.position === 'fixed' ? `, ${navigation.position}` : ''}`);
  parts.push('', '### Logo');
  if (tokens.logo) parts.push(`- ${tokens.logo.type === 'svg' ? `inline SVG (${tokens.logo.svgChars} characters)` : tokens.logo.url}, ${tokens.logo.width}×${tokens.logo.height}${tokens.logo.alt ? `, "${tokens.logo.alt}"` : ''}${tokens.logo.linksToStartPage ? ', links to the start page' : ''}`);
  else parts.push('- no logo found in the header');
  parts.push(...[line('og:image', tokens.ogImage), line('JSON-LD logo', tokens.jsonLdLogo), line('icons', tokens.icons.slice(0, 3).map(icon => icon.url).join(', '))].filter(Boolean));
  parts.push('- A third-party logo is fine in an internal draft, never in something published.');
  const variables = tokens.customProperties.top.filter(entry => entry.uses > 0).slice(0, 25);
  if (variables.length) {
    parts.push('', `### :root custom properties (${tokens.customProperties.total}, most used)`);
    parts.push(variables.map(entry => `\`${entry.name}: ${entry.value.slice(0, 40)}\` ×${entry.uses}`).join(', '));
  }
  let text = parts.join('\n');
  if (text.length > maxChars) text = text.slice(0, maxChars - 60).replace(/\n[^\n]*$/, '') + '\n… (cut; the full tokens are in the JSON)';
  return text;
};

/**
 * Runs the whole extraction on a page.
 *
 * @param {{page: import('playwright-core').Page, cdp: import('playwright-core').CDPSession, mainFrameId: string, scopePath?: string|null}} target
 * @returns {Promise<{reason: string, tokens?: object, summary?: string, raw?: object}>}
 */
export const extractDesign = async ({ page, cdp, mainFrameId, scopePath = null }) => {
  const raw = await page.evaluate(collectDesignInPage, { scopePath, viewports: 2 });
  if (raw?.error) return { reason: raw.error };
  const css = new CssInspector(cdp);
  try {
    await css.enable();
    const fontFaces = [];
    const texts = [];
    let totalChars = 0;
    for (const header of css.pageSheets(mainFrameId)) {
      if (totalChars > MAX_TOTAL_SHEET_CHARS) break;
      const text = await css.sheetText(header.styleSheetId);
      if (text.startsWith(CONSENT_STYLE_MARKER)) continue;
      totalChars += text.length;
      texts.push(text);
      fontFaces.push(...parseFontFaces(text, header.sourceURL || raw.url));
    }
    const renderedFonts = {};
    const pathsByKey = {
      h1: raw.typography?.h1?.path, h2: raw.typography?.h2?.path, h3: raw.typography?.h3?.path, body: raw.typography?.body?.path,
    };
    const buttons = pickButtons(raw.buttons ?? []);
    pathsByKey.button = buttons.primary?.path ?? null;
    for (const [key, path] of Object.entries(pathsByKey)) {
      const nodeId = path ? await css.nodeIdForPath(path) : null;
      if (nodeId) renderedFonts[key] = await css.platformFonts(nodeId);
    }
    const hover = {};
    for (const [key, path] of [['primaryButton', buttons.primary?.path], ['secondaryButton', buttons.secondary?.path], ['navigation', raw.navigation?.linkPath]]) {
      const nodeId = path ? await css.nodeIdForPath(path) : null;
      if (nodeId) hover[key] = await css.hoverDifferences(nodeId, HOVER_PROPERTIES).catch(() => ({}));
    }
    const tokens = buildDesignTokens(raw, { fontFaces, variableUses: countVariableUses(texts), renderedFonts, hover });
    return { reason: 'OK', tokens, summary: designSummary(tokens), raw };
  } finally {
    await css.disable();
  }
};
