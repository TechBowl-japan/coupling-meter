<?php

namespace Fixture\Symfony\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dtb_product')]
class Product
{
    public int $id = 0;
}
