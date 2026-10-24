<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastProcessExecutor;
use App\Blast\BlastVersionReader;
use App\Blast\Process\ProcessResult;
use App\Enum\BlastProgram;
use App\Tests\Support\FakeBlastInstallation;
use App\Tests\Support\RecordingProcessRunner;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlastVersionReader::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastProcessExecutor::class)]
#[UsesClass(ProcessResult::class)]
final class BlastVersionReaderTest extends TestCase
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

    public function testVersionIsReadFromTheProgramBanner(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(0, "tblastx: 2.17.0+\n Package: blast 2.17.0, build Jul  1 2025\n", ''));

        self::assertSame('2.17.0+', $this->reader($runner)->read(BlastProgram::Tblastx));
        self::assertSame([$this->blast->binary('tblastx'), '-version'], $runner->calls[0]['command']);
    }

    public function testUnexpectedBannerGivesNoVersion(): void
    {
        self::assertNull($this->reader(new RecordingProcessRunner(new ProcessResult(0, "something else\n", '')))->read(BlastProgram::Blastn));
    }

    private function reader(RecordingProcessRunner $runner): BlastVersionReader
    {
        return new BlastVersionReader($this->blast->locator(), new BlastProcessExecutor($runner, 10.0));
    }
}
