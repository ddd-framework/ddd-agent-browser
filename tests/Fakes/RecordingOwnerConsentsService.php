<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Domain\AI\Exceptions\Conversations\OwnerConsentMissingException;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;

/** The operator's consent as a switch: given or not; every request and use is recorded */
class RecordingOwnerConsentsService extends OwnerConsentsService
{
    public bool $isConsentGiven = false;

    /** @var string[] The target keys asked about */
    public array $askedTargetKeys = [];

    public int $usedCount = 0;

    public function __construct()
    {
    }

    public function reserveOwnerConsentForStep(?AgentToolCallProvenance $callProvenance, OwnerConsentRequest $request): OwnerConsentReservation
    {
        $this->askedTargetKeys[] = $request->targetKey;
        return $this->isConsentGiven
            ? OwnerConsentReservation::ungated($request, OwnerConsentVerdict::ungated())
            : OwnerConsentReservation::notReserved($request, OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_SITE_OFF));
    }

    public function requireOwnerConsentForStep(?AgentToolCallProvenance $callProvenance, OwnerConsentRequest $request): OwnerConsentReservation
    {
        $ownerConsentReservation = $this->reserveOwnerConsentForStep($callProvenance, $request);
        if (!$ownerConsentReservation->isWriteAllowed()) {
            throw new OwnerConsentMissingException('consent missing for ' . $request->targetKey);
        }
        return $ownerConsentReservation;
    }

    public function markConsentUsed(?AgentToolCallProvenance $callProvenance, OwnerConsentReservation $reservation): void
    {
        $this->usedCount++;
    }
}
