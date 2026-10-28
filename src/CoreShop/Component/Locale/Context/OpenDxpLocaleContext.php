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

namespace CoreShop\Component\Locale\Context;

use OpenDxp\Localization\LocaleServiceInterface;
use OpenDxp\Tool;

class OpenDxpLocaleContext implements LocaleContextInterface
{
    public function __construct(
        private LocaleServiceInterface $opendxpLocaleService,
    ) {
    }

    public function getLocaleCode(): string
    {
        /**
         * @var string|null $opendxpLocale
         *
         * @psalm-var string|null $opendxpLocale
         */
        $opendxpLocale = $this->opendxpLocaleService->findLocale();

        if (null === $opendxpLocale) {
            throw new LocaleNotFoundException();
        }

        if (!Tool::isValidLanguage($opendxpLocale)) {
            return Tool::getDefaultLanguage();
        }

        return $opendxpLocale;
    }
}
