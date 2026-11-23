// Pace per host: at most one render of a host at a time and
// a minimum gap between two renders of the same host. Low request rates are part of not looking like a bot.

export class HostPacer {
  /**
   * @param {{minimumGapMs: number, now?: () => number}} options
   */
  constructor({ minimumGapMs, now = Date.now }) {
    this.minimumGapMs = minimumGapMs;
    this.now = now;
    this.hosts = new Map();
  }

  /**
   * Waits until the host is free and its gap has passed.
   *
   * @param {string} host
   * @param {AbortSignal} signal
   * @returns {Promise<() => void>} Call it when the render of the host is done
   */
  async acquire(host, signal) {
    const hostKey = host.toLowerCase().replace(/^www\./, '');
    for (;;) {
      if (signal.aborted) throw new Error('timed out while waiting for the host');
      const entry = this.hosts.get(hostKey) ?? { busy: null, finishedAt: Number.NEGATIVE_INFINITY };
      if (entry.busy) {
        await Promise.race([entry.busy, new Promise(resolve => signal.addEventListener('abort', resolve, { once: true }))]);
        continue;
      }
      const waitMs = entry.finishedAt + this.minimumGapMs - this.now();
      if (waitMs > 0) {
        await new Promise(resolve => {
          const timer = setTimeout(resolve, waitMs);
          signal.addEventListener('abort', () => { clearTimeout(timer); resolve(); }, { once: true });
        });
        continue;
      }
      let release;
      entry.busy = new Promise(resolve => { release = resolve; });
      this.hosts.set(hostKey, entry);
      return () => {
        entry.busy = null;
        entry.finishedAt = this.now();
        release();
        if (this.hosts.size > 5000) this.forgetOldHosts();
      };
    }
  }

  forgetOldHosts() {
    const threshold = this.now() - this.minimumGapMs;
    for (const [hostKey, entry] of this.hosts) {
      if (!entry.busy && entry.finishedAt < threshold) this.hosts.delete(hostKey);
    }
  }
}
