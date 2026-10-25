<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\HelpController;
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
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(HelpController::class)]
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
#[UsesClass(SearchFormClientConfig::class)]
#[UsesClass(SearchFormData::class)]
#[UsesClass(SearchFormType::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(SecurityCheckGate::class)]
#[UsesClass(User::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
final class HelpControllerTest extends FunctionalTestCase
{
    public function testHelpPagesAreLinkedFromEveryPage(): void
    {
        $user = EntityFactory::user();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');
        self::assertCount(1, $crawler->filter('nav a[href="/help"]'));
        self::assertCount(1, $crawler->filter('summary a[href="/help/parameters"]'));

        $help = $this->client->click($crawler->filter('nav a[href="/help"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSame(['search', 'fasta', 'programs', 'databases', 'results', 'downloads'], $help->filter('section[id]')->each(static fn (Crawler $section): string => (string) $section->attr('id')));
    }

    public function testEveryAdvancedParameterIsDocumented(): void
    {
        $crawler = $this->client->request('GET', '/help/parameters');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['expect', 'max-target-sequences', 'word-size', 'match-mismatch', 'matrix', 'gap-costs', 'low-complexity', 'soft-masking', 'lowercase-masking', 'ungapped', 'genetic-code'],
            $crawler->filter('dt[id]')->each(static fn (Crawler $term): string => (string) $term->attr('id')),
        );
    }
}
