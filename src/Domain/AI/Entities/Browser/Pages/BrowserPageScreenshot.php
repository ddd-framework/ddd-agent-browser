<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Pages;

use DDD\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UnsupportedImageFormatException;

/**
 * A rendered-page SCREENSHOT of a URL, captured by {@see ArgusBrowserPageScreenshot} (by default the browser renderer's
 * stateless route `POST /webPageRenderer/screenshot`; a host individualises the call in a same-path subclass of it, README
 * › "Customising the renderer call"). Transient: the base64 bytes are never persisted. This object cuts the capture
 * into the parts a vision model reads.
 *
 * Only `content` (base64 PNG) or `failureReason` are required of a capture. The page facts `finalUrl`, `title`,
 * `pageHttpStatusCode`, `consentPlatform` and `consentAction` may stay null; the page height is always read from the
 * image.
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusBrowserPageScreenshot::class)]
class BrowserPageScreenshot extends ValueObject
{
    /** @var string The page loaded too slowly or the call timed out */
    public const string REASON_TIMEOUT = 'TIMEOUT';
    /** @var string The page could not be loaded (unreachable, refused, no answer) */
    public const string REASON_FETCH_FAILED = 'FETCH_FAILED';
    /** @var string The backend refused the destination */
    public const string REASON_BLOCKED = 'BLOCKED';
    /** @var string The site answered with a bot challenge */
    public const string REASON_BOT_CHALLENGE = 'BOT_CHALLENGE';
    /** @var string The host has no DNS record */
    public const string REASON_UNRESOLVABLE = 'UNRESOLVABLE';
    /** @var string Too many redirects */
    public const string REASON_TOO_MANY_REDIRECTS = 'TOO_MANY_REDIRECTS';
    /** @var string The backend is full; retry later */
    public const string REASON_BUSY = 'BUSY';
    /** @var string The capture is not a PNG */
    public const string REASON_UNSUPPORTED_TYPE = 'UNSUPPORTED_TYPE';
    /** @var string Page rendering is not available (no renderer configured) */
    public const string REASON_CAPABILITY_OFF = 'CAPABILITY_OFF';

    /** @var string|null base64-encoded image content, exactly as the endpoint delivered it. */
    public ?string $content = null;

    /**
     * @var string|null Why nothing was captured, as the renderer call (or the package before the call) names it: one of
     * the `REASON_*` constants of this class or of {@see \DDD\Domain\AI\Entities\Browser\Agents\BrowserPageScreenshotPart},
     * or the backend's own code; null on success.
     */
    public ?string $failureReason = null;

    /** @var string|null The URL the page ended on after redirects; null when not reported */
    public ?string $finalUrl = null;

    /** @var string|null The page title (third-party text); null when not reported */
    public ?string $title = null;

    /** @var int|null The HTTP status of the page itself; null when not reported */
    public ?int $pageHttpStatusCode = null;

    /** @var string|null The consent platform found on the page; null when not reported */
    public ?string $consentPlatform = null;

    /** @var string|null What the renderer did with a consent banner (OFF, REJECTED, HIDDEN, NONE); null when not reported */
    public ?string $consentAction = null;

    /** The raw decoded image bytes, or null when nothing was captured (empty / invalid base64). */
    public function getImageBlob(): ?string
    {
        if (!isset($this->content) || $this->content === '') {
            return null;
        }
        $decoded = base64_decode($this->content, true);
        return $decoded !== false ? $decoded : null;
    }

    /** The image width in px from the image header (no decode); 0 when nothing was captured. */
    public function getPixelWidth(): int
    {
        return $this->readPixelDimension(0);
    }

    /** The image height in px from the image header (no decode); 0 when nothing was captured. */
    public function getPixelHeight(): int
    {
        return $this->readPixelDimension(1);
    }

    /** Dimension 0 (width) or 1 (height) of the image header; 0 when nothing was captured. */
    protected function readPixelDimension(int $dimensionIndex): int
    {
        $imageBlob = $this->getImageBlob();
        if ($imageBlob === null) {
            return 0;
        }
        if (RasterImageDecoder::detectFormat($imageBlob) !== RasterImageDecoder::FORMAT_PNG) {
            return 0;
        }
        try {
            $rasterImageInfo = RasterImageDecoder::probe($imageBlob);
        } catch (\ImagickException) {
            return 0;
        }
        return $dimensionIndex === 0 ? $rasterImageInfo->width : $rasterImageInfo->height;
    }

    /**
     * The capture decoded as what the renderer sends: a PNG, the coder pinned ({@see RasterImageDecoder}). Any other
     * bytes are refused before Imagick sees them.
     *
     * @throws \ImagickException
     */
    protected static function readCapturedPng(string $imageBlob): \Imagick
    {
        if (RasterImageDecoder::detectFormat($imageBlob) !== RasterImageDecoder::FORMAT_PNG) {
            throw UnsupportedImageFormatException::forDetectedType(RasterImageDecoder::describeContentType($imageBlob));
        }
        return RasterImageDecoder::readBlob($imageBlob);
    }

    /**
     * The capture with the empty space below its content cut off (the tall-window render of a page that
     * blocks scrolling fills the rest of the window with background) — rows are read bottom-up in bands of
     * `$bandHeight` px; a band whose pixels barely vary is empty. Returns a new BrowserPageScreenshot (the same bytes when
     * nothing is empty, or nothing was captured).
     */
    public function withEmptyBottomCutOff(int $bandHeight = 100, float $maxEmptyBandDeviation = 2.0): static
    {
        $imageBlob = $this->getImageBlob();
        // without Imagick nothing is cut off: the capture stays as it is
        if ($imageBlob === null || !static::isImagickAvailable()) {
            return $this;
        }
        try {
            $imagick = static::readCapturedPng($imageBlob);
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $contentBottom = $height;
            // statistics come in the build's quantum range (255 or 65535): the threshold is given on the 0–255 scale
            $emptyBandDeviationLimit = $maxEmptyBandDeviation / 255 * (float)\Imagick::getQuantumRange()['quantumRangeLong'];
            while ($contentBottom > $bandHeight) {
                $band = $imagick->getImageRegion($width, $bandHeight, 0, $contentBottom - $bandHeight);
                $bandStatistics = $band->getImageChannelStatistics();
                $band->clear();
                $bandIsEmpty = true;
                foreach ($bandStatistics as $channelStatistics) {
                    if (($channelStatistics['standardDeviation'] ?? 0) > $emptyBandDeviationLimit) {
                        $bandIsEmpty = false;
                        break;
                    }
                }
                if (!$bandIsEmpty) {
                    break;
                }
                $contentBottom -= $bandHeight;
            }
            if ($contentBottom >= $height) {
                $imagick->clear();
                return $this;
            }
            $cropped = $imagick->getImageRegion($width, $contentBottom, 0, 0);
            $cropped->setImageFormat($imagick->getImageFormat());
            $cropped->setImagePage(0, 0, 0, 0);
            $croppedScreenshot = static::newInstance();
            $croppedScreenshot->content = base64_encode($cropped->getImageBlob());
            $cropped->clear();
            $imagick->clear();
            return $croppedScreenshot;
        } catch (\ImagickException) {
            return $this;
        }
    }

    /**
     * True when at least the lowest `$bandHeight` px of the capture are empty background ({@see withEmptyBottomCutOff()}
     * would shorten it). A page that ends inside its render window has such an empty bottom; a page whose content is
     * cut off at the window edge has none. False when nothing was captured.
     */
    public function hasEmptyBottom(int $bandHeight = 100, float $maxEmptyBandDeviation = 2.0): bool
    {
        $capturedHeight = $this->getPixelHeight();
        if ($capturedHeight <= 0) {
            return false;
        }
        return $this->withEmptyBottomCutOff($bandHeight, $maxEmptyBandDeviation)->getPixelHeight() < $capturedHeight;
    }

    /**
     * ONE window of the page as native-width vertical segments: `$segmentCount` slices of `$sliceHeight` px
     * starting at slice `$firstSegmentIndex` (0-based), base64, top-to-bottom — the image is decoded once and each slice
     * is a region of it ({@see \Imagick::getImageRegion()}), so a long page is never cloned per slice. A window past
     * the page end yields fewer (or no) slices; empty on a decoder failure or when nothing was captured.
     *
     * @return string[]
     */
    public function buildSegmentWindowBase64(int $sliceHeight, int $firstSegmentIndex, int $segmentCount): array
    {
        $imageBlob = $this->getImageBlob();
        if ($imageBlob === null || $sliceHeight <= 0 || $segmentCount <= 0 || $firstSegmentIndex < 0) {
            return [];
        }
        if (!static::isImagickAvailable()) {
            // without Imagick the capture cannot be cut: the first window is the whole capture as one picture
            return $firstSegmentIndex === 0 ? [(string)$this->content] : [];
        }
        try {
            $imagick = static::readCapturedPng($imageBlob);
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $format = $imagick->getImageFormat();
            $segments = [];
            for ($segmentIndex = $firstSegmentIndex; $segmentIndex < $firstSegmentIndex + $segmentCount; $segmentIndex++) {
                $offsetY = $segmentIndex * $sliceHeight;
                $segmentHeight = min($sliceHeight, $height - $offsetY);
                if ($segmentHeight <= 0) {
                    break;
                }
                $segment = $imagick->getImageRegion($width, $segmentHeight, 0, $offsetY);
                $segment->setImageFormat($format);
                $segment->setImagePage(0, 0, 0, 0); // the region IS the whole image
                $segments[] = base64_encode($segment->getImageBlob());
                $segment->clear();
                $segment->destroy();
            }
            $imagick->clear();
            $imagick->destroy();
            return $segments;
        } catch (\ImagickException) {
            return [];
        }
    }

    /** Whether the Imagick extension is loaded; without it a capture is neither trimmed nor cut into segments. */
    public static function isImagickAvailable(): bool
    {
        return class_exists(\Imagick::class);
    }
}
