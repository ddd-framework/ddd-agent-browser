<?php

declare(strict_types=1);

/**
 * Browser module: the browser renderer (image `ghcr.io/ddd-framework/ddd-browser-renderer`, built from `renderer/`
 * of the module's repository; contract in its README › "Renderer contract"). Sessions run on their own deployment of
 * the same image (role `session`), so the session endpoint is separate from the stateless one.
 *
 * A config file replaces the module's default file as a whole: keep every key.
 */
return [
    // absolute base URL of the stateless renderer (screenshots, content, analyses), e.g. http://browser-renderer:8080
    'endpoint' => null,
    // name of an env variable with an absolute base URL that replaces `endpoint`; null = none
    'endpointEnv' => 'BROWSER_RENDERER_ENDPOINT',
    // absolute base URL of the sessions deployment; null = `endpoint`
    'sessionEndpoint' => null,
    // name of an env variable with an absolute base URL that replaces `sessionEndpoint`; null = none
    'sessionEndpointEnv' => 'BROWSER_RENDERER_SESSION_ENDPOINT',
    // name of the env variable with the caller token sent as X-Render-Token (it names the caller class); null or empty = no header
    'tokenEnv' => 'BROWSER_RENDERER_TOKEN',
    // seconds one session action may take on the wire (the renderer's own limit per action is 30 s)
    'sessionActionTimeoutSeconds' => 40,
    // seconds one guarded file fetch through the renderer may take (downloads and asset exports)
    'fetchTimeoutSeconds' => 60,
    // seconds the health probe of app:browser:check-renderer may take
    'healthTimeoutSeconds' => 10,
];
