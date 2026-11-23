<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionRequest;
use DDD\Tests\Browser\Fakes\FixedEndpointBrowserRendererService;
use PHPUnit\Framework\TestCase;

/** A navigation to a host that is not public is answered BLOCKED before any call */
class BrowserRendererServiceTest extends TestCase
{
    public function testAPrivateOrNonHttpAddressIsBlockedWithoutACall(): void
    {
        $fixedEndpointBrowserRendererService = new FixedEndpointBrowserRendererService();
        foreach (['http://10.0.0.5/', 'http://127.0.0.1:9200/', 'http://169.254.169.254/latest/meta-data', 'file:///etc/passwd', 'javascript:alert(1)'] as $url) {
            $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_NAVIGATE);
            $browserSessionRequest->url = $url;
            $browserSessionAnswer = $fixedEndpointBrowserRendererService->runSessionAction($browserSessionRequest);
            self::assertSame(BrowserRendererContract::REASON_BLOCKED, $browserSessionAnswer->reason, $url);
        }
    }
}
