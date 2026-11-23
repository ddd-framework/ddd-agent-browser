// Compact views of a page's accessibility snapshot (pure functions, unit-tested). The renderer takes Playwright's
// AI snapshot (`page.ariaSnapshotJSON({mode: 'ai', boxes: true})`: roles, names, refs, viewport boxes, iframes
// included) and never hands the whole tree out. It is cut here into:
//   outline:     landmarks with their counts and headings (what the page is made of)
//   interactive: links, buttons and fields with refs, the ones in the viewport first
//   text:        a region as markdown
// Every view is paged: at most `maxChars` characters, and a cursor for the next page.

export const LANDMARK_ROLES = new Set(['banner', 'navigation', 'main', 'contentinfo', 'complementary', 'region', 'form', 'search', 'dialog', 'alertdialog']);
export const INTERACTIVE_ROLES = new Set(['link', 'button', 'textbox', 'searchbox', 'combobox', 'checkbox', 'radio', 'switch', 'slider', 'spinbutton', 'tab', 'menuitem', 'menuitemcheckbox', 'menuitemradio', 'listbox', 'treeitem']);
const FIELD_ROLES = new Set(['textbox', 'searchbox', 'combobox', 'checkbox', 'radio', 'switch', 'slider', 'spinbutton', 'listbox']);
const MAX_NAME_CHARS = 80;
const MAX_OUTLINE_HEADINGS = 40;
export const DEFAULT_MAX_CHARS = 6000;

const clip = (text, limit = MAX_NAME_CHARS) => {
  const value = String(text ?? '').replace(/\s+/g, ' ').trim();
  return value.length > limit ? value.slice(0, limit - 1) + '…' : value;
};

/**
 * @param {object|string} node
 * @returns {string} The text of a node and its descendants (for links without a name: the image inside)
 */
export const textOf = node => {
  if (typeof node === 'string') return node;
  if (!node) return '';
  const own = [node.name, node.text].filter(Boolean).join(' ');
  if (own) return own;
  return (node.children ?? []).map(textOf).filter(Boolean).join(' ');
};

/**
 * @param {object[]|object} tree The snapshot JSON (a list of root nodes)
 * @param {(node: object, ancestors: object[]) => void} visit
 */
export const walk = (tree, visit) => {
  const roots = Array.isArray(tree) ? tree : [tree];
  const visitNode = (node, ancestors) => {
    if (!node || typeof node !== 'object') return;
    visit(node, ancestors);
    const nextAncestors = [...ancestors, node];
    for (const child of node.children ?? []) visitNode(child, nextAncestors);
  };
  for (const root of roots) visitNode(root, []);
};

/**
 * @param {object[]} tree
 * @returns {Set<string>} Every ref in the snapshot
 */
export const collectRefs = tree => {
  const refs = new Set();
  walk(tree, node => { if (node.ref) refs.add(node.ref); });
  return refs;
};

/**
 * @param {object[]} tree
 * @param {string} ref
 * @returns {object|null} The node with this ref
 */
export const findByRef = (tree, ref) => {
  let found = null;
  walk(tree, node => { if (!found && node.ref === ref) found = node; });
  return found;
};

/**
 * @param {string} url A link target as the snapshot shows it
 * @param {string} pageUrl
 * @returns {string} Same-origin links as their path, others in full
 */
export const shortUrl = (url, pageUrl) => {
  if (!url) return '';
  try {
    const target = new URL(url, pageUrl);
    const page = new URL(pageUrl);
    const short = target.origin === page.origin ? target.pathname + target.search + target.hash : target.href;
    return clip(short, 120);
  } catch {
    return clip(url, 120);
  }
};

/**
 * @param {object} node
 * @param {string} pageUrl
 * @returns {string} One line for an element: role "name" [ref] state → link
 */
export const describe = (node, pageUrl) => {
  const name = clip(node.name || (node.role === 'link' || node.role === 'button' ? textOf(node) : ''));
  const parts = [node.role];
  if (node.role === 'heading' && node.level) parts[0] = `heading ${node.level}`;
  if (name) parts.push(JSON.stringify(name));
  if (node.ref) parts.push(`[ref=${node.ref}]`);
  for (const flag of ['checked', 'selected', 'expanded', 'pressed', 'disabled', 'invalid']) {
    if (node[flag] === true) parts.push(`[${flag}]`);
    else if (node[flag] === 'mixed') parts.push(`[${flag}=mixed]`);
  }
  if (FIELD_ROLES.has(node.role)) {
    if (node.placeholder) parts.push(`placeholder=${JSON.stringify(clip(node.placeholder, 40))}`);
    if (node.text && (node.role === 'textbox' || node.role === 'searchbox' || node.role === 'spinbutton')) parts.push(`value=${JSON.stringify(clip(node.text, 60))}`);
    if (node.role === 'combobox' || node.role === 'listbox') {
      const options = (node.children ?? []).filter(child => child && child.role === 'option');
      const selected = options.filter(option => option.selected).map(option => clip(option.name, 30));
      if (options.length) parts.push(`options=${options.length}${selected.length ? ` selected=${JSON.stringify(selected.join(', '))}` : ''}`);
    }
  }
  if (node.url) parts.push(`→ ${shortUrl(node.url, pageUrl)}`);
  return parts.join(' ');
};

