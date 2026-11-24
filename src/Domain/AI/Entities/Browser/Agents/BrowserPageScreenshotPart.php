<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Agents;

use DDD\Domain\AI\Entities\WebSearch\WebFetchFailureReasons;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One PART of a rendered web page for a vision model: a window of {@see SEGMENTS_PER_PART} native-width
 * slices of {@see SLICE_HEIGHT_PX} px, plus the coverage facts — the page height, the number of parts, the range shown —
 * so the model learns the page from the tool answer instead of mistaking the top for the whole page. Built by
 * {@see \DDD\Domain\AI\Services\Browser\Agents\BrowserAgentPagesService::capturePagePartForModel()}.
 */
class BrowserPageScreenshotPart extends ValueObject
{
    /**
     * Px height of one slice. A 1024 × 2000 slice stays within the image limits of both vision encoders without a
     * downscale that blurs text: OpenAI scales by the short side, Anthropic by the long edge (at most 2576 px on
     * current models).
     */
    public const int SLICE_HEIGHT_PX = 2000;

    /** Slices per part — the default picture budget of one model call (two), so every slice of a part reaches the model. */
    public const int SEGMENTS_PER_PART = 2;

    public const int PART_HEIGHT_PX = self::SLICE_HEIGHT_PX * self::SEGMENTS_PER_PART;

    public const string DEVICE_DESKTOP = 'desktop';
    public const string DEVICE_MOBILE = 'mobile';
    public const array DEVICES = [self::DEVICE_DESKTOP, self::DEVICE_MOBILE];
    public const int DESKTOP_VIEWPORT_WIDTH_PX = 1024;
    public const int MOBILE_VIEWPORT_WIDTH_PX = 390;

    /** The window height of a normal capture — a full-page render never comes back shorter. */
    public const int DEFAULT_VIEWPORT_HEIGHT_PX = 1400;

    /**
     * The window height of the retry for a page that blocks scrolling (its full-page render stops at the window): the
     * content is laid out inside a window this tall instead; empty space below the content is cut off afterwards.
     */
    public const int TALL_VIEWPORT_HEIGHT_PX = 8000;

    /** Failure reasons: the endpoint's codes, plus the package's own two refusals before any render. */
    public const string REASON_BOT_CHALLENGE = 'BOT_CHALLENGE';
    public const string REASON_TIMEOUT = 'TIMEOUT';
    public const string REASON_BLOCKED = 'BLOCKED';
    public const string REASON_UNRESOLVABLE = WebFetchFailureReasons::UNRESOLVABLE;
    public const string REASON_FETCH_FAILED = 'FETCH_FAILED';
    public const string REASON_NOT_PUBLIC = 'NOT_PUBLIC';
    public const string REASON_CAPABILITY_OFF = 'CAPABILITY_OFF';
    public const string REASON_UNSUPPORTED_TYPE = WebFetchFailureReasons::UNSUPPORTED_TYPE;
    public const string REASON_TOO_LARGE = WebFetchFailureReasons::TOO_LARGE;
    public const string REASON_NO_CONTENT = WebFetchFailureReasons::NO_CONTENT;
    public const string REASON_TOO_MANY_REDIRECTS = WebFetchFailureReasons::TOO_MANY_REDIRECTS;

    /** @var string The rendered URL */
    public string $url = '';

    /** @var string {@see DEVICE_DESKTOP} or {@see DEVICE_MOBILE} */
    public string $device = self::DEVICE_DESKTOP;

    /** @var int The rendered page width in px */
    public int $pageWidthPx = 0;

    /** @var int The rendered page height in px */
    public int $pageHeightPx = 0;

    /** @var int The part shown (1-based) */
    public int $partNumber = 1;

    /** @var string[] The slices of the part, base64 PNG, top-to-bottom */
    public array $base64Images = [];

    /** @var bool The page blocks scrolling: rendered in a {@see TALL_VIEWPORT_HEIGHT_PX} window instead of as a full page */
    public bool $renderedInTallWindow = false;

    /** @var string|null Why nothing was captured (a `REASON_*` constant or the endpoint's own code); null on success */
    public ?string $failureReason = null;

    /** @var int[] The parts of this page and device already shown earlier in the current turn (ascending) */
    public array $partNumbersAlreadyShownInTurn = [];

