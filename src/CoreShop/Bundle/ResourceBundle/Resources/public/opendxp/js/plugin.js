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

opendxp.registerNS("coreshop.resource");
opendxp.registerNS("coreshop.resource.plugin");
coreshop.resource.plugin = Class.create({
    initialize: function () {
        document.addEventListener(opendxp.events.opendxpReady, (e) => {
            this.opendxpReady(e.detail.params, e.detail.broker);
        });
        document.addEventListener(opendxp.events.postOpenObject, (e) => {
            this.postOpenObject(e.detail.object, e.detail.type);
        });
        document.addEventListener(opendxp.events.preOpenObject, (e) => {
            this.preOpenObject(e.detail.object, e.detail.type);
        });
        document.addEventListener(opendxp.events.postOpenAsset, (e) => {
            this.postOpenAsset(e.detail.object, e.detail.type);
        });
        document.addEventListener(opendxp.events.preOpenAsset, (e) => {
            this.preOpenAsset(e.detail.object, e.detail.type);
        });
        document.addEventListener(opendxp.events.postOpenDocument, (e) => {
            this.postOpenDocument(e.detail.object, e.detail.type);
        });
        document.addEventListener(opendxp.events.preOpenDocument, (e) => {
            this.preOpenDocument(e.detail.object, e.detail.type);
        });
    },

    uninstall: function () {
    },

    opendxpReady: function (params, broker) {
        this.fire('opendxp.ready', arguments);
    },

    preOpenObject: function (object, type) {
        this.fire('opendxp.preOpenObject', arguments);
    },

    postOpenObject: function (object, type) {
        this.fire('opendxp.postOpenObject', arguments);
    },

    preOpenAsset: function (asset, type) {
        this.fire('opendxp.preOpenAsset', arguments);
    },

    postOpenAsset: function (asset, type) {
        this.fire('opendxp.postOpenAsset', arguments);
    },

    preOpenDocument: function (document, type) {
        this.fire('opendxp.preOpenDocument', arguments);
    },

    postOpenDocument: function (document, type) {
        this.fire('opendxp.postOpenDocument', arguments);
    },

    fire: function(event, args) {
        args = Ext.Object.getValues(args);
        args.unshift(event);

        coreshop.broker.fireEvent.apply(this, args);
    }
});

new coreshop.resource.plugin();
