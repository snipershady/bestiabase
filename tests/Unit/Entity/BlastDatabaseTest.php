<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Exception\IncompatibleProgramException;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlastDatabase::class)]
#[CoversClass(BlastDatabaseProgram::class)]
#[CoversClass(IncompatibleProgramException::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastProgram::class)]
final class BlastDatabaseTest extends TestCase
{
    public function testNewDatabaseIsActivePublicAndWithoutPrograms(): void
    {
        $user = EntityFactory::user();
        $database = new BlastDatabase('test_aa_db', 'Protein test database', MolType::Protein, '/srv/blastdb/test_aa_db', EntityFactory::now(), $user);

        self::assertNull($database->getId());
        self::assertSame('test_aa_db', $database->getCode());
        self::assertSame('Protein test database', $database->getTitle());
        self::assertSame(MolType::Protein, $database->getMolType());
        self::assertSame('/srv/blastdb/test_aa_db', $database->getPath());
        self::assertNull($database->getDescription());
        self::assertNull($database->getSourceFasta());
        self::assertTrue($database->isActive());
        self::assertTrue($database->isPublic());
        self::assertSame([], $database->getPrograms());
        self::assertNull($database->getSequenceCount());
        self::assertNull($database->getTotalLength());
        self::assertSame($user, $database->getCreatedBy());
        self::assertSame(EntityFactory::NOW, $database->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testMetadataCanBeEdited(): void
    {
        $database = EntityFactory::database();

        $database->rename('Renamed');
        $database->describe('Six sections of the E. coli genome');
        $database->attachSourceFasta('/data/fasta/test_na_db.fa');
        $database->deactivate();
        $database->restrict();

        self::assertSame('Renamed', $database->getTitle());
        self::assertSame('Six sections of the E. coli genome', $database->getDescription());
        self::assertSame('/data/fasta/test_na_db.fa', $database->getSourceFasta());
        self::assertFalse($database->isActive());
        self::assertFalse($database->isPublic());

        $database->activate();
        $database->publish();

        self::assertTrue($database->isActive());
        self::assertTrue($database->isPublic());
    }

    public function testCompatibleProgramsAreEnabledOnce(): void
    {
        $database = EntityFactory::database();

        $database->enableProgram(BlastProgram::Blastn);
        $database->enableProgram(BlastProgram::Blastn);

        self::assertSame([BlastProgram::Blastn], $database->getPrograms());
        self::assertTrue($database->supports(BlastProgram::Blastn));
        self::assertFalse($database->supports(BlastProgram::Tblastn));
    }

    public function testAllCompatibleProgramsMirrorTheLegacyIni(): void
    {
        $database = EntityFactory::database('test_na_db', MolType::Nucleotide);
        $protein = EntityFactory::database('test_aa_db', MolType::Protein);

        $database->enableAllCompatiblePrograms();
        $protein->enableAllCompatiblePrograms();

        self::assertSame([BlastProgram::Blastn, BlastProgram::Tblastn, BlastProgram::Tblastx], $database->getPrograms());
        self::assertSame([BlastProgram::Blastp, BlastProgram::Blastx], $protein->getPrograms());
    }

    public function testProgramForAnotherMoleculeTypeIsRejected(): void
    {
        $database = EntityFactory::database('test_na_db', MolType::Nucleotide);

        $this->expectException(IncompatibleProgramException::class);
        $this->expectExceptionMessageIsOrContains('Program "blastp" searches prot databases and cannot use a nucl database.');

        $database->enableProgram(BlastProgram::Blastp);
    }

    public function testProgramCanBeDisabled(): void
    {
        $database = EntityFactory::database();
        $database->enableAllCompatiblePrograms();

        $database->disableProgram(BlastProgram::Tblastn);

        self::assertSame([BlastProgram::Blastn, BlastProgram::Tblastx], $database->getPrograms());
    }

    public function testProgramLinkExposesItsDatabase(): void
    {
        $database = EntityFactory::database();
        $blastDatabaseProgram = new BlastDatabaseProgram($database, BlastProgram::Blastn);

        self::assertSame($database, $blastDatabaseProgram->getDatabase());
        self::assertSame(BlastProgram::Blastn, $blastDatabaseProgram->getProgram());
    }

    public function testStatisticsReadFromTheVolumesAreRecorded(): void
    {
        $database = EntityFactory::database();

        $database->recordStatistics(6, 69370);

        self::assertSame(6, $database->getSequenceCount());
        self::assertSame(69370, $database->getTotalLength());
    }

    public function testAccessRulesForPublicRestrictedAndInactiveDatabases(): void
    {
        $database = EntityFactory::database();
        $user = EntityFactory::user('user@example.org', 'user');
        $other = EntityFactory::user('other@example.org', 'other');
        $admin = EntityFactory::user('admin@example.org', 'admin');
        $admin->grantRole(User::ROLE_ADMIN);

        self::assertTrue($database->isSearchableBy(null), 'public: also anonymous visitors');

        $database->restrict();
        $database->authorize($user);
        $database->authorize($user);

        self::assertSame([$user], $database->getAuthorizedUsers());
        self::assertFalse($database->isAccessibleBy(null));
        self::assertFalse($database->isAccessibleBy($other));
        self::assertTrue($database->isAccessibleBy($user));
        self::assertTrue($database->isAccessibleBy($admin), 'administrators see every database');

        $database->deactivate();

        self::assertFalse($database->isSearchableBy($admin), 'inactive: no new searches');
        self::assertTrue($database->isAccessibleBy($user), 'inactive: existing results stay visible');

        $database->revoke($user);

        self::assertFalse($database->isAccessibleBy($user));
        self::assertSame([], $database->getAuthorizedUsers());
    }
}
