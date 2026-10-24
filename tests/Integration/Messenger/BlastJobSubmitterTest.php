<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastCommandBuilder;
use App\Blast\Exception\InvalidSearchParametersException;
use App\Blast\Fasta\FastaDocument;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Fasta\FastaRecord;
use App\Blast\Job\BlastJobFactory;
use App\Blast\Job\BlastJobSubmitter;
use App\Blast\Job\BlastSearchRequest;
use App\Blast\Search\SearchParametersNormalizer;
use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastJob;
use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Message\RunBlastJob;
use App\Repository\BlastDatabaseRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(BlastJobSubmitter::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(InvalidSearchParametersException::class)]
#[UsesClass(FastaDocument::class)]
#[UsesClass(FastaNormalizer::class)]
#[UsesClass(FastaRecord::class)]
#[UsesClass(BlastJobFactory::class)]
#[UsesClass(BlastSearchRequest::class)]
#[UsesClass(SearchParametersNormalizer::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(RunBlastJob::class)]
#[UsesClass(BlastDatabaseRepository::class)]
final class BlastJobSubmitterTest extends DatabaseTestCase
{
    public function testJobIsSavedAndQueuedForAWorker(): void
    {
        $database = EntityFactory::database('test_na_db');
        $database->enableAllCompatiblePrograms();
        $this->persistAndReload($database);
        $database = $this->entityManager->getRepository($database::class)->findOneBy(['code' => 'test_na_db']);
        self::assertNotNull($database);

        $job = self::getContainer()->get(BlastJobSubmitter::class)->submit(new BlastSearchRequest(BlastProgram::Blastn, ">q1\nACGT\n", [$database]));

        $this->entityManager->clear();
        $saved = $this->entityManager->find(BlastJob::class, $job->getId());
        self::assertInstanceOf(BlastJob::class, $saved);
        self::assertSame(JobStatus::Pending, $saved->getStatus());

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RunBlastJob::class, $message);
        self::assertSame($job->getId()->toRfc4122(), $message->jobId);
    }

    public function testInvalidSearchIsNeitherSavedNorQueued(): void
    {
        try {
            self::getContainer()->get(BlastJobSubmitter::class)->submit(new BlastSearchRequest(BlastProgram::Blastn, ">q1\nACGT\n"));
            self::fail('a search without targets was accepted');
        } catch (InvalidSearchParametersException) {
        }

        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_job'));
        self::assertSame([], $this->transport()->getSent());
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
