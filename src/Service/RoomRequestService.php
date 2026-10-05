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

namespace Vivutio\Sourcing\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Vivutio\Contracts\Partner\PartnerChannelInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Partner\PartnerInterface;
use Vivutio\Contracts\Partner\PartnerMessage;
use Vivutio\Sourcing\Entity\RoomRequest;
use Vivutio\Sourcing\Enum\RequestStatusEnum;
use Vivutio\Sourcing\Exception\InvalidRequestException;
use Vivutio\Sourcing\Repository\RoomRequestRepository;

/**
 * Rooms requested from accommodation partners. A request is sent through the
 * core's partner channel the moment it is made; the camp's reply, confirmed
 * with its reference or declined, is recorded by hand; a request still open
 * is cancelled with a message saying so. Every step is kept on the request.
 */
final readonly class RoomRequestService
{
    public const int MOST_LINES = 4;
    public const int LONGEST_STAY = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private RoomRequestRepository $requests,
        private PartnerDirectoryInterface $partners,
        private PartnerChannelInterface $channel,
    ) {
    }

    /**
     * Partners a request can go to: accommodation traded with now.
     *
     * @return list<PartnerInterface>
     */
    public function camps(): array
    {
        return array_values(array_filter($this->partners->active(), static fn (PartnerInterface $partner): bool => 'accommodation' === $partner->getPartnerKind()));
    }

    public function partnerOf(RoomRequest $request): ?PartnerInterface
    {
        return $this->partners->find($request->getPartnerId());
    }

    /**
     * @param array<mixed> $sent the form
     *
     * @throws InvalidRequestException
     */
    public function send(array $sent, string $by): RoomRequest
    {
        $text = static fn (string $key): string => \is_string($sent[$key] ?? null) ? trim($sent[$key]) : '';
        $partner = '' === $text('partner') ? null : $this->partners->find($text('partner'));
        if (null === $partner || !$partner->isActive() || 'accommodation' !== $partner->getPartnerKind()) {
            throw new InvalidRequestException('partner', 'Choose a camp or lodge you trade with now.');
        }
        $party = $text('party');
        if ('' === $party || mb_strlen($party) > 120) {
            throw new InvalidRequestException('party', 'Say who the rooms are for: the Mollel party.');
        }
        $arrival = 1 === preg_match('{^\d{4}-\d{2}-\d{2}$}D', $text('arrival')) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $text('arrival')) : false;
        if (false === $arrival || $arrival->format('Y-m-d') !== $text('arrival') || $arrival < $this->clock->now()->setTime(0, 0)) {
            throw new InvalidRequestException('arrival', 'Rooms are requested from today on: a day of the calendar.');
        }
        $nights = $text('nights');
        if (!ctype_digit($nights) || (int) $nights < 1 || (int) $nights > self::LONGEST_STAY) {
            throw new InvalidRequestException('nights', \sprintf('A stay is from 1 to %d nights.', self::LONGEST_STAY));
        }

        $lines = [];
        $typed = \is_array($sent['lines'] ?? null) ? array_values($sent['lines']) : [];
        for ($i = 0; $i < self::MOST_LINES; ++$i) {
            $line = \is_array($typed[$i] ?? null) ? $typed[$i] : [];
            $rooms = \is_string($line['rooms'] ?? null) ? trim($line['rooms']) : '';
            $room = \is_string($line['room'] ?? null) ? trim($line['room']) : '';
            if ('' === $room) {
                if (0 === $i) {
                    throw new InvalidRequestException('lines[0][room]', 'Say which rooms: Double, Family tent.');
                }
                continue;
            }
            if (!ctype_digit($rooms) || (int) $rooms < 1 || (int) $rooms > 40) {
                throw new InvalidRequestException(\sprintf('lines[%d][rooms]', $i), 'Ask for 1 to 40 rooms.');
            }
            if (mb_strlen($room) > 60) {
                throw new InvalidRequestException(\sprintf('lines[%d][room]', $i), 'Say which rooms: Double, Family tent.');
            }
            $lines[] = ['rooms' => (int) $rooms, 'room' => $room];
        }
        $notes = $text('notes');
        if (mb_strlen($notes) > 2000) {
            throw new InvalidRequestException('notes', 'Notes can be at most 2,000 characters.');
        }

        $request = (new RoomRequest($this->reference(), $partner->getPartnerId(), $party, $arrival, (int) $nights, $lines))
            ->setNotes($notes)
            ->setOurReference(mb_substr($text('our_reference'), 0, 120));
        $said = $this->channel->send($partner, new PartnerMessage(
            $request->getReference(),
            \sprintf('Rooms for the %s, %s', $party, self::dates($request)),
            self::body($request),
        ));
        $request->record($this->clock->now(), $by, $said);
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        return $request;
    }

    /**
     * @throws InvalidRequestException
     */
    public function reply(RoomRequest $request, string $reply, string $theirReference, string $note, string $by): void
    {
        if (RequestStatusEnum::Requested !== $request->getStatus()) {
            throw new InvalidRequestException('reply', \sprintf('%s is %s: its reply is recorded already.', $request->getReference(), mb_strtolower($request->getStatus()->label())));
        }
        $theirReference = trim($theirReference);
        $note = trim($note);
        if (mb_strlen($theirReference) > 60 || mb_strlen($note) > 200) {
            throw new InvalidRequestException('note', 'A reference is at most 60 characters, a note 200.');
        }
        $status = match ($reply) {
            'confirmed' => RequestStatusEnum::Confirmed,
            'declined' => RequestStatusEnum::Declined,
            default => throw new InvalidRequestException('reply', 'Say whether the camp confirmed or declined.'),
        };
        if (RequestStatusEnum::Confirmed === $status && '' === $theirReference) {
            throw new InvalidRequestException('their_reference', 'A confirmation comes with the camp\'s reference: keep it here.');
        }

        $request->setStatus($status)->setTheirReference($theirReference);
        $said = array_filter([$theirReference, $note], static fn (string $part): bool => '' !== $part);
        $request->record($this->clock->now(), $by, $status->label().([] === $said ? '' : ': '.implode(' · ', $said)));
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidRequestException
     */
    public function cancel(RoomRequest $request, string $reason, string $by): void
    {
        if (!self::cancellable($request)) {
            throw new InvalidRequestException('reason', \sprintf('%s is %s: there is nothing to cancel.', $request->getReference(), mb_strtolower($request->getStatus()->label())));
        }
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > 200) {
            throw new InvalidRequestException('reason', 'Say why it is cancelled.');
        }
        $partner = $this->partners->find($request->getPartnerId()) ?? throw new InvalidRequestException('reason', 'The camp is no longer kept as a partner: tell them yourself.');

        $said = $this->channel->send($partner, new PartnerMessage(
            $request->getReference(),
            \sprintf('Cancelled: rooms for the %s, %s', $request->getParty(), self::dates($request)),
            \sprintf("Please cancel our request %s%s.\n\n%s", $request->getReference(), '' === $request->getTheirReference() ? '' : ', your reference '.$request->getTheirReference(), $reason),
        ));
        $request->setStatus(RequestStatusEnum::Cancelled)->record($this->clock->now(), $by, 'Cancelled: '.$reason.' · '.$said);
        $this->entityManager->flush();
    }

    /** "2 to 4 Nov 2026", "30 Nov to 2 Dec 2026", "30 Dec 2026 to 2 Jan 2027". */
    public static function dates(RoomRequest $request): string
    {
        $from = $request->getArrival();
        $to = $request->getDeparture();

        return match (true) {
            $from->format('Y-m') === $to->format('Y-m') => $from->format('j').' to '.$to->format('j M Y'),
            $from->format('Y') === $to->format('Y') => $from->format('j M').' to '.$to->format('j M Y'),
            default => $from->format('j M Y').' to '.$to->format('j M Y'),
        };
    }

    /** Whether rooms are still asked for or held: a request waiting, or confirmed. */
    public static function cancellable(RoomRequest $request): bool
    {
        return \in_array($request->getStatus(), [RequestStatusEnum::Requested, RequestStatusEnum::Confirmed], true);
    }

    private static function body(RoomRequest $request): string
    {
        $lines = [];
        foreach ($request->getLines() as $line) {
            $lines[] = \sprintf('%d × %s, for %d %s from %s to %s', $line['rooms'], $line['room'], $request->getNights(), 1 === $request->getNights() ? 'night' : 'nights', $request->getArrival()->format('j M Y'), $request->getDeparture()->format('j M Y'));
        }

        return \sprintf("Could you hold these rooms for the %s?\n\n%s%s\n\nPlease confirm with your reference, or tell us if you cannot.", $request->getParty(), implode("\n", $lines), '' === $request->getNotes() ? '' : "\n\n".$request->getNotes());
    }

    private function reference(): string
    {
        return \sprintf('RQ-%04d', $this->requests->count([]) + 1);
    }
}
