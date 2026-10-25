<?php

declare(strict_types=1);

namespace App\Service\Account;

use EmailValidator\Enum\EmailError;
use EmailValidator\Service\EmailValidator;
use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class DeliverableEmailValidator extends ConstraintValidator
{
    public function __construct(private readonly EmailValidator $emailValidator)
    {
    }

    #[Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DeliverableEmail) {
            throw new UnexpectedTypeException($constraint, DeliverableEmail::class);
        }

        // I valori vuoti li segnala NotBlank.
        if ($value === null || $value === '') {
            return;
        }

        $result = $this->emailValidator->validate($value);
        $error = $result->getError();

        // Un errore DNS transitorio non prova che l'indirizzo non esista: lo accertano il link di conferma.
        if ($result->isValid() || $error === EmailError::DNS_FAILURE) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ reason }}', $error instanceof EmailError ? $error->value : 'invalid')
            ->addViolation();
    }
}
