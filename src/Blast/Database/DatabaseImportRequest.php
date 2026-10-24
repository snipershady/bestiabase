<?php

declare(strict_types=1);

namespace App\Blast\Database;

use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;

final readonly class DatabaseImportRequest
{
    /**
     * @param string             $sourceFasta FASTA già presente sul server
     * @param string             $code        basename dei volumi e chiave univoca
     * @param list<BlastProgram> $programs    programmi abilitati; vuoto = tutti quelli compatibili
     * @param bool               $parseSeqids `-parse_seqids`: abilita accessioni e `blastdbcmd -entry`
     */
    public function __construct(
        public string $sourceFasta,
        public string $code,
        public string $title,
        public MolType $molType,
        public array $programs = [],
        public ?string $description = null,
        public bool $parseSeqids = true,
        public ?User $createdBy = null,
        public bool $restricted = false,
    ) {
    }
}
