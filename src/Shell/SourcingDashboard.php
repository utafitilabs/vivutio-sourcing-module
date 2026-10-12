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

use Psr\Clock\ClockInterface;
use Vivutio\Contracts\Shell\DashboardCard;
use Vivutio\Contracts\Shell\DashboardRow;
use Vivutio\Contracts\Shell\DashboardSourceInterface;
use Vivutio\Sourcing\Controller\RoomRequestController;
use Vivutio\Sourcing\Entity\RoomRequest;
use Vivutio\Sourcing\Enum\RequestStatusEnum;
use Vivutio\Sourcing\Repository\RoomRequestRepository;
use Vivutio\Sourcing\Service\RoomRequestService;

/**
 * On the organization's dashboard, the requests sent and not yet answered,
 * the longest waiting first, with how long each has waited: from three days
 * on, it is time to chase the camp.
 */
final readonly class SourcingDashboard implements DashboardSourceInterface
{
    public const int CHASE_FROM_DAYS = 3;

    public function __construct(
        private RoomRequestRepository $requests,
        private RoomRequestService $service,
        private ClockInterface $clock,
    ) {
    }

    public function figures(): iterable
    {
        return [];
    }

    public function cards(): iterable
    {
        $today = $this->clock->now()->setTime(0, 0);

        yield new DashboardCard(
            title: 'Waiting on a reply',
            kicker: 'Room requests',
            caption: 'sent, no reply yet',
            rows: array_map(fn (RoomRequest $request): DashboardRow => $this->row($request, $today), $this->requests->waiting(DashboardCard::MOST_ROWS)),
            total: $this->requests->countWaiting(),
            empty: 'Nothing waits on a reply',
            door: 'All room requests',
            route: RoomRequestController::REGISTER,
            attribute: RoomRequestController::READ,
            routeParameters: ['status' => RequestStatusEnum::Requested->value],
            order: 30,
        );
    }

    private function row(RoomRequest $request, \DateTimeImmutable $today): DashboardRow
    {
        $first = $request->getHistory()[0] ?? null;
        $sent = (null === $first ? $today : new \DateTimeImmutable($first['at']))->setTimezone($today->getTimezone())->setTime(0, 0);
        $days = (int) $sent->diff($today)->days;
        $for = '' === $request->getOurReference() ? $request->getParty() : $request->getOurReference();

        return new DashboardRow(
            title: $request->getReference(),
            detail: $this->service->campName($request->getPartnerId()),
            cells: ['for '.$for, 'sent '.$sent->format($sent->format('Y') === $today->format('Y') ? 'D j M' : 'D j M Y')],
            chip: match ($days) {
                0 => 'today',
                1 => '1 day',
                default => $days.' days',
            },
            tone: $days >= self::CHASE_FROM_DAYS ? 'warn' : '',
        );
    }
}
