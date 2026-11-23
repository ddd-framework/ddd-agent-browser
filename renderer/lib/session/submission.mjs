// The submission rule of a session (pure functions, unit-tested). Text the model typed leaves the browser only with the
// operator's yes:
//   - every non-GET navigation of any frame (a form post) is aborted;
//   - every other request whose URL or body carries typed text is aborted, except a GET to the page's own site (a
//     search box and its suggestions);
//   - a script request (fetch, XHR, beacon) that carries typed text to another site is dropped without a release.
// An aborted submission is reported with its target, field names and values, and a one-time release token for its
// signature (method, origin, path, field names, typed values). Matching typed text in a request is best effort: a
// page can encode it in ways this does not see, so the caller's origin check of every typed text is the control.
import crypto from 'node:crypto';
import { getDomain } from 'tldts-experimental';

/** Typed texts shorter than this are not searched for in requests (they would match everywhere). */
export const MIN_TYPED_LENGTH = 3;
const MAX_FIELD_VALUE_CHARS = 200;
const MAX_FIELDS = 40;

/**
 * @param {string} text
 * @returns {string[]} The lowercase forms the text takes in URLs and bodies
 */
export const encodingsOf = text => {
  const forms = new Set();
  const add = value => { if (value) forms.add(value.toLowerCase()); };
  add(text);
  add(encodeURIComponent(text));
  add(encodeURIComponent(text).replace(/%20/g, '+'));
  add(text.replace(/ /g, '+'));
  add(JSON.stringify(text).slice(1, -1));
  add(encodeURIComponent(encodeURIComponent(text)));
  add(Buffer.from(text, 'utf8').toString('base64').replace(/=+$/, ''));
  return [...forms];
};

/**
 * @param {string[]} haystacks URL and body of a request
 * @param {Iterable<string>} typedTexts The texts typed in this session
 * @returns {string[]} The typed texts that appear in the request
 */
export const findTypedTexts = (haystacks, typedTexts) => {
  const lowerHaystacks = haystacks.filter(Boolean).map(value => value.toLowerCase());
  if (lowerHaystacks.length === 0) return [];
  const found = [];
  for (const typed of typedTexts) {
    if (typeof typed !== 'string' || typed.length < MIN_TYPED_LENGTH) continue;
    if (encodingsOf(typed).some(form => lowerHaystacks.some(haystack => haystack.includes(form)))) found.push(typed);
  }
  return found;
};

const truncate = value => {
  const text = String(value ?? '');
  return text.length > MAX_FIELD_VALUE_CHARS ? text.slice(0, MAX_FIELD_VALUE_CHARS) + '…' : text;
};

/**
 * @param {string|null} body The request body as text
 * @param {string|null} contentType
 * @returns {{name: string, value: string}[]} The fields of a form or JSON body (first level), at most 40
 */
export const parseFields = (body, contentType) => {
  if (!body) return [];
  const type = (contentType ?? '').toLowerCase();
  const fields = [];
  const push = (name, value) => { if (fields.length < MAX_FIELDS) fields.push({ name: truncate(name), value: truncate(typeof value === 'object' ? JSON.stringify(value) : value) }); };
  if (type.includes('multipart/form-data')) {
    for (const match of body.matchAll(/name="([^"]*)"(?:; filename="[^"]*")?\r?\n(?:[^\r\n]+\r?\n)*\r?\n([\s\S]*?)\r?\n--/g)) push(match[1], match[2]);
    return fields;
  }
  const trimmed = body.trim();
  if (type.includes('json') || (!type.includes('urlencoded') && /^[[{]/.test(trimmed))) {
    try {
      const parsed = JSON.parse(trimmed);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        for (const [name, value] of Object.entries(parsed)) push(name, value);
        return fields;
      }
    } catch {
      // not JSON after all: try the form encoding below
    }
  }
  if (type.includes('urlencoded') || /^[^=&\s]+=[^&]*(&[^=&\s]+=[^&]*)*$/.test(trimmed)) {
    for (const [name, value] of new URLSearchParams(trimmed)) push(name, value);
  }
  return fields;
};

/**
 * @param {string} url
 * @returns {string|null} The registrable domain (eTLD+1) of a URL, the host for IPs and single labels
 */
export const siteOf = url => {
  try {
    const { hostname } = new URL(url);
    return getDomain(hostname) ?? hostname;
  } catch {
    return null;
  }
};

/**
 * @param {string} url
 * @returns {string} origin + path (no query, no fragment): what a release names as the target
 */
export const targetOf = url => {
  try {
    const parsed = new URL(url);
    return parsed.origin + parsed.pathname;
  } catch {
    return String(url).slice(0, 300);
  }
};

/**
 * @param {{method: string, url: string, fields: {name: string}[], typed: string[]}} request
 * @returns {string} The signature a release token is bound to
 */
export const submissionSignature = ({ method, url, fields, typed }) => crypto.createHash('sha256')
  .update(JSON.stringify([
    method.toUpperCase(),
    targetOf(url),
    [...new Set(fields.map(field => field.name))].sort(),
    [...new Set(typed)].sort(),
  ]))
  .digest('hex');

/**
 * @param {{method: string, url: string, isNavigation: boolean, body: string|null, bodyUnreadable?: boolean,
 *   contentType: string|null, topUrl: string, typedTexts: Iterable<string>}} request
 * @returns {{kind: 'allow'|'submission'|'dropped', typed: string[], fields: {name: string, value: string, typed: boolean}[]}}
 *   submission: aborted, a release may let it through once; dropped: aborted, never released
 */
export const judgeRequest = ({ method, url, isNavigation, body, bodyUnreadable = false, contentType, topUrl, typedTexts }) => {
  const upperMethod = method.toUpperCase();
  const typedList = [...typedTexts];
  let typed = findTypedTexts([safeDecode(url), url, body ?? ''], typedList);
  // a body the browser does not hand out (large or streamed) may carry typed text: with typed text in the session it
  // counts as carrying it
  if (bodyUnreadable && typed.length === 0 && upperMethod !== 'GET') typed = typedList.filter(text => text.length >= MIN_TYPED_LENGTH);
  const fieldsWithoutFlag = upperMethod === 'GET' ? queryFields(url) : parseFields(body, contentType);
  const fields = fieldsWithoutFlag.map(field => ({ ...field, typed: typed.some(text => field.value.toLowerCase().includes(text.toLowerCase())) }));
  if (isNavigation && upperMethod !== 'GET') return { kind: 'submission', typed, fields };
  if (typed.length === 0) return { kind: 'allow', typed, fields };
  const sameSite = siteOf(url) !== null && siteOf(url) === siteOf(topUrl);
  if (upperMethod === 'GET' && sameSite) return { kind: 'allow', typed, fields };
  if (!isNavigation && !sameSite) return { kind: 'dropped', typed, fields };
  return { kind: 'submission', typed, fields };
};

const safeDecode = url => {
  try {
    return decodeURIComponent(url.replace(/\+/g, ' '));
  } catch {
    return url;
  }
};

const queryFields = url => {
  try {
    return [...new URL(url).searchParams].slice(0, MAX_FIELDS).map(([name, value]) => ({ name: truncate(name), value: truncate(value) }));
  } catch {
    return [];
  }
};
