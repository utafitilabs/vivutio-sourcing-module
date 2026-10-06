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

namespace Vivutio\Sourcing\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Vivutio\Bundle\IdentityBundle\Test\AuthorityTestCase;
use Vivutio\Bundle\IdentityBundle\Test\Probe;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Sourcing\Controller\LodgeRateController;
use Vivutio\Sourcing\Controller\RoomRequestController;
use Vivutio\Sourcing\Entity\RoomRequest;
use Vivutio\Sourcing\Tests\Application\Kernel;

/**
 * The module held to the core's five proofs, through the base every module's
 * suite extends. Record a reviewed change to the table with:
 *
 *     VIVUTIO_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit --filter SourcingAuthorityTest
 */
final class SourcingAuthorityTest extends AuthorityTestCase
{
    private const string CAMP_UUID = '0199b1c0-0000-7000-8000-00000000c001';
    private const string REQUEST_UUID = '0199b1c0-0000-7000-8000-00000000c002';
    private const string REQUEST = '/sourcing/'.self::REQUEST_UUID;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function probes(): array
    {
        return [
            new Probe(RoomRequestController::REGISTER, 'GET', '/sourcing'),
            new Probe(LodgeRateController::REGISTER, 'GET', '/sourcing/rates'),
            new Probe(LodgeRateController::LODGE, 'GET', '/sourcing/rates/'.self::CAMP_UUID),
            new Probe(LodgeRateController::LODGE, 'POST', '/sourcing/rates/'.self::CAMP_UUID, ['currency' => 'USD', 'board' => 'Full board', 'periods' => [['from' => '2099-01-01', 'to' => '2099-03-31', 'each' => '150']]], formAt: '/sourcing/rates/'.self::CAMP_UUID),
            new Probe(RoomRequestController::NEW, 'GET', '/sourcing/new'),
            new Probe(RoomRequestController::SHOW, 'GET', self::REQUEST),
            // The first allowed records the reply; the next finds it recorded already.
            new Probe(RoomRequestController::REPLY, 'POST', self::REQUEST.'/reply', ['reply' => 'confirmed', 'their_reference' => 'PROBE-1', 'note' => ''], formAt: self::REQUEST),
            new Probe(RoomRequestController::CANCEL, 'POST', self::REQUEST.'/cancel', ['reason' => 'Probed'], formAt: self::REQUEST),
            new Probe(RoomRequestController::NEW, 'POST', '/sourcing/new', ['partner' => self::CAMP_UUID, 'party' => 'Probed party', 'arrival' => '2099-11-02', 'nights' => '2', 'lines' => [['rooms' => '1', 'room' => 'Double']], 'notes' => '', 'our_reference' => ''], formAt: '/sourcing/new'),
        ];
    }

    protected static function packageDirectory(): string
    {
        return \dirname(__DIR__);
    }

    protected static function authorityTable(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected function seedSubjects(EntityManagerInterface $entityManager): void
    {
        $entityManager->persist((new Partner('Probed camp', PartnerKindEnum::Accommodation, 'TZ', 'stay@probed-camp.example'))->setUuid(Uuid::fromString(self::CAMP_UUID)));
        $entityManager->persist((new RoomRequest('RQ-0001', self::CAMP_UUID, 'Probed party', new \DateTimeImmutable('2099-11-02'), 2, [['rooms' => 1, 'room' => 'Double']]))->setUuid(Uuid::fromString(self::REQUEST_UUID)));
    }
}
