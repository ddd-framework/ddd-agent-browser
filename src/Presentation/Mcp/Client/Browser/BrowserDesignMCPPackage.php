<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolExecutionTimeout;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRefreshesState;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\Browser\Entities\Files\BrowserFileToStore;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionEvent;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionRequest;
use DDD\Domain\Browser\Services\Agents\BrowserAgentAnswersService;
use DDD\Domain\Browser\Services\Agents\BrowserAgentSessionsService;
use DDD\Domain\Browser\Services\BrowserFileSinksService;
use DDD\Domain\Browser\Services\BrowserSessionsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserExportAssetsRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserExtractDesignRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserInspectRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserMcpResponseDto;

/**
 * The design of the session's current page (so the banner is gone and the right page is open): design tokens as a
 * summary and as `design-tokens.json`, the computed styles of one element with the CSS rules behind them, and files of
 * the page (stylesheets, logo, screenshots, fonts) through the host's file sink. Analyses are fixed scripts of the
 * renderer, addressed by name; the model never sends code.
 */
class BrowserDesignMCPPackage extends MCPPackage
{
    public static ?string $name = 'browser.design';

    public static ?string $description = 'Design of the browser session\'s page: browserExtractDesign (colours, fonts, scales, components, logo; design-tokens.json), browserInspect (computed styles and their CSS rules), browserExportAssets (stylesheets, logo, screenshots, fonts into the workspace).';

    public const array ASSET_KINDS = ['stylesheets', 'logo', 'screenshots', 'fonts'];

    public const string DESIGN_TOKENS_FILE_NAME = 'design-tokens.json';

    public static function isAvailable(): bool
    {
        return BrowserSessionMCPPackage::isAvailable();
    }

