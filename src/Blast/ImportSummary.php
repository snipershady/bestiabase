<?php

declare(strict_types=1);

namespace App\Blast;

final readonly class ImportSummary
{
    public function __construct(
        public int $queries,
        public int $hits,
        public int $hsps,
    ) {
    }
}
