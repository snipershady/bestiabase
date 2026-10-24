<?php

declare(strict_types=1);

namespace App\Blast\Result;

/**
 * Blocco di 60 colonne del pairwise: Query, riga di match, Sbjct.
 */
final readonly class AlignmentBlock
{
    public function __construct(
        public AlignmentRow $query,
        public string $midline,
        public AlignmentRow $subject,
    ) {
    }
}
