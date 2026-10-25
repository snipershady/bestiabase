<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\MolType;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\BlastTool;
use App\Service\Blast\Process\SymfonyProcessRunner;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Database demo (fixtures/blast-demo) formattati con i binari BLAST+ veri, per i test di
 * integrazione del gruppo "blast". Restituisce null se BLAST_BIN_DIR non contiene i binari.
 */
final readonly class DemoBlastDatabases
{
    public const string FASTA = __DIR__ . '/../../fixtures/blast-demo/';

    private function __construct(
        public BlastBinaryLocator $locator,
        public TemporaryDirectory $directory,
    ) {
    }

    public static function build(): ?self
    {
        $binDir = new EffectivePrimitiveTypeIdentifierService()->getStringValueFromArray('BLAST_BIN_DIR', $_SERVER, trim: true);

        if ($binDir === '' || !is_executable($binDir . '/blastn')) {
            return null;
        }

        $databases = new self(new BlastBinaryLocator($binDir), new TemporaryDirectory());
        $executor = new BlastProcessExecutor(new SymfonyProcessRunner(), 60.0);

        foreach (['test_na_db' => MolType::Nucleotide, 'test_aa_db' => MolType::Protein] as $code => $molType) {
            $executor->execute('makeblastdb', [
                $databases->locator->tool(BlastTool::MakeBlastDb),
                '-in', self::FASTA . $code . '.fa',
                '-dbtype', $molType->value,
                '-parse_seqids',
                '-out', $databases->path($molType),
            ], $databases->directory->path);
        }

        return $databases;
    }

    public function path(MolType $molType): string
    {
        return $this->directory->path . '/' . ($molType === MolType::Nucleotide ? 'test_na_db' : 'test_aa_db');
    }

    public function remove(): void
    {
        $this->directory->remove();
    }
}
