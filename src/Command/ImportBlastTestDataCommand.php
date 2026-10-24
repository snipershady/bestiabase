<?php

declare(strict_types=1);

namespace App\Command;

use App\Blast\Database\BlastDatabaseImporter;
use App\Blast\Database\DatabaseImportRequest;
use App\Entity\BlastDatabase;
use App\Enum\MolType;
use App\Exception\BestiaBaseException;
use App\Repository\BlastDatabaseRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:blast:import-test-data',
    description: 'Registers the two ViroBLAST demo databases (test_na_db, test_aa_db); already registered ones are skipped',
)]
final readonly class ImportBlastTestDataCommand
{
    /** Righe di viroblast.ini: codice => [tipo di molecola, titolo]. */
    private const array DATABASES = [
        'test_na_db' => [MolType::Nucleotide, 'Nucleotide test database'],
        'test_aa_db' => [MolType::Protein, 'Protein test database'],
    ];

    public function __construct(
        private BlastDatabaseImporter $importer,
        private BlastDatabaseRepository $databases,
        #[Autowire('%kernel.project_dir%/fixtures/blast-demo')]
        private string $fixturesDirectory,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        foreach (self::DATABASES as $code => [$molType, $title]) {
            if ($this->databases->findOneBy(['code' => $code]) instanceof BlastDatabase) {
                $io->writeln(\sprintf('<comment>%s</comment> is already registered, skipped.', $code));

                continue;
            }

            try {
                $database = $this->importer->import(new DatabaseImportRequest(
                    sourceFasta: $this->fixturesDirectory . '/' . $code . '.fa',
                    code: $code,
                    title: $title,
                    molType: $molType,
                    description: 'Demo database shipped with ViroBLAST.',
                ));
            } catch (BestiaBaseException $exception) {
                $io->error(\sprintf('%s: %s', $code, $exception->getMessage()));

                return Command::FAILURE;
            }

            $io->writeln(\sprintf('<info>%s</info> registered: %d sequences.', $code, $database->getSequenceCount() ?? 0));
        }

        $io->success('Demo databases ready.');

        return Command::SUCCESS;
    }
}
