<?php

declare(strict_types=1);

namespace App\Blast\Diagnostics;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastCommandBuilder;
use App\Blast\BlastProcessExecutor;
use App\Blast\BlastTool;
use App\Blast\BlastVersionReader;
use App\Blast\Fasta\FastaDocument;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Fasta\FastaRecord;
use App\Blast\Xml\BlastXmlParser;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Exception\BestiaBaseException;
use Generator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Verifica un'installazione di BLAST+ dopo un aggiornamento (cambio di BLAST_BIN_DIR): binari e
 * versioni, makeblastdb sui database demo, una ricerca per programma con output XML leggibile e
 * con hit, estrazione di una sequenza con blastdbcmd. Lavora in una directory temporanea che
 * rimuove alla fine e non tocca il database dell'applicazione.
 */
final readonly class BlastSmokeTest
{
    public function __construct(
        private BlastBinaryLocator $locator,
        private BlastProcessExecutor $executor,
        private BlastVersionReader $versions,
        private BlastCommandBuilder $commandBuilder,
        private BlastXmlParser $parser,
        private FastaNormalizer $fastaNormalizer,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/fixtures/blast-demo')]
        private string $demoDirectory,
    ) {
    }

    /**
     * @return Generator<int, SmokeCheck> un controllo alla volta, per mostrare l'avanzamento
     */
    public function run(): Generator
    {
        $directory = $this->filesystem->tempnam(sys_get_temp_dir(), 'bestiabase-smoke-');
        $this->filesystem->remove($directory);
        $this->filesystem->mkdir($directory);

        try {
            foreach (BlastProgram::cases() as $program) {
                yield $this->check($program->value . ' -version', fn (): string => $this->versions->read($program) ?? throw new SmokeTestFailure('unexpected -version output'));
            }

            foreach (BlastTool::cases() as $tool) {
                yield $this->check($tool->value, fn (): string => $this->locator->tool($tool));
            }

            foreach (MolType::cases() as $molType) {
                yield $this->check('makeblastdb ' . $molType->value, fn (): string => $this->buildDatabase($molType, $directory));
            }

            foreach (BlastProgram::cases() as $program) {
                yield $this->check($program->value . ' search', fn (): string => $this->search($program, $directory));
            }

            yield $this->check('blastdbcmd -entry', fn (): string => $this->extract($directory));
        } finally {
            $this->filesystem->remove($directory);
        }
    }

    /**
     * @param callable(): string $check restituisce il dettaglio se riesce, lancia un'eccezione altrimenti
     */
    private function check(string $name, callable $check): SmokeCheck
    {
        try {
            return new SmokeCheck($name, true, $check());
        } catch (BestiaBaseException $bestiaBaseException) {
            return new SmokeCheck($name, false, $bestiaBaseException->getMessage());
        }
    }

    private function buildDatabase(MolType $molType, string $directory): string
    {
        $this->executor->execute(BlastTool::MakeBlastDb->value, [
            $this->locator->tool(BlastTool::MakeBlastDb),
            '-in', $this->demoDirectory . '/' . $this->demoCode($molType) . '.fa',
            '-dbtype', $molType->value,
            '-parse_seqids',
            '-out', $directory . '/' . $this->demoCode($molType),
        ], $directory);

        return $this->demoCode($molType) . ' formatted';
    }

    private function search(BlastProgram $program, string $directory): string
    {
        $query = $directory . '/query-' . $program->value . '.fa';
        $output = $directory . '/output-' . $program->value . '.xml';
        $this->filesystem->dumpFile($query, $this->query($program->queryMolType())->toFasta());
        $this->executor->execute($program->value, $this->commandBuilder->build(
            $program,
            null,
            [$directory . '/' . $this->demoCode($program->databaseMolType())],
            $query,
            $output,
        ), $directory);

        $hits = 0;

        foreach ($this->parser->parse($output) as $iteration) {
            $hits += \count($iteration->hits);
        }

        if ($hits === 0) {
            throw new SmokeTestFailure('no hits for a query taken from the demo database');
        }

        return \sprintf('%d hits', $hits);
    }

    private function extract(string $directory): string
    {
        $result = $this->executor->execute(BlastTool::BlastDbCmd->value, [
            $this->locator->tool(BlastTool::BlastDbCmd),
            '-db', $directory . '/test_na_db',
            '-entry', 'AE000111',
            '-outfmt', '%a %l',
        ], $directory);

        if (trim($result->output) !== 'AE000111 10596') {
            throw new SmokeTestFailure(\sprintf('unexpected output "%s"', trim($result->output)));
        }

        return 'AE000111, 10596 bp';
    }

    /**
     * Query note: i primi 350 bp della prima sezione di E. coli, o la terza proteina demo (KCC3).
     */
    private function query(MolType $molType): FastaDocument
    {
        $records = $this->fastaNormalizer->normalize((string) file_get_contents($this->demoDirectory . '/' . $this->demoCode($molType) . '.fa'), $molType)->records;

        if ($molType === MolType::Protein) {
            return new FastaDocument([$records[2] ?? $records[0]]);
        }

        $sequence = substr($records[0]->sequence, 0, 350);

        return new FastaDocument([new FastaRecord('smoke-test', $sequence === '' ? 'N' : $sequence)]);
    }

    private function demoCode(MolType $molType): string
    {
        return $molType === MolType::Nucleotide ? 'test_na_db' : 'test_aa_db';
    }
}