const isInViewport = (node, viewportHeight) => {
  const box = node.box;
  if (!box || box.width <= 0 || box.height <= 0) return false;
  return box.y + box.height > 0 && box.y < viewportHeight;
};

/**
 * Lines up to maxChars from a cursor (a line index), with a note when more follow.
 *
 * @param {string[]} header Lines always shown first (not paged)
 * @param {string[]} lines
 * @param {number} cursor
 * @param {number} maxChars
 * @returns {{text: string, nextCursor: number|null, shown: number, total: number}}
 */
export const pageLines = (header, lines, cursor, maxChars) => {
  const out = [...header];
  let used = header.reduce((sum, line) => sum + line.length + 1, 0);
  const reserve = 80;
  let index = Math.max(0, cursor);
  for (; index < lines.length; index++) {
    const line = lines[index];
    if (used + line.length + 1 > maxChars - reserve && index > cursor) break;
    const shown = used + line.length + 1 > maxChars - reserve ? line.slice(0, Math.max(0, maxChars - reserve - used - 2)) + '…' : line;
    out.push(shown);
    used += shown.length + 1;
  }
  const nextCursor = index < lines.length ? index : null;
  if (nextCursor !== null) out.push(`… ${lines.length - index} more lines (cursor=${nextCursor})`);
  return { text: out.join('\n'), nextCursor, shown: index - Math.max(0, cursor), total: lines.length };
};

const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;

const matchesFind = (find, ...values) => !find || values.some(value => String(value ?? '').toLowerCase().includes(find));

/**
 * @param {object[]} tree
 * @param {{url: string, title: string, viewportHeight: number, hiddenTextChars?: number, cursor?: number, maxChars?: number, find?: string}} page
 */
export const outlineView = (tree, { url, title, hiddenTextChars = 0, cursor = 0, maxChars = DEFAULT_MAX_CHARS, find = '' }) => {
  const lowerFind = find.toLowerCase();
  const totals = { link: 0, button: 0, field: 0, heading: 0 };
  const count = (counts, node) => {
    if (node.role === 'link') counts.link++;
    else if (node.role === 'button') counts.button++;
    else if (FIELD_ROLES.has(node.role)) counts.field++;
    else if (node.role === 'heading') counts.heading++;
  };
  const lines = [];
  let headingsListed = 0;
  const landmarkCounts = new Map();
  walk(tree, (node, ancestors) => {
    count(totals, node);
    const landmark = [...ancestors].reverse().find(ancestor => LANDMARK_ROLES.has(ancestor.role));
    if (landmark) {
      const counts = landmarkCounts.get(landmark) ?? { link: 0, button: 0, field: 0, heading: 0 };
      count(counts, node);
      landmarkCounts.set(landmark, counts);
    }
  });
  walk(tree, (node, ancestors) => {
    const depth = ancestors.filter(ancestor => LANDMARK_ROLES.has(ancestor.role)).length;
    if (LANDMARK_ROLES.has(node.role)) {
      const counts = landmarkCounts.get(node) ?? { link: 0, button: 0, field: 0, heading: 0 };
      const summary = Object.entries(counts).filter(([, value]) => value > 0).map(([kind, value]) => `${value} ${kind}${value === 1 ? '' : 's'}`).join(', ');
      const line = `${'  '.repeat(depth)}- ${describe(node, url)}${summary ? `: ${summary}` : ''}`;
      if (matchesFind(lowerFind, node.name, node.role)) lines.push(line);
      return;
    }
    if (node.role === 'heading' && (node.level ?? 1) <= 3 && headingsListed < MAX_OUTLINE_HEADINGS) {
      if (!matchesFind(lowerFind, node.name, textOf(node))) return;
      headingsListed++;
      lines.push(`${'  '.repeat(depth)}- ${describe({ ...node, name: node.name || textOf(node) }, url)}`);
    }
  });
  const header = [
    `Page: ${clip(title, 120)} — ${clip(url, 200)}`,
    `Totals: ${plural(totals.link, 'link')}, ${plural(totals.button, 'button')}, ${plural(totals.field, 'field')}, ${plural(totals.heading, 'heading')}${hiddenTextChars > 0 ? `; hidden text: ${hiddenTextChars.toLocaleString('en-US')} characters (left out)` : ''}`,
  ];
  if (lines.length === 0) lines.push(lowerFind ? `(nothing matches "${find}")` : '(no landmarks or headings)');
  return { mode: 'outline', ...pageLines(header, lines, cursor, maxChars) };
};

/**
 * @param {object[]} tree
 * @param {{url: string, viewportHeight: number, cursor?: number, maxChars?: number, find?: string, onlyInView?: boolean}} page
 */
