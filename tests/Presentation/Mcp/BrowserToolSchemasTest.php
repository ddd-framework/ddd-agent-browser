<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Presentation\Mcp;

use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Presentation\Mcp\Client\Browser\BrowserDesignMCPPackage;
use DDD\Presentation\Mcp\Client\Browser\BrowserSessionMCPPackage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every browser tool builds the schema the agent sends (the way ddd-agents builds it): typed, no generic array
 * syntax leaking, no injected service or provenance, and small enough to stay loaded.
 */
class BrowserToolSchemasTest extends TestCase
{
    /** @var int Estimated tokens of one tool's schema on every model call (characters / 4) */
    protected const int MAX_SCHEMA_TOKENS = 900;

    /** @var string[] The packages of the module */
    protected const array PACKAGE_CLASSES = [BrowserSessionMCPPackage::class, BrowserDesignMCPPackage::class];

    public function testEveryToolBuildsAValidCompactSchema(): void
    {
        $toolNames = $this->findToolNames();
        self::assertCount(13, $toolNames);
        $toolDefinitions = ToolDefinitions::forPackages(static::PACKAGE_CLASSES);
        foreach ($toolNames as $toolName) {
            $toolDefinition = $toolDefinitions->getByName($toolName);
            self::assertNotNull($toolDefinition, $toolName . ' has no definition');
            $wireSchema = $toolDefinition->toOpenAiToolSchema();
            $schemaJson = (string)json_encode($wireSchema);
            self::assertSame($toolName, $wireSchema['function']['name']);
            self::assertSame('object', $wireSchema['function']['parameters']['type'] ?? null, $toolName);
            $properties = (array)($wireSchema['function']['parameters']['properties'] ?? []);
            foreach (['agentToolCallProvenance', 'callProvenance', 'browserAgentSessionsService', 'browserAgentAnswersService'] as $hiddenName) {
                self::assertArrayNotHasKey($hiddenName, $properties, $toolName . ' leaks ' . $hiddenName);
            }
            self::assertStringNotContainsString('array<', $schemaJson, $toolName . ' carries generic array syntax');
            self::assertStringNotContainsString('"type":"array","items":{"type":"array"', $schemaJson, $toolName . ' has an array without a leaf type');
            self::assertLessThanOrEqual(static::MAX_SCHEMA_TOKENS, (int)ceil(strlen($schemaJson) / 4), $toolName . ' schema is too large');
            self::assertNotNull($toolDefinition->effect, $toolName . ' declares no effect');
        }
        $selectProperties = (array)$toolDefinitions->getByName('browserSelectOption')->toOpenAiToolSchema()['function']['parameters']['properties'];
        self::assertSame('string', $selectProperties['values']['items']['type'] ?? null, '`values` is a list of strings');
    }

    public function testEveryToolDeclaresItsEffectAndUntrustedContent(): void
    {
        foreach (static::PACKAGE_CLASSES as $packageClass) {
            foreach ((new ReflectionClass($packageClass))->getMethods() as $reflectionMethod) {
                if ($reflectionMethod->getAttributes(MCPTool::class) === []) {
                    continue;
                }
                self::assertNotSame([], $reflectionMethod->getAttributes(MCPToolEffect::class), $reflectionMethod->getName());
                if ($reflectionMethod->getName() !== 'browserClose') {
                    self::assertNotSame([], $reflectionMethod->getAttributes(MCPToolReturnsUntrustedContent::class), $reflectionMethod->getName());
                }
            }
        }
    }

    /** @return string[] */
    protected function findToolNames(): array
    {
        $toolNames = [];
        foreach (static::PACKAGE_CLASSES as $packageClass) {
            foreach ((new ReflectionClass($packageClass))->getMethods() as $reflectionMethod) {
                if ($reflectionMethod->getAttributes(MCPTool::class) !== []) {
                    $toolNames[] = $reflectionMethod->getName();
                }
            }
        }
        return $toolNames;
    }
}
