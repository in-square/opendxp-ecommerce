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

namespace CoreShop\Tests\Unit\Behat;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use CoreShop\Behat\Page\Frontend\Account\RequestPasswordResetPage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestPasswordResetPageTest extends TestCase
{
    public function testWaitsForAReplacementDocumentAfterSubmitting(): void
    {
        $events = [];
        $session = $this->createMock(Session::class);
        $button = $this->createMock(NodeElement::class);
        $session->expects(self::once())->method('executeScript')
            ->with('window.__coreshopPasswordResetDocument = true;')
            ->willReturnCallback(static function () use (&$events): void { $events[] = 'mark'; })
        ;
        $button->expects(self::once())->method('click')->willReturnCallback(static function () use (&$events): void {
            self::assertSame(['mark'], $events);
            $events[] = 'submit';
        });
        $session->expects(self::once())->method('wait')
            ->with(10000, "document.readyState === 'complete' && window.__coreshopPasswordResetDocument !== true")
            ->willReturnCallback(static function () use (&$events): bool {
                self::assertSame(['mark', 'submit'], $events);
                $events[] = 'response';

                return true;
            })
        ;
        $page = $this->getMockBuilder(RequestPasswordResetPage::class)->disableOriginalConstructor()->onlyMethods(['getElement', 'getSession'])->getMock();
        $page->method('getElement')->with('reset_button')->willReturn($button);
        $page->method('getSession')->willReturn($session);
        $page->reset();
        self::assertSame(['mark', 'submit', 'response'], $events);
    }

    public function testFailsWhenTheOldDocumentRemainsVisible(): void
    {
        $session = $this->createMock(Session::class);
        $button = $this->createMock(NodeElement::class);
        $session->expects(self::once())->method('executeScript')->with('window.__coreshopPasswordResetDocument = true;');
        $button->expects(self::once())->method('click');
        $session->expects(self::once())->method('wait')
            ->with(10000, "document.readyState === 'complete' && window.__coreshopPasswordResetDocument !== true")
            ->willReturn(false)
        ;
        $page = $this->getMockBuilder(RequestPasswordResetPage::class)->disableOriginalConstructor()->onlyMethods(['getElement', 'getSession'])->getMock();
        $page->method('getElement')->willReturn($button);
        $page->method('getSession')->willReturn($session);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Password reset form submission did not finish loading a response document.');
        $page->reset();
    }
}
