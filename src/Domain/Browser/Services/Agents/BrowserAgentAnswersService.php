<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Services\Agents;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionAnswer;
use DDD\Domain\Browser\Services\BrowserAccessService;
use DDD\Infrastructure\Libs\PhotoUtils\PhotoUtils;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;

/**
 * The texts the session tools answer with: compact markdown, page strings inside untrusted marks, and the refusal
 * sentences that name the next move. A screenshot is cut into parts of 4,000 px shown as two pictures each.
 */
class BrowserAgentAnswersService extends Service
{
    /** @var string The source name of every marked passage */
    public const string UNTRUSTED_SOURCE = 'web page';

    /** @var int Pixels of one screenshot part (two pictures) */
    public const int SCREENSHOT_PART_HEIGHT = 4000;

    /** @var int Pixels of one picture of a part */
    public const int SCREENSHOT_SEGMENT_HEIGHT = 2000;

    /**
     * The sentence for an answer the tool cannot use, with the next move; null for an answer that can be shown.
     * @param BrowserSessionAnswer $browserSessionAnswer
     * @return string|null
     */
    public function buildRefusalText(BrowserSessionAnswer $browserSessionAnswer): ?string
    {
        if ($browserSessionAnswer->isRendererFault()) {
            return match ($browserSessionAnswer->httpStatusCode) {
                429 => 'The browser is busy (BUSY): retry in ' . ($browserSessionAnswer->retryAfterSeconds ?? 30) . ' s, not at once.',
                404 => 'The browser renderer offers no sessions here (its role is not `session`, or the image is older than the contract): tell the operator; the session tools cannot run.',
                401 => 'The browser renderer refused this platform\'s token: tell the operator.',
                null => 'The browser renderer did not answer (' . ($browserSessionAnswer->error ?? 'no answer') . '): try once more later.',
                default => 'The browser renderer failed (HTTP ' . $browserSessionAnswer->httpStatusCode . ': ' . ($browserSessionAnswer->error ?? 'no detail') . ').',
            };
        }
        return match ($browserSessionAnswer->reason) {
            BrowserRendererContract::REASON_OK, BrowserRendererContract::REASON_CLOSED => null,
            BrowserRendererContract::REASON_SESSION_GONE => 'SESSION_GONE' . ($browserSessionAnswer->sessionGoneCause !== null ? ' (' . $browserSessionAnswer->sessionGoneCause . ')' : '') . ': the browser session ended. Open the page again with browserNavigate.',
            BrowserRendererContract::REASON_STALE_REF => 'STALE_REF: that ref is not in the tab\'s newest snapshot, or the page changed. Take browserSnapshot (mode interactive) and use a ref from it.',
            BrowserRendererContract::REASON_FIELD_REFUSED => 'FIELD_REFUSED: that field takes a password, PIN, TAN, card or bank data; typing into it is refused. A login or payment wall ends the path: say so.',
            BrowserRendererContract::REASON_NOT_A_FIELD => 'NOT_A_FIELD: that ref is no text field. Take browserSnapshot (mode interactive) and pick a field\'s ref.',
            BrowserRendererContract::REASON_INVALID_OPTION => 'INVALID_OPTION: the field offers none of these values. Read its options in browserSnapshot (mode interactive) and pick one of them.',
            BrowserRendererContract::REASON_RELEASE_INVALID => 'RELEASE_INVALID: the operator\'s release no longer fits (expired after 15 minutes, other values, or another session). Nothing was sent. Repeat the action to ask the operator again.',
            BrowserRendererContract::REASON_ACTION_FAILED => 'ACTION_FAILED: the page did not take the action (element hidden, covered or disabled). Take a snapshot, scroll it into view or pick another ref; do not repeat it unchanged.',
            BrowserRendererContract::REASON_ACTION_TIMEOUT => 'ACTION_TIMEOUT: the page did not react in time. Take a snapshot to see its state before you try again.',
            BrowserRendererContract::REASON_TAB_LIMIT => 'TAB_LIMIT: a session has at most 3 tabs. Close one with browserTabs (action close) first.',
            BrowserRendererContract::REASON_NO_SUCH_TAB => 'NO_SUCH_TAB: that tab does not exist. List the tabs with browserTabs.',
            BrowserRendererContract::REASON_LIMIT_REACHED => 'LIMIT_REACHED: the session used up its navigations, time or transfer. Close it with browserClose and say what you found so far; open a new session only if the task needs it.',
            BrowserRendererContract::REASON_OUT_OF_RANGE => 'OUT_OF_RANGE: that part or position is beyond the page. Use a smaller part number (part 1 is the top).',
            BrowserRendererContract::REASON_NO_PAGE => 'NO_PAGE: the tab shows no page yet. Open one with browserNavigate.',
            BrowserRendererContract::REASON_UNSUPPORTED_FRAME => 'UNSUPPORTED_FRAME: this analysis works only on the main page, not inside an embedded frame. Pick a ref without the frame prefix.',
            BrowserRendererContract::REASON_BUSY => 'BUSY: the session is still working on earlier actions. Wait a few seconds, then try once more.',
            BrowserRendererContract::REASON_BLOCKED => 'BLOCKED: the address is not public (or a redirect led to one). Nothing was loaded; do not retry it.',
            BrowserRendererContract::REASON_UNRESOLVABLE => 'UNRESOLVABLE: the host name does not exist. Check the address for a typo; do not guess others.',
            BrowserRendererContract::REASON_TOO_MANY_REDIRECTS => 'TOO_MANY_REDIRECTS: the site redirects in a loop. Do not retry it.',
            BrowserRendererContract::REASON_FETCH_FAILED => 'FETCH_FAILED: the site did not deliver the page. Try once more later or say it is unreachable.',
            BrowserRendererContract::REASON_BOT_CHALLENGE => 'BOT_CHALLENGE' . ($browserSessionAnswer->challengeVendor !== null ? ' (' . $browserSessionAnswer->challengeVendor . ')' : '') . ': the site shows a bot challenge. It is never solved; one attempt per site. Read the text with getWebPageAsMarkdown if it is offered.',
            BrowserRendererContract::REASON_TIMEOUT => 'TIMEOUT: the page did not answer in time. Try once more or say it is too slow.',
            BrowserRendererContract::REASON_CONSENT_REQUIRED => $this->buildDroppedSubmissionsText($browserSessionAnswer),
            null => 'The browser renderer answered without an outcome.',
            default => $browserSessionAnswer->reason . ': the action did not complete.',
        };
    }

