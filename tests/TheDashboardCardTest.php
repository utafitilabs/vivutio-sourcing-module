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

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DomCrawler\Crawler;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Sourcing\Entity\RoomRequest;
use Vivutio\Sourcing\Enum\RequestStatusEnum;

/**
 * The organization's dashboard carries the requests sent and not yet
 * answered, the longest waiting first, for whoever may read room requests,
 * as vivutio-designs/shell/dashboard.html draws them.
 */
final class TheDashboardCardTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-10-12 09:30:00');
        $this->browser = static::createClient();
        $this->migrate();
    }

    public function testTheRequestsWaitingOnAReplyLongestFirst(): void
    {
        $mbuyu = $this->camp('Mbuyu Ridge Lodge');
        $kilele = $this->camp('Kilele Camp');
        $this->request('RQ-0011', $mbuyu, '2026-10-06 10:00', for: 'NCS-0006');
        $this->request('RQ-0012', $kilele, '2026-10-08 15:00', for: 'SPS-0003');
        $this->request('RQ-0013', $mbuyu, '2026-10-09 08:00', for: 'MIG-0002');
        $this->request('RQ-0014', $kilele, '2026-10-11 17:45', party: 'Haddad party');
        $this->request('RQ-0015', $mbuyu, '2026-10-12 07:10', for: 'NCS-0008');
        $this->request('RQ-0016', $kilele, '2026-10-12 08:00', for: 'NCS-0009');
        $this->request('RQ-0009', $kilele, '2026-10-01 09:00', status: RequestStatusEnum::Confirmed);
        $this->request('RQ-0008', $mbuyu, '2026-10-02 09:00', status: RequestStatusEnum::Declined);
        $this->request('RQ-0007', $kilele, '2026-10-03 09:00', status: RequestStatusEnum::Cancelled);

        $card = $this->card($this->home($this->person('Baraka', TierEnum::Admin)));

        self::assertSame('Room requests · sent, no reply yet', self::words($card->filter('.panel-head p')));
        self::assertSame([
            'RQ-0011 Mbuyu Ridge Lodge for NCS-0006 sent Tue 6 Oct 6 days',
            'RQ-0012 Kilele Camp for SPS-0003 sent Thu 8 Oct 4 days',
            'RQ-0013 Mbuyu Ridge Lodge for MIG-0002 sent Fri 9 Oct 3 days',
            'RQ-0014 Kilele Camp for Haddad party sent Sun 11 Oct 1 day',
            'RQ-0015 Mbuyu Ridge Lodge for NCS-0008 sent Mon 12 Oct today',
        ], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
        self::assertSame(['chip warn', 'chip warn', 'chip warn', 'chip', 'chip'], $card->filter('.chip')->each(static fn (Crawler $chip): string => (string) $chip->attr('class')));
        self::assertSame('5 of 6', self::words($card->filter('.panel-foot .state')));
        self::assertSame('All room requests', self::words($card->filter('.panel-foot a.more')));
        self::assertSame('/sourcing?status=requested', $card->filter('.panel-foot a.more')->attr('href'));
    }

    public function testWithNothingWaitingTheCardSaysSo(): void
    {
        $this->request('RQ-0001', $this->camp('Kilele Camp'), '2026-10-05 09:00', status: RequestStatusEnum::Confirmed);

        $card = $this->card($this->home($this->person('Baraka', TierEnum::Admin)));

        self::assertCount(0, $card->filter('tbody tr'));
        self::assertSame('Nothing waits on a reply', self::words($card->filter('[data-card-empty]')));
        self::assertSame('', self::words($card->filter('.panel-foot .state')));
    }

    public function testStaffSeeItWhereTheirDepartmentAllowsReadingRequests(): void
    {
        $this->request('RQ-0001', $this->camp('Kilele Camp'), '2026-10-10 09:00', for: 'SPS-0003');
        $reservations = (new Department())->setName('Reservations')->setAllows(['room_requests.read']);
        $this->em()->persist($reservations);

        $card = $this->card($this->home($this->person('Elia', TierEnum::Staff, ['dashboard.read', 'room_requests.read'], $reservations)));
        self::assertSame(['RQ-0001 Kilele Camp for SPS-0003 sent Sat 10 Oct 2 days'], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
    }

    public function testWhoeverMayNotReadRequestsIsNotShownTheCard(): void
    {
        $this->request('RQ-0001', $this->camp('Kilele Camp'), '2026-10-10 09:00', for: 'SPS-0003');
        $elsewhere = (new Department())->setName('Finance')->setAllows([]);
        $this->em()->persist($elsewhere);

        foreach ([
            'no pair' => $this->person('Neema', TierEnum::Staff, ['dashboard.read']),
            'the pair, not allowed by the department' => $this->person('Juma', TierEnum::Staff, ['dashboard.read', 'room_requests.read'], $elsewhere),
        ] as $who => $person) {
            $page = $this->home($person);
            self::assertSame('organization', $page->filter('[data-dashboard]')->attr('data-dashboard'), $who);
            self::assertCount(0, $this->cards($page), $who);
        }
    }

    private function home(User $person): Crawler
    {
        $this->browser->restart();
        $this->browser->loginUser($person);
        $page = $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function cards(Crawler $page): Crawler
    {
        return $page->filter('[data-cards] .panel')->reduce(static fn (Crawler $panel): bool => 'Waiting on a reply' === self::words($panel->filter('.panel-head h2')));
    }

    private function card(Crawler $page): Crawler
    {
        $card = $this->cards($page);
        self::assertCount(1, $card);

        return $card;
    }

    private function camp(string $name): Partner
    {
        $camp = new Partner($name, PartnerKindEnum::Accommodation, 'TZ', 'stay@'.strtolower(str_replace(' ', '-', $name)).'.example');
        $this->em()->persist($camp);
        $this->em()->flush();

        return $camp;
    }

    private function request(string $reference, Partner $camp, string $sent, string $for = '', string $party = 'Mollel party', RequestStatusEnum $status = RequestStatusEnum::Requested): void
    {
        $request = (new RoomRequest($reference, $camp->getPartnerId(), $party, new \DateTimeImmutable('2026-11-02'), 2, [['rooms' => 2, 'room' => 'Double']]))
            ->setOurReference($for)
            ->record(new \DateTimeImmutable($sent), 'Baraka Kimaro', 'Sent by email to '.$camp->getEmail())
            ->setStatus($status);
        $this->em()->persist($request);
        $this->em()->flush();
    }

    /** The words of a node, each element's own apart, as a reader sees them. */
    private static function words(Crawler $node): string
    {
        $words = [];
        foreach ($node as $element) {
            foreach ((new \DOMXPath($element->ownerDocument ?? new \DOMDocument()))->query('.//text()', $element) ?: [] as $text) {
                if ($text instanceof \DOMText) {
                    $words[] = trim((string) preg_replace('/\s+/', ' ', $text->textContent));
                }
            }
        }

        return implode(' ', array_filter($words, static fn (string $word): bool => '' !== $word));
    }

    /**
     * @param list<string>|null $grants
     */
    private function person(string $name, TierEnum $tier, ?array $grants = null, ?Department $department = null): User
    {
        $position = null;
        if (null !== $grants) {
            $position = (new Position())->setName($name.'\'s seat')->setGrants($grants);
            $this->em()->persist($position);
        }
        $user = (new User())
            ->setEmail(strtolower($name).'@vivutio-camps.example')
            ->setFirstName($name)
            ->setLastName('Kimaro')
            ->setTier($tier)
            ->setPosition($position)
            ->setDepartment($department)
            ->setPassword('a hash, never a password');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function migrate(): void
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DROP SCHEMA public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $output), $output->fetch());
    }
}
