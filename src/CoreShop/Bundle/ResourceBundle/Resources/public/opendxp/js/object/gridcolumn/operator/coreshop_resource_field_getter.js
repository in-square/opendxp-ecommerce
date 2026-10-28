/*
 * CoreShop.
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.com)
 * @license    https://www.coreshop.com/license     GPLv3 and CCL
 *
 */

opendxp.registerNS("opendxp.object.gridcolumn.operator.coreshop_resource_field_getter");

opendxp.object.gridcolumn.operator.coreshop_resource_field_getter = Class.create(opendxp.object.gridcolumn.operator.objectfieldgetter, {
    operatorGroup: "extractor",
    type: "operator",
    class: "coreshop_resource_field_getter",
    iconCls: "opendxp_icon_operator_object_field_getter",
    defaultText: "CoreShop Resource Field Getter",
    group: "getter"
});