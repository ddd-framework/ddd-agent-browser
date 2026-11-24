<?php

declare(strict_types=1);

/**
 * Agent browser module: the browser renderer (image `ghcr.io/ddd-framework/ddd-agent-browser-renderer`, built from `renderer/`
 * of the module's repository; contract in its README › "Renderer contract"). Sessions run on their own deployment of
 * the same image (role `session`), so the session endpoint is separate from the stateless one.
 *
 * A config file replaces the module's default file as a whole: keep every key.
 */
return [
    // absolute base URL of the stateless renderer (screenshots, content, analyses), e.g. http://browser-renderer:8080
    'endpoint' => null,
    // name of an env variable with an absolute base URL that replaces `endpoint`; null = none
    'endpointEnv' => 'AGENT_BROWSER_RENDERER_ENDPOINT',
    // absolute base URL of the sessions deployment; null = `endpoint`
    'sessionEndpoint' => null,
    // name of an env variable with an absolute base URL that replaces `sessionEndpoint`; null = none
    'sessionEndpointEnv' => 'AGENT_BROWSER_RENDERER_SESSION_ENDPOINT',
    // name of the env variable with the caller token sent as X-Render-Token (it names the caller class); null or empty = no header
    'tokenEnv' => 'AGENT_BROWSER_RENDERER_TOKEN',
    // seconds PHP waits for one session action; above the renderer's own deadlines (navigate 45 s, analyse 60 s)
    'sessionActionTimeoutSeconds' => 65,
    // seconds PHP waits for one page screenshot of the page tools (Agent.Web.Pages); the renderer allows 45 s of page load
    'screenshotTimeoutSeconds' => 40,
    // seconds the health probe of app:agent-browser:check-renderer may take
    'healthTimeoutSeconds' => 10,
];
