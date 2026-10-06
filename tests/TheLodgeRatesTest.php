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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DomCrawler\Crawler;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerStatusEnum;
use Vivutio\Bundle\PlaceBundle\Service\NightCostService;

/**
 * What the lodges traded with charge, as drawn (vivutio-designs
 * sourcing/rates): a currency and a board, and a price a person sharing a
 * night by dated period, kept once and read by every tour through the core.
 */
final class TheLodgeRatesTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->migrate();
        $em = $this->em();
        $em->persist(new Partner('Mbuyu Ridge Lodge', PartnerKindEnum::Accommodation, 'TZ', 'stay@mbuyu.example'));
        $em->persist(new Partner('Nyasi Plains Camp', PartnerKindEnum::Accommodation, 'TZ', 'stay@nyasi.example'));
        $em->persist((new Partner('Old River Camp', PartnerKindEnum::Accommodation, 'TZ', 'stay@old-river.example'))->setStatus(PartnerStatusEnum::Archived));
        $em->persist(new Partner('Savanna Trails Travel', PartnerKindEnum::TravelAgent, 'KE', 'bookings@savanna-trails.example'));
        $em->flush();
    }

    public function testALodgesRatesAreKeptByPeriodAndToldToTours(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $mbuyu = $this->partner('Mbuyu Ridge Lodge');

        $page = $this->browser->request('GET', '/sourcing/rates');
        self::assertSame('/sourcing/rates', $page->filter('nav.menu a[title="Lodge rates"]')->attr('href'));
        self::assertSame(['Mbuyu Ridge Lodge', 'Nyasi Plains Camp'], $page->filter('tr[data-lodge]')->each(static fn (Crawler $row): string => (string) $row->attr('data-lodge')));
        self::assertSame('No rates', trim($page->filter('tr[data-lodge="Mbuyu Ridge Lodge"] [data-periods]')->text()));

        $page = $this->browser->request('GET', '/sourcing/rates/'.$mbuyu->getPartnerId());
        $this->browser->submit($page->selectButton('Save the rates')->form([
            'currency' => 'usd',
            'board' => 'Full board',
            'periods[0][from]' => '2027-01-01', 'periods[0][to]' => '2027-03-31', 'periods[0][each]' => '150',
            'periods[1][from]' => '2027-06-01', 'periods[1][to]' => '2027-10-31', 'periods[1][each]' => '210.50',
        ]));
        self::assertResponseRedirects('/sourcing/rates/'.$mbuyu->getPartnerId());
        $page = $this->browser->followRedirect();
        self::assertSame('USD', $page->filter('input[name="currency"]')->attr('value'));
        self::assertSame(['2027-01-01', '2027-06-01', ''], $page->filter('input[name$="[from]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertSame(['150.00', '210.50', ''], $page->filter('input[name$="[each]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));

        $page = $this->browser->request('GET', '/sourcing/rates');
        self::assertSame('2 periods', trim($page->filter('tr[data-lodge="Mbuyu Ridge Lodge"] [data-periods]')->text()));
        self::assertSame('31 Oct 2027', trim($page->filter('tr[data-lodge="Mbuyu Ridge Lodge"] [data-covered]')->text()));

        $costs = static::getContainer()->get(NightCostService::class);
        self::assertInstanceOf(NightCostService::class, $costs);
        $night = $costs->costOf('partner:'.$mbuyu->getPartnerId(), new \DateTimeImmutable('2027-08-04'));
        self::assertNotNull($night);
        self::assertSame(['USD', 21050, 'Full board, sharing'], [$night->currency, $night->each, $night->basis]);
        self::assertNull($costs->costOf('partner:'.$mbuyu->getPartnerId(), new \DateTimeImmutable('2027-05-01')), 'a night no period covers');
        self::assertNull($costs->costOf('partner:'.$this->partner('Nyasi Plains Camp')->getPartnerId(), new \DateTimeImmutable('2027-08-04')), 'a lodge with no rates');
    }

    public function testWhatARateCannotBeIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $mbuyu = $this->partner('Mbuyu Ridge Lodge');
        $good = ['currency' => 'USD', 'board' => 'Full board', 'periods[0][from]' => '2027-01-01', 'periods[0][to]' => '2027-03-31', 'periods[0][each]' => '150', 'periods[1][from]' => '', 'periods[1][to]' => '', 'periods[1][each]' => ''];

        foreach ([
            ['currency', ['currency' => 'dollars']],
            ['board', ['board' => '']],
            ['periods[0][from]', ['periods[0][from]' => '2027-02-30']],
            ['periods[0][to]', ['periods[0][to]' => '2026-12-31']],
            ['periods[0][each]', ['periods[0][each]' => 'a lot']],
            ['periods[1][from]', ['periods[1][from]' => '2027-03-01', 'periods[1][to]' => '2027-04-30', 'periods[1][each]' => '120']],
        ] as [$field, $values]) {
            $page = $this->browser->request('GET', '/sourcing/rates/'.$mbuyu->getPartnerId());
            $form = $page->selectButton('Save the rates')->form();
            $form->disableValidation();
            $page = $this->browser->submit($form->setValues([...$good, ...$values]));
            self::assertResponseStatusCodeSame(422, $field);
            self::assertSame($field, $page->filter('.wrong input, .field.wrong input')->attr('name'), $field);
        }

        $this->browser->request('GET', '/sourcing/rates/'.$this->partner('Savanna Trails Travel')->getPartnerId());
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/sourcing/rates/'.$this->partner('Old River Camp')->getPartnerId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testStaffSeeTheRatesAndChangeThemOnlyIfTheirSeatAllows(): void
    {
        $mbuyu = $this->partner('Mbuyu Ridge Lodge');
        $reservations = (new Department())->setName('Reservations')->setAllows(['lodge_rates.read', 'lodge_rates.manage']);
        $this->em()->persist($reservations);
        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['lodge_rates.read'], $reservations));

        $page = $this->browser->request('GET', '/sourcing/rates/'.$mbuyu->getPartnerId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('form[method="post"]'));
        $this->browser->request('POST', '/sourcing/rates/'.$mbuyu->getPartnerId(), ['currency' => 'USD']);
        self::assertResponseStatusCodeSame(403);
    }

    private function partner(string $name): Partner
    {
        $partner = $this->em()->getRepository(Partner::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Partner::class, $partner);

        return $partner;
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

    private function signedInAs(User $user): void
    {
        $this->browser->restart();
        $this->browser->loginUser($user);
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
