// The fixed logo export that runs inside the page (isolated world, read-only). A logo is exported as it is SEEN:
//   - an inline SVG becomes self-contained: the computed fill, stroke, opacity, stop colours and fonts of every element
//     are written as attributes (so currentColor, var() and CSS classes are resolved), hidden elements are dropped,
//     same-document <use> targets are copied in;
//   - a background surface around the logo (a wrapper with its own background colour and radius, e.g. a pill) is part
//     of the visible logo: it becomes a <rect rx> behind the drawing;
//   - the PNG is drawn on a canvas from that SVG (or from the raster bytes the caller passes as a data URL), so it has a
//     transparent background and transparent rounded corners: no page pixels around it.
// Returns the SVG with the surface, the bare drawing (for dark backgrounds when the drawing is light), the surface
// colour and a PNG data URL. The function must stay self-contained (it is serialized into the page).

/**
 * @param {{path: string, rasterDataUrl?: string|null, scale?: number, withPng?: boolean}} options path: CSS path of the logo element
 */
export async function exportLogoInPage({ path, rasterDataUrl = null, scale = 2, withPng = true }) {
  const element = document.querySelector(path);
  if (!element) return { error: 'NOT_FOUND' };
  const canvasForColours = document.createElement('canvas');
  canvasForColours.width = 1;
  canvasForColours.height = 1;
  const colourContext = canvasForColours.getContext('2d', { willReadFrequently: true });
  const toColour = value => {
    if (!value || value === 'none' || value === 'transparent') return null;
    colourContext.clearRect(0, 0, 1, 1);
    colourContext.fillStyle = '#000000';
    colourContext.fillStyle = value;
    colourContext.fillRect(0, 0, 1, 1);
    const [red, green, blue, alpha] = colourContext.getImageData(0, 0, 1, 1).data;
    return { hex: '#' + [red, green, blue].map(part => part.toString(16).padStart(2, '0')).join(''), alpha: alpha / 255, luminance: (0.2126 * red + 0.7152 * green + 0.0722 * blue) / 255 };
  };

  const logoBox = element.getBoundingClientRect();
  if (logoBox.width === 0 || logoBox.height === 0) return { error: 'NOT_VISIBLE' };

  // the surface: the element itself or a close wrapper (at most 3× the logo's area) with an opaque background colour
  let surface = null;
  const candidates = [element];
  for (let current = element.parentElement, depth = 0; current && depth < 4; current = current.parentElement, depth++) candidates.push(current);
  for (const candidate of candidates) {
    const box = candidate.getBoundingClientRect();
    if (box.width * box.height > logoBox.width * logoBox.height * 3) break;
    const style = getComputedStyle(candidate);
    const colour = toColour(style.backgroundColor);
    if (colour && colour.alpha >= 0.5 && (!style.backgroundImage || style.backgroundImage === 'none')) {
      surface = { box, colour: colour.hex, radius: Math.min(parseFloat(style.borderTopLeftRadius) || 0, box.height / 2, box.width / 2) };
      break;
    }
  }
  const frame = surface ? surface.box : logoBox;
  const width = Math.round(frame.width * 100) / 100;
  const height = Math.round(frame.height * 100) / 100;
  const offsetX = Math.round((logoBox.left - frame.left) * 100) / 100;
  const offsetY = Math.round((logoBox.top - frame.top) * 100) / 100;

  let drawing = null;
  let lightDrawing = false;
  if (element.localName === 'svg') {
    const clone = element.cloneNode(true);
    const originals = [element, ...element.querySelectorAll('*')];
    const copies = [clone, ...clone.querySelectorAll('*')];
    const toRemove = [];
    const fillLuminances = [];
    const colourAttribute = value => {
      if (!value || value === 'none') return 'none';
      const reference = value.match(/url\(["']?[^"')#]*(#[^"')]+)["']?\)/);
      if (reference) return `url(${reference[1]})`;
      const colour = toColour(value);
      if (!colour) return 'none';
      return colour.alpha < 1 ? `rgba(${parseInt(colour.hex.slice(1, 3), 16)}, ${parseInt(colour.hex.slice(3, 5), 16)}, ${parseInt(colour.hex.slice(5, 7), 16)}, ${Math.round(colour.alpha * 1000) / 1000})` : colour.hex;
    };
    const SHAPES = new Set(['path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'use', 'g', 'svg', 'symbol']);
    for (let index = 0; index < originals.length; index++) {
      const original = originals[index];
      const copy = copies[index];
      if (!copy) continue;
      const style = getComputedStyle(original);
      const name = original.localName;
      if (index > 0 && !['defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'symbol', 'title', 'desc'].includes(name.toLowerCase())
        && !original.closest('defs, clipPath, mask, pattern, symbol') && (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity) === 0)) {
        toRemove.push(copy);
        continue;
      }
      if (SHAPES.has(name)) {
        const fill = colourAttribute(style.fill);
        copy.setAttribute('fill', fill);
        const stroke = colourAttribute(style.stroke);
        copy.setAttribute('stroke', stroke);
        if (stroke !== 'none') copy.setAttribute('stroke-width', style.strokeWidth);
        if (style.fillRule && style.fillRule !== 'nonzero') copy.setAttribute('fill-rule', style.fillRule);
        for (const [property, attribute] of [['opacity', 'opacity'], ['fillOpacity', 'fill-opacity'], ['strokeOpacity', 'stroke-opacity']]) {
          if (style[property] && parseFloat(style[property]) !== 1) copy.setAttribute(attribute, style[property]);
        }
        if (name === 'text' || name === 'tspan') {
          copy.setAttribute('font-family', style.fontFamily);
          copy.setAttribute('font-size', style.fontSize);
          copy.setAttribute('font-weight', style.fontWeight);
        }
        if (['path', 'rect', 'circle', 'ellipse', 'polygon', 'text'].includes(name) && fill.startsWith('#')) {
          const box = original.getBBox?.();
          fillLuminances.push({ luminance: toColour(fill).luminance, weight: box ? Math.max(1, box.width * box.height) : 1 });
        }
      }
      if (name === 'stop') {
        copy.setAttribute('stop-color', colourAttribute(style.stopColor));
        if (parseFloat(style.stopOpacity) !== 1) copy.setAttribute('stop-opacity', style.stopOpacity);
      }
      copy.removeAttribute('class');
      copy.removeAttribute('style');
    }
    for (const node of toRemove) node.remove();
    // <use href="#id"> whose target lives elsewhere in the document: copy the target into the drawing
    const missing = new Set();
    for (const use of clone.querySelectorAll('use')) {
      const reference = use.getAttribute('href') ?? use.getAttribute('xlink:href') ?? '';
      if (reference.startsWith('#') && !clone.querySelector(reference.replace(/([^\w#-])/g, '\\$1'))) missing.add(reference.slice(1));
    }
    if (missing.size) {
      const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
      for (const id of missing) {
        const target = document.getElementById(id);
        if (target) defs.appendChild(target.cloneNode(true));
      }
      clone.insertBefore(defs, clone.firstChild);
    }
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    if (!clone.getAttribute('viewBox')) clone.setAttribute('viewBox', `0 0 ${Math.round(logoBox.width * 100) / 100} ${Math.round(logoBox.height * 100) / 100}`);
    clone.setAttribute('width', String(Math.round(logoBox.width * 100) / 100));
    clone.setAttribute('height', String(Math.round(logoBox.height * 100) / 100));
    for (const attribute of ['aria-hidden', 'focusable', 'role', 'id']) clone.removeAttribute(attribute);
    drawing = new XMLSerializer().serializeToString(clone);
    const totalWeight = fillLuminances.reduce((sum, entry) => sum + entry.weight, 0);
    lightDrawing = totalWeight > 0 && fillLuminances.reduce((sum, entry) => sum + entry.luminance * entry.weight, 0) / totalWeight > 0.75;
  }

  let svg = null;
  if (drawing) {
    if (surface) {
      const nested = drawing.replace(/^<svg\b/, `<svg x="${offsetX}" y="${offsetY}"`);
      svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><rect width="${width}" height="${height}" rx="${Math.round(surface.radius * 100) / 100}" fill="${surface.colour}"/>${nested}</svg>`;
    } else {
      svg = drawing;
    }
  }

  // the PNG: surface and drawing on a transparent canvas
  let png = null;
  let pngError = null;
  const source = drawing ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(drawing)}` : rasterDataUrl;
  if (source && withPng) {
    try {
      const canvas = document.createElement('canvas');
      canvas.width = Math.ceil(width * scale);
      canvas.height = Math.ceil(height * scale);
      const context = canvas.getContext('2d');
      context.scale(scale, scale);
      if (surface) {
        context.fillStyle = surface.colour;
        context.beginPath();
        context.roundRect(0, 0, width, height, surface.radius);
        context.fill();
      }
      const image = new Image();
      image.src = source;
      await image.decode();
      context.drawImage(image, offsetX, offsetY, logoBox.width, logoBox.height);
      png = canvas.toDataURL('image/png');
    } catch (error) {
      pngError = String(error?.message ?? error);
    }
  }

  return {
    svg, svgOnDark: surface && lightDrawing ? drawing : null, background: surface?.colour ?? null, radius: surface?.radius ?? null,
    lightDrawing, png, pngError, width, height, logoWidth: logoBox.width, logoHeight: logoBox.height,
  };
}
