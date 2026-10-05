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

namespace Vivutio\Sourcing\Shell;

use Vivutio\Contracts\Shell\MenuEntry;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Sourcing\Controller\RoomRequestController;

/**
 * Room requests in the menu, with lucide's bed.
 */
final readonly class SourcingMenu implements MenuSourceInterface
{
    public function entries(): iterable
    {
        yield new MenuEntry(
            RoomRequestController::REGISTER,
            'Room requests',
            RoomRequestController::READ,
            '<path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/>',
            'room_requests',
        );
    }
}
