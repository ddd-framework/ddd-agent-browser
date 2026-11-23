<?php

declare(strict_types=1);

/**
 * Raw sink calls of this module that are fine, each with its reason (Core skill ddd-untrusted-input-specialist).
 * Checked by tests/UntrustedSinkScanTest.php. The module has none: pages are rendered by the browser renderer (a
 * separate service), images go through RasterImageDecoder, foreign file bytes go to the host's file sink.
 */
return [];
