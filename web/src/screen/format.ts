// Labels, states and small helpers of the browser screens. Pure: no React.
import { Ban, CircleCheck, CircleDot, CircleX, Download, Globe, Keyboard, MousePointerClick, PanelsTopLeft, Power, ShieldAlert, ShieldCheck, TextCursorInput, Unplug } from 'lucide-react';

import { m } from '@mgamadeus/ddd-admin-ui/i18n';
import { defineStatusMap } from '@mgamadeus/ddd-admin-ui/logic';
import type { BrowserRendererDeploymentCheck, BrowserSession, BrowserSessionStatus } from '../types.ts';

/** `BrowserSession.status`. */
export const BROWSER_SESSION_STATUS = defineStatusMap<BrowserSessionStatus>({
  OPEN: {
    label: m('Open', { context: 'browser-session-status' }),
    tone: 'success',
    icon: CircleDot,
    description: m('The renderer holds the browser context; the agent can act on the page', { context: 'browser-session-status' }),
  },
  CLOSED: {
    label: m('Closed', { context: 'browser-session-status' }),
    tone: 'neutral',
    icon: CircleCheck,
    description: m('Ended by the agent, an operator or the purge', { context: 'browser-session-status' }),
  },
  GONE: {
    label: m('Gone', { context: 'browser-session-status' }),
    tone: 'warning',
    icon: Unplug,
    description: m('The renderer no longer knew the context (restart or idle time)', { context: 'browser-session-status' }),
  },
}, { context: 'browser-session-status' });

/** The status filters of the list, in order (`ALL` = no filter). */
export const BROWSER_SESSION_STATUS_FILTERS = ['ALL', 'OPEN', 'CLOSED', 'GONE'] as const;
export type BrowserSessionStatusFilter = (typeof BROWSER_SESSION_STATUS_FILTERS)[number];

/** The icon of a log entry by its type. */
export const browserEventIconOf = (eventType: string | null | undefined) => {
  switch (eventType) {
    case 'NAVIGATED':
      return Globe;
    case 'CLICKED':
      return MousePointerClick;
    case 'TYPED':
    case 'SELECTED':
      return TextCursorInput;
    case 'KEY_PRESSED':
      return Keyboard;
    case 'TAB_CHANGED':
      return PanelsTopLeft;
    case 'CONSENT_REQUIRED':
      return ShieldAlert;
    case 'SUBMISSION_RELEASED':
      return ShieldCheck;
    case 'REFUSED':
      return Ban;
    case 'ASSET_STORED':
      return Download;
    case 'CLOSED':
      return Power;
    case 'GONE':
      return CircleX;
    default:
      return CircleDot;
  }
};

/** Log entries an operator should see first (a consent asked, a refusal, the renderer losing the context). */
export const isNotableBrowserEvent = (eventType: string | null | undefined): boolean =>
  eventType === 'CONSENT_REQUIRED' || eventType === 'REFUSED' || eventType === 'GONE';

/** The page of a session in one line: its title, else its URL's host and path, else `#id`. */
export const browserSessionTitleOf = (session: Pick<BrowserSession, 'id' | 'currentTitle' | 'currentUrl'>): string => {
  const title = session.currentTitle?.trim();
  if (title) return title;
  const shortUrl = shortBrowserUrlOf(session.currentUrl);
  return shortUrl ?? `#${session.id}`;
};

/** `www.example.com/team` of `https://www.example.com/team?x=1`; the text itself when it is no URL; null when empty. */
export const shortBrowserUrlOf = (url: string | null | undefined): string | null => {
  const trimmed = url?.trim();
  if (!trimmed) return null;
  try {
    const parsed = new URL(trimmed);
    const path = parsed.pathname === '/' ? '' : parsed.pathname;
    return `${parsed.host}${path}`;
  } catch {
    return trimmed;
  }
};

/** A URL that may be offered as an outgoing link (http or https only); null for everything else. */
export const safeBrowserHrefOf = (url: string | null | undefined): string | null => {
  const trimmed = url?.trim();
  if (!trimmed) return null;
  try {
    const parsed = new URL(trimmed);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : null;
  } catch {
    return null;
  }
};

/** The checks with a problem. */
export const failingDeploymentChecksOf = (checks: readonly BrowserRendererDeploymentCheck[]): BrowserRendererDeploymentCheck[] =>
  checks.filter(check => (check.problems ?? []).length > 0);
