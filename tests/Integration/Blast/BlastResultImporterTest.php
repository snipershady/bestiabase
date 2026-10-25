<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blast;

use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Repository\BlastHitRepository;
use App\Service\Blast\BlastResultImporter;
use App\Service\Blast\ImportSummary;
use App\Service\Blast\SequenceNames;
use App\Service\Blast\Xml\BlastXmlParser;
use App\Service\Blast\Xml\ParsedHit;
use App\Service\Blast\Xml\ParsedHsp;
use App\Service\Blast\Xml\ParsedIteration;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(BlastResultImporter::class)]
#[CoversClass(ImportSummary::class)]
#[UsesClass(SequenceNames::class)]
#[UsesClass(BlastXmlParser::class)]
#[UsesClass(ParsedIteration::class)]
#[UsesClass(ParsedHit::class)]
#[UsesClass(ParsedHsp::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastQuery::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastHsp::class)]
#[UsesClass(BlastHitRepository::class)]
final class BlastResultImporterTest extends DatabaseTestCase
{
    public function testResultsOfLegacyVolumesAreStoredWithReadableNames(): void
    {
        $job = $this->persistedJob();

        $summary = $this->importer()->import($job, new BlastXmlParser()->parse(__DIR__ . '/../../Fixtures/blast/blastn_legacy_volumes.xml'));

        self::assertSame([1, 2, 3], [$summary->queries, $summary->hits, $summary->hsps]);
        self::assertTrue($this->entityManager->contains($job), 'the job must stay managed');

        $this->entityManager->clear();
        $query = $this->entityManager->getRepository(BlastQuery::class)->findOneBy(['job' => $job->getId()]);
        self::assertInstanceOf(BlastQuery::class, $query);
        self::assertSame('q1', $query->getName());
        self::assertSame('q1 test query', $query->getDefinition());
        self::assertSame(350, $query->getLength());

        $hit = $this->entityManager->getRepository(BlastHit::class)->findOneBy(['query' => $query, 'rank' => 1]);
        self::assertInstanceOf(BlastHit::class, $hit);
        self::assertSame('gi|1786181|gb|AE000111|ECAE000111', $hit->getSubjectName());
        self::assertSame('AE000111', $hit->getSubjectAccession());
        self::assertSame(2, $hit->getHspCount());
        self::assertSame('0', $hit->getBestEValue());
        self::assertEqualsWithDelta(100.0, $hit->getIdentityPercentage() ?? 0.0, 1e-9);
        self::assertSame(-1, $hit->getHsps()[1]->getHitFrame());
    }

    public function testQueryWithoutHitsIsStoredAndUnnamedQueriesAreNumbered(): void
    {
        $job = $this->persistedJob();

        $summary = $this->importer()->import($job, [
            new ParsedIteration(1, 'No definition line', 12, []),
            new ParsedIteration(2, '', 8, []),
        ]);

        self::assertSame([2, 0, 0], [$summary->queries, $summary->hits, $summary->hsps]);
        $names = $this->entityManager->getConnection()->fetchFirstColumn('SELECT name FROM blast_query ORDER BY position');
        self::assertSame(['Query1', 'Query2'], $names);
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT definition FROM blast_query WHERE position = 2'));
    }

    public function testLargeResultsAreFlushedInBatchesWithoutKeepingEntitiesInMemory(): void
    {
        $job = $this->persistedJob();

        $summary = $this->importer()->import($job, $this->syntheticIterations(400));

        self::assertSame([400, 400, 400], [$summary->queries, $summary->hits, $summary->hsps]);
        self::assertSame(400, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_hsp'));
        self::assertSame(1, $this->entityManager->getUnitOfWork()->size(), 'only the job is still managed');
    }

    private function persistedJob(): BlastJob
    {
        $job = EntityFactory::job();
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    private function importer(): BlastResultImporter
    {
        return new BlastResultImporter($this->entityManager);
    }

    /**
     * @return Generator<int, ParsedIteration>
     */
    private function syntheticIterations(int $count): Generator
    {
        for ($number = 1; $number <= $count; ++$number) {
            $hsp = new ParsedHsp(1, 50.0, 25.0, '1e-5', 1, 25, 1, 25, 1, 1, 25, 25, 0, 25, 'ACGT', 'ACGT', '||||');

            yield new ParsedIteration($number, 'q' . $number, 25, [new ParsedHit(1, 'gnl|BL_ORD_ID|' . $number, 's' . $number . ' subject', (string) $number, 25, [$hsp])]);
        }
    }
}
