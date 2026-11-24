'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/contract';
import { ExternalLink, Power, RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';

import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { DateCell } from '@mgamadeus/ddd-admin-ui/list';
import { StatusBadge } from '@mgamadeus/ddd-admin-ui/list';
import { DefinitionList } from '@mgamadeus/ddd-admin-ui/page';
import { PageHeader } from '@mgamadeus/ddd-admin-ui/page';
import { SectionHeader } from '@mgamadeus/ddd-admin-ui/page';
import { StatusCallout } from '@mgamadeus/ddd-admin-ui/page';
import { Badge } from '@mgamadeus/ddd-admin-ui/primitives';
import { Button } from '@mgamadeus/ddd-admin-ui/primitives';
import { Card, CardContent } from '@mgamadeus/ddd-admin-ui/primitives';
import { cn } from '@mgamadeus/ddd-admin-ui/primitives';
import { Skeleton } from '@mgamadeus/ddd-admin-ui/primitives';
import type { BrowserApi } from '../api.ts';
import { BROWSER_SESSION_STATUS, browserEventIconOf, browserSessionTitleOf, isNotableBrowserEvent, safeBrowserHrefOf } from './format.ts';
import type { BrowserSessionStatus } from '../types.ts';
import { useCloseBrowserSession } from './useCloseBrowserSession.tsx';

/** Log entries the detail screen asks for. */
export const BROWSER_SESSION_EVENTS_LIMIT = 500;

const errorText = (error: unknown, fallback: string) => normalizeDddError(error, fallback).message;

const EventsLog = ({ api, browserSessionId }: { api: BrowserApi; browserSessionId: number }) => {
  const { t } = useTranslate();
  const eventsQuery = api.useBrowserSessionEventsQuery({ browserSessionId, limit: BROWSER_SESSION_EVENTS_LIMIT });
  const events = eventsQuery.data?.events?.elements ?? [];
  if (eventsQuery.isLoading) return <Skeleton className="h-40 w-full rounded-xl" />;
  if (eventsQuery.isError) return <StatusCallout tone="destructive" title={t('The session log could not be loaded.')} description={errorText(eventsQuery.error, '')} />;
  if (events.length === 0) return <p className="py-10 text-center text-[13px] text-muted-foreground">{t('The session log is empty.')}</p>;
  return (
    <ol className="surface space-y-3 rounded-2xl p-4">
      {events.map((event, index) => {
        const Icon = browserEventIconOf(event.eventType);
        const notable = isNotableBrowserEvent(event.eventType);
        return (
          <li key={String(event.id ?? index)} className="flex gap-3 text-[13px]">
            <Icon className={cn('mt-0.5 size-4 shrink-0', notable ? 'text-warning' : 'text-muted-foreground')} />
            <div className="min-w-0 space-y-0.5">
              <p className="flex flex-wrap items-center gap-x-2">
                <span className="font-mono font-semibold">{event.eventType}</span>
                {event.toolName && (
                  <Badge variant="outline" className="font-mono">
                    {event.toolName}
                  </Badge>
                )}
                <DateCell value={event.changeHistory?.createdTime ?? null} />
              </p>
              {event.url && <p className="break-all font-mono text-xs text-muted-foreground">{event.url}</p>}
              {event.detail && <p className="break-words whitespace-pre-wrap text-muted-foreground">{event.detail}</p>}
            </div>
          </li>
        );
      })}
    </ol>
  );
};

export interface BrowserSessionDetailScreenProps {
  api: BrowserApi;
  browserSessionId: number;
  /** A link back (the list), drawn left of the title. */
  backLink?: ReactNode;
  headerActions?: ReactNode;
  /** The link of a conversation in the host's agent screens; `null` = no link. */
  buildConversationHref?: (conversationId: number) => string | null;
}

/**
 * One browser session: its facts (status, page, conversation, account, navigations, start, last action, end) and its
 * log, oldest first (navigations, clicks, typed texts, consents, refusals, stored files, the end). An open session can
 * be closed with a reason. Every page string is shown as text; the current page opens in a new tab only as http(s).
 */
export const BrowserSessionDetailScreen = ({ api, browserSessionId, backLink, headerActions, buildConversationHref }: BrowserSessionDetailScreenProps) => {
  const { t } = useTranslate();
  const sessionQuery = api.useBrowserSessionQuery({ browserSessionId });
  const closer = useCloseBrowserSession(api);
  const session = sessionQuery.data?.session;

  if (sessionQuery.isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-12 w-80" />
        <Skeleton className="h-32 w-full rounded-2xl" />
      </div>
    );
  }
  if (!session) {
    return (
      <div className="space-y-4">
        <PageHeader title={`#${browserSessionId}`} leading={backLink} />
        <StatusCallout
          tone="destructive"
          title={t('This browser session was not found.')}
          description={sessionQuery.isError ? errorText(sessionQuery.error, t('The browser session could not be loaded.')) : undefined}
        />
      </div>
    );
  }

  const pageHref = safeBrowserHrefOf(session.currentUrl);
  const conversationHref = session.conversationId ? (buildConversationHref?.(session.conversationId) ?? null) : null;
  return (
    <div className="space-y-6">
      <PageHeader
        leading={backLink}
        title={browserSessionTitleOf(session)}
        description={<span className="font-mono text-xs">#{session.id}</span>}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {headerActions}
            <Button type="button" variant="outline" onClick={() => void sessionQuery.refetch?.()} disabled={sessionQuery.isFetching}>
              <RefreshCw className={cn(sessionQuery.isFetching && 'animate-spin')} />
              {t('Refresh')}
            </Button>
            {session.status === 'OPEN' && (
              <Button
                type="button"
                variant="outline"
                className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                disabled={closer.isPending}
                onClick={() => closer.ask(session)}
              >
                <Power />
                {t('Close session', { context: 'browser-action' })}
              </Button>
            )}
          </div>
        }
      />
      <Card>
        <CardContent className="pt-6">
          <DefinitionList
            items={[
              {
                id: 'status',
                label: t('Status', { context: 'browser-session' }),
                value: <StatusBadge value={(session.status ?? null) as BrowserSessionStatus | null} map={BROWSER_SESSION_STATUS} />,
              },
              {
                id: 'page',
                label: t('Page', { context: 'browser-session' }),
                value: session.currentUrl ? (
                  <span className="flex min-w-0 items-center gap-2">
                    <span className="break-all font-mono text-xs">{session.currentUrl}</span>
                    {pageHref && (
                      <a href={pageHref} target="_blank" rel="noopener noreferrer nofollow" aria-label={t('Open the page in a new tab')} className="shrink-0 text-muted-foreground hover:text-foreground">
                        <ExternalLink className="size-3.5" />
                      </a>
                    )}
                  </span>
                ) : null,
              },
              { id: 'title', label: t('Title', { context: 'browser-session' }), value: session.currentTitle },
              {
                id: 'conversation',
                label: t('Conversation', { context: 'browser-session' }),
                value: session.conversationId ? (
                  conversationHref ? (
                    <a href={conversationHref} className="font-mono text-xs underline-offset-2 hover:underline">
                      #{session.conversationId}
                    </a>
                  ) : (
                    <span className="font-mono text-xs">#{session.conversationId}</span>
                  )
                ) : null,
              },
              { id: 'space', label: t('Agent space', { context: 'browser-session' }), value: session.agentSpaceId ? <span className="font-mono text-xs">#{session.agentSpaceId}</span> : null },
              { id: 'account', label: t('Account', { context: 'browser-session' }), value: session.accountId ? <span className="font-mono text-xs">#{session.accountId}</span> : null },
              { id: 'navigations', label: t('Navigations', { context: 'browser-session' }), value: session.navigationCount ?? 0 },
              { id: 'started', label: t('Started', { context: 'browser-session' }), value: <DateCell value={session.changeHistory?.createdTime ?? null} /> },
              { id: 'lastAction', label: t('Last action', { context: 'browser-session' }), value: <DateCell value={session.lastActionAt ?? null} /> },
              { id: 'closed', label: t('Ended', { context: 'browser-session' }), value: session.closedAt ? <DateCell value={session.closedAt} /> : null },
            ]}
          />
        </CardContent>
      </Card>
      <section className="space-y-4">
        <SectionHeader title={t('Session log')} />
        <EventsLog api={api} browserSessionId={session.id} />
      </section>
      {closer.dialog}
    </div>
  );
};
