<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Controller\SearchController;
use App\Controller\SecurityController;
use App\EventSubscriber\NotFoundSubscriber;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastJobRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Security\SecurityHeadersListener;
use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaDifficulties;
use App\Service\Altcha\AltchaDifficulty;
use App\Service\Altcha\AltchaSecret;
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
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use DOMElement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(SecurityHeadersListener::class)]
#[CoversClass(SecurityExtension::class)]
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
#[UsesClass(SecurityController::class)]
#[UsesClass(SearchFormClientConfig::class)]
#[UsesClass(SearchFormData::class)]
#[UsesClass(SearchFormType::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastJobRepository::class)]
#[UsesClass(AltchaChallengeFactory::class)]
#[UsesClass(AltchaDifficulties::class)]
#[UsesClass(AltchaDifficulty::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(SecurityCheckGate::class)]
#[UsesClass(AltchaSecret::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
final class SecurityHeadersTest extends FunctionalTestCase
{
    public function testInDebugModeTheProfilerReusesTheNonceOfThePage(): void
    {
        foreach ([true, false] as $debug) {
            $request = new Request();
            $request->attributes->set(SecurityHeadersListener::NONCE_ATTRIBUTE, 'abc123');
            $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new Response());

            new SecurityHeadersListener($debug)->onResponse($event);

            $headers = $event->getResponse()->headers;
            self::assertSame($debug ? 'abc123' : null, $headers->get('X-SymfonyProfiler-Script-Nonce'));
            self::assertSame($debug ? 'abc123' : null, $headers->get('X-SymfonyProfiler-Style-Nonce'));
        }
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::SUB_REQUEST, new Response());

        new SecurityHeadersListener()->onResponse($event);

        self::assertFalse($event->getResponse()->headers->has('Content-Security-Policy'));
    }

    public function testPagesCarryTheSecurityHeadersAndANoncedCsp(): void
    {
        $crawler = $this->client->request('GET', '/');
        $headers = $this->client->getResponse()->headers;
        $csp = (string) $headers->get('Content-Security-Policy');
        $nonce = (string) $crawler->filter('meta[name="csp-nonce"]')->attr('content');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
        self::assertStringContainsString("script-src 'self' 'nonce-{$nonce}'", $csp);
        self::assertStringContainsString("style-src 'self' 'nonce-{$nonce}'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("worker-src 'self' blob:", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('same-origin', $headers->get('Referrer-Policy'));
        self::assertStringContainsString('camera=()', (string) $headers->get('Permissions-Policy'));

        $scripts = $crawler->filter('head script');
        self::assertGreaterThan(0, $scripts->count());

        foreach ($scripts as $script) {
            self::assertInstanceOf(DOMElement::class, $script);
            self::assertSame($nonce, $script->getAttribute('nonce'), 'every inline script of the importmap carries the nonce');
        }

        self::assertCount(0, $crawler->filter('body script'), 'no inline scripts in the page body: Turbo would re-run them with a stale nonce');
    }

    public function testTheNonceChangesOnEveryRequest(): void
    {
        $first = (string) $this->client->request('GET', '/')->filter('meta[name="csp-nonce"]')->attr('content');
        $second = (string) $this->client->request('GET', '/login')->filter('meta[name="csp-nonce"]')->attr('content');

        self::assertNotSame($first, $second);
    }

    public function testNonPageResponsesAreProtectedToo(): void
    {
        $this->client->request('GET', '/job/01a16e36-1f83-7128-a937-e08ba427daa6/status');

        self::assertResponseRedirects('/nopainnogain');
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }
}
