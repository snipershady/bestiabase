<?php

declare(strict_types=1);

namespace App\Blast\Job;

use App\Blast\Search\SearchParameters;
use App\Entity\BlastDatabase;
use App\Entity\User;
use App\Enum\BlastProgram;

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
