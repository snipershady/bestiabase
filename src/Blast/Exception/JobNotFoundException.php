<?php

declare(strict_types=1);

namespace App\Blast\Exception;

use App\Exception\BestiaBaseException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class JobNotFoundException extends RuntimeException implements BestiaBaseException
{
    public static function withId(Uuid $id): self
    {
        return new self(\sprintf('BLAST job "%s" does not exist.', $id->toRfc4122()));
    }
}
