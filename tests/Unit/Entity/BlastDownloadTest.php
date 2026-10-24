<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\BlastDownload;
use App\Entity\BlastHit;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Entity\User;
use App\Enum\DownloadSequenceType;
use App\Tests\Support\EntityFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlastDownload::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastQuery::class)]
#[UsesClass(User::class)]
final class BlastDownloadTest extends TestCase
{
    public function testDownloadKeepsTheSelectedHits(): void
    {
        $job = EntityFactory::job();
        $user = EntityFactory::user();
        $query = EntityFactory::query($job);
        $hits = [EntityFactory::hit($query, 1), EntityFactory::hit($query, 2)];

        $download = new BlastDownload($job, DownloadSequenceType::Mapping, $hits, EntityFactory::now(), $user);

        self::assertNull($download->getId());
        self::assertSame($job, $download->getJob());
        self::assertSame(DownloadSequenceType::Mapping, $download->getSequenceType());
        self::assertSame($hits, $download->getHits());
        self::assertSame(EntityFactory::NOW, $download->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame($user, $download->getUser());
    }

    public function testHitsOfAnotherJobAreRejected(): void
    {
        $hit = EntityFactory::hit(EntityFactory::query(EntityFactory::job()));

        $this->expectException(InvalidArgumentException::class);

        new BlastDownload(EntityFactory::job(), DownloadSequenceType::Entire, [$hit], EntityFactory::now());
    }
}
