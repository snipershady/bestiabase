<?php

declare(strict_types=1);

namespace App\Service\Blast\Database;

use App\Entity\BlastDatabase;
use App\Enum\MolType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Posizione dei volumi dei database registrati (env BLAST_DB_DIR).
 *
 * Nel DB si salvano path relativi a BLAST_DB_DIR ("nucleotide/test_na_db"): spostare lo
 * storage significa cambiare la variabile d'ambiente, senza toccare i dati. Un path assoluto
 * resta valido per database esterni già formattati.
 */
final readonly class BlastDatabaseStorage
{
    private string $directory;

    public function __construct(
        #[Autowire('%env(resolve:BLAST_DB_DIR)%')]
        string $directory,
    ) {
        $this->directory = rtrim($directory, '/');
    }

    /**
     * Path relativo dei volumi di un nuovo database: "{nucleotide|protein}/{code}".
     */
    public function relativePathFor(string $code, MolType $molType): string
    {
        return ($molType === MolType::Nucleotide ? 'nucleotide' : 'protein') . '/' . $code;
    }

    public function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->directory . '/' . $path;
    }

    /**
     * Path dei volumi da passare a `-db`.
     */
    public function volumesOf(BlastDatabase $database): string
    {
        return $this->absolute($database->getPath());
    }
}
