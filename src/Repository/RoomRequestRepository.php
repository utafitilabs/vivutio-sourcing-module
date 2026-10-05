<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio sourcing module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\Sourcing\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Vivutio\Sourcing\Entity\RoomRequest;

/**
 * @extends ServiceEntityRepository<RoomRequest>
 */
class RoomRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomRequest::class);
    }
}
