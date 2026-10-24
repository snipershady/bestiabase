<?php

declare(strict_types=1);

namespace App\Blast\Fasta;

final readonly class FastaRecord
{
    /**
     * @param string           $definition defline senza '>' (vuoto per una sequenza senza header)
     * @param non-empty-string $sequence   residui senza spazi né cifre, maiuscole/minuscole preservate
     */
    public function __construct(
        public string $definition,
        public string $sequence,
    ) {
    }

    public function length(): int
    {
        return \strlen($this->sequence);
    }
}
