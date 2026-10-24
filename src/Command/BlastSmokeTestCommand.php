<?php

declare(strict_types=1);

namespace App\Command;

use App\Blast\Diagnostics\BlastSmokeTest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:blast:smoke-test',
    description: 'Checks the BLAST+ installation in BLAST_BIN_DIR (run it after every upgrade of BLAST+)',
)]
final readonly class BlastSmokeTestCommand
{
    public function __construct(
        private BlastSmokeTest $smokeTest,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $failures = 0;

        foreach ($this->smokeTest->run() as $check) {
            $io->writeln(\sprintf('%s <comment>%s</comment>: %s', $check->passed ? '<info>OK</info>  ' : '<error>FAIL</error>', $check->name, $check->detail));
            $failures += $check->passed ? 0 : 1;
        }

        if ($failures > 0) {
            $io->error(\sprintf('%d check(s) failed: do not put this BLAST+ installation in production.', $failures));

            return Command::FAILURE;
        }

        $io->success('BLAST+ installation ready.');

        return Command::SUCCESS;
    }
}
