<?php

declare(strict_types=1);

namespace App\Service\Blast;

use App\Enum\BlastProgram;
use App\Service\Blast\Exception\BlastExecutionException;

/**
 * Versione del binario BLAST+ (`<programma> -version` → "blastn: 2.17.0+"), salvata nel job:
 * i risultati dipendono dalla versione e BLAST_BIN_DIR può cambiare tra un job e l'altro.
 */
final readonly class BlastVersionReader
{
    public function __construct(
        private BlastBinaryLocator $locator,
        private BlastProcessExecutor $executor,
    ) {
    }

    /**
     * @throws BlastExecutionException
     */
    public function read(BlastProgram $program): ?string
    {
        $binary = $this->locator->program($program);
        $result = $this->executor->execute($program->value, [$binary, '-version'], \dirname($binary));

        return preg_match('/^' . $program->value . ':\s*(\S+)/m', $result->output, $match) === 1 ? $match[1] : null;
    }
}
