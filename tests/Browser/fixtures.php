<?php

declare(strict_types=1);

/*
 * Dati per i test del browser (tests/Browser/*.test.mjs), nel database di test.
 *
 *   php tests/Browser/fixtures.php seed            crea database e job, stampa gli id in JSON
 *   php tests/Browser/fixtures.php finish <uuid>   porta un job in attesa a "finished"
 *   php tests/Browser/fixtures.php teardown        cancella tutto ciò che ha creato (prefisso browser_)
 */

use App\Entity\BlastDatabase;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Enum\SearchType;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require __DIR__ . '/../../vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(__DIR__ . '/../../.env');

$kernel = new Kernel('test', false);
$kernel->boot();
$entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');

if (!$entityManager instanceof EntityManagerInterface) {
    throw new LogicException('Doctrine is not available.');
}

$connection = $entityManager->getConnection();
$now = new DateTimeImmutable();

$teardown = static function () use ($connection): void {
    // Le FK cancellano in cascata query, hit, HSP e download dei job.
    $connection->executeStatement("DELETE FROM blast_job WHERE JSON_SEARCH(database_snapshot, 'one', 'browser\\_%', NULL, '$[*].code') IS NOT NULL");
    $connection->executeStatement("DELETE FROM blast_database WHERE code LIKE 'browser\\_%'");
    $connection->executeStatement("DELETE FROM `user` WHERE email LIKE 'browser\\_%'");
};

$database = static function (string $code, string $title, MolType $molType) use ($entityManager, $now): BlastDatabase {
    $database = new BlastDatabase($code, $title, $molType, ($molType === MolType::Nucleotide ? 'nucleotide/' : 'protein/') . $code, $now);
    $database->enableAllCompatiblePrograms();

    $entityManager->persist($database);

    return $database;
};

$job = static function (BlastDatabase $database) use ($entityManager, $now): BlastJob {
    $entityManager->flush();
    $job = new BlastJob(Uuid::v7(), BlastProgram::Blastn, SearchType::Basic, [], [['id' => (int) $database->getId(), 'code' => $database->getCode(), 'title' => $database->getTitle()]], ">q1\nACGT\n", $now);
    $entityManager->persist($job);

    return $job;
};

$command = $argv[1] ?? '';

if ($command === 'seed') {
    $teardown();
    $nucleotide = $database('browser_na', 'Browser nucleotides', MolType::Nucleotide);
    $database('browser_aa', 'Browser proteins', MolType::Protein);
    // L'utente abilitato che esegue le ricerche (password: BROWSER_PASSWORD in support.mjs).
    $user = new User('browser_user@example.org', 'browser_user', password_hash('browser test password', \PASSWORD_BCRYPT, ['cost' => 4]), $now);
    $user->activate();
    $user->verifyEmail($now);
    $entityManager->persist($user);
    $pending = $job($nucleotide);
    $finished = $job($nucleotide);
    $finished->start($now);
    $finished->finish($now, 1, 3);
    $query = new BlastQuery($finished, 1, 'q1', 4);
    $entityManager->persist($query);

    for ($rank = 1; $rank <= 3; ++$rank) {
        $hit = new BlastHit($finished, $query, $rank, 'subject_' . $rank, 4, null, 'subject_' . $rank . ' browser test');
        $hit->addHsp(new BlastHsp($hit, 1, 50.0 - $rank, 25.0, '1e-5', 4, 4, 0, 4, 1, 4, 1, 4, 1, 1, 'ACGT', '||||', 'ACGT'));
        $entityManager->persist($hit);
    }

    $entityManager->flush();
    echo json_encode(['pending' => $pending->getId()->toRfc4122(), 'finished' => $finished->getId()->toRfc4122()], \JSON_THROW_ON_ERROR), "\n";
} elseif ($command === 'finish') {
    $pending = $entityManager->find(BlastJob::class, Uuid::fromString($argv[2] ?? ''));

    if (!$pending instanceof BlastJob) {
        throw new InvalidArgumentException('Unknown job.');
    }

    $pending->start($now);
    $pending->finish($now, 1, 0);
    $entityManager->flush();
} elseif ($command === 'teardown') {
    $teardown();
} else {
    fwrite(\STDERR, "Usage: fixtures.php seed|finish <uuid>|teardown\n");

    exit(1);
}
