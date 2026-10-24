<?php

declare(strict_types=1);

namespace App\Tests\Integration\Diagnostics;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastCommandBuilder;
use App\Blast\BlastProcessExecutor;
use App\Blast\BlastVersionReader;
use App\Blast\Diagnostics\BlastSmokeTest;
use App\Blast\Diagnostics\SmokeCheck;
use App\Blast\Diagnostics\SmokeTestFailure;
use App\Blast\Exception\BlastBinaryNotFoundException;
use App\Blast\Exception\BlastOutputParseException;
use App\Blast\Fasta\FastaDocument;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Fasta\FastaRecord;
use App\Blast\Process\ProcessResult;
use App\Blast\Process\SymfonyProcessRunner;
use App\Blast\Xml\BlastXmlParser;
use App\Blast\Xml\ParsedHit;
use App\Blast\Xml\ParsedHsp;
use App\Blast\Xml\ParsedIteration;
use App\Command\BlastSmokeTestCommand;
use App\Enum\BlastProgram;
use App\Tests\Support\DemoBlastDatabases;
use App\Tests\Support\FakeBlastInstallation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(BlastSmokeTest::class)]
#[CoversClass(SmokeCheck::class)]
#[CoversClass(SmokeTestFailure::class)]
#[CoversClass(BlastSmokeTestCommand::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(BlastProcessExecutor::class)]
#[UsesClass(BlastVersionReader::class)]
#[UsesClass(BlastBinaryNotFoundException::class)]
#[UsesClass(BlastOutputParseException::class)]
#[UsesClass(FastaDocument::class)]
#[UsesClass(FastaNormalizer::class)]
#[UsesClass(FastaRecord::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(BlastXmlParser::class)]
#[UsesClass(ParsedHit::class)]
#[UsesClass(ParsedHsp::class)]
#[UsesClass(ParsedIteration::class)]
#[UsesClass(BlastProgram::class)]
final class BlastSmokeTestTest extends KernelTestCase
{
    #[Group('blast')]
    public function testARealInstallationPassesEveryCheck(): void
    {
        $databases = DemoBlastDatabases::build() ?? self::markTestSkipped('BLAST+ binaries not available in BLAST_BIN_DIR.');
        $databases->remove();

        $result = self::runCommand('app:blast:smoke-test', interactive: false);

        self::assertSame(Command::SUCCESS, $result->statusCode, $result->getDisplay());
        self::assertSame(15, substr_count($result->getDisplay(), 'OK  '));
        self::assertStringContainsString('blastdbcmd -entry: AE000111, 10596 bp', $result->getDisplay());
    }

    public function testABrokenInstallationIsReported(): void
    {
        // Binari finti: escono con 0 senza produrre nulla (né versione, né XML, né sequenze).
        $blast = new FakeBlastInstallation();
        new Filesystem()->remove($blast->binary('blastdbcmd'));

        try {
            $tester = new CommandTester($this->command($blast));
            $status = $tester->execute([], ['interactive' => false]);
        } finally {
            $blast->remove();
        }

        $display = $tester->getDisplay();
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('FAIL blastn -version: unexpected -version output', $display);
        self::assertStringContainsString('FAIL blastdbcmd: BLAST+ binary', $display);
        self::assertStringContainsString('OK   makeblastdb nucl', $display);
        self::assertStringContainsString('FAIL blastn search: BLAST XML output', $display);
        self::assertStringContainsString('do not put this BLAST+ installation in production', preg_replace('/\s+/', ' ', $display) ?? '');
    }

    public function testAnInstallationThatFindsNothingIsReported(): void
    {
        // Binari finti che "funzionano": versione corretta, XML valido ma senza hit, blastdbcmd che
        // restituisce un'altra sequenza.
        $blast = new FakeBlastInstallation();
        $emptyOutput = $blast->directory->path . '/empty.xml';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($emptyOutput, '<?xml version="1.0"?><BlastOutput><BlastOutput_iterations><Iteration><Iteration_iter-num>1</Iteration_iter-num><Iteration_query-def>q</Iteration_query-def><Iteration_query-len>4</Iteration_query-len><Iteration_hits></Iteration_hits></Iteration></BlastOutput_iterations></BlastOutput>');

        foreach (['blastn', 'blastp', 'blastx', 'tblastn', 'tblastx'] as $program) {
            $filesystem->dumpFile($blast->binary($program), "#!/bin/sh\nif [ \"\$1\" = \"-version\" ]; then echo \"{$program}: 2.17.0+\"; exit 0; fi\nwhile [ \$# -gt 0 ]; do if [ \"\$1\" = \"-out\" ]; then cp {$emptyOutput} \"\$2\"; fi; shift; done\n");
            $filesystem->chmod($blast->binary($program), 0o755);
        }

        $filesystem->dumpFile($blast->binary('blastdbcmd'), "#!/bin/sh\necho 'OTHER 1'\n");

        try {
            $tester = new CommandTester($this->command($blast));
            $status = $tester->execute([], ['interactive' => false]);
        } finally {
            $blast->remove();
        }

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('OK   blastn -version: 2.17.0+', $tester->getDisplay());
        self::assertStringContainsString('FAIL blastn search: no hits for a query taken from the demo database', $tester->getDisplay());
        self::assertStringContainsString('FAIL blastdbcmd -entry: unexpected output "OTHER 1"', $tester->getDisplay());
    }

    private function command(FakeBlastInstallation $blast): Command
    {
        $locator = $blast->locator();
        $executor = new BlastProcessExecutor(new SymfonyProcessRunner(), 30.0);
        $smokeTest = new BlastSmokeTest(
            $locator,
            $executor,
            new BlastVersionReader($locator, $executor),
            new BlastCommandBuilder($locator),
            new BlastXmlParser(),
            new FastaNormalizer(1_000_000),
            new Filesystem(),
            DemoBlastDatabases::FASTA,
        );
        $application = new Application(self::bootKernel());
        $application->addCommand(new BlastSmokeTestCommand($smokeTest));

        return $application->find('app:blast:smoke-test');
    }
}
