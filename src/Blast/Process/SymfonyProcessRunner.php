<?php

declare(strict_types=1);

namespace App\Blast\Process;

use Override;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class SymfonyProcessRunner implements ProcessRunnerInterface
{
    #[Override]
    public function run(array $command, string $workingDirectory, float $timeout, array $environment = []): ProcessResult
    {
        $process = new Process($command, $workingDirectory, $environment, null, $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ProcessResult(
                $process->getExitCode() ?? -1,
                $process->getOutput(),
                $process->getErrorOutput(),
                true,
            );
        }

        return new ProcessResult($process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput());
    }
}
