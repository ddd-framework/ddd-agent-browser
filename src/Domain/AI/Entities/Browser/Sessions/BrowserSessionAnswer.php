<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Sessions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Entities\Browser\Sessions\Answers\BrowserAssetFile;
use DDD\Domain\AI\Entities\Browser\Sessions\Answers\BrowserAssetFiles;
use DDD\Domain\AI\Entities\Browser\Sessions\Answers\BrowserSubmission;
use DDD\Domain\AI\Entities\Browser\Sessions\Answers\BrowserSubmissionField;
use DDD\Domain\AI\Entities\Browser\Sessions\Answers\BrowserSubmissionFields;
use DDD\Domain\AI\Entities\Browser\Sessions\Answers\BrowserSubmissions;
use DDD\Domain\AI\Entities\Browser\Sessions\Answers\BrowserTab;
use DDD\Domain\AI\Entities\Browser\Sessions\Answers\BrowserTabs;

/**
 * The answer of one session route. A page outcome is HTTP 200 with `data.reason` ({@see fillFromRendererData()}); a
 * fault of the renderer itself (auth, limits, an unknown route outside its role) is `httpStatusCode` + `error`. Every
 * text in here that comes from a page is third-party content.
 */
class BrowserSessionAnswer extends ValueObject
{
    /** @var string|null The session action the answer belongs to */
    public ?string $action = null;

    /** @var int|null The HTTP status of the answer; null when nothing answered */
    public ?int $httpStatusCode = null;

    /** @var string|null The renderer's error of a fault, or the transport failure */
    public ?string $error = null;

    /** @var int|null Seconds the renderer asked to wait (429) */
    public ?int $retryAfterSeconds = null;

    /** @var string|null The page outcome (`OK`, `SESSION_GONE`, `STALE_REF`, `CONSENT_REQUIRED`, …) */
    public ?string $reason = null;

    public ?string $sessionKey = null;

    public ?string $tab = null;

    /** @var string|null The URL of the tab after the action (navigate: finalUrl, act: url) */
    public ?string $finalUrl = null;

    /** @var int|null The HTTP status of the page (navigate) */
    public ?int $statusCode = null;

    public ?string $title = null;

    /** @var string[] The redirect hops of a navigation */
    public array $redirects = [];

    /** @var string|null The consent manager the renderer found (navigate) */
    public ?string $consentCmp = null;

    /** @var string|null What it did with it (`REJECTED`, `HIDDEN`, `NONE`, …) */
    public ?string $consentAction = null;

    /** @var string|null The vendor of a bot challenge */
    public ?string $challengeVendor = null;

    /** @var string|null The snapshot text (navigate: outline; snapshot: the asked mode) */
    public ?string $snapshotText = null;

    public ?string $nextCursor = null;

    /** @var bool|null Whether an act navigated */
    public ?bool $navigated = null;

    /** @var string|null The changed region after an act (≤ 1,500 characters) */
    public ?string $changed = null;

    /** @var string|null A tab an act opened (the first of `newTabs`) */
    public ?string $newTab = null;

    /** @var string[] Tabs an act opened */
    public array $newTabs = [];

    /** @var string[] Dialogs an act met (dismissed) */
    public array $dialogs = [];

    /** @var bool|null Whether a sent release token let its request through (act) */
    public ?bool $releaseUsed = null;

    /** @var string|null Why a session is gone (`idle`, `maxAge`, `closed`, `transferLimit`, `shutdown`, `unknown`) */
    public ?string $sessionGoneCause = null;

    public ?BrowserSubmissions $submissions = null;

    /** @var string|null A screenshot, base64 */
    public ?string $imageContent = null;

    public ?string $imageFormat = null;

    public ?int $imageWidth = null;

    public ?int $imageHeight = null;

    public ?bool $imageTruncated = null;

    public ?BrowserTabs $tabs = null;

    /** @var string|null `analyse design`: the design tokens as JSON (the content of design-tokens.json) */
    public ?string $designTokensJson = null;

