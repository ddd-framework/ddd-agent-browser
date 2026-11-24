<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Browser;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererDeploymentCheck;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererHealth;
use DDD\Domain\AI\Services\Browser\BrowserRendererService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The deploy check of the browser renderer: each configured deployment answers `/healthz` with a contract version this
 * package works with, and the sessions deployment runs in role `session` or `all`. Exit 1 on any problem.
 */
#[AsCommand(
    name: 'app:agent-browser:check-renderer',
    description: 'Checks the browser renderer deployments: /healthz answers, the contract version matches this package (BrowserRendererContract), the sessions deployment serves sessions. Exit 1 on a problem',
    aliases: ['app:browser:check-renderer']
)]
class BrowserCheckRenderer extends Command
{
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var BrowserRendererService $browserRendererService */
        $browserRendererService = DDDService::instance()->getService(BrowserRendererService::class);
        $deploymentChecks = [];
        $baseUrl = $browserRendererService->getBaseUrl();
        $sessionBaseUrl = $browserRendererService->getSessionBaseUrl();
        if ($baseUrl !== null) {
            $deploymentChecks[] = $this->checkDeployment('stateless', $baseUrl, $browserRendererService->probeHealth($baseUrl), false);
        }
        if ($sessionBaseUrl !== null && $sessionBaseUrl !== $baseUrl) {
            $deploymentChecks[] = $this->checkDeployment('sessions', $sessionBaseUrl, $browserRendererService->probeHealth($sessionBaseUrl), true);
        } elseif ($sessionBaseUrl !== null && $deploymentChecks !== []) {
            // one deployment serves both: it must also serve sessions
            $deploymentChecks[0] = $this->checkDeployment('stateless+sessions', $baseUrl, $browserRendererService->probeHealth((string)$baseUrl), true);
        }
        $isHealthy = $deploymentChecks !== [];
        foreach ($deploymentChecks as $deploymentCheck) {
            $isHealthy = $isHealthy && $deploymentCheck->problems === [];
        }
        if ($input->getOption('format') === 'json') {
            $output->writeln((string)json_encode([
                'packageContractVersion' => BrowserRendererContract::CONTRACT_VERSION,
                'minimumContractVersion' => BrowserRendererContract::MINIMUM_CONTRACT_VERSION,
                'healthy' => $isHealthy,
                'deployments' => array_map([$this, 'buildDeploymentCheckRow'], $deploymentChecks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $isHealthy ? Command::SUCCESS : Command::FAILURE;
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        if ($deploymentChecks === []) {
            $symfonyStyle->error('No browser renderer is configured (AI.Browser.renderer: endpoint / endpointEnv).');
            return Command::FAILURE;
        }
        $tableRows = [];
        foreach ($deploymentChecks as $deploymentCheck) {
            $tableRows[] = [
                $deploymentCheck->deployment,
                $deploymentCheck->baseUrl,
                $deploymentCheck->health?->status ?? '—',
                $deploymentCheck->health?->role ?? '—',
                $deploymentCheck->health?->contractVersion ?? '—',
                $deploymentCheck->health?->chromeVersion ?? '—',
                $deploymentCheck->problems === [] ? 'OK' : implode('; ', $deploymentCheck->problems),
            ];
        }
        $symfonyStyle->table(['Deployment', 'Base URL', 'Status', 'Role', 'Contract', 'Chrome', 'Check'], $tableRows);
        if (!$isHealthy) {
            $symfonyStyle->error('The browser renderer does not fit this package (contract ' . BrowserRendererContract::CONTRACT_VERSION . ', at least ' . BrowserRendererContract::MINIMUM_CONTRACT_VERSION . ').');
            return Command::FAILURE;
        }
        $symfonyStyle->success('The browser renderer answers with a matching contract.');
        return Command::SUCCESS;
    }

    protected function checkDeployment(string $deploymentName, string $baseUrl, BrowserRendererHealth $browserRendererHealth, bool $mustServeSessions): BrowserRendererDeploymentCheck
    {
        $problems = [];
        if ($browserRendererHealth->httpStatusCode === null) {
            $problems[] = 'no answer (' . ($browserRendererHealth->failureReason ?? 'unreachable') . ')';
        } elseif (!$browserRendererHealth->isReady()) {
            $problems[] = 'not ready (HTTP ' . $browserRendererHealth->httpStatusCode . ', status ' . ($browserRendererHealth->status ?? '?') . ')';
        }
        if ($browserRendererHealth->httpStatusCode !== null && !BrowserRendererContract::isCompatibleContractVersion($browserRendererHealth->contractVersion)) {
            $problems[] = 'contract ' . ($browserRendererHealth->contractVersion ?? 'unknown') . ' does not serve this package (needs ' . BrowserRendererContract::MINIMUM_CONTRACT_VERSION . ' or newer within the major)';
        }
        if ($mustServeSessions && $browserRendererHealth->httpStatusCode !== null && !$browserRendererHealth->servesSessions()) {
            $problems[] = 'role ' . ($browserRendererHealth->role ?? 'unknown') . ' answers no session routes (needs RENDER_ROLE=session or all)';
        }
        $browserRendererDeploymentCheck = new BrowserRendererDeploymentCheck();
        $browserRendererDeploymentCheck->deployment = $deploymentName;
        $browserRendererDeploymentCheck->baseUrl = $baseUrl;
        $browserRendererDeploymentCheck->health = $browserRendererHealth;
        $browserRendererDeploymentCheck->problems = $problems;
        return $browserRendererDeploymentCheck;
    }

    /** The JSON row of a check (the command's output format) */
    public function buildDeploymentCheckRow(BrowserRendererDeploymentCheck $browserRendererDeploymentCheck): object
    {
        return (object)[
            'deployment' => $browserRendererDeploymentCheck->deployment,
            'baseUrl' => $browserRendererDeploymentCheck->baseUrl,
            'status' => $browserRendererDeploymentCheck->health?->status,
            'role' => $browserRendererDeploymentCheck->health?->role,
            'contractVersion' => $browserRendererDeploymentCheck->health?->contractVersion,
            'chromeVersion' => $browserRendererDeploymentCheck->health?->chromeVersion,
            'httpStatusCode' => $browserRendererDeploymentCheck->health?->httpStatusCode,
            'problems' => $browserRendererDeploymentCheck->problems,
        ];
    }
}
