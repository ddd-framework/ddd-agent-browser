<?php

declare(strict_types=1);

/**
 * Browser module: files that leave the browser (downloads, exported assets, design token files). They go only through
 * the host's file sink (a service implementing DDD\Domain\Browser\Services\Files\BrowserFileSink); without one the
 * tools that write files are not offered.
 *
 * A config file replaces the module's default file as a whole: keep every key.
 */
return [
    // bytes one fetched file may have (the renderer refuses more as well)
    'maxFileBytes' => 26214400,
    // assets one browserExportAssets call stores at most
    'maxAssetsPerExport' => 20,
];
