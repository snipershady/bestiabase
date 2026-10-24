<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Job;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastCommandBuilder;
use App\Blast\Exception\DatabaseUnavailableException;
use App\Blast\Exception\InvalidFastaException;
use App\Blast\Exception\InvalidSearchParametersException;
use App\Blast\Fasta\FastaDocument;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Fasta\FastaRecord;
use App\Blast\Job\BlastJobFactory;
use App\Blast\Job\BlastSearchRequest;
use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\SearchParameters;
use App\Blast\Search\SearchParametersNormalizer;
use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastJob;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Enum\MolType;
use App\Enum\SearchType;
use App\Exception\IncompatibleProgramException;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FakeBlastInstallation;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Factory\TimeBasedUuidFactory;
use Symfony\Component\Uid\UuidV7;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[CoversClass(BlastJobFactory::class)]
#[CoversClass(BlastSearchRequest::class)]
#[CoversClass(DatabaseUnavailableException::class)]
#[CoversClass(InvalidSearchParametersException::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(FastaNormalizer::class)]
#[UsesClass(FastaDocument::class)]
#[UsesClass(FastaRecord::class)]
#[UsesClass(SearchParameters::class)]
#[UsesClass(SearchParametersNormalizer::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(InvalidFastaException::class)]
#[UsesClass(IncompatibleProgramException::class)]
final class BlastJobFactoryTest extends TestCase
{
    private FakeBlastInstallation $blast;

    private BlastJobFactory $factory;

    #[Override]
    protected function setUp(): void
    {
        $this->blast = new FakeBlastInstallation();
        $this->factory = new BlastJobFactory(
            new FastaNormalizer(1_000_000),
            new SearchParametersNormalizer(new EffectivePrimitiveTypeIdentifierService()),
            new BlastCommandBuilder($this->blast->locator()),
            new TimeBasedUuidFactory(UuidV7::class),
            new MockClock(EntityFactory::NOW),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->blast->remove();
    }

    public function testBasicSearchOnRegisteredDatabases(): void
    {
        $user = EntityFactory::user();
        $job = $this->factory->create(new BlastSearchRequest(
            program: BlastProgram::Blastn,
            queryFasta: "junk\r\n>q1 first\r\nACGT\r\n",
            databases: [EntityFactory::persistedDatabase(7, 'test_na_db')],
            user: $user,
        ));

        self::assertInstanceOf(UuidV7::class, $job->getId());
        self::assertSame(JobStatus::Pending, $job->getStatus());
        self::assertSame(SearchType::Basic, $job->getSearchType());
        self::assertSame([], $job->getParameters());
        self::assertSame([['id' => 7, 'code' => 'test_na_db', 'title' => 'Title of test_na_db']], $job->getDatabases());
        self::assertSame(">q1 first\nACGT\n", $job->getQueryFasta());
        self::assertNull($job->getSubjectFasta());
        self::assertSame($user, $job->getUser());
        self::assertSame(EntityFactory::NOW, $job->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testAdvancedSearchStoresTheParameterSnapshot(): void
    {
        $job = $this->factory->create(new BlastSearchRequest(
            program: BlastProgram::Blastp,
            queryFasta: 'MKVLAAW',
            databases: [EntityFactory::persistedDatabase(3, 'test_aa_db', MolType::Protein)],
            parameters: SearchParameters::defaultsFor(BlastProgram::Blastp),
        ));

        self::assertSame(SearchType::Advanced, $job->getSearchType());
        self::assertSame('BLOSUM62', $job->getParameters()['matrix'] ?? null);
        self::assertSame(">\nMKVLAAW\n", $job->getQueryFasta());
    }

    public function testUploadedSubjectIsNormalizedForTheDatabaseMoleculeType(): void
    {
        $job = $this->factory->create(new BlastSearchRequest(
            program: BlastProgram::Tblastn,
            queryFasta: ">p\nMKV\n",
            subjectFasta: ">s1\r\nacgt\r\n",
        ));

        self::assertSame([], $job->getDatabases());
        self::assertSame(">s1\nacgt\n", $job->getSubjectFasta());
    }

    public function testSearchWithoutTargetsIsRejected(): void
    {
        $this->expectException(InvalidSearchParametersException::class);
        $this->expectExceptionMessageIsOrContains('Select at least one database or upload a FASTA file');

        $this->factory->create(new BlastSearchRequest(BlastProgram::Blastn, ">q\nACGT\n"));
    }

    public function testDatabaseForAnotherMoleculeTypeIsRejected(): void
    {
        $this->expectException(IncompatibleProgramException::class);

        $this->factory->create(new BlastSearchRequest(BlastProgram::Blastp, ">q\nMKV\n", [EntityFactory::persistedDatabase(1, 'test_na_db')]));
    }

    public function testRestrictedDatabaseIsOnlyAvailableToAuthorizedUsersOrFromTheCli(): void
    {
        $database = EntityFactory::persistedDatabase(5, 'mouse_db');
        $database->restrict();

        $member = EntityFactory::user();
        $database->authorize($member);

        self::assertSame($member, $this->factory->create(new BlastSearchRequest(BlastProgram::Blastn, 'ACGT', [$database], user: $member))->getUser());
        self::assertNull($this->factory->create(new BlastSearchRequest(BlastProgram::Blastn, 'ACGT', [$database], trusted: true))->getUser(), 'the CLI is trusted');

        $this->expectException(DatabaseUnavailableException::class);
        $this->expectExceptionMessageIsOrContains('BLAST database "mouse_db" is not available.');

        $this->factory->create(new BlastSearchRequest(BlastProgram::Blastn, 'ACGT', [$database]));
    }

    public function testUnsavedDatabaseIsRejected(): void
    {
        $database = EntityFactory::database();
        $database->enableAllCompatiblePrograms();

        $this->expectException(DatabaseUnavailableException::class);
        $this->expectExceptionMessageIsOrContains('"test_na_db" must be registered');

        $this->factory->create(new BlastSearchRequest(BlastProgram::Blastn, ">q\nACGT\n", [$database]));
    }

    public function testParametersUnsupportedByTheProgramAreRejectedAtSubmission(): void
    {
        $this->expectException(InvalidSearchParametersException::class);

        $this->factory->create(new BlastSearchRequest(
            program: BlastProgram::Tblastx,
            queryFasta: ">q\nACGT\n",
            databases: [EntityFactory::persistedDatabase(1)],
            parameters: new SearchParameters(10.0, 3, 50, ungapped: true),
        ));
    }

    public function testProteinQueryForANucleotideProgramIsRejected(): void
    {
        $this->expectException(InvalidFastaException::class);

        $this->factory->create(new BlastSearchRequest(BlastProgram::Blastn, ">q\nMKVLEFQ\n", [EntityFactory::persistedDatabase(1)]));
    }
}
