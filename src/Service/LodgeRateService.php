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
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Partner\PartnerInterface;
use Vivutio\Sourcing\Entity\LodgeContract;
use Vivutio\Sourcing\Exception\InvalidRequestException;
use Vivutio\Sourcing\Repository\LodgeContractRepository;

/**
 * What the lodges traded with charge: kept once a lodge, a currency, a board
 * and a price a person sharing a night by dated period; periods never
 * overlap, so a night has one price or none.
 */
final readonly class LodgeRateService
{
    public const string ACCOMMODATION = 'accommodation';
    public const int LONGEST_PERIOD_DAYS = 366;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LodgeContractRepository $contracts,
        private PartnerDirectoryInterface $partners,
    ) {
    }

    /**
     * The accommodation partners traded with now, by name.
     *
     * @return list<PartnerInterface>
     */
    public function lodges(): array
    {
        return array_values(array_filter($this->partners->active(), static fn (PartnerInterface $partner): bool => self::ACCOMMODATION === $partner->getPartnerKind()));
    }

    /** A lodge traded with now, by its partner id; null for anything else. */
    public function lodge(string $partnerId): ?PartnerInterface
    {
        $partner = $this->partners->find($partnerId);

        return null !== $partner && $partner->isActive() && self::ACCOMMODATION === $partner->getPartnerKind() ? $partner : null;
    }

    public function contractOf(string $partnerId): ?LodgeContract
    {
        return $this->contracts->findOneBy(['partnerId' => $partnerId]);
    }

    /**
     * @param array<mixed> $sent currency, board, periods[i][from|to|each]
     *
     * @throws InvalidRequestException
     */
    public function save(PartnerInterface $lodge, array $sent): void
    {
        $text = static fn (mixed $value): string => \is_string($value) ? trim($value) : '';
        $currency = strtoupper($text($sent['currency'] ?? null));
        if (1 !== preg_match('{^[A-Z]{3}$}D', $currency)) {
            throw new InvalidRequestException('currency', 'A currency is its three-letter code: USD, TZS.');
        }
        $board = $text($sent['board'] ?? null);

        $periods = [];
        $typed = \is_array($sent['periods'] ?? null) ? array_values($sent['periods']) : [];
        foreach ($typed as $i => $row) {
            $row = \is_array($row) ? $row : [];
            [$fromTyped, $toTyped, $eachTyped] = [$text($row['from'] ?? null), $text($row['to'] ?? null), str_replace(',', '', $text($row['each'] ?? null))];
            if ('' === $fromTyped && '' === $toTyped && '' === $eachTyped) {
                continue;
            }
            $from = self::day($fromTyped) ?? throw new InvalidRequestException(\sprintf('periods[%d][from]', $i), 'The first night: 2027-01-01.');
            $to = self::day($toTyped);
            if (null === $to || $to < $from || $from->diff($to)->days >= self::LONGEST_PERIOD_DAYS) {
                throw new InvalidRequestException(\sprintf('periods[%d][to]', $i), 'The last night, on or after the first and within a year of it.');
            }
            if (1 !== preg_match('{^\d{1,7}(\.\d{1,2})?$}D', $eachTyped)) {
                throw new InvalidRequestException(\sprintf('periods[%d][each]', $i), 'A price a person sharing, to the cent: 150.00.');
            }
            foreach ($periods as $kept) {
                if ($fromTyped <= $kept['to'] && $toTyped >= $kept['from']) {
                    throw new InvalidRequestException(\sprintf('periods[%d][from]', $i), \sprintf('It overlaps %s – %s.', self::spoken($kept['from']), self::spoken($kept['to'])));
                }
            }
            $periods[] = ['from' => $fromTyped, 'to' => $toTyped, 'each' => (int) round((float) $eachTyped * 100)];
        }
        if ([] !== $periods && ('' === $board || mb_strlen($board) > LodgeContract::BOARD_MAX_LENGTH)) {
            throw new InvalidRequestException('board', \sprintf('The board the rates are for, up to %d characters: Full board.', LodgeContract::BOARD_MAX_LENGTH));
        }
        usort($periods, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        $contract = $this->contractOf($lodge->getPartnerId());
        if (null === $contract) {
            $contract = new LodgeContract($lodge->getPartnerId());
            $this->entityManager->persist($contract);
        }
        $contract->setTerms($currency, $board, $periods);
        $this->entityManager->flush();
    }

    /**
     * The period a night is in, or null.
     *
     * @return array{from: string, to: string, each: int}|null
     */
    public static function periodOn(LodgeContract $contract, \DateTimeImmutable $night): ?array
    {
        $day = $night->format('Y-m-d');
        foreach ($contract->getPeriods() as $period) {
            if ($day >= $period['from'] && $day <= $period['to']) {
                return $period;
            }
        }

        return null;
    }

    /** "2027-03-31" as "31 Mar 2027". */
    public static function spoken(string $day): string
    {
        return (new \DateTimeImmutable($day))->format('j M Y');
    }

    private static function day(string $typed): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $typed);

        return false === $day || $day->format('Y-m-d') !== $typed ? null : $day;
    }
}
