// inspect(ref): one element's computed styles without the defaults, its box model, and for every reported value the
// CSS rule that sets it (CSS.getMatchedStylesForNode): selector, stylesheet, line and the custom property it reads.
import { CssInspector } from './css.mjs';

/** The properties a designer asks about, with their initial values (a value equal to it is a default and left out). */
export const INSPECTED_PROPERTIES = {
  'color': null, 'background-color': 'rgba(0, 0, 0, 0)', 'background-image': 'none', 'opacity': '1',
  'font-family': null, 'font-size': null, 'font-weight': '400', 'font-style': 'normal', 'line-height': 'normal', 'letter-spacing': 'normal',
  'text-transform': 'none', 'text-decoration-line': 'none', 'text-align': 'start', 'white-space': 'normal',
  'display': null, 'position': 'static', 'top': 'auto', 'right': 'auto', 'bottom': 'auto', 'left': 'auto', 'z-index': 'auto',
  'width': null, 'height': null, 'min-width': 'auto', 'max-width': 'none', 'min-height': 'auto', 'max-height': 'none',
  'margin-top': '0px', 'margin-right': '0px', 'margin-bottom': '0px', 'margin-left': '0px',
  'padding-top': '0px', 'padding-right': '0px', 'padding-bottom': '0px', 'padding-left': '0px',
  'border-top-width': '0px', 'border-right-width': '0px', 'border-bottom-width': '0px', 'border-left-width': '0px',
  'border-top-style': 'none', 'border-top-color': null,
  'border-top-left-radius': '0px', 'border-top-right-radius': '0px', 'border-bottom-right-radius': '0px', 'border-bottom-left-radius': '0px',
  'box-shadow': 'none', 'outline-style': 'none',
  'flex-direction': 'row', 'flex-wrap': 'nowrap', 'justify-content': 'normal', 'align-items': 'normal', 'gap': 'normal', 'row-gap': 'normal', 'column-gap': 'normal',
  'grid-template-columns': 'none', 'grid-template-rows': 'none',
  'transform': 'none', 'transition': 'all', 'cursor': 'auto', 'overflow-x': 'visible', 'overflow-y': 'visible',
};
const INHERITED = new Set(['color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform', 'text-align', 'white-space', 'cursor']);

/**
 * @param {string} property A longhand, e.g. padding-top
 * @param {string} declared A declared name, e.g. padding
 * @returns {boolean} Whether the declaration sets the property
 */
export const declarationSets = (property, declared) => {
  if (declared === property) return true;
  if (property.startsWith(declared + '-')) return true;
  const shorthands = {
    'background-color': ['background'], 'background-image': ['background'], 'font-family': ['font'], 'font-size': ['font'], 'font-weight': ['font'],
    'line-height': ['font'], 'font-style': ['font'], 'border-top-left-radius': ['border-radius'], 'border-top-right-radius': ['border-radius'],
    'border-bottom-right-radius': ['border-radius'], 'border-bottom-left-radius': ['border-radius'], 'text-decoration-line': ['text-decoration'],
    'row-gap': ['gap'], 'column-gap': ['gap'], 'flex-direction': ['flex-flow'], 'flex-wrap': ['flex-flow'],
    'border-top-width': ['border', 'border-width', 'border-top'], 'border-right-width': ['border', 'border-width', 'border-right'],
    'border-bottom-width': ['border', 'border-width', 'border-bottom'], 'border-left-width': ['border', 'border-width', 'border-left'],
    'border-top-style': ['border', 'border-style', 'border-top'], 'border-top-color': ['border', 'border-color', 'border-top'],
    'overflow-x': ['overflow'], 'overflow-y': ['overflow'],
  };
  return (shorthands[property] ?? []).includes(declared);
};

/**
 * The declaration that wins for a property among matched rules (author origin, cascade order as CDP lists it: later
 * wins, !important beats normal).
 *
 * @param {object[]} matchedRules CDP RuleMatch[]
 * @param {object|null} inlineStyle CDP CSSStyle
 * @param {string} property
 * @returns {{selector: string, styleSheetId: string|null, line: number|null, declared: string, important: boolean}|null}
 */
export const winningDeclaration = (matchedRules, inlineStyle, property) => {
  let winner = null;
  const consider = (candidate) => {
    if (!winner || candidate.important || !winner.important) winner = candidate;
  };
  for (const match of matchedRules ?? []) {
    const rule = match.rule;
    if (rule.origin !== 'regular') continue;
    for (const declaration of rule.style?.cssProperties ?? []) {
      if (declaration.disabled || declaration.parsedOk === false || declaration.implicit || !declaration.value) continue;
      if (!declarationSets(property, declaration.name)) continue;
      consider({ selector: rule.selectorList?.text ?? '', styleSheetId: rule.styleSheetId ?? null, line: rule.style?.range ? rule.style.range.startLine + 1 : null, declared: `${declaration.name}: ${declaration.value}`, important: Boolean(declaration.important) });
    }
  }
  for (const declaration of inlineStyle?.cssProperties ?? []) {
    if (declaration.disabled || declaration.implicit || !declaration.value || !declarationSets(property, declaration.name)) continue;
    consider({ selector: 'style attribute', styleSheetId: null, line: null, declared: `${declaration.name}: ${declaration.value}`, important: Boolean(declaration.important) });
  }
  return winner;
};

/**
 * @param {{page: import('playwright-core').Page, cdp: import('playwright-core').CDPSession, locator: import('playwright-core').Locator, properties?: string[]}} target
 * @returns {Promise<object>}
 */
