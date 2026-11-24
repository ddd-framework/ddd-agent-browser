<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Config;

use DDD\Domain\AI\Entities\Browser\BrowserAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Browser\BrowserDesignMCPPackage;
use DDD\Presentation\Mcp\Client\Browser\BrowserPagesMCPPackage;
use DDD\Presentation\Mcp\Client\Browser\BrowserSessionMCPPackage;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The rights of the browser's tools: sessions and design need useBrowser, the page screenshot useWeb; each skill names
 * the right of its package, and the binding passes the naming lint of ddd-agents.
 */
class BrowserAccountRightsTest extends TestCase
{
    protected ?array $configTreeBefore = null;

    protected function tearDown(): void
    {
        if ($this->configTreeBefore !== null) {
            (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configTreeBefore);
        }
        parent::tearDown();
    }

    public function testEachPackageRequiresItsRight(): void
    {
        self::assertSame('useBrowser', BrowserAccountRights::USE_BROWSER);
        self::assertSame('useWeb', BrowserAccountRights::USE_WEB);
        self::assertSame([BrowserAccountRights::USE_BROWSER], MCPPackage::findRequiredAccountRightNamesOfClass(BrowserSessionMCPPackage::class));
        self::assertSame([BrowserAccountRights::USE_BROWSER], MCPPackage::findRequiredAccountRightNamesOfClass(BrowserDesignMCPPackage::class));
        self::assertSame([BrowserAccountRights::USE_WEB], MCPPackage::findRequiredAccountRightNamesOfClass(BrowserPagesMCPPackage::class));
    }

    public function testTheSkillsNameTheRightsAndTheBindingPassesTheLint(): void
    {
        Config::addConfigDirectory(dirname(__DIR__, 2) . '/config/app', true);
        $aISkillsService = new AISkillsService();
        self::assertSame([BrowserAccountRights::USE_BROWSER], $aISkillsService->getAISkillByName('Agent.Web.Browser')?->requiredAccountRights);
        self::assertSame([BrowserAccountRights::USE_BROWSER], $aISkillsService->getAISkillByName('Agent.Web.BrowserDesign')?->requiredAccountRights);
        self::assertSame([BrowserAccountRights::USE_WEB], $aISkillsService->getAISkillByName('Agent.Web.Pages')?->requiredAccountRights);

        $configTreeProperty = new ReflectionProperty(Config::class, 'configTree');
        $configTree = $configTreeProperty->isInitialized() ? ($configTreeProperty->getValue() ?? []) : [];
        $this->configTreeBefore = $configTree;
        $configTree['AI']['skillPackages'] = [
            'Agent.Web.Browser' => [BrowserSessionMCPPackage::class],
            'Agent.Web.BrowserDesign' => [BrowserDesignMCPPackage::class],
            'Agent.Web.Pages' => [BrowserPagesMCPPackage::class],
        ];
        $configTreeProperty->setValue(null, $configTree);
        $aISkillFilesLintService = new AISkillFilesLintService();
        self::assertSame([], $aISkillFilesLintService->findBindingNamingProblems());
        self::assertSame([], $aISkillFilesLintService->findSkillNamingProblems([dirname(__DIR__, 2) . '/config/app/AI/Skills']));
    }
}
