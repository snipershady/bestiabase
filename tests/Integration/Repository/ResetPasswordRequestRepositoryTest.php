<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use stdClass;

#[CoversClass(ResetPasswordRequestRepository::class)]
#[CoversClass(ResetPasswordRequest::class)]
#[UsesClass(User::class)]
final class ResetPasswordRequestRepositoryTest extends DatabaseTestCase
{
    public function testTheMostRecentRequestIsReportedOnlyWhileItIsValid(): void
    {
        $repository = self::getContainer()->get(ResetPasswordRequestRepository::class);
        $user = EntityFactory::user();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $beforeAnyRequest = $repository->getMostRecentNonExpiredRequestDate($user);
        self::assertNull($beforeAnyRequest, 'no request yet');

        $valid = $repository->createResetPasswordRequest($user, new DateTimeImmutable('+1 hour'), 'selector-valid-1234', 'hash-1');
        $this->entityManager->persist($valid);
        $this->entityManager->flush();

        $withValidRequest = $repository->getMostRecentNonExpiredRequestDate($user);
        self::assertInstanceOf(DateTimeImmutable::class, $withValidRequest);

        $repository->removeRequests($user);
        $expired = $repository->createResetPasswordRequest($user, new DateTimeImmutable('-1 hour'), 'selector-expired-12', 'hash-2');
        $this->entityManager->persist($expired);
        $this->entityManager->flush();

        $withExpiredRequest = $repository->getMostRecentNonExpiredRequestDate($user);
        self::assertNull($withExpiredRequest, 'the only request has expired');
    }

    public function testOnlyUsersCanOwnARequest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::getContainer()->get(ResetPasswordRequestRepository::class)->createResetPasswordRequest(new stdClass(), new DateTimeImmutable('+1 hour'), 'selector', 'hash');
    }
}
