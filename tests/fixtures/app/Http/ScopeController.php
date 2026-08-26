<?php

namespace Fixture\Http;

use Fixture\Domain\User;
use Fixture\Domain\UserFactory;
use Fixture\Domain\UserRepository;

final class ScopeController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function afterAnonymousClass(): User
    {
        $listener = new class {
            public function handle(): void
            {
            }
        };

        return new User('after-anonymous');
    }

    public function afterClosure(UserFactory $factory): ?User
    {
        $callback = static fn (int $id): int => $id;
        $another = static function (): void {
        };

        return $this->users->find(1) ?? $factory->create('after-closure');
    }
}

function scope_helper(): void
{
    new \Fixture\Domain\LegacyUser();
}
