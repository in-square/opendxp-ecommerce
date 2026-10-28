opendxp.registerNS('coreshop.opendxp.plugin.grid');
coreshop.opendxp.plugin.grid = Class.create({

    openerCallback: null,
    allowedClasses: [],
    actionStore: null,
    type: null,
    gridPaginator: null,

    initialize: function (type, openerCallback, allowedClasses, gridPaginator) {
        this.type = type;
        this.openerCallback = openerCallback;
        this.allowedClasses = allowedClasses;
        this.gridPaginator = gridPaginator;
        this.actionStore = this.getActionStore();

        document.addEventListener(opendxp.events.prepareOnRowContextmenu, this.prepareOnRowContextmenu.bind(this));
    },

    getActionStore: function () {
        var actionStore = new Ext.data.Store({
            restful: false,
            proxy: new Ext.data.HttpProxy({
                url: Routing.generate('coreshop_opendxp_grid_get_actions', {listType: this.type}),
            }),
            reader: new Ext.data.JsonReader({}, [
                {name: 'id'},
                {name: 'name'}
            ])
        });

        actionStore.load();

        return actionStore;
    },

    prepareOnRowContextmenu: function (event) {
        var menu = event.detail.menu;
        var grid = event.detail.grid;
        var selectedRows = event.detail.selectedRows;

        var extraParams = grid.getStore().getProxy().getExtraParams(),
            _ = this;

        if (!extraParams || !extraParams['class']) {
            return;
        }

        if (!Ext.Array.contains(this.allowedClasses, extraParams['class'])) {
            return;
        }

        menu.removeAll(true);

        if (selectedRows.length <= 1) {
            menu.add(new Ext.menu.Item({
                text: t('open'),
                iconCls: 'opendxp_icon_open',
                handler: function (grid, menu) {
                    var data = selectedRows[0];
                    if (data && data.data) {
                        this.openerCallback(data.data.id);
                    }
                }.bind(this, grid, menu)
            }));
        } else {
            menu.add(new Ext.menu.Item({
                text: t('open_selected'),
                iconCls: 'opendxp_icon_open',
                handler: function () {
                    for (var i = 0; i < selectedRows.length; i++) {
                        this.openerCallback(selectedRows[i].data.id);
                    }
                }.bind(this)
            }));
        }

        if (this.actionStore !== undefined) {

            var addActionsToMenu = function () {
                var actionItems = [];
                this.actionStore.each(function (rec) {
                    actionItems.push({
                        text: rec.get('name'),
                        iconCls: 'opendxp_icon_table',
                        name: rec.get('id'),
                        handler: function (item) {
                            this.applyAction(grid, item.name, selectedRows)
                        }.bind(this)
                    });
                }.bind(this));

                if (actionItems.length > 0) {
                    menu.add({
                        text: t('coreshop_order_list_action') + ' (' + selectedRows.length + ' ' + t(selectedRows.length === 1 ? 'item' : 'items') + ')',
                        iconCls: 'opendxp_icon_table opendxp_icon_overlay_go',
                        hideOnClick: false,
                        menu: actionItems
                    });
                }
            }.bind(this);

            if (this.actionStore.isLoading() || !this.actionStore.isLoaded()) {
                this.actionStore.on('load', function () {
                    addActionsToMenu();
                }.bind(this));
            } else {
                addActionsToMenu();
            }
        }
    },

    applyAction: function (grid, actionId, selectedRows) {

        var selectedObjects = [];
        for (var i = 0; i < selectedRows.length; i++) {
            selectedObjects.push(selectedRows[i].id)
        }

        grid.setLoading(t('loading'));

        Ext.Ajax.request({
            url: Routing.generate('coreshop_opendxp_grid_apply_action'),
            method: 'post',
            params: {
                actionId: actionId,
                ids: Ext.encode(selectedObjects)
            },
            success: function (response) {
                grid.setLoading(false);
                var res = Ext.decode(response.responseText);
                this.showMessageWindow(res.success ? 'success' : 'error', res.message);
            }.bind(this),
            failure: function (response) {
                grid.setLoading(false);
                // do nothing: opendxp will throw a error window.
            }.bind(this)
        });

    },

    showMessageWindow: function (type, message) {
        var win = new Ext.Window({
            modal: true,
            iconCls: 'opendxp_icon_' + type,
            title: t('coreshop_order_list_action_review'),
            width: 700,
            maxHeight: 500,
            html: message,
            autoScroll: true,
            bodyStyle: 'padding: 10px; background:#fff;',
            buttonAlign: 'center',
            shadow: false,
            closable: false,
            buttons: [{
                text: t('OK'),
                handler: function () {
                    this.gridPaginator.moveFirst();
                    win.close();
                }.bind(this)
            }]
        });

        win.show();
    }

});
