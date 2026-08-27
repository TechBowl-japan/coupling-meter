<?php

namespace Fixture\Support;

use Fixture\Http\UserController;

// Support -> Http を model で参照。Http -> Support は trait なので、双方向とも model 以上
final class Reporter
{
    public function controller(): ?UserController
    {
        return null;
    }
}
