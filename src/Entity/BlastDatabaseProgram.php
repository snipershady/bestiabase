<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BlastProgram;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Programma BLAST abilitato su un database (ex righe `program: db => titolo` di viroblast.ini).
 */
#[ORM\Entity]
#[ORM\Table(name: 'blast_database_program')]
class BlastDatabaseProgram
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: BlastDatabase::class, inversedBy: 'programs')]
        #[ORM\JoinColumn(name: 'blast_database_id', onDelete: 'CASCADE')]
        private readonly BlastDatabase $database,
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 16, enumType: BlastProgram::class)]
        private readonly BlastProgram $program,
    ) {
    }

    public function getDatabase(): BlastDatabase
    {
        return $this->database;
    }

    public function getProgram(): BlastProgram
    {
        return $this->program;
    }
}
