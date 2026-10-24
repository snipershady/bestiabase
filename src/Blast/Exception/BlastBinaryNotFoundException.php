<?php

declare(strict_types=1);

namespace App\Blast\Exception;

use App\Exception\BestiaBaseException;
use RuntimeException;

final class BlastBinaryNotFoundException extends RuntimeException implements BestiaBaseException
{
    public static function at(string $path): self
    {
        return new self(\sprintf('BLAST+ binary "%s" does not exist or is not executable. Check BLAST_BIN_DIR.', $path));
    }
}
