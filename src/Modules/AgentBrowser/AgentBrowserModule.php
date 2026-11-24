<?php

declare(strict_types=1);

namespace DDD\Modules\AgentBrowser;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * The browser module: interactive browser sessions on the browser renderer (a separate service; its image is built
 * from `renderer/` of this repository), the session log, design extraction from a session's page and, beside
 * `mgamadeus/ddd-agents`, the agent tools and skills for them and (ddd-agents >= 0.3.1) the page reader of the web
 * research.
 */
class AgentBrowserModule extends DDDModule
{
    /** @var string A class of ddd-agents: the agent part of the module registers only beside it */
    public const string AGENTS_PACKAGE_CLASS = 'DDD\\Domain\\AI\\Entities\\MCPPackages\\MCPPackage';

    /** @var string The page text port of ddd-agents (>= 0.3.1): the page reader registers only beside it */
    public const string WEB_PAGE_TEXT_SOURCE_CLASS = 'DDD\\Domain\\AI\\Services\\WebSearch\\WebPageTextSource';

    public static function getSourcePath(): string
    {
        return __DIR__ . '/../..';
    }

    public static function getConfigPath(): ?string
    {
        return __DIR__ . '/../../../config/app';
    }

    public static function getPublicServiceNamespaces(): array
    {
        return [
            'DDD\\Domain\\AI\\Services\\Browser\\',
        ];
    }

    public static function getExcludePatterns(): array
    {
        return [...parent::getExcludePatterns(), 'Dtos', 'Modules', 'Testing'];
    }

    public static function getNamespacesRequiringClasses(): array
    {
        return [
            'DDD\\Domain\\AI\\Entities\\Browser\\Agents\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Domain\\AI\\Services\\Browser\\Agents\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Presentation\\Mcp\\Client\\Browser\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Domain\\AI\\Services\\Browser\\PageText\\' => self::WEB_PAGE_TEXT_SOURCE_CLASS,
        ];
    }
}
