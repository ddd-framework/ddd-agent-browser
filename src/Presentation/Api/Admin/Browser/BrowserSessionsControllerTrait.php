<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Browser;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Services\Browser\BrowserSessionsAdminService;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Admin\Browser\Dtos\BrowserRendererGetResponseDto;
use DDD\Presentation\Api\Admin\Browser\Dtos\BrowserSessionClosePostRequestDto;
use DDD\Presentation\Api\Admin\Browser\Dtos\BrowserSessionEventsGetRequestDto;
use DDD\Presentation\Api\Admin\Browser\Dtos\BrowserSessionEventsGetResponseDto;
use DDD\Presentation\Api\Admin\Browser\Dtos\BrowserSessionGetRequestDto;
use DDD\Presentation\Api\Admin\Browser\Dtos\BrowserSessionGetResponseDto;
use DDD\Presentation\Api\Admin\Browser\Dtos\BrowserSessionsGetRequestDto;
use DDD\Presentation\Api\Admin\Browser\Dtos\BrowserSessionsGetResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The browser endpoints of an admin API: the agents' browser sessions (newest first, by status, conversation or
 * account), one session with its log, closing an open session, and the check of the renderer deployments
 * ({@see BrowserSessionsAdminService}). An application mounts them with one controller of its own, which sets the path
 * prefix, the tag and the access:
 *
 *     #[Route('/maintenance/browser')]
 *     #[Tag(group: 'Maintenance', name: 'BrowserSessions', description: 'The agents\' browser sessions and the renderer')]
 *     class AdminBrowserSessionsController extends AdminController
 *     {
 *         use BrowserSessionsControllerTrait;
 *
 *         protected function throwForbiddenUnlessBrowserAccessAllowed(): void { … superadmin only … }
 *     }
 *
 * URLs, titles and log details are third-party text: a screen shows them as text only.
 */
trait BrowserSessionsControllerTrait
{
    /**
     * The browser sessions, newest first; whether sessions are switched on and a renderer is configured.
     */
    #[Get('/sessions')]
    #[Summary('BrowserSessions List')]
    public function listBrowserSessions(
        BrowserSessionsGetRequestDto $requestDto,
        BrowserSessionsAdminService $browserSessionsAdminService
    ): BrowserSessionsGetResponseDto {
        $this->throwForbiddenUnlessBrowserAccessAllowed();
        $limit = $browserSessionsAdminService->normalizeSessionsLimit((int)$requestDto->limit);
        $browserSessions = $browserSessionsAdminService->findSessions(
            $requestDto->status,
            $requestDto->conversationId,
            $requestDto->accountId,
            $limit,
            (int)$requestDto->offset
        );
        $responseDto = new BrowserSessionsGetResponseDto();
        $responseDto->hasMore = $browserSessionsAdminService->hasMoreThan($browserSessions, $limit);
        $responseDto->sessions = $browserSessions;
        $responseDto->areSessionsEnabled = $browserSessionsAdminService->areSessionsEnabled();
        $responseDto->isConfigured = $browserSessionsAdminService->isSessionEndpointConfigured();
        return $responseDto;
    }

    /**
     * The renderer deployments checked against this package: `/healthz`, contract version, session role.
     */
    #[Get('/renderer')]
    #[Summary('BrowserRenderer Check')]
    public function getBrowserRenderer(BrowserSessionsAdminService $browserSessionsAdminService): BrowserRendererGetResponseDto
    {
        $this->throwForbiddenUnlessBrowserAccessAllowed();
        $responseDto = new BrowserRendererGetResponseDto();
        $responseDto->deploymentChecks = $browserSessionsAdminService->checkRendererDeployments();
        $responseDto->isHealthy = $responseDto->deploymentChecks->areAllHealthy();
        $responseDto->packageContractVersion = BrowserRendererContract::CONTRACT_VERSION;
        $responseDto->minimumContractVersion = BrowserRendererContract::MINIMUM_CONTRACT_VERSION;
        $responseDto->areSessionsEnabled = $browserSessionsAdminService->areSessionsEnabled();
        return $responseDto;
    }

    /**
     * One browser session.
     *
     * @throws NotFoundException
     */
    #[Get('/sessions/{browserSessionId}', requirements: ['browserSessionId' => '\d+'])]
    #[Summary('BrowserSession Details')]
    public function getBrowserSession(
        BrowserSessionGetRequestDto $requestDto,
        BrowserSessionsAdminService $browserSessionsAdminService
    ): BrowserSessionGetResponseDto {
        $this->throwForbiddenUnlessBrowserAccessAllowed();
        $responseDto = new BrowserSessionGetResponseDto();
        $responseDto->session = $browserSessionsAdminService->findSession($requestDto->browserSessionId);
        return $responseDto;
    }

    /**
     * The log of one browser session, oldest first.
     *
     * @throws NotFoundException
     */
    #[Get('/sessions/{browserSessionId}/events', requirements: ['browserSessionId' => '\d+'])]
    #[Summary('BrowserSession Events')]
    public function listBrowserSessionEvents(
        BrowserSessionEventsGetRequestDto $requestDto,
        BrowserSessionsAdminService $browserSessionsAdminService
    ): BrowserSessionEventsGetResponseDto {
        $this->throwForbiddenUnlessBrowserAccessAllowed();
        $browserSession = $browserSessionsAdminService->findSession($requestDto->browserSessionId);
        $responseDto = new BrowserSessionEventsGetResponseDto();
        $responseDto->events = $browserSessionsAdminService->findEventsOfSession($browserSession, $requestDto->limit);
        return $responseDto;
    }

    /**
     * Closes an open browser session: the renderer closes the context, the log names the reason; the agent's next
     * navigation opens a new session.
     *
     * @throws NotFoundException
     * @throws ConflictException When the session is no longer open
     */
    #[Post('/sessions/{browserSessionId}/close', requirements: ['browserSessionId' => '\d+'])]
    #[Summary('BrowserSession Close')]
    public function closeBrowserSession(
        BrowserSessionClosePostRequestDto $requestDto,
        BrowserSessionsAdminService $browserSessionsAdminService
    ): BrowserSessionGetResponseDto {
        $this->throwForbiddenUnlessBrowserAccessAllowed();
        $browserSession = $browserSessionsAdminService->findSession($requestDto->browserSessionId);
        $responseDto = new BrowserSessionGetResponseDto();
        $responseDto->session = $browserSessionsAdminService->closeSession($browserSession, $requestDto->reason);
        return $responseDto;
    }

    /**
     * The access hook of every browser endpoint, called first. Empty by default: the application restricts the path
     * (`access_control`) and overrides this for an account predicate (operators only) by throwing a
     * {@see ForbiddenException}.
     *
     * @throws ForbiddenException
     */
    protected function throwForbiddenUnlessBrowserAccessAllowed(): void
    {
    }
}
