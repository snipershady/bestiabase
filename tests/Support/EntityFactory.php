<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\BlastDatabase;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Enum\SearchType;
use DateTimeImmutable;
use ReflectionProperty;
use Symfony\Component\Uid\Uuid;

/**
 * Costruttori di entity valide per i test, con default sovrascrivibili.
 */
final class EntityFactory
{
    public const string NOW = '2026-10-24 12:00:00';

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    /**
     * @param non-empty-string $email
     * @param non-empty-string $username
     */
    public static function user(string $email = 'user@example.org', string $username = 'user'): User
    {
        return new User($email, $username, 'hashed-password', self::now());
    }

    /**
     * @param non-empty-string $code
     */
    public static function database(string $code = 'test_na_db', MolType $molType = MolType::Nucleotide): BlastDatabase
    {
        return new BlastDatabase($code, 'Title of ' . $code, $molType, '/srv/blastdb/' . $code, self::now());
    }

    /**
     * Database con id assegnato, come se fosse già stato salvato.
     *
     * @param non-empty-string $code
     */
    public static function persistedDatabase(int $id, string $code = 'test_na_db', MolType $molType = MolType::Nucleotide): BlastDatabase
    {
        $database = self::database($code, $molType);
        $database->enableAllCompatiblePrograms();
        new ReflectionProperty(BlastDatabase::class, 'id')->setValue($database, $id);

        return $database;
    }

    public static function job(BlastProgram $program = BlastProgram::Blastn, ?User $user = null, ?string $subjectFasta = null): BlastJob
    {
        return new BlastJob(
            Uuid::v7(),
            $program,
            SearchType::Basic,
            ['expect' => 10.0, 'maxTargetSequences' => 50],
            [['id' => 1, 'code' => 'test_na_db', 'title' => 'Nucleotide test database']],
            ">q1\nACGT\n",
            self::now(),
            $subjectFasta,
            $user,
        );
    }

    /**
     * Job "basic" blastn su un database già salvato (lo snapshot ne contiene l'id).
     */
    public static function jobOn(BlastDatabase $database, ?DateTimeImmutable $createdAt = null, ?User $user = null): BlastJob
    {
        return new BlastJob(
            Uuid::v7(),
            BlastProgram::Blastn,
            SearchType::Basic,
            [],
            [['id' => $database->getId() ?? 0, 'code' => $database->getCode(), 'title' => $database->getTitle()]],
            ">q1 test query\nACGT\n",
            $createdAt ?? self::now(),
            null,
            $user,
        );
    }

    public static function query(BlastJob $job, int $position = 1): BlastQuery
    {
        return new BlastQuery($job, max(1, $position), 'q' . $position, 350, 'q' . $position . ' test query');
    }

    public static function hit(BlastQuery $query, int $rank = 1): BlastHit
    {
        return new BlastHit($query->getJob(), $query, max(1, $rank), 'gi|1786181|gb|AE000111|ECAE000111', 10596, 'AE000111', 'Escherichia coli section 1');
    }

    public static function hsp(BlastHit $hit, int $rank = 1, float $bitScore = 632.0, int $identity = 350, int $alignLength = 350): BlastHsp
    {
        return new BlastHsp(
            hit: $hit,
            rank: max(1, $rank),
            bitScore: $bitScore,
            rawScore: 350.0,
            eValue: '0.0',
            identity: max(0, $identity),
            positive: max(0, $identity),
            gaps: 0,
            alignLength: max(1, $alignLength),
            queryFrom: 1,
            queryTo: 350,
            hitFrom: 1,
            hitTo: 350,
            queryFrame: 1,
            hitFrame: 1,
            querySequence: 'ACGT',
            midline: '||||',
            hitSequence: 'ACGT',
        );
    }
}
