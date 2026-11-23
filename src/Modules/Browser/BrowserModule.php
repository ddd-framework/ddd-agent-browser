<?php

declare(strict_types=1);

namespace DDD\Modules\Browser;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * The browser module: interactive browser sessions on the browser renderer (a separate service; its image is built
 * from `renderer/` of this repository), the session log, design extraction from a session's page and, beside
 * `mgamadeus/ddd-agents`, the agent tools and skills for them.
 */
class BrowserModule extends DDDModule
{
    /** @var string A class of ddd-agents: the agent part of the module registers only beside it */
    public const string AGENTS_PACKAGE_CLASS = 'DDD\\Domain\\AI\\Entities\\MCPPackages\\MCPPackage';

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
            'DDD\\Domain\\Browser\\Services\\',
        ];
    }

    public static function getExcludePatterns(): array
    {
        return [...parent::getExcludePatterns(), 'Dtos', 'Modules', 'Testing'];
    }

    public static function getNamespacesRequiringClasses(): array
    {
        return [
            'DDD\\Domain\\Browser\\Services\\Agents\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Presentation\\Mcp\\Client\\Browser\\' => self::AGENTS_PACKAGE_CLASS,
        ];
    }
}
