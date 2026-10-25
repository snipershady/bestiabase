<?php

declare(strict_types=1);

namespace App\Service\Blast\Xml;

/**
 * Valori di un `<Hsp>` così come scritti da BLAST+.
 */
final readonly class ParsedHsp
{
    public function __construct(
        public int $number,
        public float $bitScore,
        public float $score,
        public string $eValue,
        public int $queryFrom,
        public int $queryTo,
        public int $hitFrom,
        public int $hitTo,
        public int $queryFrame,
        public int $hitFrame,
        public int $identity,
        public int $positive,
        public int $gaps,
        public int $alignLength,
        public string $querySequence,
        public string $hitSequence,
        public string $midline,
    ) {
    }
}
