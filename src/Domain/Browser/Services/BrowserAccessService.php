<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Services;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * Who may browse interactively. Interactive sessions are for staff agents only: by default an admin account. A host
 * overrides {@see isInteractiveBrowsingAllowed()} in a same-path subclass (e.g. superadmins only, or a staff role).
 */
class BrowserAccessService extends Service
{
    public function areSessionsEnabled(): bool
    {
        return (bool)Config::get('Browser.sessions.enabled');
    }

    /**
     * @param Account|null $account the account the call runs for
     * @return bool
     */
    public function isInteractiveBrowsingAllowed(?Account $account): bool
    {
        return $account !== null && ($account->roles?->isAdmin() ?? false);
    }

    public function getMaxTypedTextCharacters(): int
    {
        return max(1, (int)(Config::get('Browser.sessions.maxTypedTextCharacters') ?? 200));
    }

    public function getMaxAnswerCharacters(): int
    {
        return max(500, (int)(Config::get('Browser.sessions.maxAnswerCharacters') ?? 6000));
    }
}
