<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\JobStatus;
use LogicException;

final class InvalidJobTransitionException extends LogicException implements BestiaBaseException
{
    public static function create(JobStatus $from, JobStatus $to): self
    {
        return new self(\sprintf('A BLAST job cannot move from "%s" to "%s".', $from->value, $to->value));
    }
}
