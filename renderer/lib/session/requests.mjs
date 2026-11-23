// Validation of the session routes' bodies: every field has a type, a range or a fixed set of values. Nothing a caller
// sends reaches the page as code: actions are enums, elements are refs from the last snapshot.
import { ASSET_KINDS } from '../analysis/assets.mjs';

export const SESSION_ACTIONS = ['open', 'navigate', 'snapshot', 'act', 'screenshot', 'tabs', 'analyse', 'log', 'close'];
export const ACT_KINDS = ['click', 'type', 'select', 'press', 'scroll'];
export const KEYS = ['Enter', 'Tab', 'Shift+Tab', 'Escape', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'PageUp', 'PageDown', 'Home', 'End', 'Backspace', 'Delete', 'Space'];
export const MAX_TYPED_CHARS = 200;

const badRequest = message => Object.assign(new Error(message), { statusCode: 400 });

const choice = (body, field, fallback, choices) => {
  const value = body[field] ?? fallback;
  if (!choices.includes(value)) throw badRequest(`${field} must be one of ${choices.join(', ')}`);
  return value;
};
const integer = (body, field, fallback, minimum, maximum) => {
  const value = body[field] ?? fallback;
  if (value === null) return null;
  if (!Number.isInteger(value) || value < minimum || value > maximum) throw badRequest(`${field} must be a whole number between ${minimum} and ${maximum}`);
  return value;
};
const bool = (body, field, fallback) => {
  const value = body[field] ?? fallback;
  if (typeof value !== 'boolean') throw badRequest(`${field} must be true or false`);
  return value;
};
const optionalString = (body, field, maximum, pattern = null) => {
  const value = body[field];
  if (value === undefined || value === null || value === '') return null;
  if (typeof value !== 'string' || value.length > maximum || (pattern && !pattern.test(value))) throw badRequest(`${field} is not valid`);
  return value;
};

const SESSION_KEY = /^[0-9a-f]{32}$/;
const REF = /^(f\d{1,4})?e\d{1,6}$/;
const TAB = /^t\d{1,4}$/;

