<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Programmi BLAST+ supportati: il valore è il nome del binario.
 */
enum BlastProgram: string
{
    case Blastn = 'blastn';
    case Blastp = 'blastp';
    case Blastx = 'blastx';
    case Tblastn = 'tblastn';
    case Tblastx = 'tblastx';

    /**
     * @return list<self>
     */
    public static function forDatabaseMolType(MolType $molType): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $program): bool => $program->databaseMolType() === $molType,
        ));
    }

    public function queryMolType(): MolType
    {
        return match ($this) {
            self::Blastn, self::Blastx, self::Tblastx => MolType::Nucleotide,
            self::Blastp, self::Tblastn => MolType::Protein,
        };
    }

    public function databaseMolType(): MolType
    {
        return match ($this) {
            self::Blastn, self::Tblastn, self::Tblastx => MolType::Nucleotide,
            self::Blastp, self::Blastx => MolType::Protein,
        };
    }
}
