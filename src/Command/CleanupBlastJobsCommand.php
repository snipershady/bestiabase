<?php

declare(strict_types=1);

namespace App\Command;

use App\Blast\Job\JobCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:blast:cleanup',
    description: 'Deletes finished, failed and cancelled jobs older than the retention period, with their results and files',
)]
final readonly class CleanupBlastJobsCommand
{
    public function __construct(
        private JobCleaner $cleaner,
        #[Autowire('%env(int:BLAST_JOB_RETENTION_DAYS)%')]
        private int $defaultRetentionDays,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Retention in days (default: BLAST_JOB_RETENTION_DAYS)')]
        ?int $days = null,
        #[Option('Only count the jobs that would be deleted')]
        bool $dryRun = false,
    ): int {
        $retention = $days ?? $this->defaultRetentionDays;

        if ($retention < 1) {
            $io->error('The retention must be at least 1 day.');

            return Command::INVALID;
        }

        $count = $this->cleaner->purgeOlderThan($retention, $dryRun);

        $io->success(\sprintf($dryRun ? '%d jobs older than %d days would be deleted.' : '%d jobs older than %d days deleted.', $count, $retention));

        return Command::SUCCESS;
    }
}