export const inspectElement = async ({ cdp, locator, properties = [] }) => {
  const element = await locator.evaluate(node => {
    if (window !== window.top) return { error: 'FRAME' };
    const parts = [];
    let current = node;
    while (current && current.nodeType === 1 && current !== document.documentElement) {
      const parent = current.parentElement;
      if (!parent) break;
      parts.unshift(`${current.localName}:nth-child(${Array.prototype.indexOf.call(parent.children, current) + 1})`);
      current = parent;
    }
    parts.unshift('html');
    const rect = node.getBoundingClientRect();
    const describeNode = target => target.localName + (target.id ? `#${target.id}` : '') + (typeof target.className === 'string' && target.className.trim() ? '.' + target.className.trim().split(/\s+/).slice(0, 3).join('.') : '');
    const ancestors = [];
    for (let parent = node.parentElement; parent && ancestors.length < 6; parent = parent.parentElement) ancestors.push(describeNode(parent));
    return { path: parts.join(' > '), label: describeNode(node), text: (node.innerText ?? '').replace(/\s+/g, ' ').trim().slice(0, 80), rect: { x: Math.round(rect.x), y: Math.round(rect.y + window.scrollY), width: Math.round(rect.width), height: Math.round(rect.height) }, ancestors };
  });
  if (element.error) return { reason: 'UNSUPPORTED_FRAME' };
  const css = new CssInspector(cdp);
  try {
    await css.enable();
    const nodeId = await css.nodeIdForPath(element.path);
    if (!nodeId) return { reason: 'STALE_REF' };
    const computed = await css.computed(nodeId);
    const matched = await css.matched(nodeId);
    const wanted = properties.length ? properties.filter(name => name in computed) : Object.keys(INSPECTED_PROPERTIES);
    const styles = {};
    const rules = [];
    for (const property of wanted) {
      const value = computed[property];
      if (value === undefined) continue;
      if (!properties.length && INSPECTED_PROPERTIES[property] !== null && value === INSPECTED_PROPERTIES[property]) continue;
      styles[property] = value;
      let winner = winningDeclaration(matched.matchedCSSRules, matched.inlineStyle, property);
      let inheritedFrom = null;
      if (!winner && INHERITED.has(property)) {
        for (const [index, entry] of (matched.inherited ?? []).entries()) {
          winner = winningDeclaration(entry.matchedCSSRules, entry.inlineStyle, property);
          if (winner) {
            inheritedFrom = element.ancestors[index] ?? `ancestor ${index + 1}`;
            break;
          }
        }
      }
      if (!winner) continue;
      const variable = winner.declared.match(/var\(\s*(--[\w-]+)/)?.[1] ?? null;
      rules.push({ property, value, selector: winner.selector, stylesheet: winner.styleSheetId ? css.sheetName(winner.styleSheetId) : 'style attribute', line: winner.line, declared: winner.declared.slice(0, 160), variable, variableValue: variable ? (computed[variable] ?? null) : null, inheritedFrom, important: winner.important });
    }
    const box = await cdp.send('DOM.getBoxModel', { nodeId }).then(result => result.model).catch(() => null);
    const boxModel = {
      width: box?.width ?? element.rect.width, height: box?.height ?? element.rect.height, x: element.rect.x, y: element.rect.y,
      margin: ['top', 'right', 'bottom', 'left'].map(side => computed[`margin-${side}`]).join(' '),
      border: ['top', 'right', 'bottom', 'left'].map(side => computed[`border-${side}-width`]).join(' '),
      padding: ['top', 'right', 'bottom', 'left'].map(side => computed[`padding-${side}`]).join(' '),
      boxSizing: computed['box-sizing'],
    };
    return { reason: 'OK', element: { label: element.label, text: element.text, ancestors: element.ancestors }, styles, box: boxModel, rules, summary: inspectSummary(element, styles, boxModel, rules) };
  } finally {
    await css.disable();
  }
};

/**
 * @returns {string} Compact markdown
 */
export const inspectSummary = (element, styles, box, rules, maxChars = 5000) => {
  const parts = [`## ${element.label}${element.text ? ` "${element.text.slice(0, 60)}"` : ''}`, `inside ${element.ancestors.slice(0, 3).join(' < ')}`, '', `Box: ${Math.round(box.width)}×${Math.round(box.height)} at ${box.x},${box.y}; margin ${box.margin}; border ${box.border}; padding ${box.padding}; ${box.boxSizing}`, '', '### Styles (defaults left out)'];
  const ruleByProperty = new Map(rules.map(rule => [rule.property, rule]));
  for (const [property, value] of Object.entries(styles)) {
    const rule = ruleByProperty.get(property);
    const origin = rule ? ` ← \`${rule.selector.slice(0, 80)}\`${rule.variable ? ` via ${rule.variable}` : ''}${rule.inheritedFrom ? ` (inherited from ${rule.inheritedFrom})` : ''} in ${shortSheet(rule.stylesheet)}${rule.line ? `:${rule.line}` : ''}` : '';
    parts.push(`- ${property}: ${value.slice(0, 120)}${origin}`);
  }
  let text = parts.join('\n');
  if (text.length > maxChars) text = text.slice(0, maxChars - 40).replace(/\n[^\n]*$/, '') + '\n… (cut)';
  return text;
};

const shortSheet = name => {
  try {
    const url = new URL(name);
    return url.pathname.split('/').filter(Boolean).pop() ?? url.host;
  } catch {
    return name.slice(0, 60);
  }
};
