<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Browser\Pages\BrowserPageScreenshot;
use DDD\Domain\Base\Repo\Argus\ArgusSettings;
use RuntimeException;
use Throwable;

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

    /** @var ArgusSettings[] A copy of the prepared settings of every call */
    public static array $preparedArgusSettings = [];

    /** Whether the load throws before any answer (a transport error) */
    public static bool $throwOnLoad = false;

    public static function resetScript(): void
    {
        static::$preparedArgusSettings = [];
        static::$throwOnLoad = false;
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
        $preparedArgusSettings = clone $argusSettings;
        $preparedArgusSettings->argusLoad = clone $argusSettings->argusLoad;
        static::$preparedArgusSettings[] = $preparedArgusSettings;
        if (static::$throwOnLoad) {
            throw new RuntimeException('connection refused by the screenshot service');
        }
        $argusSettings->argusLoad->cacheTtl = 0;
        $callResponseData = static::$scriptedAnswer;
        $argusApiOperation = null;
        $this->handleLoadResponse($callResponseData, $argusApiOperation);
    }

    /** The service's transport errors: a refused connection means the destination was refused */
    public static function findFailureReasonOfTransportError(Throwable $throwable): string
    {
        return str_contains($throwable->getMessage(), 'refused by the screenshot service')
            ? BrowserPageScreenshot::REASON_BLOCKED
            : parent::findFailureReasonOfTransportError($throwable);
    }
}
