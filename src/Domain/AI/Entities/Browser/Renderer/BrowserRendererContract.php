<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Renderer;

/**
 * The HTTP contract between this package and the browser renderer (image `ghcr.io/ddd-framework/ddd-agent-browser-renderer`,
 * source `renderer/` of the module's repository). The renderer reports its contract version in `GET /healthz`; it is the
 * renderer's package version, released with the same tag as this package. Within one major the contract only grows:
 * the renderer is deployed first, then the PHP release that reads a new field. Full description: README › "Renderer
 * contract".
 */
class BrowserRendererContract
{
    /** @var string The contract this package release speaks (= the package version without a prerelease suffix) */
    public const string CONTRACT_VERSION = '0.5.1';

    /** @var string The lowest renderer contract this package works with */
    public const string MINIMUM_CONTRACT_VERSION = '0.1.0';

    public const string ROUTE_HEALTH = '/healthz';
    public const string ROUTE_SCREENSHOT = '/webPageRenderer/screenshot';
    public const string ROUTE_SESSION_PREFIX = '/browser/session/';

    public const string SESSION_ACTION_OPEN = 'open';
    public const string SESSION_ACTION_NAVIGATE = 'navigate';
    public const string SESSION_ACTION_SNAPSHOT = 'snapshot';
    public const string SESSION_ACTION_ACT = 'act';
    public const string SESSION_ACTION_SCREENSHOT = 'screenshot';
    public const string SESSION_ACTION_TABS = 'tabs';
    public const string SESSION_ACTION_ANALYSE = 'analyse';
    public const string SESSION_ACTION_LOG = 'log';
    public const string SESSION_ACTION_CLOSE = 'close';

    /** @var string[] Every session route segment */
    public const array SESSION_ACTIONS = [
        self::SESSION_ACTION_OPEN, self::SESSION_ACTION_NAVIGATE, self::SESSION_ACTION_SNAPSHOT, self::SESSION_ACTION_ACT,
        self::SESSION_ACTION_SCREENSHOT, self::SESSION_ACTION_TABS, self::SESSION_ACTION_ANALYSE, self::SESSION_ACTION_LOG,
        self::SESSION_ACTION_CLOSE,
    ];

    /** Header that carries the caller token (the renderer derives the caller class from it) */
    public const string TOKEN_HEADER = 'X-Render-Token';

    public const string ROLE_STATELESS = 'stateless';
    public const string ROLE_SESSION = 'session';
    public const string ROLE_ALL = 'all';

    // page outcomes (HTTP 200, data.reason)
    public const string REASON_OK = 'OK';
    public const string REASON_CLOSED = 'CLOSED';
    public const string REASON_SESSION_GONE = 'SESSION_GONE';
    public const string REASON_STALE_REF = 'STALE_REF';
    public const string REASON_FIELD_REFUSED = 'FIELD_REFUSED';
    public const string REASON_CONSENT_REQUIRED = 'CONSENT_REQUIRED';
    public const string REASON_BLOCKED = 'BLOCKED';
    public const string REASON_BOT_CHALLENGE = 'BOT_CHALLENGE';
    public const string REASON_TIMEOUT = 'TIMEOUT';
    public const string REASON_UNRESOLVABLE = 'UNRESOLVABLE';
    public const string REASON_TOO_MANY_REDIRECTS = 'TOO_MANY_REDIRECTS';
    public const string REASON_FETCH_FAILED = 'FETCH_FAILED';
    public const string REASON_NOT_A_FIELD = 'NOT_A_FIELD';
    public const string REASON_INVALID_OPTION = 'INVALID_OPTION';
    public const string REASON_RELEASE_INVALID = 'RELEASE_INVALID';
    public const string REASON_ACTION_FAILED = 'ACTION_FAILED';
    public const string REASON_ACTION_TIMEOUT = 'ACTION_TIMEOUT';
    public const string REASON_TAB_LIMIT = 'TAB_LIMIT';
    public const string REASON_NO_SUCH_TAB = 'NO_SUCH_TAB';
    public const string REASON_LIMIT_REACHED = 'LIMIT_REACHED';
    public const string REASON_OUT_OF_RANGE = 'OUT_OF_RANGE';
    public const string REASON_NO_PAGE = 'NO_PAGE';
    public const string REASON_UNSUPPORTED_FRAME = 'UNSUPPORTED_FRAME';
    public const string REASON_BUSY = 'BUSY';

    /** @var string A ref of a snapshot: `e12`, or with a frame prefix `f3e12` */
    public const string REF_PATTERN = '/^(f\d+)?e\d+$/';

    /**
     * Whether a model-written ref has the renderer's shape.
     * @param string|null $ref
     * @return bool
     */
    public static function isValidRef(?string $ref): bool
    {
        return $ref !== null && preg_match(static::REF_PATTERN, $ref) === 1;
    }

    /**
     * Whether a renderer that reports `$rendererContractVersion` serves this package (same major, at least the minimum).
     * @param string|null $rendererContractVersion
     * @return bool
     */
    public static function isCompatibleContractVersion(?string $rendererContractVersion): bool
    {
        if ($rendererContractVersion === null || trim($rendererContractVersion) === '') {
            return false;
        }
        $rendererCoreVersion = (string)preg_replace('/[-+].*$/', '', trim($rendererContractVersion));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $rendererCoreVersion)) {
            return false;
        }
        $rendererMajor = (int)explode('.', $rendererCoreVersion)[0];
        $minimumMajor = (int)explode('.', static::MINIMUM_CONTRACT_VERSION)[0];
        return $rendererMajor === $minimumMajor && version_compare($rendererCoreVersion, static::MINIMUM_CONTRACT_VERSION, '>=');
    }
}
