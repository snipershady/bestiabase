<?php

declare(strict_types=1);

namespace App\Service\Altcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use Psr\Clock\ClockInterface;

/**
 * Emette le sfide ALTCHA (proof of work PBKDF2) da passare in linea al widget. La sfida è firmata
 * con ALTCHA_KEY e porta nei dati lo scopo per cui vale.
 */
final readonly class AltchaChallengeFactory
{
    public const string VALIDITY = '+10 minutes';

    public function __construct(
        private AltchaSecret $secret,
        private AltchaDifficulties $difficulties,
        private ClockInterface $clock,
    ) {
    }

    /**
     * La sfida in JSON, pronta per l'attributo `challenge` del widget.
     */
    public function createJson(AltchaPurpose $purpose): string
    {
        $difficulty = $this->difficulties->forPurpose($purpose);

        return new Altcha(hmacSignatureSecret: $this->secret->value)->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2($difficulty->hmacAlgorithm()),
            cost: $difficulty->cost(),
            // Vuoto: la libreria ne genera uno casuale lungo keyPrefixLength byte. Con il valore di
            // default ('00') la lunghezza richiesta verrebbe ignorata.
            keyPrefix: '',
            expiresAt: $this->clock->now()->modify(self::VALIDITY),
            data: ['purpose' => $purpose->value],
            keyPrefixLength: $difficulty->keyPrefixLength(),
        ))->toJson();
    }
}
