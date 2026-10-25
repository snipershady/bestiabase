<?php

declare(strict_types=1);

namespace App\Service\Blast\Exception;

use App\Exception\BestiaBaseException;
use DomainException;

final class DatabaseImportException extends DomainException implements BestiaBaseException
{
    public static function invalidCode(string $code): self
    {
        return new self(\sprintf('Database code "%s" is invalid: use 1-128 letters, digits, "_" or "-", starting with a letter or digit.', $code));
    }

    public static function duplicateCode(string $code): self
    {
        return new self(\sprintf('A BLAST database with code "%s" is already registered.', $code));
    }

    public static function unreadableSource(string $path): self
    {
        return new self(\sprintf('FASTA file "%s" does not exist or is not readable.', $path));
    }

    public static function invalidStoragePath(string $path): self
    {
        return new self(\sprintf('BLAST database path "%s" must not contain whitespace (check BLAST_DB_DIR).', $path));
    }

    public static function invalidMetadata(string $path, string $reason): self
    {
        return new self(\sprintf('Cannot read the metadata of BLAST database "%s": %s', $path, $reason));
    }
}
