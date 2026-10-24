<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form\Admin;

use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Form\Admin\DatabaseAdminData;
use App\Form\Admin\UserAdminData;
use App\Tests\Support\EntityFactory;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseAdminData::class)]
#[CoversClass(UserAdminData::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastProgram::class)]
final class AdminDataTest extends TestCase
{
    public function testDatabaseFormRoundTrip(): void
    {
        $database = EntityFactory::database('test_aa_db', MolType::Protein);
        $database->enableAllCompatiblePrograms();

        $kept = EntityFactory::user('kept@example.org', 'kept');
        $removed = EntityFactory::user('removed@example.org', 'removed');
        $added = EntityFactory::user('added@example.org', 'added');
        $database->authorize($kept);
        $database->authorize($removed);

        $data = DatabaseAdminData::fromDatabase($database);
        self::assertSame(['Title of test_aa_db', null, true, true, [BlastProgram::Blastp, BlastProgram::Blastx]], [$data->title, $data->description, $data->active, $data->public, $data->programs]);

        $data->title = '  HepA proteins  ';
        $data->description = 'Human and mouse';
        $data->active = false;
        $data->public = false;
        $data->programs = [BlastProgram::Blastp];
        $data->authorizedUsers = new ArrayCollection([$kept, $added]);
        $data->applyTo($database);

        self::assertSame('HepA proteins', $database->getTitle());
        self::assertSame('Human and mouse', $database->getDescription());
        self::assertFalse($database->isActive());
        self::assertFalse($database->isPublic());
        self::assertSame([BlastProgram::Blastp], $database->getPrograms());
        self::assertSame([$kept, $added], $database->getAuthorizedUsers());

        $data->title = ' ';
        $data->description = ' ';
        $data->active = true;
        $data->public = true;
        $data->applyTo($database);

        self::assertSame('HepA proteins', $database->getTitle(), 'an empty title keeps the current one');
        self::assertNull($database->getDescription());
        self::assertTrue($database->isActive());
        self::assertTrue($database->isPublic());
    }

    public function testUserFormRoundTrip(): void
    {
        $user = EntityFactory::user('ada@example.org', 'ada');
        $first = $this->restricted('first_db');
        $second = $this->restricted('second_db');
        $first->authorize($user);

        $data = UserAdminData::fromUser($user, [$first, $second]);
        self::assertSame(['ada@example.org', 'ada', false, true, [$first]], [$data->email, $data->username, $data->admin, $data->active, $data->databases->toArray()]);

        $data->admin = true;
        $data->active = false;
        $data->databases = new ArrayCollection([$second]);
        $data->applyTo($user, [$first, $second]);

        self::assertTrue($user->isAdmin());
        self::assertFalse($user->isActive());
        self::assertSame([], $first->getAuthorizedUsers());
        self::assertSame([$user], $second->getAuthorizedUsers());

        $data->admin = false;
        $data->active = true;
        $data->applyTo($user, [$first, $second]);

        self::assertFalse($user->isAdmin());
        self::assertTrue($user->isActive());
    }

    private function restricted(string $code): BlastDatabase
    {
        $database = EntityFactory::database($code === '' ? 'db' : $code);
        $database->restrict();

        return $database;
    }
}
