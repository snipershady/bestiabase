<?php

declare(strict_types=1);

namespace App\Blast\Xml;

/**
 * Una `<Iteration>`: una sequenza query con i suoi hit.
 */
final readonly class ParsedIteration
{
    /**
     * @param list<ParsedHit> $hits nell'ordine di BLAST (e-value crescente)
     */
    public function __construct(
        public int $number,
        public string $queryDefinition,
        public int $queryLength,
        public array $hits,
    ) {
    }
}
