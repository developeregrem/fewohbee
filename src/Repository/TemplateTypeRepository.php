<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityRepository;

class TemplateTypeRepository extends EntityRepository
{
    public function findAll(): array
    {
        return $this->findBy([], [
            'name' => 'ASC',
        ]);
    }
}
