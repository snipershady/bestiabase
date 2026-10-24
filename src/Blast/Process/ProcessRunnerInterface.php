<?php

declare(strict_types=1);

namespace App\Blast\Process;

/**
 * Esecuzione di processi esterni. Il comando è sempre un argv (mai una stringa di shell).
 */
interface ProcessRunnerInterface
{
    /**
     * @param non-empty-list<string> $command     argv: il primo elemento è il path del binario
     * @param float                  $timeout     secondi massimi di esecuzione; allo scadere il processo viene terminato
     * @param array<string, string>  $environment variabili aggiunte all'ambiente ereditato
     */
    public function run(array $command, string $workingDirectory, float $timeout, array $environment = []): ProcessResult;
}
