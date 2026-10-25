<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastJobRepository;
use App\Service\Blast\BlastCommandBuilder;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\BlastResultImporter;
use App\Service\Blast\BlastRunner;
use App\Service\Blast\BlastVersionReader;
use App\Service\Blast\Database\BlastDatabaseStorage;
use App\Service\Blast\Job\BlastJobProcessor;
use App\Service\Blast\JobWorkspace;
use App\Service\Blast\Process\ProcessResult;
use App\Service\Blast\Search\SearchParametersNormalizer;
use App\Service\Blast\Xml\BlastXmlParser;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Motore BLAST completo con processi simulati: binari finti, workspace temporaneo e un
 * RecordingProcessRunner al posto di BLAST+.
 */
final readonly class BlastTestKit
{
    public const string FIXTURES = __DIR__ . '/../Fixtures/blast/';

    public FakeBlastInstallation $blast;

    public TemporaryDirectory $var;

    public function __construct()
    {
        $this->blast = new FakeBlastInstallation();
        $this->var = new TemporaryDirectory();
    }

    public function remove(): void
    {
        $this->blast->remove();
        $this->var->remove();
    }

    public function processor(
        RecordingProcessRunner $processRunner,
        BlastJobRepository $jobs,
        BlastDatabaseRepository $databases,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): BlastJobProcessor {
        $locator = $this->blast->locator();

        return new BlastJobProcessor(
            $jobs,
            $databases,
            new BlastRunner(
                $locator,
                new BlastCommandBuilder($locator),
                new SearchParametersNormalizer(new EffectivePrimitiveTypeIdentifierService()),
                new BlastProcessExecutor($processRunner, 60.0),
                $this->workspace(),
                new BlastDatabaseStorage('/srv/blastdb'),
            ),
            new BlastVersionReader($locator, new BlastProcessExecutor(new RecordingProcessRunner(new ProcessResult(0, "blastn: 2.17.0+\n Package: blast 2.17.0\n", '')), 60.0)),
            new BlastXmlParser(),
            new BlastResultImporter($entityManager),
            $this->workspace(),
            $entityManager,
            $clock,
            new NullLogger(),
        );
    }

    public function workspace(): JobWorkspace
    {
        return new JobWorkspace($this->var->path, new Filesystem());
    }

    /**
     * BLAST simulato che scrive la fixture XML indicata nel file passato con -out.
     */
    public static function blastWritingOutput(string $fixture): RecordingProcessRunner
    {
        return new RecordingProcessRunner()->onRun(static function (array $command) use ($fixture): void {
            new Filesystem()->copy(self::FIXTURES . $fixture, self::outputPath($command));
        });
    }

    /**
     * @param non-empty-list<string> $command
     */
    public static function outputPath(array $command): string
    {
        $index = array_search('-out', $command, true);

        return \is_int($index) && isset($command[$index + 1]) ? $command[$index + 1] : throw new LogicException('The command has no -out value.');
    }
}
