// Colour arithmetic for the design tokens (pure).

/**
 * @param {string} hex #rrggbb
 * @returns {[number, number, number]}
 */
export const hexToRgb = hex => {
  const value = hex.replace('#', '');
  return [0, 2, 4].map(offset => Number.parseInt(value.slice(offset, offset + 2), 16));
};

/**
 * @param {string} hex
 * @returns {number} HSL saturation 0–1
 */
export const saturation = hex => {
  const [red, green, blue] = hexToRgb(hex).map(channel => channel / 255);
  const max = Math.max(red, green, blue);
  const min = Math.min(red, green, blue);
  const lightness = (max + min) / 2;
  if (max === min) return 0;
  const delta = max - min;
  return lightness > 0.5 ? delta / (2 - max - min) : delta / (max + min);
};

/**
 * @param {string} hex
 * @returns {number} HSL lightness 0–1
 */
export const lightness = hex => {
  const channels = hexToRgb(hex).map(channel => channel / 255);
  return (Math.max(...channels) + Math.min(...channels)) / 2;
};

/**
 * @param {string} hex
 * @returns {boolean} Saturated and neither near black nor near white: a colour that can carry a brand
 */
export const isBrandLike = hex => saturation(hex) >= 0.25 && lightness(hex) >= 0.18 && lightness(hex) <= 0.88;

/**
 * @param {string} hex
 * @returns {number} WCAG relative luminance
 */
export const luminance = hex => {
  const [red, green, blue] = hexToRgb(hex).map(channel => {
    const value = channel / 255;
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
};

/**
 * @param {string} foreground
 * @param {string} background
 * @returns {number} WCAG contrast ratio, two decimals
 */
export const contrastRatio = (foreground, background) => {
  const lighter = Math.max(luminance(foreground), luminance(background));
  const darker = Math.min(luminance(foreground), luminance(background));
  return Math.round(((lighter + 0.05) / (darker + 0.05)) * 100) / 100;
};

/**
 * @param {number} ratio
 * @returns {string} AAA, AA, AA large or fail (normal text thresholds)
 */
export const contrastGrade = ratio => (ratio >= 7 ? 'AAA' : ratio >= 4.5 ? 'AA' : ratio >= 3 ? 'AA large only' : 'fail');

/**
 * @param {string} first
 * @param {string} second
 * @returns {number} Euclidean distance in RGB (0–441)
 */
export const colourDistance = (first, second) => {
  const [r1, g1, b1] = hexToRgb(first);
  const [r2, g2, b2] = hexToRgb(second);
  return Math.sqrt((r1 - r2) ** 2 + (g1 - g2) ** 2 + (b1 - b2) ** 2);
};

/**
 * Merges near-identical colours (anti-aliasing, 1-step differences) into the heavier one.
 *
 * @param {Record<string, number>} weights hex → weight
 * @param {number} threshold
 * @returns {{hex: string, weight: number}[]} Heaviest first
 */
export const mergeSimilar = (weights, threshold = 12) => {
  const sorted = Object.entries(weights).filter(([hex]) => /^#[0-9a-f]{6}$/.test(hex)).sort((first, second) => second[1] - first[1]);
  const merged = [];
  for (const [hex, weight] of sorted) {
    const near = merged.find(entry => colourDistance(entry.hex, hex) <= threshold);
    if (near) near.weight += weight;
    else merged.push({ hex, weight });
  }
  return merged.sort((first, second) => second.weight - first.weight);
};
