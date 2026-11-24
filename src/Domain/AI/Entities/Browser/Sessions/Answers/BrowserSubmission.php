<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Sessions\Answers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A request that would have sent data (a non-GET navigation, or text typed in this session to another site) and that the
 * renderer aborted. After the operator's yes the same action is repeated with `releaseToken`: the renderer then lets
 * exactly one matching request through, once (method + origin + path + hash of the field names and typed values).
 */
class BrowserSubmission extends ValueObject
{
    /** A request the operator can release once (it carries a release token) */
    public const string KIND_SUBMISSION = 'submission';

    /** A script request that carried typed text to another site: dropped for good, no release exists */
    public const string KIND_DROPPED = 'dropped';

    public ?string $kind = self::KIND_SUBMISSION;

    public ?string $method = null;

    /** @var string|null Origin and path of the target */
    public ?string $target = null;

    public ?BrowserSubmissionFields $fields = null;

    public ?string $releaseToken = null;

    /** @var string|null When the release token expires (renderer time, ISO 8601) */
    public ?string $expiresAt = null;

    /**
     * The key the operator's consent is bound to: the target and the field names and values, never the token.
     * @return string
     */
    public function buildConsentKey(): string
    {
        $fieldParts = [];
        foreach ($this->fields?->getElements() ?? [] as $browserSubmissionField) {
            $fieldParts[] = $browserSubmissionField->name . '=' . $browserSubmissionField->value;
        }
        sort($fieldParts);
        return strtoupper((string)$this->method) . ' ' . $this->target . '|' . hash('sha256', implode("\n", $fieldParts));
    }

    /**
     * What the operator is asked about: the target and every field with its value.
     * @return string
     */
    public function buildDescription(): string
    {
        $fieldLines = [];
        foreach ($this->fields?->getElements() ?? [] as $browserSubmissionField) {
            $fieldLines[] = '`' . $browserSubmissionField->name . '` = "' . $browserSubmissionField->value . '"' . ($browserSubmissionField->typed ? ' (typed in this session)' : '');
        }
        return strtoupper((string)$this->method) . ' to ' . $this->target . ($fieldLines === [] ? ' (no fields)' : ' with ' . implode(', ', $fieldLines));
    }

    public function isReleasable(): bool
    {
        return $this->kind !== self::KIND_DROPPED && $this->releaseToken !== null;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
