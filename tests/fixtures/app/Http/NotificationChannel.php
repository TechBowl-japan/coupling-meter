<?php

namespace Fixture\Http;

interface NotificationChannel
{
    public function send(string $message): void;
}
