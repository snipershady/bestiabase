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
use App\Controller\HelpController;
use App\Controller\SearchController;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Repository\BlastDatabaseRepository;
use App\Security\SecurityHeadersListener;
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
final class HelpControllerTest extends FunctionalTestCase
{
    public function testHelpPagesAreLinkedFromEveryPage(): void
    {
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
