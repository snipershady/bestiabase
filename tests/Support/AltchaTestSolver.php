<?php

declare(strict_types=1);

namespace App\Tests\Support;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaDifficulties;
use App\Service\Altcha\AltchaPurpose;
use JsonException;
use LogicException;

/**
 * Fa il lavoro del widget ALTCHA nei test: risolve davvero la sfida (a difficoltà "low" nei test)
 * e restituisce il payload che il browser metterebbe nel campo `altcha`.
 */
final readonly class AltchaTestSolver
{
    public function __construct(
        private AltchaChallengeFactory $challenges,
        private AltchaDifficulties $difficulties,
    ) {
    }

    /**
     * La sfida emessa dal server, letta come la legge il widget.
     *
     * @throws JsonException
     */
    public static function challenge(string $json): Challenge
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new LogicException('The challenge is not a JSON object.');
        }

        return Challenge::fromArray(array_filter($data, \is_string(...), \ARRAY_FILTER_USE_KEY));
    }

    /**
     * @throws JsonException
     */
    public function payload(AltchaPurpose $purpose): string
    {
        $challenge = self::challenge($this->challenges->createJson($purpose));
        $algorithm = new Pbkdf2($this->difficulties->forPurpose($purpose)->hmacAlgorithm());

        $solution = new Altcha()->solveChallenge(new SolveChallengeOptions($challenge, $algorithm));

        return new Payload($challenge, $solution ?? throw new LogicException('The challenge was not solved in time.'))->toBase64();
    }
}
