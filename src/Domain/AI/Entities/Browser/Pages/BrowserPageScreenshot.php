<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Pages;

use DDD\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UnsupportedImageFormatException;

/**
 * A rendered-page SCREENSHOT of a URL, captured through the browser renderer's stateless route
 * (`POST /webPageRenderer/screenshot`). Transient: the base64 bytes are never persisted. The capture is
 * {@see ArgusBrowserPageScreenshot}; this object cuts it into the parts a vision model reads.
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusBrowserPageScreenshot::class)]
class BrowserPageScreenshot extends ValueObject
{
    /** @var string|null base64-encoded image content, exactly as the endpoint delivered it. */
    public ?string $content = null;

    /**
     * @var string|null Why nothing was captured, as the endpoint (or the package before the render) names it: one of
     * the `REASON_*` constants of {@see \DDD\Domain\AI\Entities\Browser\Agents\BrowserPageScreenshotPart} or the endpoint's own code; null on success or when no
     * reason is known.
     */
    public ?string $failureReason = null;

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
