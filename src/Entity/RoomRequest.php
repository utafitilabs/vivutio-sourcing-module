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

namespace Vivutio\Sourcing\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Vivutio\Sourcing\Enum\RequestStatusEnum;
use Vivutio\Sourcing\Repository\RoomRequestRepository;

/**
 * Rooms requested from an accommodation partner: for whom, when, which rooms,
 * the references both sides quote, where it stands, and each step taken.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: RoomRequestRepository::class)]
#[ORM\Table(name: 'sourcing_room_request')]
class RoomRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /** "RQ-0001", quoted in every message. */
    #[ORM\Column(length: 16, unique: true)]
    private string $reference;

    /** The accommodation partner asked, by the id the core's partners are known by. */
    #[ORM\Column(length: 36)]
    private string $partnerId;

    #[ORM\Column(length: 120)]
    private string $party;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $arrival;

    #[ORM\Column]
    private int $nights;

    /** @var list<array{rooms: int, room: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $lines;

    #[ORM\Column(type: Types::TEXT)]
    private string $notes = '';

    /** What it is for here: a tour booking, a group. */
    #[ORM\Column(length: 120)]
    private string $ourReference = '';

    /** The key of the need it answers ("tour_booking:01a…:3"), when it was made from one. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $need = null;

    #[ORM\Column(length: 60)]
    private string $theirReference = '';

    #[ORM\Column(length: 16, enumType: RequestStatusEnum::class)]
    private RequestStatusEnum $status = RequestStatusEnum::Requested;

    /** @var list<array{at: string, by: string, said: string}> each step, oldest first */
    #[ORM\Column(type: Types::JSON)]
    private array $history = [];

    /**
     * @param list<array{rooms: int, room: string}> $lines
     */
    public function __construct(string $reference, string $partnerId, string $party, \DateTimeImmutable $arrival, int $nights, array $lines)
    {
        $this->uuid = Uuid::v7();
        $this->reference = $reference;
        $this->partnerId = $partnerId;
        $this->party = $party;
        $this->arrival = $arrival;
        $this->nights = $nights;
        $this->lines = $lines;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getPartnerId(): string
    {
        return $this->partnerId;
    }

    public function getParty(): string
    {
        return $this->party;
    }

    public function getArrival(): \DateTimeImmutable
    {
        return $this->arrival;
    }

    public function getNights(): int
    {
        return $this->nights;
    }

    public function getDeparture(): \DateTimeImmutable
    {
        return $this->arrival->modify(\sprintf('+%d days', $this->nights));
    }

    /**
     * @return list<array{rooms: int, room: string}>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function setNotes(string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getOurReference(): string
    {
        return $this->ourReference;
    }

    /** The need it answers, by its key, when it was made from one. */
    public function getNeed(): ?string
    {
        return $this->need;
    }

    public function setNeed(?string $need): static
    {
        $this->need = $need;

        return $this;
    }

    public function setOurReference(string $ourReference): static
    {
        $this->ourReference = $ourReference;

        return $this;
    }

    public function getTheirReference(): string
    {
        return $this->theirReference;
    }

    public function setTheirReference(string $theirReference): static
    {
        $this->theirReference = $theirReference;

        return $this;
    }

    public function getStatus(): RequestStatusEnum
    {
        return $this->status;
    }

    public function setStatus(RequestStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return list<array{at: string, by: string, said: string}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function record(\DateTimeImmutable $at, string $by, string $said): static
    {
        $this->history[] = ['at' => $at->format(\DATE_ATOM), 'by' => $by, 'said' => $said];

        return $this;
    }
}
