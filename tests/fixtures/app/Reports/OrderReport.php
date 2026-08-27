<?php

namespace Fixture\Reports;

use Illuminate\Support\Facades\DB;

// 生 SQL とクエリビルダで orders を直接触る
final class OrderReport
{
    public function totals(): array
    {
        $rows = DB::select('SELECT customer_id, SUM(total) AS total FROM orders o JOIN order_items i ON i.order_id = o.id GROUP BY customer_id');

        return DB::table('order_items')->count() > 0 ? $rows : [];
    }
}
