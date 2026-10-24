<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Database;

use App\Blast\Database\BlastDatabaseStorage;
use App\Entity\BlastDatabase;
use App\Enum\MolType;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlastDatabaseStorage::class)]
#[UsesClass(BlastDatabase::class)]
final class BlastDatabaseStorageTest extends TestCase
{
    public function testNewDatabasesGetARelativePathByMoleculeType(): void
    {
        $storage = new BlastDatabaseStorage('/srv/blastdb/');

        self::assertSame('nucleotide/test_na_db', $storage->relativePathFor('test_na_db', MolType::Nucleotide));
        self::assertSame('protein/test_aa_db', $storage->relativePathFor('test_aa_db', MolType::Protein));
    }

    public function testRelativePathsFollowTheConfiguredDirectory(): void
    {
        $database = new BlastDatabase('test_na_db', 'T', MolType::Nucleotide, 'nucleotide/test_na_db', EntityFactory::now());

        self::assertSame('/srv/blastdb/nucleotide/test_na_db', new BlastDatabaseStorage('/srv/blastdb')->volumesOf($database));
        self::assertSame('/mnt/new/nucleotide/test_na_db', new BlastDatabaseStorage('/mnt/new/')->volumesOf($database), 'moving the storage needs no data change');
    }

    public function testAbsolutePathsOfExternalDatabasesAreKept(): void
    {
        self::assertSame('/data/ncbi/nt', new BlastDatabaseStorage('/srv/blastdb')->absolute('/data/ncbi/nt'));
    }
}
