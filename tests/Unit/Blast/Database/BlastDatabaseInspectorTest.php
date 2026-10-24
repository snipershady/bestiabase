<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Database;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastProcessExecutor;
use App\Blast\Database\BlastDatabaseInspector;
use App\Blast\Database\BlastDatabaseMetadata;
use App\Blast\Exception\DatabaseImportException;
use App\Blast\Process\ProcessResult;
use App\Enum\MolType;
use App\Tests\Support\FakeBlastInstallation;
use App\Tests\Support\RecordingProcessRunner;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[CoversClass(BlastDatabaseInspector::class)]
#[CoversClass(BlastDatabaseMetadata::class)]
#[CoversClass(DatabaseImportException::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastProcessExecutor::class)]
#[UsesClass(ProcessResult::class)]
final class BlastDatabaseInspectorTest extends TestCase
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

    public function testMetadataIsReadFromTheBlastdbcmdJson(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(0, '{"dbtype":"Nucleotide","number-of-letters":69370,"number-of-sequences":6,"bytes-total":69011}', ''));

        $metadata = $this->inspector($runner)->metadata('/srv/blastdb/nucleotide/test_na_db', MolType::Nucleotide);

        self::assertSame([6, 69370, 69011], [$metadata->sequences, $metadata->letters, $metadata->bytes]);
        self::assertSame(
            [$this->blast->binary('blastdbcmd'), '-db', '/srv/blastdb/nucleotide/test_na_db', '-dbtype', 'nucl', '-metadata'],
            $runner->calls[0]['command'],
        );
        self::assertSame('/srv/blastdb/nucleotide', $runner->calls[0]['workingDirectory']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidOutputs(): iterable
    {
        yield 'not json' => ['Database: test', 'did not return JSON'];
        yield 'json scalar' => ['42', 'missing sequence or letter count'];
        yield 'missing counts' => ['{"dbtype":"Protein"}', 'missing sequence or letter count'];
        yield 'too deep' => [str_repeat('[', 20) . str_repeat(']', 20), 'Maximum stack depth exceeded'];
    }

    #[DataProvider('invalidOutputs')]
    public function testUnexpectedOutputIsRejected(string $output, string $message): void
    {
        $this->expectException(DatabaseImportException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->inspector(new RecordingProcessRunner(new ProcessResult(0, $output, '')))->metadata('/srv/db', MolType::Protein);
    }

    private function inspector(RecordingProcessRunner $runner): BlastDatabaseInspector
    {
        return new BlastDatabaseInspector($this->blast->locator(), new BlastProcessExecutor($runner, 60.0), new EffectivePrimitiveTypeIdentifierService());
    }
}
