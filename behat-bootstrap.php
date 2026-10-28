<?php
/**
 * CoreShop.
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.com)
 * @license    https://www.coreshop.com/license     GNU General Public License version 3 (GPLv3)
 */

if (!defined('OPENDXP_PROJECT_ROOT')) {
    define(
        'OPENDXP_PROJECT_ROOT',
        getenv('OPENDXP_PROJECT_ROOT')
            ?: getenv('REDIRECT_OPENDXP_PROJECT_ROOT')
            ?: __DIR__
    );
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ .'/src/BehatKernel.php';

(new \Symfony\Component\Dotenv\Dotenv())->bootEnv(OPENDXP_PROJECT_ROOT . '/.env');

\OpenDxp\Bootstrap::bootstrap();
