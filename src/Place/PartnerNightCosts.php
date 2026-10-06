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

namespace Vivutio\Sourcing\Place;

use Vivutio\Contracts\Stay\NightCost;
use Vivutio\Contracts\Stay\NightCostSourceInterface;
use Vivutio\Sourcing\Service\LodgeRateService;

/**
 * What a night at a partner's lodge costs a person sharing: the rate of the
 * period the night is in, on the board agreed with the lodge.
 */
final readonly class PartnerNightCosts implements NightCostSourceInterface
{
    /** The kind a module keeps a partner's lodge as, the core partners' word. */
    public const string KIND = 'partner';

    public function __construct(private LodgeRateService $rates)
    {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function cost(string $id, \DateTimeImmutable $night): ?NightCost
    {
        $contract = $this->rates->contractOf($id);
        $period = null === $contract ? null : LodgeRateService::periodOn($contract, $night);

        return null === $contract || null === $period ? null : new NightCost($contract->getCurrency(), $period['each'], $contract->getBoard().', sharing');
    }
}
