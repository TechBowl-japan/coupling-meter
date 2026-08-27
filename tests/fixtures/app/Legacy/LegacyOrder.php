<?php

namespace Fixture\Legacy;

use Illuminate\Database\Eloquent\Model;

// Domain\Order と同じテーブルを指す双子モデル
final class LegacyOrder extends Model
{
    protected $table = 'orders';
}
