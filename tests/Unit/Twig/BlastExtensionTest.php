<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Enum\MolType;
use App\Service\Blast\Result\BlastNumberFormat;
use App\Twig\BlastExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlastExtension::class)]
#[UsesClass(BlastNumberFormat::class)]
final class BlastExtensionTest extends TestCase
{
    public function testNcbiLinkDependsOnTheDatabaseMoleculeType(): void
    {
        $extension = new BlastExtension();

        self::assertSame('https://www.ncbi.nlm.nih.gov/nuccore/AE000111?report=genbank', $extension->ncbiUrl('AE000111', MolType::Nucleotide));
        self::assertSame('https://www.ncbi.nlm.nih.gov/protein/AAF24986.1?report=genbank', $extension->ncbiUrl('AAF24986.1', MolType::Protein));
        self::assertSame('https://www.ncbi.nlm.nih.gov/nuccore/NM_000546.6?report=genbank', $extension->ncbiUrl('NM_000546.6', MolType::Nucleotide));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function notAccessions(): iterable
    {
        yield 'missing' => [null];
        yield 'free text from an uploaded FASTA' => ['patient_12_clone4'];
        yield 'markup' => ['"><script>alert(1)</script>'];
        yield 'path' => ['../../admin'];
    }

    #[DataProvider('notAccessions')]
    public function testOnlyRecognisedAccessionsBecomeLinks(?string $accession): void
    {
        self::assertNull(new BlastExtension()->ncbiUrl($accession, MolType::Nucleotide));
    }

    public function testNumberFilters(): void
    {
        $extension = new BlastExtension();

        self::assertSame('3e-06', $extension->eValue('3.07929e-06'));
        self::assertSame('632', $extension->bitScore(632.465));
    }
}
