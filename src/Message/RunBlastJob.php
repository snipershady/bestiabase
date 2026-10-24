<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Chiede a un worker di eseguire un job BLAST. Porta solo l'id: tutto il resto è nel DB, così
 * il messaggio resta valido anche se viene consegnato più volte o in ritardo.
 */
#[AsMessage('async')]
final readonly class RunBlastJob
{
    /**
     * @param string $jobId UUID RFC 4122 del job
     */
    public function __construct(
        public string $jobId,
    ) {
    }
}
