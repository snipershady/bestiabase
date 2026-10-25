<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\DatabaseAdminController;
use App\Controller\Admin\JobAdminController;
use App\Controller\Admin\UserAdminController;
use App\Controller\SearchController;
use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastJob;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Enum\UserRole;
use App\EventSubscriber\NotFoundSubscriber;
use App\Form\Admin\DatabaseAdminData;
use App\Form\Admin\DatabaseAdminType;
use App\Form\Admin\UserAdminData;
use App\Form\Admin\UserAdminType;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastJobRepository;
use App\Repository\UserRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Security\SecurityHeadersListener;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastCommandBuilder;
use App\Service\Blast\Fasta\FastaNormalizer;
use App\Service\Blast\Job\BlastJobFactory;
use App\Service\Blast\Job\BlastJobSubmitter;
use App\Service\Blast\Search\GapCostPresets;
use App\Service\Blast\Search\GapCosts;
use App\Service\Blast\Search\GeneticCode;
use App\Service\Blast\Search\SearchParameters;
use App\Service\Blast\Search\SearchParametersNormalizer;
use App\Service\Mail\AccountMailer;
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Service\SecurityCheck\SecurityCheckGate;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(DatabaseAdminController::class)]
#[CoversClass(UserAdminController::class)]
#[CoversClass(JobAdminController::class)]
#[CoversClass(DatabaseAdminType::class)]
#[CoversClass(UserAdminType::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(FastaNormalizer::class)]
#[UsesClass(BlastJobFactory::class)]
#[UsesClass(BlastJobSubmitter::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(GeneticCode::class)]
#[UsesClass(SearchParameters::class)]
#[UsesClass(SearchParametersNormalizer::class)]
#[UsesClass(SearchController::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(DatabaseAdminData::class)]
#[UsesClass(UserAdminData::class)]
#[UsesClass(AccountMailer::class)]
#[UsesClass(SearchFormClientConfig::class)]
#[UsesClass(SearchFormData::class)]
#[UsesClass(SearchFormType::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastJobRepository::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(SecurityCheckGate::class)]
final class AdministrationTest extends FunctionalTestCase
{
    private User $admin;

    private User $member;

    private BlastDatabase $database;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = EntityFactory::user('admin@example.org', 'admin');
        $this->admin->grantRole(UserRole::Admin);

        $this->member = EntityFactory::user('member@example.org', 'member');
        $this->database = EntityFactory::database('mouse_db', MolType::Nucleotide);
        $this->database->enableAllCompatiblePrograms();