const url = (body, field, required) => {
  const value = body[field];
  if (value === undefined || value === null || value === '') {
    if (required) throw badRequest(`${field} is required`);
    return null;
  }
  if (typeof value !== 'string' || value.length > 2048) throw badRequest(`${field} must be a URL of at most 2048 characters`);
  let parsed;
  try {
    parsed = new URL(value);
  } catch {
    throw badRequest(`${field} is not a URL`);
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') throw badRequest(`${field} must be http or https`);
  return parsed.href;
};

const openOptions = body => ({
  device: choice(body, 'device', 'desktop', ['desktop', 'mobile']),
  viewportWidth: integer(body, 'viewportWidth', body.device === 'mobile' ? 390 : 1280, 320, 1920),
  viewportHeight: integer(body, 'viewportHeight', body.device === 'mobile' ? 844 : 800, 320, 2000),
  cookieBanners: choice(body, 'cookieBanners', 'reject', ['reject', 'hide', 'off']),
});

/**
 * @param {string} action One of SESSION_ACTIONS
 * @param {any} body
 * @returns {object} The normalized request
 */
export const validateSessionRequest = (action, body) => {
  if (!body || typeof body !== 'object' || Array.isArray(body)) throw badRequest('send a JSON object');
  const sessionKey = optionalString(body, 'sessionKey', 32, SESSION_KEY);
  const needsKey = !(action === 'open' || action === 'navigate');
  if (needsKey && !sessionKey) throw badRequest('sessionKey is required (32 hex characters)');
  const base = { sessionKey, tab: optionalString(body, 'tab', 6, TAB) };
  switch (action) {
    case 'open':
      return { ...base, ...openOptions(body) };
    case 'navigate':
      return { ...base, ...(sessionKey ? {} : openOptions(body)), url: url(body, 'url', true), newTab: bool(body, 'newTab', false) };
    case 'snapshot':
      return {
        ...base,
        mode: choice(body, 'mode', 'outline', ['outline', 'interactive', 'text']),
        ref: optionalString(body, 'ref', 12, REF),
        find: optionalString(body, 'find', 100) ?? '',
        cursor: integer(body, 'cursor', 0, 0, 1_000_000),
        maxChars: integer(body, 'maxChars', 6000, 500, 20000),
      };
    case 'act': {
      const kind = choice(body, 'action', null, ACT_KINDS);
      const request = { ...base, action: kind, ref: optionalString(body, 'ref', 12, REF), releaseToken: optionalString(body, 'releaseToken', 32, SESSION_KEY) };
      if ((kind === 'click' || kind === 'type' || kind === 'select') && !request.ref) throw badRequest(`ref is required for ${kind}`);
      if (kind === 'type') {
        if (typeof body.text !== 'string') throw badRequest('text is required for type');
        if (body.text.length > MAX_TYPED_CHARS) throw badRequest(`text must be at most ${MAX_TYPED_CHARS} characters`);
        request.text = body.text;
        request.submit = bool(body, 'submit', false);
      }
      if (kind === 'select') {
        const values = body.values;
        if (!Array.isArray(values) || values.length === 0 || values.length > 20 || values.some(value => typeof value !== 'string' || value.length > 200)) throw badRequest('values must be 1–20 option values or labels');
        request.values = values;
      }
      if (kind === 'press') request.key = choice(body, 'key', null, KEYS);
      if (kind === 'scroll') {
        request.direction = request.ref ? null : choice(body, 'direction', 'down', ['up', 'down', 'top', 'bottom']);
      }
      return request;
    }
    case 'screenshot': {
      const target = choice(body, 'target', 'viewport', ['viewport', 'element', 'fullPage']);
      const request = {
        ...base,
        target,
        ref: optionalString(body, 'ref', 12, REF),
        fromY: integer(body, 'fromY', 0, 0, 200_000),
        height: integer(body, 'height', 20000, 100, 20000),
        format: choice(body, 'format', 'png', ['png', 'jpeg']),
        quality: integer(body, 'quality', 80, 0, 100),
      };
      if (target === 'element' && !request.ref) throw badRequest('ref is required for an element screenshot');
      return request;
    }
    case 'tabs': {
      const tabsAction = choice(body, 'action', 'list', ['list', 'open', 'select', 'close']);
      if ((tabsAction === 'select' || tabsAction === 'close') && !base.tab) throw badRequest(`tab is required for ${tabsAction}`);
      return { ...base, action: tabsAction, url: tabsAction === 'open' ? url(body, 'url', false) : null };
    }
    case 'analyse': {
      const name = choice(body, 'name', null, ['design', 'inspect', 'assets']);
      const request = { ...base, name, ref: optionalString(body, 'ref', 12, REF) };
      if (name === 'inspect' && !request.ref) throw badRequest('ref is required for inspect');
      if (name === 'inspect') {
        const properties = body.properties ?? [];
        if (!Array.isArray(properties) || properties.length > 40 || properties.some(property => typeof property !== 'string' || !/^-{0,2}[a-z][a-z0-9-]{0,60}$/.test(property))) throw badRequest('properties must be at most 40 CSS property names');
        request.properties = properties;
      }
      if (name === 'assets') {
        const kinds = body.kinds ?? ['stylesheets', 'logo', 'screenshots'];
        if (!Array.isArray(kinds) || kinds.length === 0 || kinds.some(kind => !ASSET_KINDS.includes(kind))) throw badRequest(`kinds must be a list of ${ASSET_KINDS.join(', ')}`);
        request.kinds = [...new Set(kinds)];
      }
      return request;
    }
    case 'log':
      return { ...base, since: integer(body, 'since', 0, 0, 1_000_000_000) };
    case 'close':
      return base;
    default:
      throw badRequest(`unknown action ${action}`);
  }
};
