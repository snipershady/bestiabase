<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\BlastProgram;
use App\Enum\MolType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlastProgram::class)]
final class BlastProgramTest extends TestCase
{
    /**
     * @return iterable<string, array{BlastProgram, MolType, MolType}>
     */
    public static function molTypes(): iterable
    {
        yield 'blastn' => [BlastProgram::Blastn, MolType::Nucleotide, MolType::Nucleotide];
        yield 'blastp' => [BlastProgram::Blastp, MolType::Protein, MolType::Protein];
        yield 'blastx' => [BlastProgram::Blastx, MolType::Nucleotide, MolType::Protein];
        yield 'tblastn' => [BlastProgram::Tblastn, MolType::Protein, MolType::Nucleotide];
        yield 'tblastx' => [BlastProgram::Tblastx, MolType::Nucleotide, MolType::Nucleotide];
    }

    #[DataProvider('molTypes')]
    public function testMolTypesMatchTheLegacyDatabaseDirectories(BlastProgram $program, MolType $query, MolType $database): void
    {
        self::assertSame($query, $program->queryMolType());
        self::assertSame($database, $program->databaseMolType());
    }

    public function testProgramsForNucleotideDatabases(): void
    {
        self::assertSame([BlastProgram::Blastn, BlastProgram::Tblastn, BlastProgram::Tblastx], BlastProgram::forDatabaseMolType(MolType::Nucleotide));
    }

    public function testProgramsForProteinDatabases(): void
    {
        self::assertSame([BlastProgram::Blastp, BlastProgram::Blastx], BlastProgram::forDatabaseMolType(MolType::Protein));
    }
}
