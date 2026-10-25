<?php

declare(strict_types=1);

namespace App\Service\Altcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use InvalidArgumentException;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Verifica la soluzione che il widget invia nel campo `altcha` (payload base64: sfida + soluzione).
 *
 * Oltre alla firma e alla proof of work controlla che la sfida sia stata emessa per lo scopo
 * atteso e con la sua difficoltà (altrimenti se ne userebbe una più economica, presa da un'altra
 * pagina), e la rende monouso: la stessa soluzione non vale due volte.
 */
final readonly class AltchaVerifier implements AltchaVerifierInterface
{
    public function __construct(
        private AltchaSecret $secret,
        private AltchaDifficulties $difficulties,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    #[Override]
    public function verify(?string $payload, AltchaPurpose $purpose): bool
    {
        $payload = trim((string) $payload);

        if ($payload === '') {
            return false;
        }

        try {
            $decoded = Payload::fromBase64($payload);
        } catch (InvalidArgumentException) {
            return false;
        }

        $difficulty = $this->difficulties->forPurpose($purpose);
        $algorithm = new Pbkdf2($difficulty->hmacAlgorithm());
        $parameters = $decoded->challenge->parameters;

        if (!$this->issuedFor($parameters, $purpose, $difficulty, $algorithm)) {
            return false;
        }

        $item = $this->cache->getItem('altcha_used_' . hash('sha256', $parameters->nonce . $parameters->salt));

        if ($item->isHit()) {
            return false;
        }

        if (!new Altcha(hmacSignatureSecret: $this->secret->value)->verifySolution(new VerifySolutionOptions($decoded, $algorithm))->verified) {
            return false;
        }

        // Si ricorda finché la sfida sarebbe ancora valida: dopo, scade comunque.
        $this->cache->save($item->set(true)->expiresAfter($this->remainingSeconds($parameters)));

        return true;
    }

    private function issuedFor(ChallengeParameters $parameters, AltchaPurpose $purpose, AltchaDifficulty $difficulty, Pbkdf2 $algorithm): bool
    {
        return ($parameters->data['purpose'] ?? null) === $purpose->value
            && $parameters->algorithm === $algorithm->getAlgorithmName()
            && $parameters->cost === $difficulty->cost()
            && \strlen($parameters->keyPrefix) === 2 * $difficulty->keyPrefixLength();
    }

    private function remainingSeconds(ChallengeParameters $parameters): int
    {
        return max(1, (int) ceil(((float) ($parameters->expiresAt ?? 0)) - $this->clock->now()->getTimestamp()));
    }
}