export const interactiveView = (tree, { url, viewportHeight, cursor = 0, maxChars = DEFAULT_MAX_CHARS, find = '', onlyInView = false }) => {
  const lowerFind = find.toLowerCase();
  const inView = [];
  const outOfView = [];
  walk(tree, node => {
    if (!INTERACTIVE_ROLES.has(node.role) || !node.ref) return;
    if (!matchesFind(lowerFind, node.name, textOf(node), node.url, node.placeholder)) return;
    (isInViewport(node, viewportHeight) ? inView : outOfView).push(node);
  });
  const lines = [];
  if (inView.length) lines.push(`In view (${inView.length}):`, ...inView.map(node => `- ${describe(node, url)}`));
  if (!onlyInView && outOfView.length) lines.push(`Outside the view (${outOfView.length}):`, ...outOfView.map(node => `- ${describe(node, url)}`));
  if (lines.length === 0) lines.push(lowerFind ? `(no element matches "${find}")` : '(no interactive elements)');
  return { mode: 'interactive', items: inView.length + (onlyInView ? 0 : outOfView.length), ...pageLines([], lines, cursor, maxChars) };
};

/**
 * @param {object|string} node
 * @param {string} pageUrl
 * @param {string[]} out
 */
const toMarkdown = (node, pageUrl, out) => {
  if (typeof node === 'string') {
    if (node.trim()) out.push(node.trim());
    return;
  }
  if (!node) return;
  const role = node.role;
  if (role === 'heading') {
    out.push(`${'#'.repeat(Math.min(6, node.level ?? 2))} ${clip(node.name || textOf(node), 200)}`);
    return;
  }
  if (role === 'link') {
    out.push(`[${clip(textOf(node), 120) || 'link'}](${shortUrl(node.url, pageUrl)})`);
    return;
  }
  if (role === 'button') {
    out.push(`[button: ${clip(textOf(node), 80)}]`);
    return;
  }
  if (role === 'img') {
    if (node.name) out.push(`![${clip(node.name, 120)}]`);
    return;
  }
  if (FIELD_ROLES.has(role)) {
    out.push(`[${role}: ${clip(node.name, 60)}${node.text ? ` = ${clip(node.text, 60)}` : ''}]`);
    return;
  }
  if (role === 'listitem') {
    const inner = [];
    if (node.text) inner.push(node.text);
    for (const child of node.children ?? []) toMarkdown(child, pageUrl, inner);
    out.push(`- ${inner.join(' ').replace(/\s+/g, ' ').trim()}`);
    return;
  }
  if (role === 'paragraph' || role === 'cell' || role === 'blockquote') {
    const inner = [];
    if (node.text) inner.push(node.text);
    for (const child of node.children ?? []) toMarkdown(child, pageUrl, inner);
    const text = inner.join(' ').replace(/\s+/g, ' ').trim();
    if (text) out.push(text);
    return;
  }
  if (node.text && !(node.children ?? []).length) out.push(String(node.text).trim());
  for (const child of node.children ?? []) toMarkdown(child, pageUrl, out);
};

/**
 * @param {object[]|object} root The region (a node) or the whole tree
 * @param {{url: string, cursor?: number, maxChars?: number, find?: string}} page
 */
export const textView = (root, { url, cursor = 0, maxChars = DEFAULT_MAX_CHARS, find = '' }) => {
  const lines = [];
  for (const node of Array.isArray(root) ? root : [root]) toMarkdown(node, url, lines);
  const lowerFind = find.toLowerCase();
  const filtered = lowerFind ? lines.filter(line => line.toLowerCase().includes(lowerFind)) : lines;
  if (filtered.length === 0) filtered.push(lowerFind ? `(no text matches "${find}")` : '(no text)');
  return { mode: 'text', ...pageLines([], filtered, cursor, maxChars) };
};

/**
 * Every element as one line, for the "what changed" answer of an action.
 *
 * @param {object[]} tree
 * @param {string} url
 * @returns {string[]}
 */
export const flattenLines = (tree, url) => {
  const lines = [];
  walk(tree, node => {
    if (node.role === 'generic' && !node.name && !node.text) return;
    const text = node.text && !node.name ? `: ${clip(node.text, 100)}` : '';
    lines.push(describe(node, url) + text);
  });
  return lines;
};

/**
 * @param {string[]} before
 * @param {string[]} after
 * @param {number} maxChars
 * @returns {string} The lines of `after` that were not there before, at most maxChars
 */
export const changedLines = (before, after, maxChars = 1500) => {
  const remaining = new Map();
  for (const line of before) remaining.set(line, (remaining.get(line) ?? 0) + 1);
  const added = [];
  for (const line of after) {
    const left = remaining.get(line) ?? 0;
    if (left > 0) remaining.set(line, left - 1);
    else added.push(`- ${line}`);
  }
  if (added.length === 0) return '';
  let text = '';
  for (let index = 0; index < added.length; index++) {
    if (text.length + added[index].length + 1 > maxChars - 40) {
      text += `… ${added.length - index} more changed lines`;
      break;
    }
    text += added[index] + '\n';
  }
  return text.trimEnd();
};
