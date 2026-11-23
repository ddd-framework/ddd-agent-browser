<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionRequest;
use PHPUnit\Framework\TestCase;

/** The contract version check and the bodies of the session routes */
class BrowserRendererContractTest extends TestCase
{
    public function testTheSameMajorAtLeastTheMinimumIsCompatible(): void
    {
        self::assertTrue(BrowserRendererContract::isCompatibleContractVersion(BrowserRendererContract::CONTRACT_VERSION));
        self::assertTrue(BrowserRendererContract::isCompatibleContractVersion('0.1.0-rc.3'), 'a prerelease of the same version');
        self::assertTrue(BrowserRendererContract::isCompatibleContractVersion('0.4.2'));
        self::assertFalse(BrowserRendererContract::isCompatibleContractVersion('1.0.0'), 'another major');
        self::assertFalse(BrowserRendererContract::isCompatibleContractVersion('0.0.9'));
        self::assertFalse(BrowserRendererContract::isCompatibleContractVersion(null));
        self::assertFalse(BrowserRendererContract::isCompatibleContractVersion('latest'));
    }

    public function testANavigationSendsOnlyItsSetParameters(): void
    {
        $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_NAVIGATE);
        $browserSessionRequest->url = 'https://example.com/';
        self::assertSame('/browser/session/navigate', $browserSessionRequest->buildRoute());
        self::assertSame(['url' => 'https://example.com/'], $browserSessionRequest->toRendererBody(), 'no sessionKey: the navigation opens a session');
    }

    public function testActTabsAndAnalyseNameTheirVerbAsTheContractDoes(): void
    {
        $actRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_ACT, 'a1b2');
        $actRequest->actAction = 'type';
        $actRequest->ref = 'e7';
        $actRequest->text = 'Kontakt';
        $actRequest->releaseToken = 'rt-1';
        self::assertSame(['sessionKey' => 'a1b2', 'ref' => 'e7', 'text' => 'Kontakt', 'releaseToken' => 'rt-1', 'action' => 'type'], $actRequest->toRendererBody());

        $tabsRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_TABS, 'a1b2');
        $tabsRequest->tabAction = 'select';
        $tabsRequest->tab = 't2';
        self::assertSame(['sessionKey' => 'a1b2', 'tab' => 't2', 'action' => 'select'], $tabsRequest->toRendererBody());

        $analyseRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_ANALYSE, 'a1b2');
        $analyseRequest->analysisName = 'assets';
        $analyseRequest->kinds = ['logo'];
        self::assertSame(['sessionKey' => 'a1b2', 'kinds' => ['logo'], 'name' => 'assets'], $analyseRequest->toRendererBody());
    }
}
