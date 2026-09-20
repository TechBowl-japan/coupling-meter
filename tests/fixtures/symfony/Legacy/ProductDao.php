<?php

namespace Fixture\Symfony\Legacy;

final class ProductDao
{
    public function names(): array
    {
        return $this->query('SELECT name FROM dtb_product WHERE del_flg = 0');
    }

    private function query(string $sql): array
    {
        return [];
    }
}
