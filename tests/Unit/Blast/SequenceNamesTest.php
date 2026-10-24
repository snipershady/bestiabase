<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast;

use App\Blast\SequenceNames;
use App\Blast\Xml\ParsedHit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SequenceNames::class)]
#[UsesClass(ParsedHit::class)]
final class SequenceNamesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function queries(): iterable
    {
        yield 'first token' => ['q1 test query', 1, 'q1'];
        yield 'cut at comma and semicolon' => ['q2, second;x', 2, 'q2'];
        yield 'cut at plus' => ['HIV+env sample', 1, 'HIV'];
        yield 'empty defline' => ['   ', 3, 'Query3'];
        yield 'BLAST placeholder' => ['No definition line', 4, 'Query4'];
        yield 'separator first' => [',odd', 5, 'Unnamed'];
    }

    #[DataProvider('queries')]
    public function testQueryName(string $definition, int $position, string $expected): void
    {
        self::assertSame($expected, SequenceNames::query($definition, $position));
    }

    /**
     * @return iterable<string, array{string, string, string, string, ?string}>
     */
    public static function subjects(): iterable
    {
        // [Hit_id, Hit_def, Hit_accession, nome atteso, accessione attesa]
        yield 'parse_seqids GenBank' => ['gi|1786181|gb|AE000111|ECAE000111', 'Escherichia coli', 'AE000111', 'gi|1786181|gb|AE000111|ECAE000111', 'AE000111'];
        yield 'legacy volumes, gi defline' => ['gnl|BL_ORD_ID|0', 'gi|1786181|gb|AE000111|ECAE000111 Escherichia coli', '0', 'gi|1786181|gb|AE000111|ECAE000111', 'AE000111'];
        yield 'legacy volumes, versioned protein' => ['gnl|BL_ORD_ID|7', 'gi|6693791|gb|AAF24984.1|AF082179_1 (AF082179) HARP', '7', 'gi|6693791|gb|AAF24984.1|AF082179_1', 'AAF24984.1'];
        yield 'RefSeq accession' => ['gnl|BL_ORD_ID|1', 'gi|123|ref|NM_000546.6| TP53', '1', 'gi|123|ref|NM_000546.6|', 'NM_000546.6'];
        yield 'bare accession' => ['gnl|BL_ORD_ID|2', 'X12345.1 some gene', '2', 'X12345.1', 'X12345.1'];
        yield 'bare RefSeq accession' => ['gnl|BL_ORD_ID|3', 'XM_12345 predicted', '3', 'XM_12345', 'XM_12345'];
        yield 'general database id' => ['gnl|BL_ORD_ID|4', 'gnl|hiv|AB012345.2 env', '4', 'gnl|hiv|AB012345.2', 'AB012345.2'];
        yield 'parse_seqids versioned protein' => ['gi|6693798|gb|AAF24986.1|AF116242_1', 'K-Cl cotransporter KCC3', 'AAF24986', 'gi|6693798|gb|AAF24986.1|AF116242_1', 'AAF24986.1'];
        yield 'SwissProt seqid falls back to Hit_accession' => ['sp|P69905|HBA_HUMAN', 'Hemoglobin subunit alpha', 'P69905', 'sp|P69905|HBA_HUMAN', 'P69905'];
        yield 'local id' => ['lcl|patient-12', 'clone 4', 'patient-12', 'patient-12', null];
        yield 'uploaded subject without accession' => ['gnl|BL_ORD_ID|5', 'patient_12_clone4, visit 2', '5', 'patient_12_clone4', null];
        yield 'empty local id' => ['lcl|', '', '', 'lcl|', null];
    }

    #[DataProvider('subjects')]
    public function testSubjectNameAndAccession(string $id, string $definition, string $accession, string $name, ?string $expectedAccession): void
    {
        $hit = new ParsedHit(1, $id, $definition, $accession, 100, []);

        self::assertSame($name, SequenceNames::subject($hit));
        self::assertSame($expectedAccession, SequenceNames::subjectAccession($hit));
    }
}
