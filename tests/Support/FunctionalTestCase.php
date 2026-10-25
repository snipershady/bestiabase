<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaDifficulties;
use App\Service\Altcha\AltchaPurpose;
use App\Service\SecurityCheck\SecurityCheckCookie;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test HTTP con il database in una transazione annullata a fine test. Il kernel non viene
 * riavviato tra le richieste, così client e test condividono la stessa connessione.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected EntityManagerInterface $entityManager;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        // I contatori del rate limiter sopravvivono tra i test: ogni test parte da zero.
        $limiterCache = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limiterCache);
        $limiterCache->clear();

        $this->passSecurityCheck();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        parent::tearDown();
    }

    /**
     * Il visitatore ha già superato la verifica di sicurezza della home (proof of work).
     */
    protected function passSecurityCheck(): void
    {
        $cookie = self::getContainer()->get(SecurityCheckCookie::class)->issue(Request::create('/'));
        $this->client->getCookieJar()->set(new BrowserKitCookie($cookie->getName(), (string) $cookie->getValue()));
    }

    /**
     * La soluzione ALTCHA che il widget metterebbe nel form.
     */
    protected function altchaPayload(AltchaPurpose $purpose): string
    {
        return new AltchaTestSolver(self::getContainer()->get(AltchaChallengeFactory::class), self::getContainer()->get(AltchaDifficulties::class))->payload($purpose);
    }
}