    /**
     * The sentence for requests the renderer dropped for good (script requests that carried typed text to another
     * site): there is no release and nothing to ask the operator.
     * @param BrowserSessionAnswer $browserSessionAnswer
     * @return string
     */
    public function buildDroppedSubmissionsText(BrowserSessionAnswer $browserSessionAnswer): string
    {
        $targets = [];
        foreach ($browserSessionAnswer->submissions?->getElements() ?? [] as $browserSubmission) {
            if (!$browserSubmission->isReleasable()) {
                $targets[] = strtoupper((string)$browserSubmission->method) . ' ' . $browserSubmission->target;
            }
        }
        return 'DROPPED: the page tried to send your typed text to another site by script (' . implode(', ', array_unique($targets))
            . '). That request was dropped for good; there is no release, so do not ask the operator for it. Continue without it or say the page needs it.';
    }

    /**
     * The answer of a navigation: status line and the outline snapshot.
     * @param BrowserSessionAnswer $browserSessionAnswer
     * @return string
     */
    public function buildNavigationText(BrowserSessionAnswer $browserSessionAnswer): string
    {
        $statusParts = ['Opened ' . ($browserSessionAnswer->finalUrl ?? '?')];
        if ($browserSessionAnswer->statusCode !== null) {
            $statusParts[] = 'HTTP ' . $browserSessionAnswer->statusCode;
        }
        if ($browserSessionAnswer->tab !== null) {
            $statusParts[] = 'tab ' . $browserSessionAnswer->tab;
        }
        if ($browserSessionAnswer->redirects !== []) {
            $statusParts[] = 'redirects: ' . implode(' → ', $browserSessionAnswer->redirects);
        }
        if ($browserSessionAnswer->consentCmp !== null || $browserSessionAnswer->consentAction !== null) {
            $statusParts[] = 'cookie banner: ' . strtolower((string)($browserSessionAnswer->consentAction ?? 'none')) . ($browserSessionAnswer->consentCmp !== null ? ' (' . $browserSessionAnswer->consentCmp . ')' : '');
        }
        $lines = [implode(' · ', $statusParts)];
        if ($browserSessionAnswer->title !== null) {
            $lines[] = 'Title: ' . ToolResultUntrustedSegments::wrapSingleLine(static::UNTRUSTED_SOURCE, $browserSessionAnswer->title);
        }
        if ($browserSessionAnswer->snapshotText !== null) {
            $lines[] = $this->wrapPageText($browserSessionAnswer->snapshotText);
        }
        $lines[] = 'Next: browserSnapshot (interactive) for links and fields with refs, (text) to read a region.';
        return implode("\n", $lines);
    }

    /**
     * The answer of a snapshot: the asked view and the cursor of the next page.
     * @param BrowserSessionAnswer $browserSessionAnswer
     * @return string
     */
    public function buildSnapshotText(BrowserSessionAnswer $browserSessionAnswer): string
    {
        $lines = [($browserSessionAnswer->finalUrl !== null ? $browserSessionAnswer->finalUrl . "\n" : '') . $this->wrapPageText((string)$browserSessionAnswer->snapshotText)];
        if ($browserSessionAnswer->nextCursor !== null) {
            $lines[] = 'More: browserSnapshot with cursor "' . $browserSessionAnswer->nextCursor . '".';
        }
        return implode("\n", $lines);
    }

