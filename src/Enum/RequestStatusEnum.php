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

namespace Vivutio\Sourcing\Enum;

/**
 * Where a request for rooms stands: sent and waiting, confirmed or declined
 * by the camp, or cancelled by the organization.
 */
enum RequestStatusEnum: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Confirmed => 'Confirmed',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
        };
    }
}
