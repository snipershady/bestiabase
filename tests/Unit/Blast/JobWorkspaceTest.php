<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast;

use App\Service\Blast\JobWorkspace;
use App\Tests\Support\TemporaryDirectory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

#[CoversClass(JobWorkspace::class)]
final class JobWorkspaceTest extends TestCase
{
    private TemporaryDirectory $var;

    private JobWorkspace $workspace;

    private Uuid $id;

    #[Override]
    protected function setUp(): void
    {
        $this->var = new TemporaryDirectory();
        $this->workspace = new JobWorkspace($this->var->path, new Filesystem());
        $this->id = Uuid::v7();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->var->remove();
    }

    public function testArchiveCompressesTheOutputAndDropsTheInputs(): void
    {
        $xml = str_repeat("<Hit>AE000111</Hit>\n", 1000);
        $this->workspace->write($this->id, JobWorkspace::OUTPUT_FILE, $xml);
        $this->workspace->write($this->id, JobWorkspace::QUERY_FILE, ">q1\nACGT\n");
        $this->workspace->write($this->id, JobWorkspace::SUBJECT_FILE, ">s1\nACGT\n");
        $this->workspace->write($this->id, JobWorkspace::SUBJECT_DATABASE . '.nhr', 'binary');

        $this->workspace->archive($this->id);

        self::assertSame(
            [JobWorkspace::OUTPUT_ARCHIVE],
            array_values(array_diff((array) scandir($this->workspace->directory($this->id)), ['.', '..'])),
        );
        self::assertSame($xml, file_get_contents('compress.zlib://' . $this->workspace->path($this->id, JobWorkspace::OUTPUT_ARCHIVE)));
        self::assertLessThan(\strlen($xml) / 10, filesize($this->workspace->path($this->id, JobWorkspace::OUTPUT_ARCHIVE)));
    }

    public function testArchiveWithoutOutputOnlyDropsTheInputs(): void
    {
        $this->workspace->write($this->id, JobWorkspace::QUERY_FILE, ">q1\nACGT\n");

        $this->workspace->archive($this->id);

        self::assertFileDoesNotExist($this->workspace->path($this->id, JobWorkspace::QUERY_FILE));
        self::assertNull($this->workspace->output($this->id));
    }

    public function testArchiveOfAMissingWorkspaceDoesNothing(): void
    {
        $this->workspace->archive($this->id);

        self::assertDirectoryDoesNotExist($this->workspace->directory($this->id));
    }

    public function testOutputPrefersTheArchiveAndFallsBackToThePlainFile(): void
    {
        self::assertNull($this->workspace->output($this->id));

        $plain = $this->workspace->write($this->id, JobWorkspace::OUTPUT_FILE, '<BlastOutput/>');
        self::assertSame($plain, $this->workspace->output($this->id), 'jobs finished before archiving keep working');

        $archive = $this->workspace->write($this->id, JobWorkspace::OUTPUT_ARCHIVE, 'gz');
        self::assertSame($archive, $this->workspace->output($this->id));
    }

    public function testAFailedCompressionKeepsTheOriginalOutput(): void
    {
        $plain = $this->workspace->write($this->id, JobWorkspace::OUTPUT_FILE, '<BlastOutput/>');
        // Una directory al posto del file temporaneo impedisce la scrittura.
        new Filesystem()->mkdir($this->workspace->path($this->id, JobWorkspace::OUTPUT_ARCHIVE . '.tmp'));

        try {
            $this->workspace->archive($this->id);
            self::fail('The compression should have failed.');
        } catch (IOException) {
            self::assertFileExists($plain);
        }
    }
}
