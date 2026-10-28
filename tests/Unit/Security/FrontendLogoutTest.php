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

namespace CoreShop\Tests\Unit\Security;

use CoreShop\Bundle\CoreBundle\EventListener\ShopUserLogoutHandler;
use CoreShop\Bundle\StorageListBundle\Core\EventListener\SessionStoreStorageListLogoutSubscriber;
use CoreShop\Bundle\StorageListBundle\DependencyInjection\Compiler\RegisterFrontendLogoutListenersPass;
use CoreShop\Component\Core\Model\StoreInterface;
use CoreShop\Component\StorageList\Context\StorageListContextInterface;
use CoreShop\Component\StorageList\Model\StorageListInterface;
use CoreShop\Component\Store\Context\StoreContextInterface;
use CoreShop\Component\Store\Model\StoreAwareInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class FrontendLogoutTest extends TestCase
{
    public function testBackendOnlyContainerDoesNotRegisterLogoutListeners(): void
    {
        $container = new ContainerBuilder();
        $listener = $container->register('logout', ShopUserLogoutHandler::class)
            ->addTag('coreshop.frontend_logout_listener')
        ;

        (new RegisterFrontendLogoutListenersPass())->process($container);

        self::assertFalse($listener->hasTag('kernel.event_listener'));
        $container->compile();
    }

    public function testFrontendDispatcherAliasIsSupported(): void
    {
        $container = new ContainerBuilder();
        $container->register('frontend_dispatcher', EventDispatcher::class);
        $container->setAlias('security.event_dispatcher.coreshop_frontend', 'frontend_dispatcher');
        $listener = $container->register('logout', ShopUserLogoutHandler::class)
            ->addTag('coreshop.frontend_logout_listener')
        ;

        (new RegisterFrontendLogoutListenersPass())->process($container);

        self::assertSame([[
            'event' => LogoutEvent::class,
            'method' => 'onLogoutSuccess',
            'dispatcher' => 'security.event_dispatcher.coreshop_frontend',
        ]], $listener->getTag('kernel.event_listener'));
    }

    public function testOnlyFrontendLogoutClearsSessionListsAndSetsLocalizedRedirect(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(7);
        $storeContext = $this->createMock(StoreContextInterface::class);
        $storeContext->method('getStore')->willReturn($store);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('generate')
            ->with('coreshop_index', ['_locale' => 'pl'])->willReturn('/pl/shop')
        ;
        $list = $this->createMockForIntersectionOfInterfaces([StorageListInterface::class, StoreAwareInterface::class]);
        $list->method('getStore')->willReturn($store);
        $listContext = $this->createMock(StorageListContextInterface::class);
        $listContext->method('getStorageList')->willReturn($list);
        $container = new ContainerBuilder();
        foreach (['event_dispatcher', 'security.event_dispatcher.coreshop_frontend', 'security.event_dispatcher.admin'] as $id) {
            $container->register($id, EventDispatcher::class)->setPublic(true);
        }
        $container->setDefinition('cart_logout', (new Definition(ShopUserLogoutHandler::class, [$router, 'coreshop_index', $storeContext]))
            ->addTag('coreshop.frontend_logout_listener'));
        $container->setDefinition('list_logout', (new Definition(SessionStoreStorageListLogoutSubscriber::class, [$listContext, 'coreshop.wishlist']))
            ->addTag('coreshop.frontend_logout_listener'));
        (new RegisterFrontendLogoutListenersPass())->process($container);
        (new RegisterListenersPass())->process($container);
        $container->compile();

        $request = new Request();
        $request->setLocale('pl');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $session->set('coreshop.cart.7', 123);
        $session->set('coreshop.wishlist.7', 456);
        $session->set('coreshop.cart.8', 789);
        $event = new LogoutEvent($request, null);

        $container->get('event_dispatcher')->dispatch($event);
        $container->get('security.event_dispatcher.admin')->dispatch($event);
        self::assertSame(123, $session->get('coreshop.cart.7'));
        self::assertSame(456, $session->get('coreshop.wishlist.7'));
        self::assertNull($event->getResponse());

        $container->get('security.event_dispatcher.coreshop_frontend')->dispatch($event);

        self::assertFalse($session->has('coreshop.cart.7'));
        self::assertFalse($session->has('coreshop.wishlist.7'));
        self::assertSame(789, $session->get('coreshop.cart.8'));
        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/pl/shop', $event->getResponse()->getTargetUrl());
    }
}
