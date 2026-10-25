<?php

declare(strict_types=1);

namespace App\Service\Blast;

use App\Enum\BlastProgram;
use App\Service\Blast\Exception\BlastBinaryNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Unico punto che conosce dove stanno i binari NCBI BLAST+ (env BLAST_BIN_DIR).
 * Aggiornare BLAST+ significa cambiare la directory, non il codice.
 */
final readonly class BlastBinaryLocator
{
    private string $binDir;

    public function __construct(
        #[Autowire(env: 'BLAST_BIN_DIR')]
        string $binDir,
    ) {
        $this->binDir = rtrim($binDir, '/');
    }

    /**
     * @throws BlastBinaryNotFoundException
     */
    public function program(BlastProgram $program): string
    {
        return $this->locate($program->value);
    }

    /**
     * @throws BlastBinaryNotFoundException
     */
    public function tool(BlastTool $tool): string
    {
        return $this->locate($tool->value);
    }

    private function locate(string $binary): string
    {
        $path = $this->binDir . '/' . $binary;

        if (!is_file($path) || !is_executable($path)) {
            throw BlastBinaryNotFoundException::at($path);
        }

        return $path;
    }
}
