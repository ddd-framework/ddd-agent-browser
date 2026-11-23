// The open sessions of the process, filed under the caller class that opened them: a key is found only together with
// its class, so one caller (a dev workspace) can never address another's (production) session. Limits per class, idle
// close, maximum age.
import crypto from 'node:crypto';
import { BrowserSession, SESSION_LIMITS } from './session.mjs';

const TOMBSTONE_MS = 60 * 60_000;

export class SessionManager {
  /**
   * @param {{getBrowser: () => import('playwright-core').Browser, maxSessionsFor: (callerClass: string) => number, log: Function, limits?: object}} setup
   */
  constructor({ getBrowser, maxSessionsFor, log, limits = SESSION_LIMITS }) {
    this.getBrowser = getBrowser;
    this.maxSessionsFor = maxSessionsFor;
    this.log = log;
    this.limits = limits;
    this.sessions = new Map();
    this.tombstones = new Map();
    this.sweeper = setInterval(() => void this.sweep(), 15_000);
    this.sweeper.unref();
  }

  static id(callerClass, key) {
    return `${callerClass}\n${key}`;
  }

  countFor(callerClass) {
    let count = 0;
    for (const session of this.sessions.values()) if (session.callerClass === callerClass && !session.isClosed) count++;
    return count;
  }

  /**
   * @param {string} callerClass
   * @param {object} options device, viewport, cookieBanners
   * @returns {Promise<BrowserSession>}
   */
  async open(callerClass, options) {
    const maximum = this.maxSessionsFor(callerClass);
    if (this.countFor(callerClass) >= maximum) {
      throw Object.assign(new Error(`all ${maximum} sessions of this caller are open; close one or retry later`), { statusCode: 429, retryAfter: 60 });
    }
    const key = crypto.randomBytes(16).toString('hex');
    const session = new BrowserSession({ key, callerClass, browser: this.getBrowser(), options, log: this.log, limits: this.limits });
    const id = SessionManager.id(callerClass, key);
    this.sessions.set(id, session);
    try {
      await session.start();
    } catch (error) {
      this.sessions.delete(id);
      await session.close('startFailed');
      throw error;
    }
    this.log('info', 'session opened', { callerClass, open: this.countFor(callerClass), maximum });
    return session;
  }

  /**
   * @param {string} callerClass
   * @param {string} key
   * @returns {{session: BrowserSession|null, cause: string|null}}
   */
  get(callerClass, key) {
    const id = SessionManager.id(callerClass, key);
    const session = this.sessions.get(id);
    if (session && !session.isClosed) return { session, cause: null };
    if (session) this.forget(id, session.closed?.cause ?? 'closed');
    return { session: null, cause: this.tombstones.get(id)?.cause ?? null };
  }

  forget(id, cause) {
    this.sessions.delete(id);
    this.tombstones.set(id, { cause, at: Date.now() });
  }

  async closeSession(session, cause) {
    await session.close(cause);
    this.forget(SessionManager.id(session.callerClass, session.key), cause);
  }

  async sweep(now = Date.now()) {
    for (const [id, session] of this.sessions) {
      if (session.isClosed) {
        this.forget(id, session.closed.cause);
        continue;
      }
      if (session.queued > 0) continue;
      const idleLimit = session.consentPending ? this.limits.idleWithConsentMs : this.limits.idleMs;
      if (now - session.createdAt > this.limits.maxAgeMs) await this.closeSession(session, 'maxAge');
      else if (now - session.lastActivity > idleLimit) await this.closeSession(session, 'idle');
    }
    for (const [id, tombstone] of this.tombstones) if (now - tombstone.at > TOMBSTONE_MS) this.tombstones.delete(id);
  }

  async closeAll(cause) {
    for (const session of this.sessions.values()) await session.close(cause);
    this.sessions.clear();
    clearInterval(this.sweeper);
  }

  /**
   * @returns {Record<string, number>} Open sessions per caller class
   */
  openByClass() {
    const counts = {};
    for (const session of this.sessions.values()) if (!session.isClosed) counts[session.callerClass] = (counts[session.callerClass] ?? 0) + 1;
    return counts;
  }
}
