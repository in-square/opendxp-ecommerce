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

namespace CoreShop\Bundle\ThemeBundle\Context;

use CoreShop\Bundle\ThemeBundle\Service\ThemeNotResolvedException;
use CoreShop\Bundle\ThemeBundle\Service\ThemeResolverInterface;
use OpenDxp\Http\Request\Resolver\OpenDxpContextResolver;
use Sylius\Bundle\ThemeBundle\Context\SettableThemeContext;
use Sylius\Bundle\ThemeBundle\Context\ThemeContextInterface;
use Sylius\Bundle\ThemeBundle\Model\ThemeInterface;
use Sylius\Bundle\ThemeBundle\Repository\ThemeRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class ThemeContext implements ThemeContextInterface
{
    public function __construct(
        private ThemeResolverInterface $resolver,
        private ThemeRepositoryInterface $themeRepository,
        private OpenDxpContextResolver $opendxpContext,
        private RequestStack $requestStack,
        private SettableThemeContext $settableThemeContext,
    ) {
    }

    public function getTheme(): ?ThemeInterface
    {
        $request = $this->requestStack->getMainRequest();

        if (!$request || null !== $this->settableThemeContext->getTheme()) {
            return $this->settableThemeContext->getTheme();
        }

        $isAjaxBrickRendering = $request->attributes->get('_route') === 'opendxp_admin_document_page_areabrick-render-index-editmode';

        if (!$isAjaxBrickRendering && $this->opendxpContext->matchesOpenDxpContext($request, OpenDxpContextResolver::CONTEXT_ADMIN)) {
            return $this->settableThemeContext->getTheme();
        }

        try {
            return $this->themeRepository->findOneByName($this->resolver->resolveTheme());
        } catch (ThemeNotResolvedException) {
            return $this->settableThemeContext->getTheme();
        }
    }
}
