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

namespace Vivutio\Sourcing\Tests\Application\StandIn;

use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Partner\RoomNeed;
use Vivutio\Contracts\Partner\RoomNeedSourceInterface;

/**
 * A package's room needs, played by a stand-in: an invented tour booking's two
 * stays at the first camp traded with.
 */
final readonly class StandInRoomNeeds implements RoomNeedSourceInterface
{
    public function __construct(private PartnerDirectoryInterface $partners)
    {
    }

    public function needs(): iterable
    {
        foreach ($this->partners->active() as $partner) {
            if ('accommodation' !== $partner->getPartnerKind()) {
                continue;
            }
            yield new RoomNeed('stand_in:1', $partner->getPartnerId(), 'NC-0001 · Day 1', 'Hansen family', 4, new \DateTimeImmutable('2027-08-02'), 1, 'Northern Circuit', 'Two children, 9 and 12.');
            yield new RoomNeed('stand_in:3', $partner->getPartnerId(), 'NC-0001 · Days 3–4', 'Hansen family', 3, new \DateTimeImmutable('2027-08-04'), 2, 'Northern Circuit', '');

            return;
        }
    }
}
