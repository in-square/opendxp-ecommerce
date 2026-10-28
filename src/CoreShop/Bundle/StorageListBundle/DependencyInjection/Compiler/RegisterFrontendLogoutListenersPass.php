<?php

declare(strict_types=1);

/*
 * CoreShop
 *
 * This source file is available under two different licenses:
 *  - GNU General Public License version 3 (GPLv3)
 *  - CoreShop Commercial License (CCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.com)
 * @license    https://www.coreshop.com/license     GPLv3 and CCL
 *
 */

namespace CoreShop\Bundle\StorageListBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class RegisterFrontendLogoutListenersPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $dispatcher = 'security.event_dispatcher.coreshop_frontend';

        if (!$container->hasDefinition($dispatcher) && !$container->hasAlias($dispatcher)) {
            return;
        }

        foreach ($container->findTaggedServiceIds('coreshop.frontend_logout_listener') as $id => $tags) {
            $container->getDefinition($id)->addTag('kernel.event_listener', [
                'event' => LogoutEvent::class,
                'method' => 'onLogoutSuccess',
                'dispatcher' => $dispatcher,
            ]);
        }
    }
}