    /** @var string|null `analyse design|inspect`: the summary as markdown (≤ 5,000 characters) */
    public ?string $analysisSummary = null;

    /** @var BrowserAssetFiles|null `analyse assets`: the fetched files */
    public ?BrowserAssetFiles $assetFiles = null;

    public function isRendererFault(): bool
    {
        return $this->httpStatusCode !== 200;
    }

    public function isOk(): bool
    {
        return !$this->isRendererFault() && in_array($this->reason, [BrowserRendererContract::REASON_OK, BrowserRendererContract::REASON_CLOSED], true);
    }

    /**
     * Reads the `data` object of a page outcome. Unknown fields are ignored (the contract only grows within a major).
     * @param object $data
     * @return void
     */
    public function fillFromRendererData(object $data): void
    {
        $this->reason = static::readString($data, 'reason');
        $this->sessionKey = static::readString($data, 'sessionKey') ?? $this->sessionKey;
        $this->tab = static::readString($data, 'tab');
        $this->finalUrl = static::readString($data, 'finalUrl') ?? static::readString($data, 'url');
        $this->statusCode = isset($data->statusCode) && is_numeric($data->statusCode) ? (int)$data->statusCode : null;
        $this->title = static::readString($data, 'title');
        $this->redirects = [];
        foreach (is_array($data->redirects ?? null) ? $data->redirects : [] as $redirect) {
            if (is_string($redirect)) {
                $this->redirects[] = $redirect;
            } elseif (is_object($redirect) && is_string($redirect->url ?? null)) {
                $this->redirects[] = $redirect->url;
            }
        }
        if (isset($data->consent) && is_object($data->consent)) {
            $this->consentCmp = static::readString($data->consent, 'cmp');
            $this->consentAction = static::readString($data->consent, 'action');
        }
        $this->challengeVendor = static::readString($data, 'challengeVendor');
        if (isset($data->snapshot) && is_object($data->snapshot)) {
            $this->snapshotText = static::readString($data->snapshot, 'text');
            $this->nextCursor = static::readString($data->snapshot, 'nextCursor');
        } elseif ($this->action === BrowserRendererContract::SESSION_ACTION_SNAPSHOT) {
            $this->snapshotText = static::readString($data, 'text');
            $this->nextCursor = static::readString($data, 'nextCursor');
        }
        $this->navigated = isset($data->navigated) ? (bool)$data->navigated : null;
        $this->changed = static::readString($data, 'changed');
        $this->newTabs = static::readStringList($data, 'newTabs');
        $this->newTab = static::readString($data, 'newTab') ?? ($this->newTabs[0] ?? null);
        $this->dialogs = static::readStringList($data, 'dialogs');
        $this->releaseUsed = isset($data->releaseUsed) ? (bool)$data->releaseUsed : null;
        $this->sessionGoneCause = static::readString($data, 'cause');
        $this->submissions = static::readSubmissions($data);
        if ($this->action === BrowserRendererContract::SESSION_ACTION_SCREENSHOT) {
            $this->imageContent = static::readString($data, 'content');
            $this->imageFormat = static::readString($data, 'format');
            $this->imageWidth = isset($data->width) && is_numeric($data->width) ? (int)$data->width : null;
            $this->imageHeight = isset($data->height) && is_numeric($data->height) ? (int)$data->height : null;
            $this->imageTruncated = isset($data->truncated) ? (bool)$data->truncated : null;
        }
        $this->tabs = static::readTabs($data);
        if (isset($data->tokens)) {
            // passed through as the renderer built it (the host stores it as design-tokens.json)
            $designTokensJson = is_string($data->tokens) ? $data->tokens : json_encode($data->tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $this->designTokensJson = is_string($designTokensJson) ? $designTokensJson : null;
        }
        $this->analysisSummary = static::readString($data, 'summary');
        $this->assetFiles = static::readAssetFiles($data);
    }

    protected static function readString(object $data, string $fieldName): ?string
    {
        $value = $data->$fieldName ?? null;
        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }

    /**
     * @return string[] the strings of a list field (an object entry gives its `tab`, `message` or `type`)
     */
    protected static function readStringList(object $data, string $fieldName): array
    {
        $strings = [];
        foreach (is_array($data->$fieldName ?? null) ? $data->$fieldName : [] as $entry) {
            if (is_scalar($entry)) {
                $strings[] = (string)$entry;
            } elseif (is_object($entry)) {
                $entryText = $entry->tab ?? $entry->message ?? $entry->type ?? null;
                if (is_scalar($entryText)) {
                    $strings[] = (string)$entryText;
                }
            }
        }
        return $strings;
    }

    protected static function readSubmissions(object $data): BrowserSubmissions
    {
        $browserSubmissions = new BrowserSubmissions();
        foreach (is_array($data->submissions ?? null) ? $data->submissions : [] as $submissionData) {
            if (!is_object($submissionData)) {
                continue;
            }
            $browserSubmission = new BrowserSubmission();
            $browserSubmission->kind = static::readString($submissionData, 'kind') ?? BrowserSubmission::KIND_SUBMISSION;
            $browserSubmission->method = static::readString($submissionData, 'method');
            $browserSubmission->target = static::readString($submissionData, 'target');
            $browserSubmission->releaseToken = static::readString($submissionData, 'releaseToken');
            $browserSubmission->expiresAt = static::readString($submissionData, 'expiresAt');
            $browserSubmissionFields = new BrowserSubmissionFields();
            foreach (is_array($submissionData->fields ?? null) ? $submissionData->fields : [] as $fieldData) {
                if (!is_object($fieldData)) {
                    continue;
                }
                $browserSubmissionField = new BrowserSubmissionField();
                $browserSubmissionField->name = static::readString($fieldData, 'name');
                $browserSubmissionField->value = is_scalar($fieldData->value ?? null) ? (string)$fieldData->value : null;
                $browserSubmissionField->typed = (bool)($fieldData->typed ?? false);
                $browserSubmissionFields->add($browserSubmissionField);
            }
            $browserSubmission->fields = $browserSubmissionFields;
            $browserSubmissions->add($browserSubmission);
        }
        return $browserSubmissions;
    }

    protected static function readTabs(object $data): ?BrowserTabs
    {
        if (!is_array($data->tabs ?? null)) {
            return null;
        }
        $browserTabs = new BrowserTabs();
        foreach ($data->tabs as $tabData) {
            if (!is_object($tabData)) {
                continue;
            }
            $browserTab = new BrowserTab();
            $browserTab->tab = static::readString($tabData, 'tab');
            $browserTab->url = static::readString($tabData, 'url');
            $browserTab->title = static::readString($tabData, 'title');
            $browserTab->active = (bool)($tabData->active ?? false);
            $browserTabs->add($browserTab);
        }
        return $browserTabs;
    }

    protected static function readAssetFiles(object $data): ?BrowserAssetFiles
    {
        if (!is_array($data->files ?? null)) {
            return null;
        }
        $browserAssetFiles = new BrowserAssetFiles();
        foreach ($data->files as $fileData) {
            if (!is_object($fileData)) {
                continue;
            }
            $browserAssetFile = new BrowserAssetFile();
            $browserAssetFile->kind = static::readString($fileData, 'kind');
            $browserAssetFile->name = static::readString($fileData, 'name');
            $browserAssetFile->mimeType = static::readString($fileData, 'mimeType');
            $browserAssetFile->sourceUrl = static::readString($fileData, 'sourceUrl');
            $browserAssetFile->size = isset($fileData->size) && is_numeric($fileData->size) ? (int)$fileData->size : null;
            $browserAssetFile->content = static::readString($fileData, 'content');
            $browserAssetFiles->add($browserAssetFile);
        }
        return $browserAssetFiles;
    }
}
