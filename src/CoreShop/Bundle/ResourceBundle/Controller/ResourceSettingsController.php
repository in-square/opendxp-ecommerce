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

namespace CoreShop\Bundle\ResourceBundle\Controller;

use CoreShop\Bundle\ResourceBundle\CoreShopResourceBundle;
use CoreShop\Component\OpenDxp\Element\ElementId;
use OpenDxp\Model\Element\AbstractElement;
use OpenDxp\Model\Element\Service;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ResourceSettingsController extends AdminController
{
    public function getNicePathAction(Request $request): Response
    {
        $targets = $this->getParameterFromRequest($request, 'targets');
        $targets = is_string($targets) ? json_decode($targets, true) : null;
        $result = [];

        foreach (is_array($targets) ? $targets : [] as $target) {
            if (!is_array($target)) {
                continue;
            }

            $type = $target['type'] ?? null;
            $id = ElementId::normalize($target['id'] ?? null);
            if (!is_string($type) || null === $id) {
                continue;
            }

            $element = Service::getElementById($type, $id);

            if ($element instanceof AbstractElement) {
                $result[$element->getId()] = $element->getFullPath();
            }
        }

        return $this->viewHandler->handle(['success' => true, 'data' => $result]);
    }

    public function getConfigAction(): Response
    {
        $config = [
            'classMap' => [],
            'stack' => [],
        ];

        if ($this->parameterBag->has('coreshop.all.opendxp_classes')) {
            /**
             * @var array $classes
             */
            $classes = $this->parameterBag->get('coreshop.all.opendxp_classes');

            foreach ($classes as $key => $definition) {
                if (!isset($definition['classes']['type'])) {
                    continue;
                }

                if ($definition['classes']['type'] !== CoreShopResourceBundle::OPENDXP_MODEL_TYPE_OBJECT) {
                    continue;
                }

                $alias = explode('.', $key);
                $application = $alias[0];
                $alias = $alias[1];

                if (isset($definition['classes']['opendxp_class_name'])) {
                    $class = $definition['classes']['opendxp_class_name'];
                } else {
                    $fullClassName = $definition['classes']['model'];
                    $class = str_replace(['OpenDxp\\Model\\DataObject\\', '\\'], '', $fullClassName);
                }

                $config['classMap'][$application][$alias] = $class;
            }

            /**
             * @var array $stack
             */
            $stack = $this->getParameter('coreshop.all.stack.opendxp_class_names');

            foreach ($stack as $key => $impl) {
                $alias = explode('.', $key);
                $application = $alias[0];
                $alias = $alias[1];

                $config['stack'][$application][$alias] = $impl;
                $config['full_stack'][] = $key;
            }
        }

        return $this->viewHandler->handle($config);
    }
}
