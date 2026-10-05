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

namespace Vivutio\Sourcing\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Sourcing\Entity\RoomRequest;
use Vivutio\Sourcing\Enum\RequestStatusEnum;
use Vivutio\Sourcing\Exception\InvalidRequestException;
use Vivutio\Sourcing\Repository\RoomRequestRepository;
use Vivutio\Sourcing\Service\RoomRequestService;

/**
 * Room requests: the register by status, a new request (sent as it is made),
 * a request's page with its steps, and recording a reply or cancelling.
 */
final readonly class RoomRequestController
{
    public const string REGISTER = 'sourcing_requests';
    public const string NEW = 'sourcing_request_new';
    public const string SHOW = 'sourcing_request';
    public const string REPLY = 'sourcing_request_reply';
    public const string CANCEL = 'sourcing_request_cancel';

    public const string READ = 'room_requests.read';
    public const string RECORD = 'room_requests.record';
    public const string MANAGE = 'room_requests.manage';

    public function __construct(
        private Environment $twig,
        private RoomRequestService $service,
        private RoomRequestRepository $requests,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/sourcing', name: self::REGISTER, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function register(Request $request): Response
    {
        $status = RequestStatusEnum::tryFrom($request->query->getString('status'));
        $all = $this->requests->findBy([], ['arrival' => 'ASC', 'reference' => 'ASC']);
        $counts = array_fill_keys(array_map(static fn (RequestStatusEnum $case): string => $case->value, RequestStatusEnum::cases()), 0);
        $camps = [];
        foreach ($all as $one) {
            ++$counts[$one->getStatus()->value];
            $camps[$one->getPartnerId()] ??= $this->service->partnerOf($one)?->getName() ?? 'A partner no longer kept';
        }

        return new Response($this->twig->render('@VivutioSourcing/sourcing/index.html.twig', [
            'requests' => array_values(array_filter($all, static fn (RoomRequest $one): bool => null === $status || $one->getStatus() === $status)),
            'camps' => $camps,
            'total' => \count($all),
            'counts' => $counts,
            'statuses' => RequestStatusEnum::cases(),
            'status' => $status,
        ]));
    }

    #[Route('/sourcing/new', name: self::NEW, methods: ['GET', 'POST'])]
    #[IsGranted(self::RECORD)]
    public function new(Request $request, #[CurrentUser] UserInterface $user): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->newPage([]);
        }
        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('sourcing_request_new', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->newPage($sent, expired: true);
        }

        try {
            $made = $this->service->send($sent, self::nameOf($user));
        } catch (InvalidRequestException $refusal) {
            return $this->newPage($sent, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($made);
    }

    #[Route('/sourcing/{uuid}', name: self::SHOW, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        RoomRequest $roomRequest,
    ): Response {
        return $this->requestPage($roomRequest);
    }

    #[Route('/sourcing/{uuid}/reply', name: self::REPLY, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function reply(
        Request $request,
        #[CurrentUser] UserInterface $user,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        RoomRequest $roomRequest,
    ): Response {
        $payload = $request->getPayload();
        if (!$this->tokens->isTokenValid(new CsrfToken('sourcing_request_step', $payload->getString('_token')))) {
            return $this->requestPage($roomRequest, expired: true);
        }

        try {
            $this->service->reply($roomRequest, $payload->getString('reply'), $payload->getString('their_reference'), $payload->getString('note'), self::nameOf($user));
        } catch (InvalidRequestException $refusal) {
            return $this->requestPage($roomRequest, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($roomRequest);
    }

    #[Route('/sourcing/{uuid}/cancel', name: self::CANCEL, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function cancel(
        Request $request,
        #[CurrentUser] UserInterface $user,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        RoomRequest $roomRequest,
    ): Response {
        $payload = $request->getPayload();
        if (!$this->tokens->isTokenValid(new CsrfToken('sourcing_request_step', $payload->getString('_token')))) {
            return $this->requestPage($roomRequest, expired: true);
        }

        try {
            $this->service->cancel($roomRequest, $payload->getString('reason'), self::nameOf($user));
        } catch (InvalidRequestException $refusal) {
            return $this->requestPage($roomRequest, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($roomRequest);
    }

    private static function nameOf(UserInterface $user): string
    {
        return $user instanceof User ? $user->getFullName() : $user->getUserIdentifier();
    }

    private function to(RoomRequest $roomRequest): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::SHOW, ['uuid' => $roomRequest->getUuid()]));
    }

    /**
     * @param array<mixed>          $sent
     * @param array<string, string> $wrong
     */
    private function newPage(array $sent, array $wrong = [], bool $expired = false): Response
    {
        $text = static fn (string $key, string $default = ''): string => \is_string($sent[$key] ?? null) ? $sent[$key] : $default;
        $typedLines = \is_array($sent['lines'] ?? null) ? array_values($sent['lines']) : [];
        $lines = [];
        for ($i = 0; $i < RoomRequestService::MOST_LINES; ++$i) {
            $line = \is_array($typedLines[$i] ?? null) ? $typedLines[$i] : [];
            $lines[] = ['rooms' => \is_string($line['rooms'] ?? null) ? $line['rooms'] : '1', 'room' => \is_string($line['room'] ?? null) ? $line['room'] : ''];
        }

        return new Response($this->twig->render('@VivutioSourcing/sourcing/new.html.twig', [
            'camps' => $this->service->camps(),
            'typed' => ['partner' => $text('partner'), 'party' => $text('party'), 'arrival' => $text('arrival'), 'nights' => $text('nights', '1'), 'notes' => $text('notes'), 'our_reference' => $text('our_reference')],
            'lines' => $lines,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, string> $wrong
     */
    private function requestPage(RoomRequest $roomRequest, array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioSourcing/sourcing/request.html.twig', [
            'request' => $roomRequest,
            'camp' => $this->service->partnerOf($roomRequest),
            'dates' => RoomRequestService::dates($roomRequest),
            'open' => RequestStatusEnum::Requested === $roomRequest->getStatus(),
            'cancellable' => RoomRequestService::cancellable($roomRequest),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
