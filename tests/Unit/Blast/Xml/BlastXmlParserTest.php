<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Xml;

use App\Blast\Exception\BlastOutputParseException;
use App\Blast\Xml\BlastXmlParser;
use App\Blast\Xml\ParsedHit;
use App\Blast\Xml\ParsedHsp;
use App\Blast\Xml\ParsedIteration;
use App\Tests\Support\TemporaryDirectory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(BlastXmlParser::class)]
#[CoversClass(ParsedIteration::class)]
#[CoversClass(ParsedHit::class)]
#[CoversClass(ParsedHsp::class)]
#[CoversClass(BlastOutputParseException::class)]
final class BlastXmlParserTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../Fixtures/blast/';

    private TemporaryDirectory $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testQueryWithoutHitsAndQueryWithHits(): void
    {
        $iterations = $this->parse('blastn_parse_seqids.xml');

        self::assertCount(2, $iterations);
        self::assertSame(1, $iterations[0]->number);
        self::assertSame('nohit random', $iterations[0]->queryDefinition);
        self::assertSame(40, $iterations[0]->queryLength);
        self::assertSame([], $iterations[0]->hits);

        $hit = $iterations[1]->hits[0];
        self::assertSame('q2, second;x', $iterations[1]->queryDefinition);
        self::assertSame(1, $hit->number);
        self::assertSame('gi|1786181|gb|AE000111|ECAE000111', $hit->id);
        self::assertSame('AE000111', $hit->accession);
        self::assertStringStartsWith('Escherichia coli', $hit->definition);
        self::assertSame(10596, $hit->length);

        $hsp = $hit->hsps[0];
        self::assertSame(1, $hsp->number);
        self::assertEqualsWithDelta(98.6682, $hsp->bitScore, 1e-9);
        self::assertEqualsWithDelta(108.0, $hsp->score, 1e-9);
        self::assertSame('5.36511e-24', $hsp->eValue);
        self::assertSame([1, 54, 1, 54, 1, 1], [$hsp->queryFrom, $hsp->queryTo, $hsp->hitFrom, $hsp->hitTo, $hsp->queryFrame, $hsp->hitFrame]);
        self::assertSame([54, 54, 0, 54], [$hsp->identity, $hsp->positive, $hsp->gaps, $hsp->alignLength]);
        self::assertSame(54, \strlen($hsp->querySequence));
        self::assertSame($hsp->querySequence, $hsp->hitSequence);
        self::assertSame(str_repeat('|', 54), $hsp->midline);
    }

    public function testLegacyVolumesWithoutSeqidsExposeOrdinalIds(): void
    {
        $hits = $this->parse('blastn_legacy_volumes.xml')[0]->hits;

        self::assertSame('gnl|BL_ORD_ID|0', $hits[0]->id);
        self::assertSame('0', $hits[0]->accession);
        self::assertStringStartsWith('gi|1786181|gb|AE000111|', $hits[0]->definition);
        self::assertCount(2, $hits[0]->hsps, 'the second HSP is the minus-strand match');
        self::assertSame(-1, $hits[0]->hsps[1]->hitFrame);
        self::assertGreaterThan($hits[0]->hsps[1]->hitTo, $hits[0]->hsps[1]->hitFrom);
    }

    public function testTranslatedSearchKeepsTheFrames(): void
    {
        $hsp = $this->parse('tblastn_frames.xml')[0]->hits[0]->hsps[0];

        self::assertSame(0, $hsp->queryFrame);
        self::assertSame(-1, $hsp->hitFrame);
    }

    public function testMissingFileIsUnreadable(): void
    {
        $this->expectException(BlastOutputParseException::class);
        $this->expectExceptionMessageIsOrContains('cannot be read');

        iterator_to_array(new BlastXmlParser()->parse($this->directory->path . '/missing.xml'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedOutputs(): iterable
    {
        $valid = (string) file_get_contents(self::FIXTURES . 'blastn_parse_seqids.xml');

        yield 'truncated inside a hit' => [substr($valid, 0, 3000), 'cannot expand <Hit>'];
        yield 'broken markup before the hits' => [str_replace('<Iteration_query-len>40</Iteration_query-len>', '<Iteration_query-len>40</Iteration_query-lenX>', $valid), 'is malformed'];
        yield 'non numeric length' => [str_replace('<Hit_len>10596</Hit_len>', '<Hit_len>long</Hit_len>', $valid), '<Hit_len> is not an integer: "long"'];
        yield 'non numeric score' => [str_replace('<Hsp_bit-score>98.6682</Hsp_bit-score>', '<Hsp_bit-score>n/a</Hsp_bit-score>', $valid), '<Hsp_bit-score> is not a number: "n/a"'];
        yield 'missing field' => [str_replace('<Hit_accession>AE000111</Hit_accession>', '', $valid), '<Hit> has no <Hit_accession>'];
    }

    #[DataProvider('malformedOutputs')]
    public function testMalformedOutputIsRejected(string $xml, string $message): void
    {
        $path = $this->directory->path . '/output.xml';
        new Filesystem()->dumpFile($path, $xml);

        $this->expectException(BlastOutputParseException::class);
        $this->expectExceptionMessageIsOrContains($message);

        iterator_to_array(new BlastXmlParser()->parse($path));
    }

    /**
     * @return list<ParsedIteration>
     */
    private function parse(string $fixture): array
    {
        return iterator_to_array(new BlastXmlParser()->parse(self::FIXTURES . $fixture), false);
    }
}
