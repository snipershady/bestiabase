<?php

declare(strict_types=1);

namespace App\Command;

use App\Blast\Job\BlastJobFactory;
use App\Blast\Job\BlastJobProcessor;
use App\Blast\Job\BlastJobSubmitter;
use App\Blast\Job\BlastSearchRequest;
use App\Blast\Result\BlastNumberFormat;
use App\Blast\Result\HitFilter;
use App\Blast\Result\HitRow;
use App\Blast\Search\SearchParameters;
use App\Entity\BlastDatabase;
use App\Entity\BlastJob;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Exception\BestiaBaseException;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastHitRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:blast:search',
    description: 'Runs a BLAST search synchronously, stores the results and prints the summary table',
)]
final readonly class BlastSearchCommand
{
    /** Righe mostrate per query, come la tabella del legacy. */
    private const int ROWS_PER_QUERY = 10;

    public function __construct(
        private BlastJobFactory $jobFactory,
        private BlastJobSubmitter $submitter,
        private BlastJobProcessor $processor,
        private BlastDatabaseRepository $databases,
        private UserRepository $users,
        private BlastHitRepository $hits,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $database
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('BLAST program: blastn, blastp, blastx, tblastn or tblastx')]
        BlastProgram $program,
        #[Argument('Query FASTA file (or a bare sequence file)')]
        string $query,
        #[Option('Code of a registered database (repeatable)')]
        array $database = [],
        #[Option('FASTA file to search against, formatted on the fly with makeblastdb')]
        ?string $subject = null,
        #[Option('Advanced search with the defaults of the legacy form')]
        bool $advanced = false,
        #[Option('Expect value threshold (implies --advanced)')]
        ?float $evalue = null,
        #[Option('Maximum number of target sequences (implies --advanced)')]
        ?int $maxTargetSeqs = null,
        #[Option('Queue the job for a Messenger worker instead of running it now')]
        bool $async = false,
        #[Option('Email of the user who owns the job (default: no owner)')]
        ?string $user = null,
    ): int {
        $queryFasta = $this->read($io, $query);
        $subjectFasta = $subject === null ? null : $this->read($io, $subject);
        $databases = $this->databases($io, $database);
        $owner = $user === null ? null : $this->users->findOneBy(['email' => $user]);

        if ($user !== null && !$owner instanceof User) {
            $io->error(\sprintf('No user is registered with email "%s".', $user));

            return Command::INVALID;
        }

        if ($queryFasta === null || ($subject !== null && $subjectFasta === null) || $databases === null) {
            return Command::INVALID;
        }

        $request = new BlastSearchRequest(
            program: $program,
            queryFasta: $queryFasta,
            databases: $databases,
            parameters: $this->parameters($program, $advanced, $evalue, $maxTargetSeqs),
            subjectFasta: $subjectFasta,
            user: $owner,
            // Il CLI è uno strumento di amministrazione: usa qualunque database registrato.
            trusted: true,
        );

        try {
            $job = $async ? $this->submitter->submit($request) : $this->jobFactory->create($request);
        } catch (BestiaBaseException $bestiaBaseException) {
            $io->error($bestiaBaseException->getMessage());

            return Command::INVALID;
        }

        if ($async) {
            $io->success(\sprintf('Job %s queued. Run "bin/console messenger:consume async" to process it.', $job->getId()->toRfc4122()));

            return Command::SUCCESS;
        }

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $io->writeln(\sprintf('Job <info>%s</info> created, running %s…', $job->getId()->toRfc4122(), $program->value));

        $job = $this->processor->process($job->getId());

        if ($job->getStatus() !== JobStatus::Finished) {
            $io->error(\sprintf('Job %s %s: %s', $job->getId()->toRfc4122(), $job->getStatus()->value, $job->getErrorMessage() ?? ''));

            return Command::FAILURE;
        }

        $this->printResults($io, $job);

        return Command::SUCCESS;
    }

    private function parameters(BlastProgram $program, bool $advanced, ?float $evalue, ?int $maxTargetSeqs): ?SearchParameters
    {
        if (!$advanced && $evalue === null && $maxTargetSeqs === null) {
            return null;
        }

        $defaults = SearchParameters::defaultsFor($program);

        return new SearchParameters(
            expect: $evalue ?? $defaults->expect,
            wordSize: $defaults->wordSize,
            maxTargetSequences: $maxTargetSeqs ?? $defaults->maxTargetSequences,
            nucleotideScoring: $defaults->nucleotideScoring,
            matrix: $defaults->matrix,
            gapCosts: $defaults->gapCosts,
            lowComplexityFilter: $defaults->lowComplexityFilter,
            softMasking: $defaults->softMasking,
            lowercaseMasking: $defaults->lowercaseMasking,
            ungapped: $defaults->ungapped,
            queryGeneticCode: $defaults->queryGeneticCode,
            databaseGeneticCode: $defaults->databaseGeneticCode,
        );
    }

    private function read(SymfonyStyle $io, string $path): ?string
    {
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($content === false) {
            $io->error(\sprintf('File "%s" does not exist or is not readable.', $path));

            return null;
        }

        return $content;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<BlastDatabase>|null
     */
    private function databases(SymfonyStyle $io, array $codes): ?array
    {
        $databases = [];

        foreach ($codes as $code) {
            $database = $this->databases->findOneBy(['code' => $code]);

            if (!$database instanceof BlastDatabase) {
                $io->error(\sprintf('No BLAST database is registered with code "%s".', $code));

                return null;
            }

            $databases[] = $database;
        }

        return $databases;
    }

    private function printResults(SymfonyStyle $io, BlastJob $job): void
    {
        // Stessa query della pagina web: al massimo 10 hit per query, come la tabella legacy.
        $rows = array_map(
            static fn (HitRow $row): array => [$row->queryName, $row->subjectName, BlastNumberFormat::bitScore($row->bitScore), $row->identities(), $row->percentage(), BlastNumberFormat::eValue($row->eValue)],
            $this->hits->findRows($job, new HitFilter(top: self::ROWS_PER_QUERY), 1, max(1, $job->getNumQueries() ?? 1)),
        );

        if ($rows === []) {
            $io->warning('No comparison meets the cutoff criterion. Try a higher expect value.');

            return;
        }

        // Table invece di $io->table(): stesso stile, ma senza ConsoleSection (non disponibili in CommandTester).
        new Table($io)
            ->setStyle('symfony-style-guide')
            ->setHeaders(['Query', 'Subject', 'Score', 'Identities (Query length)', 'Percentage', 'Expect'])
            ->setRows($rows)
            ->render();
        $io->success(\sprintf('%d queries, %d hits (at most %d shown per query).', $job->getNumQueries() ?? 0, $job->getNumHits(), self::ROWS_PER_QUERY));
    }
}
