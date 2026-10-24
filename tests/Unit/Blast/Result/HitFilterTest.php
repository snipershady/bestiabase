<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Result;

use App\Blast\Result\BlastNumberFormat;
use App\Blast\Result\CutoffType;
use App\Blast\Result\HitFilter;
use App\Blast\Result\HitRow;
use App\Blast\Result\HitSort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HitFilter::class)]
#[CoversClass(HitRow::class)]
#[UsesClass(BlastNumberFormat::class)]
final class HitFilterTest extends TestCase
{
    public function testDefaultsMirrorTheLegacyTable(): void
    {
        $filter = new HitFilter();

        self::assertSame(10, $filter->top);
        self::assertSame(CutoffType::None, $filter->cutoff);
        self::assertSame(HitSort::Rank, $filter->sort);
        self::assertSame([1, 20], $filter->queryRange());
        self::assertSame([], $filter->toQuery(), 'defaults are omitted from links');
    }

    public function testPageSelectsTheQueryRangeAndLinksKeepTheFilters(): void
    {
        $filter = new HitFilter(top: 0, cutoff: CutoffType::Identity, value: 95.0, sort: HitSort::Score, page: 3);

        self::assertSame([41, 60], $filter->queryRange());
        self::assertSame(['top' => 0, 'cutoff' => 'identity', 'value' => 95.0, 'sort' => 'score'], $filter->toQuery());
    }

    public function testRowFormatsTheLegacyColumns(): void
    {
        $row = new HitRow(7, 1, 'q1', 350, 1, 'AE000111', 'AE000111', 'E. coli', 632.4, 232, 233, '0');

        self::assertSame('232/233 (350)', $row->identities());
        self::assertSame(99, $row->percentage());
    }
}
