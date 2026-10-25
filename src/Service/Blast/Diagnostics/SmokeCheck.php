<?php

declare(strict_types=1);

namespace App\Service\Blast\Diagnostics;

final readonly class SmokeCheck
{
    public function __construct(
        public string $name,
        public bool $passed,
        public string $detail,
    ) {
    }
}