    /**
     * The design tokens of the session's current page (open it with browserNavigate first): palette by role with
     * contrast ratios, `:root` custom properties, typography of H1–H3, body and buttons (the rendered font, and the
     * closest free font as a fallback), radius/shadow/spacing scales, button, card and navigation styles, the logo.
     * Answers a summary; the full tokens are saved as web/<domain>/design-tokens.json in the workspace when a file sink
     * is set up (export the logo with browserExportAssets into the same folder: the token file names it).
     * A web font is licensed for its website: name it, use the proposed free font and say so.
     *
     * @example {}
     * @example {"scope": "ref", "ref": "e5"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('styles and texts of a third-party web page')]
    #[MCPToolRefreshesState('the analysis reads the page as it is now')]
    #[MCPToolExecutionTimeout(80, 'one design analysis in the renderer (≤ 30 s) and the file; PHP waits up to 65 s for the renderer')]
    #[MCPToolSummary('Design tokens of the session\'s page (palette by role, custom properties, typography with free fallback font, scales, components, logo); summary + design-tokens.json in the workspace.')]
    public function browserExtractDesign(
        BrowserExtractDesignRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        BrowserFileSinksService $browserFileSinksService,
        BrowserSessionsService $browserSessionsService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $browserAgentSessionsService->requireInteractiveBrowsing();
        $scope = $requestDto->scope ?? 'page';
        if (!in_array($scope, ['page', 'ref'], true)) {
            throw new BadRequestException('`scope` is `page` or `ref`.');
        }
        if ($scope === 'ref') {
            BrowserSessionMCPPackage::throwUnlessValidRef($requestDto->ref, __FUNCTION__, true);
        }
        $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, false);
        $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_ANALYSE, $browserSession->sessionKey);
        $browserSessionRequest->analysisName = 'design';
        $browserSessionRequest->ref = $scope === 'ref' ? $requestDto->ref : null;
        $browserSessionAnswer = $browserAgentSessionsService->runAction($browserSession, $browserSessionRequest, __FUNCTION__);
        $refusalText = $browserAgentAnswersService->buildRefusalText($browserSessionAnswer);
        if ($refusalText !== null) {
            throw new BadRequestException($refusalText);
        }
        $lines = ['Design of ' . ($browserSession->currentUrl ?? 'the page') . ':'];
        $lines[] = $browserAgentAnswersService->wrapPageText((string)($browserSessionAnswer->analysisSummary ?? 'The renderer returned no summary.'));
        if ($browserSessionAnswer->designTokensJson !== null) {
            $browserFileToStore = new BrowserFileToStore();
            $browserFileToStore->fileName = static::DESIGN_TOKENS_FILE_NAME;
            $browserFileToStore->targetFolder = $browserFileSinksService->buildWebFolder($browserSession->currentUrl);
            $browserFileToStore->contentType = 'application/json';
            $browserFileToStore->kind = 'design-tokens';
            $browserFileToStore->pageUrl = $browserSession->currentUrl;
            $browserFileToStore->setBytes($browserSessionAnswer->designTokensJson);
            $browserStoredFile = $browserFileSinksService->storeFile($browserFileToStore, $browserAgentSessionsService->buildFileSinkContext($callProvenance, __FUNCTION__));
            if ($browserStoredFile->isStored()) {
                $lines[] = 'Saved the full tokens as ' . $browserStoredFile->path . ' (untrusted: values from the page).';
                $browserSessionsService->recordEvent($browserSession, BrowserSessionEvent::EVENT_ASSET_STORED, __FUNCTION__, $browserSession->currentUrl, (string)$browserStoredFile->path);
            } else {
                $lines[] = 'The full tokens were not saved (' . $browserStoredFile->refusalReason . ').';
            }
        }
        return BrowserMcpResponseDto::withText(implode("\n", $lines));
    }

    /**
     * The computed styles of one element by ref (only values that differ from the browser default, or the listed
     * `properties`), its box model, and which CSS rule sets each value (selector, stylesheet, the custom property it
     * came from).
     *
     * @example {"ref": "e12"}
     * @example {"ref": "e12", "properties": ["background-color", "border-radius", "font-family"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('styles and texts of a third-party web page')]
    #[MCPToolRefreshesState('the inspection reads the page as it is now')]
    #[MCPToolExecutionTimeout(80, 'one inspection in the renderer; PHP waits up to 65 s for the renderer')]
    #[MCPToolSummary('Computed styles (non-default or listed properties), box model and the CSS rules behind each value, for one element ref.')]
    public function browserInspect(
        BrowserInspectRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $browserAgentSessionsService->requireInteractiveBrowsing();
        BrowserSessionMCPPackage::throwUnlessValidRef($requestDto->ref, __FUNCTION__, true);
        $properties = [];
        foreach ($requestDto->properties as $property) {
            if (is_string($property) && preg_match('/^-{0,2}[a-z][a-z0-9-]{0,63}$/i', trim($property)) === 1) {
                $properties[] = strtolower(trim($property));
            }
        }
        $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, false);
        $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_ANALYSE, $browserSession->sessionKey);
        $browserSessionRequest->analysisName = 'inspect';
        $browserSessionRequest->ref = trim($requestDto->ref);
        $browserSessionRequest->properties = $properties === [] ? null : array_values(array_unique($properties));
        $browserSessionAnswer = $browserAgentSessionsService->runAction($browserSession, $browserSessionRequest, __FUNCTION__);
        $refusalText = $browserAgentAnswersService->buildRefusalText($browserSessionAnswer);
        if ($refusalText !== null) {
            throw new BadRequestException($refusalText);
        }
        return BrowserMcpResponseDto::withText(
            'Element ' . trim($requestDto->ref) . ':' . "\n" . $browserAgentAnswersService->wrapPageText((string)($browserSessionAnswer->analysisSummary ?? 'The renderer returned no summary.'))
        );
    }

    /**
     * Files of the session's current page into the workspace, through the host's file sink (type read from the bytes,
     * nothing executable, malware scan): `stylesheets` (resolved), `logo` (SVG without scripts, plus PNG),
     * `screenshots`, `fonts` (only when the operator asked; say that their licence rarely allows reuse). A foreign logo
     * is fine in an internal pitch, never in something published: say so when you place one.
     *
     * @example {"kinds": ["logo", "stylesheets"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WORKSPACE)]
    #[MCPToolReturnsUntrustedContent('files of a third-party web page')]
    #[MCPToolRefreshesState('the export reads the page as it is now')]
    #[MCPToolExecutionTimeout(90, 'the renderer fetches up to 20 files (≤ 25 MB each)')]
    #[MCPToolSummary('Save files of the session\'s page into the workspace via the file sink: stylesheets, logo, screenshots, fonts (fonts only on request; licence note).')]
    public function browserExportAssets(
        BrowserExportAssetsRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserFileSinksService $browserFileSinksService,
        BrowserSessionsService $browserSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $browserAgentSessionsService->requireInteractiveBrowsing();
        if (!$browserFileSinksService->hasFileSink()) {
            throw new BadRequestException('No file sink is set up on this platform: files of a page cannot be saved. Describe the assets instead.');
        }
        $kinds = array_values(array_intersect(static::ASSET_KINDS, array_map('strval', $requestDto->kinds)));
        if ($kinds === []) {
            throw new BadRequestException('`kinds` lists at least one of: ' . implode(', ', static::ASSET_KINDS) . '.');
        }
        $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, false);
        $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_ANALYSE, $browserSession->sessionKey);
        $browserSessionRequest->analysisName = 'assets';
        $browserSessionRequest->kinds = $kinds;
        $browserSessionAnswer = $browserAgentSessionsService->runAction($browserSession, $browserSessionRequest, __FUNCTION__);
        $refusalText = $browserAgentAnswersService->buildRefusalText($browserSessionAnswer);
        if ($refusalText !== null) {
            throw new BadRequestException($refusalText);
        }
        $maxAssets = max(1, (int)(Config::get('Browser.files.maxAssetsPerExport') ?? 20));
        $maxFileBytes = max(1, (int)(Config::get('Browser.files.maxFileBytes') ?? 26214400));
        $fileSinkContext = $browserAgentSessionsService->buildFileSinkContext($callProvenance, __FUNCTION__);
        $lines = [];
        $storedCount = 0;
        foreach ($browserSessionAnswer->assetFiles?->getElements() ?? [] as $browserAssetFile) {
            if ($storedCount >= $maxAssets) {
                $lines[] = '- more files were left out (at most ' . $maxAssets . ' per call)';
                break;
            }
            $bytes = base64_decode((string)$browserAssetFile->content, true);
            if ($bytes === false || $bytes === '' || strlen($bytes) > $maxFileBytes) {
                $lines[] = '- ' . ($browserAssetFile->name ?? 'a file') . ': not saved (empty, unreadable or larger than ' . intdiv($maxFileBytes, 1048576) . ' MB)';
                continue;
            }
            $browserFileToStore = new BrowserFileToStore();
            $browserFileToStore->kind = $browserAssetFile->kind;
            $browserFileToStore->sourceUrl = $browserAssetFile->sourceUrl;
            $browserFileToStore->pageUrl = $browserSession->currentUrl;
            $browserFileToStore->contentType = $browserFileSinksService->detectContentType($bytes, $browserAssetFile->mimeType);
            // the renderer's names (logo.png, stylesheet-01-main.css, …) are kept: design-tokens.json refers to them
            $browserFileToStore->fileName = $browserFileSinksService->sanitizeFileName($browserAssetFile->name, ($browserAssetFile->kind ?? 'asset') . '-' . ($storedCount + 1));
            $browserFileToStore->targetFolder = $browserFileSinksService->buildWebFolder($browserSession->currentUrl);
            $browserFileToStore->setBytes($bytes);
            $browserStoredFile = $browserFileSinksService->storeFile($browserFileToStore, $fileSinkContext);
            if (!$browserStoredFile->isStored()) {
                $lines[] = '- ' . $browserFileToStore->fileName . ': refused (' . $browserStoredFile->refusalReason . ')';
                continue;
            }
            $storedCount++;
            $lines[] = '- ' . $browserStoredFile->path . ' · ' . ($browserStoredFile->contentType ?? $browserFileToStore->contentType) . ' · '
                . number_format(($browserStoredFile->sizeBytes ?? strlen($bytes)) / 1024, 1) . ' KB · untrusted · from ' . $browserAssetFile->sourceUrl;
            $browserSessionsService->recordEvent($browserSession, BrowserSessionEvent::EVENT_ASSET_STORED, __FUNCTION__, $browserAssetFile->sourceUrl, (string)$browserStoredFile->path);
        }
        if ($lines === []) {
            return BrowserMcpResponseDto::withText('The page offers no files of the kinds ' . implode(', ', $kinds) . '.');
        }
        $notes = in_array('fonts', $kinds, true) ? ["\nFonts are licensed for their website: name them and use a free font in your own documents."] : [];
        return BrowserMcpResponseDto::withText('Saved ' . $storedCount . ' file(s) of ' . ($browserSession->currentUrl ?? 'the page') . ":\n" . implode("\n", $lines) . implode('', $notes));
    }
}
