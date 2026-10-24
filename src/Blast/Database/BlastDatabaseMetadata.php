<?php

declare(strict_types=1);

namespace App\Blast\Database;

final readonly class BlastDatabaseMetadata
{
    public function __construct(
        public int $sequences,
        public int $letters,
        public int $bytes,
    ) {
    }
}
