// One Google Chrome per process (channel "chrome", headed in Xvfb, driven by Patchright), shared by the stateless
// renders and the sessions of the same process. Contexts isolate the jobs from each other.
import { chromium } from 'patchright';

export const LOCALE = 'de-DE';
export const TIMEZONE = 'Europe/Berlin';

/**
 * Chrome's flags. Site isolation is switched off on purpose: the address guard and the submission rule intercept
 * requests per page target (CDP Fetch), and with site isolation a cross-site iframe runs in its own renderer process
 * whose subresources and redirect hops that interceptor never sees. In one process per page every request of every
 * frame, worker and redirect hop passes the guard. The browser holds no data, logins or secrets, so the isolation
 * between sites inside one context protects nothing here.
 *
 * @param {{noSandbox: boolean}} options
 * @returns {string[]}
 */
export const chromeArguments = ({ noSandbox }) => {
  const args = [
    `--lang=${LOCALE}`,
    '--window-size=1920,1080',
    '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
    '--webrtc-ip-handling-policy=disable_non_proxied_udp',
    '--disable-quic',
    '--disable-site-isolation-trials',
    '--disable-features=IsolateOrigins,site-per-process',
  ];
  if (noSandbox) args.push('--no-sandbox');
  return args;
};

/**
 * @param {{noSandbox: boolean, headless?: boolean}} options headless only for local runs without a display
 * @returns {Promise<import('playwright-core').Browser>}
 */
export const launchChrome = ({ noSandbox, headless = process.env.RENDER_HEADLESS === '1' }) =>
  chromium.launch({ channel: 'chrome', headless, args: chromeArguments({ noSandbox }) });
