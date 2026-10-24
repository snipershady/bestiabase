<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Blast\Result\BlastNumberFormat;
use App\Blast\Result\CutoffType;
use App\Blast\Result\HitFilter;
use App\Blast\Result\HitRow;
use App\Blast\Result\HitSort;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Repository\BlastHitRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(BlastHitRepository::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastQuery::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastNumberFormat::class)]
#[UsesClass(HitFilter::class)]
#[UsesClass(HitRow::class)]
#[UsesClass(BlastHsp::class)]
final class BlastHitRepositoryTest extends DatabaseTestCase
{
    public function testRowsHonourTopCutoffSortAndQueryRange(): void
    {
        $job = $this->resultsJob();
        $hits = self::getContainer()->get(BlastHitRepository::class);
        $names = static fn (array $rows): array => array_map(static fn (HitRow $row): string => $row->queryName . '#' . $row->rank, $rows);

        self::assertSame(['q1#1', 'q1#2', 'q1#3', 'q2#1'], $names($hits->findRows($job, new HitFilter(), 1, 20)));
        self::assertSame(['q1#1', 'q1#2', 'q2#1'], $names($hits->findRows($job, new HitFilter(top: 2), 1, 20)));
        self::assertSame(['q1#1', 'q2#1'], $names($hits->findRows($job, new HitFilter(top: 0, cutoff: CutoffType::Identity, value: 100), 1, 20)), '232/233 is 99%, not 100%');
        self::assertSame(['q1#1'], $names($hits->findRows($job, new HitFilter(top: 1, cutoff: CutoffType::Identity, value: 99), 1, 1)), 'cutoff first, then top N');
        self::assertSame(['q1#1', 'q1#2'], $names($hits->findRows($job, new HitFilter(cutoff: CutoffType::Score, value: 100), 1, 20)));
        self::assertSame(['q1#2', 'q1#1', 'q1#3', 'q2#1'], $names($hits->findRows($job, new HitFilter(sort: HitSort::Score), 1, 20)));
        self::assertSame(['q1#1', 'q1#2', 'q1#3', 'q2#1'], $names($hits->findRows($job, new HitFilter(sort: HitSort::Identity), 1, 20)));
        self::assertSame(['q2#1'], $names($hits->findRows($job, new HitFilter(), 2, 2)));

        $first = $hits->findRows($job, new HitFilter(), 1, 1)[0];
        self::assertSame(['q1', 350, 'gi|1786181|gb|AE000111|ECAE000111', 'AE000111', 'Escherichia coli section 1', 233, 233, '0.0'], [
            $first->queryName, $first->queryLength, $first->subjectName, $first->subjectAccession, $first->subjectTitle, $first->identity, $first->alignLength, $first->eValue,
        ]);
    }

    public function testHitsAreOnlyFoundInsideTheirJob(): void
    {
        $job = $this->resultsJob();
        $other = EntityFactory::job();
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $hits = self::getContainer()->get(BlastHitRepository::class);
        $hitId = $hits->findRows($job, new HitFilter(), 1, 1)[0]->hitId;

        self::assertInstanceOf(BlastHit::class, $hits->findOneInJob($job, $hitId));
        self::assertNull($hits->findOneInJob($other, $hitId), 'hit ids are sequential: another job must not reach them');
    }

    public function testSelectedHitsComeBackInBlastOrderAndOnlyFromTheJob(): void
    {
        $job = $this->resultsJob();
        $other = $this->resultsJob();
        $hits = self::getContainer()->get(BlastHitRepository::class);
        $ids = array_map(static fn (HitRow $row): int => $row->hitId, $hits->findRows($job, new HitFilter(top: 0), 1, 20));
        $foreign = $hits->findRows($other, new HitFilter(), 1, 1)[0]->hitId;

        $selected = $hits->findSelectedInJob($job, [$ids[3], $ids[0], $foreign]);

        self::assertSame([$ids[0], $ids[3]], array_map(static fn (BlastHit $hit): ?int => $hit->getId(), $selected));
    }

    public function testHitsOfAQueryArePaginatedInBlastOrder(): void
    {
        $job = $this->resultsJob();
        $hits = self::getContainer()->get(BlastHitRepository::class);

        self::assertSame([1, 2], array_map(static fn (BlastHit $hit): int => $hit->getRank(), $hits->findForQuery($job, 1, 0, 2)));
        self::assertSame([3], array_map(static fn (BlastHit $hit): int => $hit->getRank(), $hits->findForQuery($job, 1, 2, 2)));
        self::assertSame([], $hits->findForQuery($job, 3, 0, 2));
    }

    /**
     * q1: rank 1 (233/233, 300 bit), rank 2 (232/233 = 99%, 400 bit), rank 3 (100/200, 50 bit);
     * q2: rank 1 (10/10, 20 bit).
     */
    private function resultsJob(): BlastJob
    {
        $job = EntityFactory::job();
        $first = EntityFactory::query($job, 1);
        $second = EntityFactory::query($job, 2);
        $entities = [$job, $first, $second];

        foreach ([[$first, 1, 300.0, 233, 233], [$first, 2, 400.0, 232, 233], [$first, 3, 50.0, 100, 200], [$second, 1, 20.0, 10, 10]] as [$query, $rank, $bits, $identity, $length]) {
            $hit = EntityFactory::hit($query, $rank);
            $hit->addHsp(EntityFactory::hsp($hit, 1, $bits, $identity, $length));
            $entities[] = $hit;
        }

        $this->persistAndReload(...$entities);

        return $this->entityManager->find(BlastJob::class, $job->getId()) ?? self::fail('job not saved');
    }
}
