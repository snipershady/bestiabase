<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blast;

use App\Blast\Result\AlignmentCoordinates;
use App\Blast\Result\BlastNumberFormat;
use App\Blast\Result\TabularExporter;
use App\Entity\BlastDatabase;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Enum\MolType;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[CoversClass(TabularExporter::class)]
#[UsesClass(AlignmentCoordinates::class)]
#[UsesClass(BlastNumberFormat::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastHsp::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastQuery::class)]
final class TabularExporterTest extends DatabaseTestCase
{
    public function testOneLinePerHspWithBlastColumns(): void
    {
        self::assertSame(
            "q1\tAE000111\t100.000\t350\t0\t0\t1\t350\t1\t350\t0.0\t632\n"
            . "q1\tAE000111\t91.429\t35\t1\t2\t274\t307\t307\t274\t3.08e-06\t43.7\n",
            $this->export(false),
        );
    }

    public function testCommentedFormatDescribesEveryQueryIncludingThoseWithoutHits(): void
    {
        self::assertSame(
            "# BLASTN 2.17.0+\n# Query: q1 test query\n# Database: test_na_db\n# Fields: " . TabularExporter::FIELDS . "\n# 2 hits found\n"
            . "q1\tAE000111\t100.000\t350\t0\t0\t1\t350\t1\t350\t0.0\t632\n"
            . "q1\tAE000111\t91.429\t35\t1\t2\t274\t307\t307\t274\t3.08e-06\t43.7\n"
            . "# BLASTN 2.17.0+\n# Query: q2\n# Database: test_na_db\n# 0 hits found\n"
            . "# BLAST processed 2 queries\n",
            $this->export(true),
        );
    }

    private function export(bool $withComments): string
    {
        $database = EntityFactory::database('test_na_db', MolType::Nucleotide);
        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $job = EntityFactory::jobOn($database);
        $job->recordBlastVersion('2.17.0+');

        $withHits = new BlastQuery($job, 1, 'q1', 350, 'q1 test query');
        $withoutHits = new BlastQuery($job, 2, 'q2', 40);
        $hit = EntityFactory::hit($withHits);
        $hit->addHsp(EntityFactory::hsp($hit, 1, 632.465, 350, 350));
        $hit->addHsp(new BlastHsp($hit, 2, 43.7246, 47.0, '3.07929e-06', 32, 32, 2, 35, 274, 307, 307, 274, 1, -1, 'AAAAAAGCCCGCACCTGACA-GTGCGGGCTTTTTT', '||||||||||||| ||| || ||||||||||||||', 'AAAAAAGCCCGCA-CTGTCAGGTGCGGGCTTTTTT'));
        $this->persistAndReload($job, $withHits, $withoutHits, $hit);
        $job = $this->entityManager->find(BlastJob::class, $job->getId()) ?? self::fail('job not saved');

        $output = '';
        new TabularExporter($this->entityManager->getConnection(), new EffectivePrimitiveTypeIdentifierService())
            ->export($job, $withComments, static function (string $text) use (&$output): void {
                $output .= $text;
            });

        return $output;
    }
}
