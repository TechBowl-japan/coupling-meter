<?php

namespace Fixture\Domain;

final class UserFactory
{
    public function create(string $name): User
    {
        return new User($name);
    }
}
