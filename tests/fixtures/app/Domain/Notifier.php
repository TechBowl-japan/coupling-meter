<?php

namespace Fixture\Domain;

use Fixture\Http\NotificationChannel;

// Domain -> Http だが interface 経由（contract）。DIP で逆転済みの依存
final class Notifier
{
    public function __construct(private readonly NotificationChannel $channel)
    {
    }

    public function notify(User $user): void
    {
        $this->channel->send($user->name);
    }
}
