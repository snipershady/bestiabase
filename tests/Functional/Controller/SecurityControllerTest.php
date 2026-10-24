<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastCommandBuilder;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Job\BlastJobFactory;
use App\Blast\Job\BlastJobSubmitter;
use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\GeneticCode;
use App\Blast\Search\SearchParameters;
use App\Blast\Search\SearchParametersNormalizer;
use App\Controller\SearchController;
use App\Controller\SecurityController;
use App\Entity\User;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\UserRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Security\RecordLastLoginListener;
use App\Security\SecurityHeadersListener;
use App\Security\UserChecker;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(SecurityController::class)]
#[CoversClass(RecordLastLoginListener::class)]
#[CoversClass(UserChecker::class)]
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
#[UsesClass(User::class)]
#[UsesClass(SearchFormClientConfig::class)]
#[UsesClass(SearchFormData::class)]
#[UsesClass(SearchFormType::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
final class SecurityControllerTest extends FunctionalTestCase
{
    private const string PASSWORD = 'correct horse battery';

    public function testSignInRecordsTheLastLoginAndSignOut(): void
    {
        $this->user();

        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="_csrf_token"]'));

        $this->signIn(self::PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('nav', 'Sign out (lab)');
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'lab@example.org'])?->getLastLoginAt());

        $this->client->request('GET', '/login');
        self::assertResponseRedirects('/', null, 'already signed in');

        $this->client->request('GET', '/logout');
        $this->client->followRedirect();
        self::assertSelectorTextContains('nav', 'Sign in');
    }

    public function testWrongPasswordIsReported(): void
    {
        $this->user();

        $this->signIn('wrong password');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Invalid credentials.');
    }

    public function testDisabledUsersCannotSignIn(): void
    {
        $user = $this->user();
        $user->deactivate();

        $this->entityManager->flush();

        $this->signIn(self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Your account has been disabled.');
    }

    private function signIn(string $password): void
    {
        $this->client->request('POST', '/login', ['_username' => 'lab@example.org', '_password' => $password, '_csrf_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function user(): User
    {
        $user = EntityFactory::user('lab@example.org', 'lab');
        $user->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
