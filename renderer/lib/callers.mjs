// Caller classes: a token says who calls (prod, dev, or a named caller such as acme-prod). Every class has its own
// limits, so one caller never takes another's capacity, and sessions are filed under the class that opened them.
import crypto from 'node:crypto';

const TOKEN_PREFIX = 'RENDER_TOKENS_';

/**
 * @param {string} envSuffix e.g. "ACME_PROD"
 * @returns {string} The class name, e.g. "acme-prod"
 */
export const callerClassFromEnvSuffix = envSuffix => envSuffix.toLowerCase().replace(/_/g, '-');

/**
 * @param {string} callerClass e.g. "acme-prod"
 * @returns {string} The env suffix, e.g. "ACME_PROD"
 */
export const envSuffixFromCallerClass = callerClass => callerClass.toUpperCase().replace(/-/g, '_');

const integerOrNull = value => {
  const parsed = Number.parseInt(value ?? '', 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
};

/**
 * A class whose name ends in "prod" gets the production defaults.
 *
 * @param {string} callerClass
 * @returns {boolean}
 */
export const isProductionClass = callerClass => /(^|-)prod$/.test(callerClass);

const tokenDigest = token => crypto.createHash('sha256').update(token).digest();

export class CallerRegistry {
  /**
   * @param {Record<string, string|undefined>} env
   */
  constructor(env = process.env) {
    this.env = env;
    this.tokens = [];
    for (const [name, value] of Object.entries(env)) {
      if (!name.startsWith(TOKEN_PREFIX) || !value) continue;
      const callerClass = callerClassFromEnvSuffix(name.slice(TOKEN_PREFIX.length));
      if (callerClass === '') continue;
      for (const token of value.split(',').map(entry => entry.trim()).filter(Boolean)) {
        this.tokens.push({ digest: tokenDigest(token), callerClass });
      }
    }
    this.classes = [...new Set(this.tokens.map(token => token.callerClass))];
    if (this.classes.length === 0) this.classes = ['dev'];
  }

  get isAuthEnabled() {
    return this.tokens.length > 0;
  }

  /**
   * @param {import('node:http').IncomingMessage} request
   * @returns {string|null} The caller class of the presented token, null when it is missing or unknown
   */
  resolve(request) {
    if (!this.isAuthEnabled) return 'dev';
    const presented = request.headers['x-render-token'];
    if (typeof presented !== 'string' || presented === '') return null;
    const presentedDigest = tokenDigest(presented);
    let callerClass = null;
    // compare against every token (no early exit): the time does not reveal which one matched
    for (const token of this.tokens) {
      if (crypto.timingSafeEqual(token.digest, presentedDigest)) callerClass = token.callerClass;
    }
    return callerClass;
  }

  /**
   * @param {string} callerClass
   * @param {string} limitName e.g. "MAX_PAGES" or "MAX_SESSIONS"
   * @param {number} productionDefault
   * @param {number} otherDefault
   * @returns {number}
   */
  limit(callerClass, limitName, productionDefault, otherDefault) {
    return integerOrNull(this.env[`RENDER_${limitName}_${envSuffixFromCallerClass(callerClass)}`])
      ?? (isProductionClass(callerClass) ? productionDefault : otherDefault);
  }
}
