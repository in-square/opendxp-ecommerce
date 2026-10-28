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

namespace CoreShop\Component\Core\Locale\Context;

use CoreShop\Component\Locale\Context\LocaleContextInterface;
use CoreShop\Component\Locale\Context\LocaleNotFoundException;
use CoreShop\Component\Resource\Translation\Provider\TranslationLocaleProviderInterface;
use OpenDxp\Http\Request\Resolver\OpenDxpContextResolver;
use OpenDxp\Model\User;
use OpenDxp\Security\User\TokenStorageUserResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class OpenDxpAdminLocaleContext implements LocaleContextInterface
{
    public function __construct(
        private OpenDxpContextResolver $opendxpContextResolver,
        private TokenStorageUserResolver $tokenStorageUserResolver,
        private TranslationLocaleProviderInterface $localeProvider,
        private RequestStack $requestStack,
    ) {
    }

    public function getLocaleCode(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request instanceof Request) {
            throw new LocaleNotFoundException('No Request in RequestStack, cannot determine OpenDxp Context');
        }

        if ($this->opendxpContextResolver->getOpenDxpContext($request) !== OpenDxpContextResolver::CONTEXT_ADMIN) {
            throw new LocaleNotFoundException('Not in Admin Mode');
        }

        $user = $this->tokenStorageUserResolver->getUser();

        if (!$user instanceof User) {
            throw new LocaleNotFoundException('No valid Admin User found');
        }

        $backendLanguage = $user->getLanguage();
        $localizedTableLanguage = null;

        $frontendLanguages = $this->localeProvider->getDefinedLocalesCodes();

        // no frontend language defined. this should never happen.
        if (empty($frontendLanguages)) {
            throw new LocaleNotFoundException('No valid Frontend Languages found');
        }

        if (!empty($backendLanguage) && in_array($backendLanguage, $frontendLanguages)) {
            $localizedTableLanguage = strtolower($backendLanguage);
        } else {
            $first = reset($frontendLanguages);
            $localizedTableLanguage = strtolower($first);
        }

        return $localizedTableLanguage;
    }
}
