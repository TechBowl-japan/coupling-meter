<?php

namespace Fixture\Symfony\Entity;

/**
 * 属性より前の書き方。docblock の注釈でテーブルを宣言する。
 *
 * @ORM\Entity
 * @ORM\Table(name="dtb_customer")
 */
class Customer
{
    public int $id = 0;
}
