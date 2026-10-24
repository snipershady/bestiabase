<?php

declare(strict_types=1);

namespace App\Blast\Exception;

use App\Enum\BlastProgram;
use App\Exception\BestiaBaseException;
use DomainException;

final class InvalidSearchParametersException extends DomainException implements BestiaBaseException
{
    public static function unsupportedOption(string $option, BlastProgram $program): self
    {
        return new self(\sprintf('Option "%s" is not available for %s.', $option, $program->value));
    }

    public static function outOfRange(string $option, string $constraint): self
    {
        return new self(\sprintf('Option "%s" must be %s.', $option, $constraint));
    }

    public static function noTarget(): self
    {
        return new self('Select at least one database or upload a FASTA file to search against.');
    }
}
