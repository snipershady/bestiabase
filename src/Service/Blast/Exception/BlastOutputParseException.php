<?php

declare(strict_types=1);

namespace App\Service\Blast\Exception;

use App\Exception\BestiaBaseException;
use RuntimeException;

final class BlastOutputParseException extends RuntimeException implements BestiaBaseException
{
    public static function unreadable(string $path): self
    {
        return new self(\sprintf('BLAST XML output "%s" cannot be read.', $path));
    }

    public static function malformed(string $path, string $reason): self
    {
        return new self(\sprintf('BLAST XML output "%s" is malformed: %s', $path, $reason));
    }
}
