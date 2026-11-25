<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

/**
 * The snake_case subclass with Argus faked: the load records the endpoint and payload it would send and hands the
 * scripted answer to the response handler (the suite has no cache backend, so nothing is cached).
 */
class ScriptedSnakeCaseApiBrowserPageScreenshot extends SnakeCaseApiBrowserPageScreenshot
{
    /** The decoded JSON answer of the service */
    public static mixed $scriptedAnswer = null;

    /** @var string[] The endpoint of every call */
    public static array $calledEndpoints = [];

    /** @var array<int, array<string, mixed>> The payload of every call */
    public static array $sentPayloads = [];

    public static function resetScript(): void
    {
        static::$scriptedAnswer = null;
        static::$calledEndpoints = [];
        static::$sentPayloads = [];
        static::$configuredEndpointPath = null;
    }

    public function argusLoad(
        bool $useArgusEntityCache = true,
        bool $useApiACallCache = true,
        bool $displayCall = false,
        bool $displayResponse = false,
        bool $autoloadCurrentObject = true,
        ?int $apiCallCacheLifetime = null
    ): void {
        $argusSettings = $this->getArgusSettings();
        static::$calledEndpoints[] = (string)$argusSettings->argusLoad->loadEndpoint;
        static::$sentPayloads[] = $this->getLoadPayload();
        $argusSettings->argusLoad->cacheTtl = 0;
        $callResponseData = static::$scriptedAnswer;
        $argusApiOperation = null;
        $this->handleLoadResponse($callResponseData, $argusApiOperation);
    }
}
