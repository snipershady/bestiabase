<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Controller\SearchController;
use App\Entity\User;
use App\EventSubscriber\NotFoundSubscriber;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Repository\BlastDatabaseRepository;
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
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Service\SecurityCheck\SecurityCheckGate;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(SearchController::class)]
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
#[UsesClass(User::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(SearchFormClientConfig::class)]
#[UsesClass(SearchFormData::class)]
#[UsesClass(SearchFormType::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(SecurityCheckGate::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
final class HomePageTest extends FunctionalTestCase
{
    public function testAnonymousVisitorsSeeTheProjectDescriptionButCannotSearch(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#about-title', 'About BestiaBase');
        self::assertSelectorTextContains('main', 'similarity searches with NCBI BLAST+');
        self::assertSelectorExists('main a[href="/login"]');
        self::assertSelectorExists('main a[href="/register"]');
        self::assertSelectorNotExists('form[data-controller="search-form"]');

        $this->client->request('POST', '/', ['search_form' => ['_token' => 'csrf-token', 'program' => 'blastn', 'query' => 'ACGT']], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_job'));
    }

    public function testEnabledUsersSeeOnlyTheSearchForm(): void
    {
        $this->client->loginUser($this->persisted(EntityFactory::user()));
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#about-title');
        self::assertSelectorExists('form[data-controller="search-form"]');
    }

    public function testDisabledUsersAreAskedToWaitForAnAdministrator(): void
    {
        $user = EntityFactory::user();
        $user->deactivate();

        $this->client->loginUser($this->persisted($user));

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-info', 'Please wait for an administrator to enable your account.');
        self::assertSelectorNotExists('form[data-controller="search-form"]');

        $this->client->request('POST', '/', ['search_form' => ['_token' => 'csrf-token', 'program' => 'blastn', 'query' => 'ACGT']], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/my-jobs');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnconfirmedAddressesAreAskedToConfirmTheEmail(): void
    {
        $this->client->loginUser($this->persisted(EntityFactory::pendingUser()));
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.alert-warning', 'Confirm your email address');
        self::assertSelectorTextContains('.alert-info', 'Please wait for an administrator to enable your account.');
        self::assertSelectorExists('form[action="/account/verify-email/resend"]');
    }

    private function persisted(User $user): User
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
