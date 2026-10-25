<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Exception\BestiaBaseException;
use App\Service\Blast\Database\BlastDatabaseImporter;
use App\Service\Blast\Database\DatabaseImportRequest;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:blast:import-db',
    description: 'Formats a FASTA file with makeblastdb and registers it as a searchable BLAST database',
)]
final readonly class ImportBlastDatabaseCommand
{
    public function __construct(
        private BlastDatabaseImporter $importer,
    ) {
    }

    /**
     * @param list<string> $program
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('FASTA file already on the server')]
        string $fasta,
        #[Argument('Unique code, used as volume basename (letters, digits, "_", "-")')]
        string $code,
        #[Argument('Molecule type: nucl or prot')]
        MolType $molType,
        #[Argument('Title shown in the search form')]
        string $title,
        #[Option('Program allowed on the database (repeatable); default: all compatible programs')]
        array $program = [],
        #[Option('Free text description')]
        ?string $description = null,
        #[Option('Do not use -parse_seqids (accessions and blastdbcmd -entry will not be available)')]
        bool $noParseSeqids = false,
        #[Option('Restricted database: only authorized users (see app:blast:access) and administrators can search it')]
        bool $restricted = false,
    ): int {
        $programs = [];

        foreach ($program as $name) {
            $parsed = BlastProgram::tryFrom($name);

            if ($parsed === null) {
                $io->error(\sprintf('Unknown program "%s". Use one of: %s.', $name, implode(', ', array_column(BlastProgram::cases(), 'value'))));

                return Command::INVALID;
            }

            $programs[] = $parsed;
        }

        try {
            $database = $this->importer->import(new DatabaseImportRequest(
                sourceFasta: $fasta,
                code: $code,
                title: $title,
                molType: $molType,
                programs: $programs,
                description: $description,
                parseSeqids: !$noParseSeqids,
                restricted: $restricted,
            ));
        } catch (BestiaBaseException $bestiaBaseException) {
            $io->error($bestiaBaseException->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Registered "%s" (%s, %s): %d sequences, %s letters, programs: %s.',
            $database->getCode(),
            $database->getMolType()->value,
            $database->isPublic() ? 'public' : 'restricted',
            $database->getSequenceCount() ?? 0,
            (string) $database->getTotalLength(),
            implode(', ', array_map(static fn (BlastProgram $enabled): string => $enabled->value, $database->getPrograms())),
        ));

        return Command::SUCCESS;
    }
}
