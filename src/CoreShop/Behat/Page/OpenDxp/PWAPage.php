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

namespace CoreShop\Behat\Page\OpenDxp;

use Behat\Mink\Exception\DriverException;
use FriendsOfBehat\PageObjectExtension\Page\UnexpectedPageException;

class PWAPage extends AbstractOpenDxpPage implements PWAPageInterface
{
    public function getRouteName(): string
    {
        return 'opendxp_admin_index';
    }

    protected function verifyUrl(array $urlParameters = []): void
    {
        $url = preg_replace('/\?.*/', '', $this->getSession()->getCurrentUrl());

        if ($url !== $this->getUrl($urlParameters)) {
            throw new UnexpectedPageException(sprintf('Expected to be on "%s" but found "%s" instead', $this->getUrl($urlParameters), $this->getSession()->getCurrentUrl()));
        }
    }

    public function waitTillLoaded(): void
    {
        $loaded = $this->getDocument()->waitFor(30, function () {
            return $this->getDocument()->find('css', 'body.coreshop_loaded') && $this->getDocument()->find('css', '#opendxp_menu_coreshop_main');
        });

        if (!$loaded) {
            throw new DriverException('OpenDXP admin did not finish loading within 30 seconds.');
        }
    }

    public function hasLogoutButton(): bool
    {
        return $this->getDocument()->has('css', '#opendxp_logout');
    }

    public function hasOpenDxpTabWithId(string $id): bool
    {
        return $this->getDocument()->has('css', '#opendxp_panel_tabs #' . $id);
    }

    public function openResource(string $application, string $resource): void
    {
        $this->getSession()->executeScript(
            sprintf(
                'coreshop.global.resource.open(\'%s\', \'%s\');',
                $application,
                $resource,
            ),
        );
    }
}
