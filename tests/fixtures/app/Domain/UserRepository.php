<?php

namespace Fixture\Domain;

interface UserRepository
{
    public function find(int $id): ?User;
}
