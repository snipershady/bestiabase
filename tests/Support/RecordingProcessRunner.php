<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Blast\Process\ProcessResult;
use App\Blast\Process\ProcessRunnerInterface;
use Closure;
use Override;

/**
 * Registra i comandi ricevuti e restituisce, in ordine, i risultati programmati
 * (successo vuoto quando la coda è finita).
 */
final class RecordingProcessRunner implements ProcessRunnerInterface
{
    /** @var list<array{command: non-empty-list<string>, workingDirectory: string, timeout: float, environment: array<string, string>}> */
    public array $calls = [];

    /** @var list<ProcessResult> */
    private array $results;

    /** @var (Closure(non-empty-list<string>): void)|null effetto collaterale simulato (es. scrivere l'output) */
    private ?Closure $sideEffect = null;

    public function __construct(ProcessResult ...$results)
    {
        $this->results = array_values($results);
    }

    /**
     * @param Closure(non-empty-list<string>): void $sideEffect
     */
    public function onRun(Closure $sideEffect): self
    {
        $this->sideEffect = $sideEffect;

        return $this;
    }

    #[Override]
    public function run(array $command, string $workingDirectory, float $timeout, array $environment = []): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'workingDirectory' => $workingDirectory, 'timeout' => $timeout, 'environment' => $environment];

        if ($this->sideEffect instanceof Closure) {
            ($this->sideEffect)($command);
        }

        return array_shift($this->results) ?? new ProcessResult(0, '', '');
    }

    /**
     * @return list<non-empty-list<string>>
     */
    public function commands(): array
    {
        return array_map(static fn (array $call): array => $call['command'], $this->calls);
    }
}
