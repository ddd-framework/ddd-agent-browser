// Payloads of the browser admin routes (`BrowserSessionsControllerTrait` of the Composer package
// mgamadeus/ddd-agent-browser). URLs, titles and log details are third-party text: shown as text only.

/** `BrowserSession.status`. */
export type BrowserSessionStatus = 'OPEN' | 'CLOSED' | 'GONE';

/** `BrowserSessionEvent.eventType`. */
export type BrowserSessionEventType =
  | 'NAVIGATED'
  | 'CLICKED'
  | 'TYPED'
  | 'SELECTED'
  | 'KEY_PRESSED'
  | 'TAB_CHANGED'
  | 'CONSENT_REQUIRED'
  | 'SUBMISSION_RELEASED'
  | 'REFUSED'
  | 'ASSET_STORED'
  | 'CLOSED'
  | 'GONE';

export interface BrowserChangeHistory {
  createdTime?: string | null;
  modifiedTime?: string | null;
}

/** One browser session of an agent conversation. */
export interface BrowserSession {
  id: number;
  conversationId?: number | null;
  agentSpaceId?: number | null;
  accountId?: number | null;
  sessionKey?: string | null;
  status?: BrowserSessionStatus | null;
  currentUrl?: string | null;
  currentTitle?: string | null;
  navigationCount?: number | null;
  lastActionAt?: string | null;
  closedAt?: string | null;
  changeHistory?: BrowserChangeHistory | null;
}

/** One entry of a session's log. */
export interface BrowserSessionEvent {
  id?: number | null;
  browserSessionId?: number | null;
  eventType?: BrowserSessionEventType | string | null;
  toolName?: string | null;
  url?: string | null;
  detail?: string | null;
  changeHistory?: BrowserChangeHistory | null;
}

/** `GET /renderer`: the health answer of one deployment. */
export interface BrowserRendererHealth {
  status?: string | null;
  contractVersion?: string | null;
  chromeVersion?: string | null;
  role?: string | null;
  httpStatusCode?: number | null;
  failureReason?: string | null;
}

/** One configured deployment checked against the package. */
export interface BrowserRendererDeploymentCheck {
  /** `stateless`, `sessions` or `stateless+sessions`. */
  deployment?: string | null;
  baseUrl?: string | null;
  health?: BrowserRendererHealth | null;
  /** What does not fit; empty = healthy. */
  problems?: string[];
}

export interface BrowserElements<T> {
  elements?: T[];
}

export interface BrowserSessionsResponse {
  sessions: BrowserElements<BrowserSession>;
  hasMore: boolean;
  areSessionsEnabled: boolean;
  isConfigured: boolean;
}

export interface BrowserSessionResponse {
  session: BrowserSession;
}

export interface BrowserSessionEventsResponse {
  events: BrowserElements<BrowserSessionEvent>;
}

export interface BrowserRendererResponse {
  deploymentChecks: BrowserElements<BrowserRendererDeploymentCheck>;
  isHealthy: boolean;
  packageContractVersion: string;
  minimumContractVersion: string;
  areSessionsEnabled: boolean;
}

/** The filters and the page of the session list; a filter left out does not narrow. */
export interface BrowserSessionsArg {
  status?: BrowserSessionStatus;
  conversationId?: number;
  accountId?: number;
  limit?: number;
  offset?: number;
}

export interface BrowserSessionIdArg {
  browserSessionId: number;
}

export interface BrowserSessionEventsArg extends BrowserSessionIdArg {
  limit?: number;
}

export interface BrowserSessionCloseArg extends BrowserSessionIdArg {
  /** Written into the session's log. */
  reason?: string;
}
