<?php

namespace Fixture\Domain;

final class User
{
    public function __construct(public readonly string $name)
    {
    }
}
