<?php

namespace Fixture\Symfony\Controller;

use Fixture\Symfony\Message\PlaceOrder;

final class OrderController
{
    public function __construct(private readonly object $bus)
    {
    }

    public function place(): void
    {
        $this->bus->dispatch(new PlaceOrder());
    }
}
