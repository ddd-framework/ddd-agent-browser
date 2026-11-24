<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Browser\Dtos;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererDeploymentChecks;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The renderer deployments checked against this package (the check of app:agent-browser:check-renderer) */
class BrowserRendererGetResponseDto extends RestResponseDto
{
    /** @var BrowserRendererDeploymentChecks One check per configured deployment; empty when none is configured */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public BrowserRendererDeploymentChecks $deploymentChecks;

    /** @var bool Every configured deployment answers and fits; false when none is configured */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $isHealthy = false;

    /** @var string The contract version of this package */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $packageContractVersion = '';

    /** @var string The lowest renderer contract this package works with */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $minimumContractVersion = '';

    /** @var bool Interactive sessions are switched on (AI.Browser.sessions.enabled) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $areSessionsEnabled = false;
}