        foreach ([$this->admin, $this->member, $this->database] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function testAdministrationIsReservedToAdministrators(): void
    {
        foreach (['/admin/databases', '/admin/users', '/admin/jobs'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseRedirects('/login', null, $page);
        }

        $this->client->loginUser($this->member);

        foreach (['/admin/databases', '/admin/users/new', '/admin/jobs'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('a[href="/admin/databases"]', 'no administration link for members');
    }

    public function testDatabaseVisibilityProgramsAndAuthorizedUsers(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/databases');
        self::assertSelectorTextContains('table', 'mouse_db');

        $crawler = $this->client->click($crawler->selectLink('Edit')->link());
        $form = $crawler->selectButton('Save')->form();
        $form['database_admin[title]'] = 'Mus musculus';
        $this->choice($form, 'database_admin[public]')->untick();
        $this->choice($form, 'database_admin[programs]', 1)->untick();
        $this->choice($form, 'database_admin[authorizedUsers]')->select([(string) $this->member->getId()]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/databases');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Database "mouse_db" updated.');
        self::assertSelectorTextContains('table', 'restricted');
        $this->entityManager->clear();
        $database = $this->entityManager->getRepository(BlastDatabase::class)->findOneBy(['code' => 'mouse_db']) ?? self::fail('database lost');
        self::assertSame('Mus musculus', $database->getTitle());
        self::assertFalse($database->isPublic());
        self::assertCount(2, $database->getPrograms());
        self::assertSame(['member@example.org'], array_map(static fn (User $user): string => $user->getEmail(), $database->getAuthorizedUsers()));
    }

    public function testDatabaseNeedsAtLeastOneProgram(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/databases/' . $this->database->getId());
        $form = $crawler->selectButton('Save')->form();

        foreach ([0, 1, 2] as $index) {
            $this->choice($form, 'database_admin[programs]', $index)->untick();
        }

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Enable at least one program.');
    }

    public function testUsersAreCreatedWithAccessToRestrictedDatabases(): void
    {
        $this->database->restrict();
        $this->entityManager->flush();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/users/new');
        $form = $crawler->selectButton('Save')->form();
        $form['user_admin[email]'] = 'new@example.org';
        $form['user_admin[username]'] = 'newcomer';
        $form['user_admin[password][first]'] = 'correct horse battery';
        $form['user_admin[password][second]'] = 'correct horse battery';
        $this->choice($form, 'user_admin[databases]', 0)->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users');
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'new@example.org']) ?? self::fail('user not saved');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'correct horse battery'));
        $database = $this->entityManager->getRepository(BlastDatabase::class)->findOneBy(['code' => 'mouse_db']) ?? self::fail('database lost');
        self::assertTrue($database->isAccessibleBy($user));

        $crawler = $this->client->request('GET', '/admin/users');
        self::assertSelectorTextContains('table', 'newcomer');
        self::assertStringContainsString('mouse_db', $crawler->filter('table tr:contains("newcomer")')->text());
    }

    public function testDuplicateUsersAndShortPasswordsAreRejected(): void
    {
        $this->client->loginUser($this->admin);

        foreach ([['member@example.org', 'someone', 'correct horse battery', 'already exists'], ['x@example.org', 'x', 'short', 'This value is too short.']] as [$email, $username, $password, $message]) {
            $crawler = $this->client->request('GET', '/admin/users/new');
            $form = $crawler->selectButton('Save')->form();
            $form['user_admin[email]'] = $email;
            $form['user_admin[username]'] = $username;
            $form['user_admin[password][first]'] = $password;
            $form['user_admin[password][second]'] = $password;
            $this->client->submit($form);

            self::assertResponseStatusCodeSame(422, $message);
            self::assertSelectorTextContains('form', $message);
        }
    }

    public function testUserIsDisabledPromotedAndGivenANewPassword(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/users/' . $this->member->getId());
        $form = $crawler->selectButton('Save')->form();
        $this->choice($form, 'user_admin[admin]')->tick();
        $this->choice($form, 'user_admin[active]')->untick();
        $form['user_admin[password][first]'] = 'another long password';
        $form['user_admin[password][second]'] = 'another long password';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users');
        $this->entityManager->clear();
        $member = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'member@example.org']) ?? self::fail('user lost');
        self::assertTrue($member->hasRole(UserRole::Admin));
        self::assertFalse($member->isActive());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($member, 'another long password'));
    }

    public function testAdministratorsCannotLockThemselvesOut(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/users/' . $this->admin->getId());
        $form = $crawler->selectButton('Save')->form();
        $this->choice($form, 'user_admin[admin]')->untick();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'You cannot remove your own administrator role');
    }

    public function testJobsOfAllUsersAreListed(): void
    {
        $job = EntityFactory::jobOn($this->database, null, $this->member);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/jobs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'member@example.org');
        self::assertSelectorTextContains('table', 'mouse_db');
    }

    /**
     * Campo checkbox/select del form: DomCrawler restituisce un tipo unione molto ampio.
     */
    private function choice(Form $form, string $name, ?int $index = null): ChoiceFormField
    {
        $field = $form[$name];

        if ($index !== null && \is_array($field)) {
            $field = $field[$index] ?? null;
        }

        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }
}
