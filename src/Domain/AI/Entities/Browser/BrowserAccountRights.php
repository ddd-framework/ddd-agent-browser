<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser;

/**
 * The account rights of the browser's agent tools, named by this package. Each MCP package carries its right as
 * `#[MCPRequiresAccountRights(...)]` and its skill names the same right in `requiredAccountRights`. An application
 * grants them: by roles in ddd-agents' config `AI.accountRights` (its default mapping gives {@see self::USE_BROWSER}
 * to the platform operators and {@see self::USE_WEB} to every account that may talk to the agent), or by its own
 * account rights implementation. A right no mapping names is not held.
 */
class BrowserAccountRights
{
    /** @var string Drive an interactive browser session and read a site's design (`Agent.Web.Browser`, `Agent.Web.BrowserDesign`) */
    public const string USE_BROWSER = 'useBrowser';

    /** @var string Read the live web: a page rendered to a screenshot (`Agent.Web.Pages`); the same right as ddd-agents' web search */
    public const string USE_WEB = 'useWeb';
}
