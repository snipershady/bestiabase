<?php

declare(strict_types=1);

namespace App\Blast\Xml;

/**
 * Valori di un `<Hit>` così come scritti da BLAST+.
 */
final readonly class ParsedHit
{
    /**
     * @param list<ParsedHsp> $hsps nell'ordine di BLAST (il primo è il migliore)
     */
    public function __construct(
        public int $number,
        public string $id,
        public string $definition,
        public string $accession,
        public int $length,
        public array $hsps,
    ) {
    }
}
