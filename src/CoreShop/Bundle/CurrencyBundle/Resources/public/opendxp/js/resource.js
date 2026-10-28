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

opendxp.registerNS('coreshop.currency.resource');
coreshop.currency.resource = Class.create(coreshop.resource, {
    initialize: function () {
        coreshop.global.addStoreWithRoute('coreshop_currencies', 'coreshop_currency_list');
        coreshop.global.addStoreWithRoute('coreshop_exchange_rates', 'coreshop_exchange_rate_list', [
            {name: 'id'},
            {name: 'fromCurrency'},
            {name: 'toCurrency'},
            {name: 'exchangeRate'}
        ]);

        Ext.Ajax.request({
            url: 'coreshop/currencies/get-config',
            method: 'get',
            success: function (response) {
                try {
                    var res = Ext.decode(response.responseText);

                    opendxp.globalmanager.add('coreshop.currency.decimal_precision', res.decimal_precision);
                    opendxp.globalmanager.add('coreshop.currency.decimal_factor', res.decimal_factor);
                } catch (e) {

                }
            }.bind(this)
        });

        opendxp.globalmanager.get('coreshop_currencies').load();

        coreshop.broker.fireEvent('resource.register', 'coreshop.currency', this);
    },

    openResource: function (item) {
        if (item === 'currency') {
            this.openCurrencyResource();
        } else if (item === 'exchange_rate') {
            this.openExchangeRateResource();
        }
    },

    openCurrencyResource: function () {
        try {
            opendxp.globalmanager.get('coreshop_currencies_panel').activate();
        }
        catch (e) {
            opendxp.globalmanager.add('coreshop_currencies_panel', new coreshop.currency.panel());
        }
    },

    openExchangeRateResource: function () {
        try {
            opendxp.globalmanager.get('coreshop_exchange_rates_panel').activate();
        }
        catch (e) {
            opendxp.globalmanager.add('coreshop_exchange_rates_panel', new coreshop.exchange_rate.panel());
        }
    }
});

coreshop.broker.addListener('opendxp.ready', function() {
    new coreshop.currency.resource();
});
