<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\JobStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobStatus::class)]
final class JobStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{JobStatus, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'pending' => [JobStatus::Pending, false];
        yield 'running' => [JobStatus::Running, false];
        yield 'finished' => [JobStatus::Finished, true];
        yield 'failed' => [JobStatus::Failed, true];
        yield 'cancelled' => [JobStatus::Cancelled, true];
    }

    #[DataProvider('statuses')]
    public function testIsTerminal(JobStatus $jobStatus, bool $terminal): void
    {
        self::assertSame($terminal, $jobStatus->isTerminal());
    }
}
