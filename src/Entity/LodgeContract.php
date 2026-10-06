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
use Vivutio\Sourcing\Repository\LodgeContractRepository;

/**
 * What an accommodation partner charges the organization: a currency, the
 * board the rates are for, and a price a person sharing a night, in cents,
 * by dated period, each from its first night to its last.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: LodgeContractRepository::class)]
#[ORM\Table(name: 'sourcing_lodge_contract')]
class LodgeContract
{
    public const int BOARD_MAX_LENGTH = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    /** The partner, by the id the core gave it. */
    #[ORM\Column(length: 36, unique: true)]
    private string $partnerId;

    #[ORM\Column(length: 3)]
    private string $currency = '';

    #[ORM\Column(length: self::BOARD_MAX_LENGTH)]
    private string $board = '';

    /** @var list<array{from: string, to: string, each: int}> dates as "Y-m-d", each in cents */
    #[ORM\Column(type: Types::JSON)]
    private array $periods = [];

    public function __construct(string $partnerId)
    {
        $this->partnerId = $partnerId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartnerId(): string
    {
        return $this->partnerId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBoard(): string
    {
        return $this->board;
    }

    /**
     * @return list<array{from: string, to: string, each: int}>
     */
    public function getPeriods(): array
    {
        return $this->periods;
    }

    /**
     * @param list<array{from: string, to: string, each: int}> $periods
     */
    public function setTerms(string $currency, string $board, array $periods): static
    {
        $this->currency = $currency;
        $this->board = $board;
        $this->periods = $periods;

        return $this;
    }
}