    /**
     * The answer of an act: what changed.
     * @param BrowserSessionAnswer $browserSessionAnswer
     * @param string $actDescription what was done (`Clicked e12`)
     * @return string
     */
    public function buildActText(BrowserSessionAnswer $browserSessionAnswer, string $actDescription): string
    {
        $statusParts = [$actDescription];
        if ($browserSessionAnswer->navigated === true) {
            $statusParts[] = 'navigated to ' . ($browserSessionAnswer->finalUrl ?? '?');
        } elseif ($browserSessionAnswer->finalUrl !== null) {
            $statusParts[] = 'still on ' . $browserSessionAnswer->finalUrl;
        }
        if ($browserSessionAnswer->newTabs !== []) {
            $statusParts[] = 'opened tab ' . implode(', ', $browserSessionAnswer->newTabs);
        } elseif ($browserSessionAnswer->newTab !== null) {
            $statusParts[] = 'opened tab ' . $browserSessionAnswer->newTab;
        }
        if ($browserSessionAnswer->dialogs !== []) {
            $statusParts[] = count($browserSessionAnswer->dialogs) . ' dialog(s) dismissed';
        }
        if ($browserSessionAnswer->releaseUsed === true) {
            $statusParts[] = 'the released request was sent';
        } elseif ($browserSessionAnswer->releaseUsed === false) {
            $statusParts[] = 'the release was not used (no matching request)';
        }
        $lines = [implode(' · ', $statusParts)];
        if ($browserSessionAnswer->title !== null && $browserSessionAnswer->navigated === true) {
            $lines[] = 'Title: ' . ToolResultUntrustedSegments::wrapSingleLine(static::UNTRUSTED_SOURCE, $browserSessionAnswer->title);
        }
        $lines[] = $browserSessionAnswer->changed !== null
            ? 'Changed:' . "\n" . $this->wrapPageText($browserSessionAnswer->changed)
            : 'Nothing visible changed.';
        return implode("\n", $lines);
    }

    /**
     * The tabs of a session, one line each.
     * @param BrowserSessionAnswer $browserSessionAnswer
     * @return string
     */
    public function buildTabsText(BrowserSessionAnswer $browserSessionAnswer): string
    {
        $lines = [];
        foreach ($browserSessionAnswer->tabs?->getElements() ?? [] as $browserTab) {
            $lines[] = '- ' . $browserTab->tab . ($browserTab->active ? ' (active)' : '') . ' · ' . $browserTab->url . ' · '
                . ToolResultUntrustedSegments::wrapSingleLine(static::UNTRUSTED_SOURCE, (string)($browserTab->title ?? ''));
        }
        return $lines === [] ? 'No tab is open.' : implode("\n", $lines);
    }

    /**
     * Cuts a screenshot into pictures of SCREENSHOT_SEGMENT_HEIGHT px; only PNG and JPEG are taken.
     * @param BrowserSessionAnswer $browserSessionAnswer
     * @return string[] the pictures, base64; empty when the image is no PNG or JPEG
     */
    public function buildScreenshotPictures(BrowserSessionAnswer $browserSessionAnswer): array
    {
        $imageBytes = base64_decode((string)$browserSessionAnswer->imageContent, true);
        if ($imageBytes === false || $imageBytes === '') {
            return [];
        }
        $imageFormat = RasterImageDecoder::detectFormat($imageBytes);
        if (!in_array($imageFormat, [RasterImageDecoder::FORMAT_PNG, RasterImageDecoder::FORMAT_JPEG], true)) {
            return [];
        }
        $imageSlices = PhotoUtils::sliceImageVertically($imageBytes, static::SCREENSHOT_SEGMENT_HEIGHT, 2);
        if ($imageSlices === null) {
            return [];
        }
        $pictures = [];
        foreach ($imageSlices as $imageSlice) {
            $pictures[] = base64_encode($imageSlice);
        }
        return $pictures;
    }

    /**
     * Page text inside untrusted marks, cut to the answer limit.
     * @param string $pageText
     * @return string
     */
    public function wrapPageText(string $pageText): string
    {
        $maxCharacters = $this->getBrowserAccessService()->getMaxAnswerCharacters();
        if (mb_strlen($pageText) > $maxCharacters) {
            $pageText = mb_substr($pageText, 0, $maxCharacters) . "\n… (cut; use a ref, find or the cursor for the rest)";
        }
        return ToolResultUntrustedSegments::wrap(static::UNTRUSTED_SOURCE, $pageText);
    }

    protected function getBrowserAccessService(): BrowserAccessService
    {
        /** @var BrowserAccessService $browserAccessService */
        $browserAccessService = DDDService::instance()->getService(BrowserAccessService::class);
        return $browserAccessService;
    }
}
