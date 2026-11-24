'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/contract';
import { CircleCheck, RefreshCw, TriangleAlert } from 'lucide-react';

import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { EmptyValue } from '@mgamadeus/ddd-admin-ui/list';
import { StatusCallout } from '@mgamadeus/ddd-admin-ui/page';
import { Badge } from '@mgamadeus/ddd-admin-ui/primitives';
import { Button } from '@mgamadeus/ddd-admin-ui/primitives';
import { Card, CardContent } from '@mgamadeus/ddd-admin-ui/primitives';
import { cn } from '@mgamadeus/ddd-admin-ui/primitives';
import { Skeleton } from '@mgamadeus/ddd-admin-ui/primitives';
import type { BrowserApi } from '../api.ts';

export interface BrowserRendererCardProps {
  api: BrowserApi;
}

/**
 * The renderer deployments checked against the package (the check of `app:agent-browser:check-renderer`): status,
 * role, contract and Chrome version per deployment, and what does not fit.
 */
export const BrowserRendererCard = ({ api }: BrowserRendererCardProps) => {
  const { t } = useTranslate();
  const rendererQuery = api.useBrowserRendererQuery();
  const renderer = rendererQuery.data;
  const checks = renderer?.deploymentChecks?.elements ?? [];

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            {renderer && (renderer.isHealthy ? <CircleCheck className="size-5 text-success" /> : <TriangleAlert className="size-5 text-warning" />)}
            <h2 className="text-[15px] font-semibold">{t('Renderer', { context: 'browser' })}</h2>
            {renderer && (
              <span className="text-xs text-muted-foreground">
                {t('Package contract %version%, works with %minimum% or newer', {
                  replacements: { version: renderer.packageContractVersion, minimum: renderer.minimumContractVersion },
                })}
              </span>
            )}
          </div>
          <Button type="button" variant="outline" size="sm" onClick={() => void rendererQuery.refetch?.()} disabled={rendererQuery.isFetching}>
            <RefreshCw className={cn(rendererQuery.isFetching && 'animate-spin')} />
            {t('Check again', { context: 'browser-renderer' })}
          </Button>
        </div>
        {rendererQuery.isLoading ? (
          <Skeleton className="h-16 w-full rounded-xl" />
        ) : rendererQuery.isError ? (
          <StatusCallout tone="destructive" title={t('The renderer check could not be loaded.')} description={normalizeDddError(rendererQuery.error, '').message} />
        ) : checks.length === 0 ? (
          <StatusCallout
            tone="info"
            title={t('No renderer is configured')}
            description={t('Set AI.Browser.renderer (endpoint or AGENT_BROWSER_RENDERER_ENDPOINT) to give the agents a browser.')}
          />
        ) : (
          <ul className="grid gap-3 md:grid-cols-2">
            {checks.map(check => {
              const problems = check.problems ?? [];
              return (
                <li key={check.deployment ?? check.baseUrl ?? ''} className="surface space-y-2 rounded-xl p-3 text-[13px]">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={problems.length === 0 ? 'outline' : 'destructive'} className="font-mono">
                      {check.deployment}
                    </Badge>
                    <span className="min-w-0 truncate font-mono text-xs text-muted-foreground" title={check.baseUrl ?? undefined}>
                      {check.baseUrl}
                    </span>
                  </div>
                  <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                    <dt className="text-muted-foreground">{t('Status', { context: 'browser-renderer' })}</dt>
                    <dd>{check.health?.status ?? <EmptyValue />}{check.health?.httpStatusCode ? ` (HTTP ${check.health.httpStatusCode})` : ''}</dd>
                    <dt className="text-muted-foreground">{t('Role', { context: 'browser-renderer' })}</dt>
                    <dd className="font-mono">{check.health?.role ?? <EmptyValue />}</dd>
                    <dt className="text-muted-foreground">{t('Contract', { context: 'browser-renderer' })}</dt>
                    <dd className="font-mono">{check.health?.contractVersion ?? <EmptyValue />}</dd>
                    <dt className="text-muted-foreground">{t('Chrome', { context: 'browser-renderer' })}</dt>
                    <dd className="font-mono">{check.health?.chromeVersion ?? <EmptyValue />}</dd>
                  </dl>
                  {problems.length > 0 && (
                    <ul className="list-disc space-y-0.5 pl-4 text-xs text-destructive">
                      {problems.map(problem => (
                        <li key={problem}>{problem}</li>
                      ))}
                    </ul>
                  )}
                </li>
              );
            })}
          </ul>
        )}
        {renderer && !renderer.areSessionsEnabled && (
          <p className="text-xs text-muted-foreground">{t('Interactive sessions are switched off (AI.Browser.sessions.enabled); page screenshots still use the renderer.')}</p>
        )}
      </CardContent>
    </Card>
  );
};
