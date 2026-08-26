<?php

namespace Fixture\Http;

use Fixture\Domain\User;
use Fixture\Domain\UserFactory;
use Fixture\Domain\UserRepository;
use Fixture\Support\Audit;
use Fixture\Support\Track;

#[Track]
final class UserController
{
    use Audit;

    public function __construct(private readonly UserRepository $users)
    {
    }

    public function show(int $id): ?User
    {
        return $this->users->find($id);
    }

    public function store(string $name): User
    {
        $factory = app(UserFactory::class);

        return $factory->create($name);
    }

    public function legacyClassName(): string
    {
        return 'Fixture\Domain\LegacyUser';
    }
}
