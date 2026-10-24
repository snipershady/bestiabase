<?php

declare(strict_types=1);

namespace App\Blast\Result;

/**
 * Una riga "Query" o "Sbjct" del pairwise: coordinate di inizio e fine e sequenza con i gap.
 */
final readonly class AlignmentRow
{
    public function __construct(
        public int $start,
        public string $sequence,
        public int $end,
    ) {
    }
}
