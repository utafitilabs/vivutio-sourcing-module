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
use Symfony\Component\Mime\Email;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Sourcing\Entity\RoomRequest;

/**
 * Rooms requested from an accommodation partner (#8, #9): the request is
 * sent through the core's partner channel, an email by the manual channel,
 * and the camp's reply is recorded by hand, each step kept on the request.
 */
final class TheRoomRequestsTest extends WebTestCase
{
    private KernelBrowser $browser;
    private Partner $camp;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->migrate();
        $this->camp = (new Partner('Ngorongoro Rim Camp', PartnerKindEnum::Accommodation, 'TZ', 'stay@rim-camp.example'))->setContact('Paulo Saitoti');
        $this->em()->persist($this->camp);
        $this->em()->persist(new Partner('Savanna Trails Safaris', PartnerKindEnum::TourOperator, 'KE', 'reservations@savanna-trails.example'));
        $this->em()->flush();
    }

    public function testARequestIsSentToTheCampAndItsReplyRecorded(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        self::assertSame('/sourcing', $this->browser->request('GET', '/')->filter('nav.menu')->selectLink('Room requests')->attr('href'));
        $page = $this->browser->request('GET', '/sourcing/new');
        self::assertSame(['Ngorongoro Rim Camp'], array_values(array_filter($page->filter('select[name="partner"] option')->each(static fn (Crawler $option): string => trim($option->text())), static fn (string $name): bool => 'Choose…' !== $name)));
        $this->send(['party' => 'Mollel party', 'our_reference' => 'Northern Circuit, Nov']);

        $request = $this->only();
        self::assertResponseRedirects('/sourcing/'.$request->getUuid());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('stay@rim-camp.example', $email->getTo()[0]->getAddress());
        self::assertSame('RQ-0001 · Rooms for the Mollel party, 2 to 4 Nov 2026', $email->getSubject());
        self::assertStringContainsString('2 × Double, for 2 nights from 2 Nov 2026 to 4 Nov 2026', (string) $email->getTextBody());

        $page = $this->browser->followRedirect();
        self::assertSame('RQ-0001', trim($page->filter('h1')->text()));
        self::assertSame('Requested', trim($page->filter('.title .chip')->text()));
        self::assertSame(['Sent by email to stay@rim-camp.example'], $this->history($page));

        $this->browser->submit($page->selectButton('Record the reply')->form(['reply' => 'confirmed', 'their_reference' => 'RIM-7781', 'note' => '']));
        $page = $this->browser->followRedirect();
        self::assertSame('Confirmed', trim($page->filter('.title .chip')->text()));
        self::assertSame('RIM-7781', trim($page->filter('[data-their-reference]')->text()));
        self::assertSame(['Sent by email to stay@rim-camp.example', 'Confirmed: RIM-7781'], $this->history($page));
        self::assertSame('Confirmed', trim($this->browser->request('GET', '/sourcing')->filter('tr[data-request="RQ-0001"] [data-status]')->text()));
    }

    public function testARequestIsDeclinedOrCancelledAndThenTakesNoReply(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->send(['party' => 'Mollel party']);
        $this->send(['party' => 'Kimaro family']);
        [$first, $second] = $this->em()->getRepository(RoomRequest::class)->findBy([], ['reference' => 'ASC']);

        $page = $this->browser->request('GET', '/sourcing/'.$first->getUuid());
        $this->browser->submit($page->selectButton('Record the reply')->form(['reply' => 'declined', 'their_reference' => '', 'note' => 'Full that week']));
        self::assertSame(['Sent by email to stay@rim-camp.example', 'Declined: Full that week'], $this->history($this->browser->followRedirect()));

        $page = $this->browser->request('GET', '/sourcing/'.$second->getUuid());
        $this->browser->submit($page->selectButton('Cancel the request')->form(['reason' => 'The party postponed']));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('RQ-0002 · Cancelled: rooms for the Kimaro family, 2 to 4 Nov 2026', $email->getSubject());
        $page = $this->browser->followRedirect();
        self::assertSame('Cancelled', trim($page->filter('.title .chip')->text()));
        self::assertSame('Cancelled: The party postponed · Sent by email to stay@rim-camp.example', $this->history($page)[1]);
        self::assertCount(0, $page->filter('form[method="post"]'), 'a cancelled request takes no reply');

        $this->browser->request('POST', '/sourcing/'.$second->getUuid().'/reply', ['reply' => 'confirmed']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testWhatARequestCannotBeIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $operator = $this->em()->getRepository(Partner::class)->findOneBy(['name' => 'Savanna Trails Safaris']);
        self::assertInstanceOf(Partner::class, $operator);

        foreach ([
            ['partner', ['partner' => $operator->getPartnerId()]],
            ['party', ['party' => '']],
            ['arrival', ['arrival' => '2020-01-01']],
            ['nights', ['nights' => '0']],
            ['lines[0][rooms]', ['lines[0][rooms]' => '0']],
            ['lines[0][room]', ['lines[0][room]' => '']],
        ] as [$field, $values]) {
            $page = $this->send($values, 422);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select, textarea')->attr('name'), $field);
        }
        self::assertEmailCount(0);
    }

    /** Reading requests, sending them and recording their replies are three pairs, each a department must allow. */
    public function testStaffDoWhatTheirDepartmentAllows(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->send(['party' => 'Mollel party']);
        $request = $this->only();

        $reservations = (new Department())->setName('Reservations')->setAllows(['room_requests.read', 'room_requests.record']);
        $this->em()->persist($reservations);
        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['room_requests.read', 'room_requests.record', 'room_requests.manage'], $reservations));

        $page = $this->browser->request('GET', '/sourcing/'.$request->getUuid());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('form[method="post"]'), 'recording replies is not allowed by the department');
        $this->browser->request('GET', '/sourcing/new');
        self::assertResponseIsSuccessful();
    }

    /** The module ships the migration its mapping needs, and nothing is left for a diff to write. */
    public function testTheMigrationsBuildWhatTheMappingDescribes(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:schema:validate', '--skip-property-types' => true]), $output), $output->fetch());
    }

    /**
     * @param array<string, string> $values
     */
    private function send(array $values, int $answered = 302): Crawler
    {
        $page = $this->browser->request('GET', '/sourcing/new');
        $form = $page->selectButton('Send the request')->form();
        $form->disableValidation();
        $page = $this->browser->submit($form->setValues([...[
            'partner' => $this->camp->getPartnerId(),
            'party' => 'Mollel party',
            'arrival' => '2026-11-02',
            'nights' => '2',
            'lines[0][rooms]' => '2',
            'lines[0][room]' => 'Double',
            'notes' => '',
        ], ...$values]));
        self::assertResponseStatusCodeSame($answered);

        return $page;
    }

    /**
     * @return list<string>
     */
    private function history(Crawler $page): array
    {
        return $page->filter('[data-history] li')->each(static fn (Crawler $line): string => trim((string) preg_replace('/\s+/', ' ', $line->filter('span')->text())));
    }

    private function only(): RoomRequest
    {
        $this->em()->clear();
        $requests = $this->em()->getRepository(RoomRequest::class)->findAll();
        self::assertCount(1, $requests);

        return $requests[0];
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
