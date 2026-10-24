<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Fasta;

use App\Blast\Exception\InvalidFastaException;
use App\Blast\Fasta\FastaDocument;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Fasta\FastaRecord;
use App\Enum\MolType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FastaNormalizer::class)]
#[CoversClass(FastaDocument::class)]
#[CoversClass(FastaRecord::class)]
#[CoversClass(InvalidFastaException::class)]
final class FastaNormalizerTest extends TestCase
{
    public function testMultiFastaWithWindowsLineEndingsAndLeadingJunk(): void
    {
        $document = $this->normalizer()->normalize("junk before\r\n>q1 first query\r\nACGT\r\nacgt\r\n>q2\r\nTTTT\r\n", MolType::Nucleotide);

        self::assertCount(2, $document->records);
        self::assertSame('q1 first query', $document->records[0]->definition);
        self::assertSame('ACGTacgt', $document->records[0]->sequence);
        self::assertSame(8, $document->records[0]->length());
        self::assertSame(">q1 first query\nACGTacgt\n>q2\nTTTT\n", $document->toFasta());
    }

    public function testOldMacLineEndingsAndByteOrderMark(): void
    {
        $document = $this->normalizer()->normalize("\u{FEFF}>q1\rAC\rGT\r", MolType::Nucleotide);

        self::assertSame('ACGT', $document->records[0]->sequence);
    }

    public function testBareSequenceFromTheTextareaGetsAnEmptyHeader(): void
    {
        $document = $this->normalizer()->normalize("  ACGTN\nRYKM  \n", MolType::Nucleotide);

        self::assertSame('', $document->records[0]->definition);
        self::assertSame(">\nACGTNRYKM\n", $document->toFasta());
    }

    public function testDigitsAndSpacesInGenbankStyleSequencesAreRemoved(): void
    {
        $document = $this->normalizer()->normalize(">p\n1 mkvl aaw*\n11 xbzj-\n", MolType::Protein);

        self::assertSame('mkvlaaw*xbzj-', $document->records[0]->sequence);
    }

    public function testLongSequencesAreWrappedAtEightyColumns(): void
    {
        $fasta = $this->normalizer()->normalize('>long' . "\n" . str_repeat('A', 170), MolType::Nucleotide)->toFasta();

        self::assertSame(">long\n" . str_repeat('A', 80) . "\n" . str_repeat('A', 80) . "\nAAAAAAAAAA\n", $fasta);
    }

    /**
     * Il limite di .env (BLAST_FASTA_MAX_BYTES = 15 MiB) deve stare in una colonna MEDIUMTEXT
     * anche nel caso peggiore: una sola riga, a cui la normalizzazione aggiunge un a capo ogni 80.
     */
    public function testLargestAllowedInputStillFitsAMediumtextColumn(): void
    {
        $limit = 15 * 1024 * 1024;
        $header = ">worst case\n";
        $input = $header . str_repeat('A', $limit - \strlen($header));

        $fasta = $this->normalizer($limit)->normalize($input, MolType::Nucleotide)->toFasta();

        self::assertLessThanOrEqual(16_777_215, \strlen($fasta));
    }

    public function testEmptyInputIsRejected(): void
    {
        $this->expectException(InvalidFastaException::class);
        $this->expectExceptionMessageIsOrContains('The sequence input is empty.');

        $this->normalizer()->normalize(" \r\n ", MolType::Nucleotide);
    }

    public function testOversizedInputIsRejectedBeforeParsing(): void
    {
        $this->expectException(InvalidFastaException::class);
        $this->expectExceptionMessageIsOrContains('maximum size of 10 bytes');

        $this->normalizer(10)->normalize(">q\nACGTACGTACGT", MolType::Nucleotide);
    }

    public function testRecordWithoutResiduesIsRejected(): void
    {
        $this->expectException(InvalidFastaException::class);
        $this->expectExceptionMessageIsOrContains('Sequence #2 has no residues.');

        $this->normalizer()->normalize(">q1\nACGT\n>q2\n\n>q3\nAC", MolType::Nucleotide);
    }

    public function testProteinResiduesInANucleotideQueryAreReported(): void
    {
        $this->expectException(InvalidFastaException::class);
        $this->expectExceptionMessageIsOrContains('Sequence #1 contains invalid characters: EFL');

        $this->normalizer()->normalize(">q1\nACGTEFLLE", MolType::Nucleotide);
    }

    public function testNonLetterSymbolsAreInvalidForProteins(): void
    {
        $this->expectException(InvalidFastaException::class);
        $this->expectExceptionMessageIsOrContains('invalid characters: #');

        $this->normalizer()->normalize(">p\nMKV#", MolType::Protein);
    }

    private function normalizer(int $maxBytes = 1_000_000): FastaNormalizer
    {
        return new FastaNormalizer($maxBytes);
    }
}
