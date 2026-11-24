<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Browser;

use DDD\Domain\AI\Services\Browser\BrowserSessionsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Deletes browser sessions and their log after `AI.Browser.sessions.retentionDays` (a daily cron; 500 per run). */
#[AsCommand(name: 'app:agent-browser:purge-sessions',
    aliases: ['app:browser:purge-sessions'], description: 'Deletes browser sessions and their log older than AI.Browser.sessions.retentionDays (500 per run; --dryRun counts)')]
class BrowserPurgeSessions extends Command
{
    protected function configure(): void
    {
        $this->addOption('dryRun', null, InputOption::VALUE_NONE, 'Count, delete nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var BrowserSessionsService $browserSessionsService */
        $browserSessionsService = DDDService::instance()->getService(BrowserSessionsService::class);
        $isDryRun = (bool)$input->getOption('dryRun');
        $sessionCount = $browserSessionsService->purgeExpiredSessions($isDryRun);
        (new SymfonyStyle($input, $output))->success(($isDryRun ? 'would delete ' : 'deleted ') . $sessionCount . ' browser session(s) with their log');
        return Command::SUCCESS;
    }
}
