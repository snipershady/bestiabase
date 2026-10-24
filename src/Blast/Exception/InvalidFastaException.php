<?php

declare(strict_types=1);

namespace App\Blast\Exception;

use App\Exception\BestiaBaseException;
use DomainException;

final class InvalidFastaException extends DomainException implements BestiaBaseException
{
    public static function empty(): self
    {
        return new self('The sequence input is empty.');
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(\sprintf('The sequence input exceeds the maximum size of %d bytes.', $maxBytes));
    }

    public static function emptySequence(int $position): self
    {
        return new self(\sprintf('Sequence #%d has no residues.', $position));
    }

    public static function invalidResidues(int $position, string $residues): self
    {
        return new self(\sprintf('Sequence #%d contains invalid characters: %s', $position, $residues));
    }
}
