<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastTool;
use App\Blast\Exception\BlastBinaryNotFoundException;
use App\Enum\BlastProgram;
use App\Tests\Support\FakeBlastInstallation;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(BlastBinaryLocator::class)]
#[CoversClass(BlastBinaryNotFoundException::class)]
final class BlastBinaryLocatorTest extends TestCase
{
    private FakeBlastInstallation $blast;

    #[Override]
    protected function setUp(): void
    {
        $this->blast = new FakeBlastInstallation();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->blast->remove();
    }

    public function testProgramsAndToolsAreResolvedInsideTheConfiguredDirectory(): void
    {
        $locator = $this->blast->locator();

        self::assertSame($this->blast->binary('tblastx'), $locator->program(BlastProgram::Tblastx));
        self::assertSame($this->blast->binary('makeblastdb'), $locator->tool(BlastTool::MakeBlastDb));
    }

    public function testMissingBinaryPointsToTheConfiguration(): void
    {
        new Filesystem()->remove($this->blast->binary('blastdbcmd'));

        $this->expectException(BlastBinaryNotFoundException::class);
        $this->expectExceptionMessageIsOrContains('Check BLAST_BIN_DIR');

        $this->blast->locator()->tool(BlastTool::BlastDbCmd);
    }

    public function testNonExecutableBinaryIsRejected(): void
    {
        new Filesystem()->chmod($this->blast->binary('blastn'), 0o644);

        $this->expectException(BlastBinaryNotFoundException::class);

        new BlastBinaryLocator($this->blast->directory->path)->program(BlastProgram::Blastn);
    }
}
