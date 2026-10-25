<?php

declare(strict_types=1);

namespace App\Service\Altcha;

use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class AltchaSolvedValidator extends ConstraintValidator
{
    public function __construct(private readonly AltchaVerifierInterface $verifier)
    {
    }

    #[Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AltchaSolved) {
            throw new UnexpectedTypeException($constraint, AltchaSolved::class);
        }

        if (!$this->verifier->verify(\is_string($value) ? $value : null, $constraint->purpose)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
