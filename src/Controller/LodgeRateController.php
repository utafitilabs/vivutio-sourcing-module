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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Contracts\Partner\PartnerInterface;
use Vivutio\Sourcing\Exception\InvalidRequestException;
use Vivutio\Sourcing\Service\LodgeRateService;

/**
 * What the lodges traded with charge, as drawn (vivutio-designs
 * sourcing/rates): the lodges, and one lodge's rates. Read with
 * lodge_rates.read, changed with lodge_rates.manage.
 */
final readonly class LodgeRateController
{
    public const string REGISTER = 'sourcing_lodge_rates';
    public const string LODGE = 'sourcing_lodge_rate';

    /** The empty rows a lodge with no rates starts with; one more after any saved. */
    public const int FIRST_ROWS = 3;

    public const string READ = 'lodge_rates.read';
    public const string MANAGE = 'lodge_rates.manage';

    public function __construct(
        private Environment $twig,
        private LodgeRateService $rates,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/sourcing/rates', name: self::REGISTER, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function register(): Response
    {
        $lodges = $this->rates->lodges();
        $contracts = [];
        foreach ($lodges as $lodge) {
            $contracts[$lodge->getPartnerId()] = $this->rates->contractOf($lodge->getPartnerId());
        }

        return new Response($this->twig->render('@VivutioSourcing/rates/index.html.twig', ['lodges' => $lodges, 'contracts' => $contracts]));
    }

    #[Route('/sourcing/rates/{partner}', name: self::LODGE, requirements: ['partner' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::READ)]
    public function lodge(Request $request, string $partner): Response
    {
        $lodge = $this->rates->lodge($partner) ?? throw new NotFoundHttpException('No lodge traded with now has this id.');
        if (!$request->isMethod('POST')) {
            $contract = $this->rates->contractOf($partner);

            return $this->lodgePage($lodge, [
                'currency' => $contract?->getCurrency() ?? '',
                'board' => $contract?->getBoard() ?? '',
                'periods' => array_map(static fn (array $p): array => ['from' => $p['from'], 'to' => $p['to'], 'each' => number_format($p['each'] / 100, 2, '.', '')], $contract?->getPeriods() ?? []),
            ]);
        }
        if (!$this->authorization->isGranted(self::MANAGE)) {
            throw new AccessDeniedHttpException('Changing the lodge rates is not allowed.');
        }
        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('sourcing_lodge_rate', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->lodgePage($lodge, $sent, expired: true);
        }

        try {
            $this->rates->save($lodge, $sent);
        } catch (InvalidRequestException $refusal) {
            return $this->lodgePage($lodge, $sent, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::LODGE, ['partner' => $partner]));
    }

    /**
     * @param array<mixed>          $typed
     * @param array<string, string> $wrong
     */
    private function lodgePage(PartnerInterface $lodge, array $typed, array $wrong = [], bool $expired = false): Response
    {
        $periods = \is_array($typed['periods'] ?? null) ? array_values($typed['periods']) : [];
        $periods = array_pad($periods, max(\count($periods) + 1, self::FIRST_ROWS), ['from' => '', 'to' => '', 'each' => '']);

        return new Response($this->twig->render('@VivutioSourcing/rates/lodge.html.twig', [
            'lodge' => $lodge,
            'typed' => $typed,
            'periods' => $periods,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
