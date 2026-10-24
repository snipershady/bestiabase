<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Repository\BlastDatabaseRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastProgram::class)]
final class BlastDatabaseRepositoryTest extends DatabaseTestCase
{
    public function testSelectableDatabasesAreActivePublicAndEnabledForTheProgram(): void
    {
        $database = $this->database('zeta_db', MolType::Nucleotide);
        $alpha = $this->database('alpha_db', MolType::Nucleotide);
        $inactive = $this->database('inactive_db', MolType::Nucleotide);
        $inactive->deactivate();

        $private = $this->database('private_db', MolType::Nucleotide);
        $private->restrict();

        $blastnOnly = $this->database('blastn_only_db', MolType::Nucleotide);
        $blastnOnly->disableProgram(BlastProgram::Tblastn);

        $protein = $this->database('protein_db', MolType::Protein);
        $this->persistAndReload($database, $alpha, $inactive, $private, $blastnOnly, $protein);

        self::getContainer()->get(BlastDatabaseRepository::class);

        self::assertSame(['alpha_db', 'zeta_db'], $this->selectable(BlastProgram::Tblastn, null));
        self::assertSame(['alpha_db', 'blastn_only_db', 'zeta_db'], $this->selectable(BlastProgram::Blastn, null));
        self::assertSame(['protein_db'], $this->selectable(BlastProgram::Blastx, null));
    }

    /**
     * @param non-empty-string $code
     */
    private function database(string $code, MolType $molType): BlastDatabase
    {
        $database = EntityFactory::database($code, $molType);
        $database->rename($code);
        $database->enableAllCompatiblePrograms();

        return $database;
    }

    /**
     * Database utilizzabili con il programma: searchableBy() più il filtro sul programma.
     *
     * @return list<string>
     */
    private function selectable(BlastProgram $program, ?User $user): array
    {
        /** @var list<BlastDatabase> $databases */
        $databases = self::getContainer()->get(BlastDatabaseRepository::class)->searchableBy($user)
            ->innerJoin('d.programs', 'p')
            ->andWhere('p.program = :program')
            ->setParameter('program', $program->value)
            ->getQuery()
            ->getResult();

        return array_map(static fn (BlastDatabase $database): string => $database->getCode(), $databases);
    }
}