    /**
     * @var int|null How many tool pictures stay in the model's view on one model call (the conversation's
     * `maxToolCallImagesOnWire`); null when unknown (outside the agent loop)
     */
    public ?int $picturesKeptInView = null;

    public static function partCountForHeight(int $pageHeightPx): int
    {
        return max(1, (int)ceil($pageHeightPx / self::PART_HEIGHT_PX));
    }

    public function getPartCount(): int
    {
        return self::partCountForHeight($this->pageHeightPx);
    }

    public function getShownFromPx(): int
    {
        return ($this->partNumber - 1) * self::PART_HEIGHT_PX;
    }

    public function getShownUntilPx(): int
    {
        return min($this->pageHeightPx, $this->partNumber * self::PART_HEIGHT_PX);
    }

    /** @return string[] `part=N` for every part of the page but the one shown */
    public function buildOtherPartArguments(): array
    {
        $otherPartArguments = [];
        for ($otherPart = 1; $otherPart <= $this->getPartCount(); $otherPart++) {
            if ($otherPart !== $this->partNumber) {
                $otherPartArguments[] = 'part=' . $otherPart;
            }
        }
        return $otherPartArguments;
    }

    /**
     * Why nothing was captured and the next move, for the model: one sentence per known reason, the generic sentence
     * with the code for an unknown one. English: it is model-facing.
     */
    public function buildFailureText(): string
    {
        $urlLabel = $this->url !== '' ? $this->url : 'the page';
        $noGuessing = ' Do not guess how it looks.';
        $sharedFailureSentence = WebFetchFailureReasons::findSharedFailureSentence($this->failureReason, $urlLabel, WebFetchFailureReasons::CAPABILITY_SCREENSHOT);
        if ($sharedFailureSentence !== null) {
            return $sharedFailureSentence . $noGuessing;
        }
        return match ($this->failureReason) {
            self::REASON_BOT_CHALLENGE => "The site {$urlLabel} protects itself against bots: the renderer only gets a "
                . 'challenge page. Do not retry the screenshot; read its text with getWebPageAsMarkdown or use another source.' . $noGuessing,
            self::REASON_TIMEOUT => "Rendering {$urlLabel} timed out. Retry ONCE; if it times out again, read the text with "
                . 'getWebPageAsMarkdown instead.' . $noGuessing,
            self::REASON_BLOCKED => "The renderer refused {$urlLabel} (blocked destination). Do not retry it." . $noGuessing,
            self::REASON_FETCH_FAILED => "The page {$urlLabel} could not be loaded by the renderer (unreachable or refused). "
                . 'Do not retry it now; getWebPageAsMarkdown may still read its text.' . $noGuessing,
            self::REASON_NOT_PUBLIC => "{$urlLabel} is not a public web address, so it is not rendered. Do not retry it." . $noGuessing,
            self::REASON_CAPABILITY_OFF => 'Screenshots are switched off in this application (no browser renderer is configured). Do not retry; use getWebPageAsMarkdown for the text.',
            default => "Could not capture a screenshot of {$urlLabel}"
                . (($this->failureReason ?? '') !== '' ? ' (' . $this->failureReason . ')' : '')
                . ' — the page may be unreachable, not public, blocked, or not an HTML page. Say that you could not load it '
                . 'visually.' . $noGuessing,
        };
    }

    /**
     * The line about the picture budget: with the conversation's budget known, how many pictures stay in view and that
     * the pictures of earlier parts are gone at the next step; else the general rule.
     */
    public function buildPictureBudgetText(): string
    {
        $describeNow = ' write down what this part shows NOW, before you open the next one, and never open a part you have already seen.';
        if ($this->picturesKeptInView === null || $this->picturesKeptInView < 1) {
            return ' Earlier screenshots leave your view when the picture budget is exceeded:' . $describeNow;
        }
        return sprintf(
            ' Only the newest %d picture(s) stay in your view (this part has %d): once you open another part, the pictures of this one are gone —%s',
            $this->picturesKeptInView,
            count($this->base64Images),
            $describeNow
        ) . $this->buildDescribeBeforeNextPartText();
    }

