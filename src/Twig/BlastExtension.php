<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\MolType;
use App\Service\Blast\Result\BlastNumberFormat;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Funzioni e filtri Twig per i risultati BLAST.
 */
final readonly class BlastExtension
{
    /** Accessioni GenBank/RefSeq/UniProt riconosciute: solo queste diventano link. */
    private const string ACCESSION = '/^[A-Z]{1,6}_?\d+(?:\.\d+)?$/';

    /**
     * Link alla scheda NCBI del soggetto: `nuccore` per i database nucleotidici, `protein` per quelli
     * proteici (il legacy usava sempre nuccore). Null se l'accessione non è riconosciuta: il link non
     * viene mai costruito da testo arbitrario del FASTA.
     */
    #[AsTwigFunction('ncbi_url')]
    public function ncbiUrl(?string $accession, MolType $molType): ?string
    {
        if ($accession === null || preg_match(self::ACCESSION, $accession) !== 1) {
            return null;
        }

        return \sprintf('https://www.ncbi.nlm.nih.gov/%s/%s?report=genbank', $molType === MolType::Nucleotide ? 'nuccore' : 'protein', rawurlencode($accession));
    }

    #[AsTwigFilter('blast_evalue')]
    public function eValue(string $eValue): string
    {
        return BlastNumberFormat::eValue($eValue);
    }

    #[AsTwigFilter('blast_bits')]
    public function bitScore(float $bitScore): string
    {
        return BlastNumberFormat::bitScore($bitScore);
    }
}
