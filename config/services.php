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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Partner\PartnerChannelInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Sourcing\Access\SourcingConcerns;
use Vivutio\Sourcing\Controller\RoomRequestController;
use Vivutio\Sourcing\Repository\RoomRequestRepository;
use Vivutio\Sourcing\Service\RoomRequestService;
use Vivutio\Sourcing\Shell\SourcingMenu;

/*
 * Every service is defined explicitly, with an id prefixed by the bundle's
 * alias; nothing is autowired or autoconfigured, so a tag is applied by hand.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html#services
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('sourcing.access.concerns', SourcingConcerns::class)
        ->tag(ConcernSourceInterface::TAG);
    $services->set('sourcing.menu', SourcingMenu::class)
        ->tag(MenuSourceInterface::TAG);

    $services->set(RoomRequestRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set('sourcing.room_requests', RoomRequestService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service(RoomRequestRepository::class),
            service(PartnerDirectoryInterface::class),
            service(PartnerChannelInterface::class),
        ]);

    $services->set('sourcing.controller.room_requests', RoomRequestController::class)
        ->args([
            service('twig'),
            service('sourcing.room_requests'),
            service(RoomRequestRepository::class),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(RoomRequestController::class, 'sourcing.controller.room_requests')->public();
};