    /**
     * On the last part of a page whose earlier parts were shown in this turn: the model's descriptions of those parts
     * are its record, the summary is built from them. Empty otherwise.
     */
    public function buildEarlierDescriptionsAreTheRecordText(): string
    {
        $partCount = $this->getPartCount();
        if ($partCount < 2 || $this->partNumber < $partCount) {
            return '';
        }
        $earlierPartNumbers = [];
        foreach ($this->partNumbersAlreadyShownInTurn as $shownPartNumber) {
            if ($shownPartNumber < $this->partNumber) {
                $earlierPartNumbers[] = $shownPartNumber;
            }
        }
        if ($earlierPartNumbers === []) {
            return '';
        }
        return sprintf(
            ' This is the last part. Your descriptions of part %s above are your record of them: build the summary from them and never say you could not inspect those parts.',
            implode(', ', $earlierPartNumbers)
        );
    }

    /**
     * The order for the next step while the budget holds less than two parts and a later part exists: the next message
     * writes what this part shows and may open the next part after that text; a part opened without it is refused.
     * Empty otherwise.
     */
    public function buildDescribeBeforeNextPartText(): string
    {
        if ($this->picturesKeptInView === null || $this->picturesKeptInView >= 2 * self::SEGMENTS_PER_PART || $this->partNumber >= $this->getPartCount()) {
            return '';
        }
        return sprintf(
            ' Your NEXT message first writes what part %1$d shows; the same message may then open part=%2$d (a part opened without that text is refused). '
            . 'Your descriptions stay your record of each part after its pictures left your view.',
            $this->partNumber,
            $this->partNumber + 1
        );
    }

    /** The line about parts already shown in this turn; empty when none was. */
    public function buildAlreadyShownText(): string
    {
        if ($this->partNumbersAlreadyShownInTurn === []) {
            return '';
        }
        $shownPartArguments = [];
        foreach ($this->partNumbersAlreadyShownInTurn as $shownPartNumber) {
            $shownPartArguments[] = 'part=' . $shownPartNumber;
        }
        $repeatNote = in_array($this->partNumber, $this->partNumbersAlreadyShownInTurn, true)
            ? sprintf(' You already opened part=%d of this view in this turn: use your description of it instead of opening it again.', $this->partNumber)
            : '';
        return ' Already shown in this turn (' . $this->device . '): ' . implode(', ', $shownPartArguments) . '.' . $repeatNote;
    }

    /**
     * The coverage line the tool answers with — what was rendered, how tall the page is, which part
     * is shown, which others exist, and the two honest limits (earlier pictures may leave the view; the render may be
     * up to ten minutes old). English: it is model-facing.
     */
    public function toCoverageText(): string
    {
        $partCount = $this->getPartCount();
        $coverage = $partCount === 1
            ? sprintf('The page is %s px tall — the whole page is shown as %d segment(s), top first.', number_format($this->pageHeightPx), count($this->base64Images))
            : sprintf(
                'The page is %s px tall = %d parts. Shown: part %d of %d (%s–%s px) as %d segment(s), top first. Other parts: %s.',
                number_format($this->pageHeightPx),
                $partCount,
                $this->partNumber,
                $partCount,
                number_format($this->getShownFromPx()),
                number_format($this->getShownUntilPx()),
                count($this->base64Images),
                implode(', ', $this->buildOtherPartArguments())
            );
        $tallWindowNote = $this->renderedInTallWindow
            ? sprintf(' This site blocks scrolling, so it was rendered in a %s px tall window: content further down than that is not in the screenshot.', number_format(self::TALL_VIEWPORT_HEIGHT_PX))
            : '';
        // the URL is the only text of the result a third party may have chosen: marked, so the screening reads it and
        // not the platform's own lines (the pictures themselves are not screened)
        return sprintf('Screenshot of %s (%s, %d px wide). ', ToolResultUntrustedSegments::wrapSingleLine($this->url, $this->url), $this->device, $this->pageWidthPx) . $coverage . $tallWindowNote
            . $this->buildAlreadyShownText()
            . ' Describe / judge what you actually SEE (layout, colours, text, images, visual issues); do not infer from the URL.'
            . $this->buildPictureBudgetText()
            . $this->buildEarlierDescriptionsAreTheRecordText()
            . ' The render may be up to 10 minutes old; pass noCache: true for a fresh one'
            . ' (after a change to the page).';
    }
}
