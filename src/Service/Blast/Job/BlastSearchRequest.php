<?php

declare(strict_types=1);

namespace App\Service\Blast\Job;

use App\Entity\BlastDatabase;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Service\Blast\Search\SearchParameters;

/**
 * Richiesta di ricerca così come arriva da un form o dal CLI, prima della normalizzazione.
 */
final readonly class BlastSearchRequest
{
    /**
     * @param string              $queryFasta   FASTA o sequenza nuda, non ancora normalizzati
     * @param list<BlastDatabase> $databases    database registrati selezionati
     * @param ?SearchParameters   $parameters   null = ricerca "basic"
     * @param ?string             $subjectFasta FASTA caricato su cui cercare, non ancora normalizzato
     * @param bool                $trusted      richiesta da CLI (amministrazione): nessun controllo dei
     *                                          permessi sui database; dal web è sempre false
     */
    public function __construct(
        public BlastProgram $program,
        public string $queryFasta,
        public array $databases = [],
        public ?SearchParameters $parameters = null,
        public ?string $subjectFasta = null,
        public ?User $user = null,
        public bool $trusted = false,
    ) {
    }
}
