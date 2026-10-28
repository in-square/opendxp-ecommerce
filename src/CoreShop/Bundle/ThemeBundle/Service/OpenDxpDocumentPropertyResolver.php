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

namespace CoreShop\Bundle\ThemeBundle\Service;

use OpenDxp\Http\Request\Resolver\DocumentResolver;
use OpenDxp\Http\Request\Resolver\SiteResolver;
use OpenDxp\Model\Document;
use OpenDxp\Model\Site;
use Symfony\Component\HttpFoundation\RequestStack;

final class OpenDxpDocumentPropertyResolver implements ThemeResolverInterface, DocumentThemeResolverInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private DocumentResolver $documentResolver,
        private Document\Service $documentService,
        private SiteResolver $siteResolver,
    ) {
    }

    public function resolveTheme(): string
    {
        try {
            $request = $this->requestStack->getMainRequest();

            if (!$request) {
                throw new ThemeNotResolvedException();
            }

            $site = $this->siteResolver->getSite($request);

            $isAjaxBrickRendering = $request->attributes->get('_route') === 'opendxp_admin_document_page_areabrick-render-index-editmode';
            $document = null;

            if ($isAjaxBrickRendering) {
                $documentId = $request->request->get('documentId');

                if ($documentId) {
                    $document = Document::getById((int) $documentId);
                }
            } else {
                $document = $this->documentResolver->getDocument($request);
            }

            if (!$document) {
                $basePath = '';

                if ($site instanceof Site) {
                    $basePath = $site->getRootPath();
                }

                /**
                 * @psalm-suppress InternalMethod
                 */
                $document = $this->documentService->getNearestDocumentByPath($basePath . $request->getPathInfo());
            }

            if ($document instanceof Document && $document->getProperty('theme')) {
                return $document->getProperty('theme');
            }
        } catch (\Exception $ex) {
            throw new ThemeNotResolvedException($ex);
        }

        throw new ThemeNotResolvedException();
    }

    public function resolveThemeForDocument(Document $document): string
    {
        if ($document->getProperty('theme')) {
            return $document->getProperty('theme');
        }

        throw new ThemeNotResolvedException();
    }
}
