<?php

declare(strict_types=1);

namespace App\Blast\Exception;

use App\Exception\BestiaBaseException;
use RuntimeException;

final class DatabaseUnavailableException extends RuntimeException implements BestiaBaseException
{
    /**
     * @param list<string> $codes
     */
    public static function removed(array $codes): self
    {
        return new self(\sprintf('The selected BLAST database(s) are no longer available: %s.', implode(', ', $codes)));
    }

    public static function notPersisted(string $code): self
    {
        return new self(\sprintf('BLAST database "%s" must be registered before it can be searched.', $code));
    }

    /**
     * Stesso messaggio per database inesistenti e riservati: non rivela quali database esistono.
     */
    public static function notSearchable(string $code): self
    {
        return new self(\sprintf('BLAST database "%s" is not available.', $code));
    }
}
