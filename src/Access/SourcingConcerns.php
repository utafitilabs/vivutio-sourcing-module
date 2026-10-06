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

namespace Vivutio\Sourcing\Access;

use Vivutio\Contracts\Access\Concern;
use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Access\Scope;
use Vivutio\Contracts\Access\Verb;

/**
 * What a position may grant about rooms requested from partners: reading the
 * requests, sending one, and recording replies and cancelling.
 */
final readonly class SourcingConcerns implements ConcernSourceInterface
{
    public const string ROOM_REQUESTS = 'room_requests';
    public const string LODGE_RATES = 'lodge_rates';

    public function declaredBy(): string
    {
        return 'Sourcing';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::ROOM_REQUESTS,
            label: 'Room requests',
            description: 'Rooms requested from the camps and lodges you trade with: reading them, sending one, and recording replies and cancelling.',
            verbs: [Verb::Read, Verb::Record, Verb::Manage],
            scopes: [Scope::ORGANIZATION],
            moduleSlug: 'sourcing',
        );

        yield new Concern(
            key: self::LODGE_RATES,
            label: 'Lodge rates',
            description: 'What the camps and lodges you trade with charge you: reading the rates, and keeping them.',
            verbs: [Verb::Read, Verb::Manage],
            scopes: [Scope::ORGANIZATION],
            moduleSlug: 'sourcing',
        );
    }
}
