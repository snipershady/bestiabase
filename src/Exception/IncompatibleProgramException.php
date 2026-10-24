<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\BlastProgram;
use App\Enum\MolType;
use DomainException;

final class IncompatibleProgramException extends DomainException implements BestiaBaseException
{
    public static function forDatabase(BlastProgram $program, MolType $molType): self
    {
        return new self(\sprintf(
            'Program "%s" searches %s databases and cannot use a %s database.',
            $program->value,
            $program->databaseMolType()->value,
            $molType->value,
        ));
    }
}
