/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) 2009-2013 pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

pimcore.registerNS("pimcore.object.tags.advancedManyToManyObjectRelation");
pimcore.object.tags.advancedManyToManyObjectRelation = Class.create(pimcore.object.tags.manyToManyObjectRelation, {

    type: "advancedManyToManyObjectRelation",
    dataChanged: false,
    idProperty: "rowId",
    pathProperty: "fullpath",
    allowBatchAppend: true,
    allowBatchRemove: true,

    initialize: function (data, fieldConfig) {
        this.data = [];
        this.fieldConfig = fieldConfig;

        var classStore = pimcore.globalmanager.get("object_types_store");
        var classIdx = classStore.findExact("text", fieldConfig.allowedClassId);
        var classNameText;
        if (classIdx >= 0) {
            var classRecord = classStore.getAt(classIdx);
            classNameText = classRecord.data.text;
        } else {
            classNameText = "";
        }

        this.fieldConfig.classes = [{classes: classNameText, id: fieldConfig.allowedClassId}];

        if (data) {
            this.data = data;
        }

        var fields = [];
        var visibleFields = Ext.isString(this.fieldConfig.visibleFields) ? this.fieldConfig.visibleFields.split(",") : [];
        this.visibleFields = visibleFields;

        fields.push("id");
        fields.push("index");
        fields.push("inheritedFields");
        fields.push("metadata");

        var i;

        for (i = 0; i < visibleFields.length; i++) {
            fields.push(visibleFields[i]);
        }

        for (i = 0; i < this.fieldConfig.columns.length; i++) {
            fields.push(this.fieldConfig.columns[i].key);
        }

        var modelName = 'ObjectsMultipleRelations';
        if (!Ext.ClassManager.isCreated(modelName)) {
            Ext.define(modelName, {
                extend: 'Ext.data.Model',
                idProperty: this.idProperty,
                fields: fields
            });
        }

        let storeConfig = {
            data: this.data,
            listeners: {
                add: function () {
                    this.dataChanged = true
                }.bind(this),
                remove: function () {
                    this.dataChanged = true
                }.bind(this),
                clear: function () {
                    this.dataChanged = true
                }.bind(this),
                update: function (store) {
                    if (store.ignoreDataChanged) {
                        return
                    }
                    this.dataChanged = true
                }.bind(this)
            },
            model: modelName
        }

        if (this.fieldConfig.displayMode === 'combo') {
            storeConfig.proxy = {
                type: 'ajax',
                url: Routing.generate('pimcore_admin_dataobject_dataobject_relation_objects_list'),
                extraParams: {
                    fieldConfig: JSON.stringify(this.fieldConfig),
                    data: this.data.map(function(element) {
                        return element.id;
                    }).join(','),
                },
                reader: {
                    type: 'json',
                    rootProperty: 'options',
                    successProperty: 'success',
                    messageProperty: 'message'
                }
            };
            storeConfig.fields = ['id', 'label'];
            storeConfig.autoLoad = true;
            storeConfig.listeners = {
                beforeload: function(store) {
                    store.getProxy().setExtraParam('unsavedChanges', this.object ? this.object.getSaveData().data : {});
                    store.getProxy().setExtraParam('context', JSON.stringify(this.getContext()));
                }.bind(this)
            };
        }

        this.store = new Ext.data.JsonStore(storeConfig);
    },

    createLayout: function (readOnly) {
        var autoHeight = false;
        if (!this.fieldConfig.height) {
            autoHeight = true;
        }

        if (this.fieldConfig.displayMode === 'combo') {
            this.component = Ext.create('Ext.form.field.Tag', {
                store: this.store,
                autoLoadOnValue: true,
                height: 'auto',
                width: '100%',
                value: this.data.map(function(item) {
                    return item.id;
                }),
                typeAhead: true,
                minChars: 3,
                filterPickList: true,
                triggerAction: "all",
                displayField: "label",
                valueField: "id",
                fieldLabel: this.fieldConfig.title,
                tpl: new Ext.XTemplate(
                    '<tpl for="."><li role="option" unselectable="on" class="x-boundlist-item" data-recordid="{id}" style="display:flex;">',
                    '  {label}',
                    '</li></tpl>'
                ),
                listeners: {
                    change: function() {
                        this.dataChanged = true;
                    }.bind(this),
                    focus: function() {
                        this.store.getProxy().setExtraParam('data', '');
                    }.bind(this)
                }
            });
        } else {
            let visibleFields = this.visibleFields || [];

            let columns = [];

            if (visibleFields.length === 0) {
                columns.push(
                    {text: 'ID', dataIndex: 'id', width: 50},
                    {
                        text: t("reference"),
                        dataIndex: 'fullpath',
                        flex: 200,
                        renderer: this.fullPathRenderCheck.bind(this)
                    }
                );
            }

            for (let i = 0; i < visibleFields.length; i++) {
                if (!empty(this.fieldConfig.visibleFieldDefinitions) && !empty(visibleFields[i])) {
                    let layout = this.fieldConfig.visibleFieldDefinitions[visibleFields[i]];

                    let field = {
                        key: visibleFields[i],
                        label: layout.title == "fullpath" ? t("reference") : layout.title,
                        layout: layout,
                        position: i,
                        type: layout.fieldtype
                    };

                    let fc = pimcore.object.tags[layout.fieldtype].prototype.getGridColumnConfig(field);

                    fc.flex = 1;
                    fc.hidden = false;
                    fc.layout = field;
                    fc.editor = null;
                    fc.sortable = false;

                    if (fc.layout.key === "fullpath") {
                        fc.renderer = this.fullPathRenderCheck.bind(this);
                    } else if (fc.layout.layout.fieldtype == 'select'
                    || fc.layout.layout.fieldtype == 'multiselect'
                    || fc.layout.layout.fieldtype == 'booleanSelect') {
                        fc.layout.layout.options.forEach(option => {
                            option.key = t(option.key);
                        });
                    }

                    columns.push(fc);
                }
            }

            for (i = 0; i < this.fieldConfig.columns.length; i++) {
                let width = 100;
                if (this.fieldConfig.columns[i].width) {
                    width = this.fieldConfig.columns[i].width;
                }

                let cellEditor = null;
                let renderer = null;
                let listeners = null;

                if (this.fieldConfig.columns[i].type == "number" && !readOnly) {
                cellEditor = function() {
                        return new Ext.form.NumberField({});
                    }.bind();
                } else if (this.fieldConfig.columns[i].type == "text" && !readOnly) {
                cellEditor = function() {
                        return new Ext.form.TextField({});
                    };
                } else if (this.fieldConfig.columns[i].type == "select") {
                    if(!readOnly) {
                        var selectData = [];

                        if (this.fieldConfig.columns[i].value) {
                            var selectDataRaw = this.fieldConfig.columns[i].value.split(";");

                            for (var j = 0; j < selectDataRaw.length; j++) {
                                selectData.push([selectDataRaw[j], t(selectDataRaw[j])]);
                            }
                        }

                        cellEditor = function(selectData) {
                            return new Ext.form.ComboBox({
                                typeAhead: true,
                                queryDelay: 0,
                                queryMode: "local",
                                forceSelection: true,
                                triggerAction: 'all',
                                lazyRender: false,
                                mode: 'local',

                                store: new Ext.data.ArrayStore({
                                    fields: [
                                        'value',
                                        'label'
                                    ],
                                    data: selectData
                                }),
                                valueField: 'value',
                                displayField: 'label'
                            });
                        }.bind(this, selectData);
                    }

                    renderer = function (value, metaData, record, rowIndex, colIndex, store) {
                        return t(value);
                    }
                } else if (this.fieldConfig.columns[i].type == "multiselect") {
                    if (!readOnly) {
                        cellEditor = function (fieldInfo) {
                            return new pimcore.object.helpers.metadataMultiselectEditor({
                                fieldInfo: fieldInfo
                            });
                        }.bind(this, this.fieldConfig.columns[i]);
                    }

                    renderer = function (value, metaData, record, rowIndex, colIndex, store) {
                        if (Ext.isString(value)) {
                            value = value.split(',');
                        }

                        if (Ext.isArray(value)) {
                            return value.map(function (str) {
                                return t(str);
                            }).join(',')
                        } else {
                            return value;
                        }
                    }
                } else if (this.fieldConfig.columns[i].type == "bool") {
                    renderer = function (value, metaData, record, rowIndex, colIndex, store) {
                        if (this.fieldConfig.noteditable) {
                            metaData.tdCls += ' grid_cbx_noteditable';
                        }

                        return Ext.String.format('<div style="text-align: center"><div role="button" class="x-grid-checkcolumn {0}" style=""></div></div>', value ? 'x-grid-checkcolumn-checked' : '');
                    }.bind(this);

                    listeners = {
                        "mousedown": this.cellMousedown.bind(this, this.fieldConfig.columns[i].key, this.fieldConfig.columns[i].type)
                    };

                    if (readOnly) {
                        columns.push(Ext.create('Ext.grid.column.Check', {
                            text: t(this.fieldConfig.columns[i].label),
                            dataIndex: this.fieldConfig.columns[i].key,
                            width: width,
                            renderer: renderer
                        }));
                        continue;
                    }
                }

                var columnConfig = {
                    text: t(this.fieldConfig.columns[i].label),
                    dataIndex: this.fieldConfig.columns[i].key,
                    renderer: renderer,
                    listeners: listeners,
                    width: width
                };

                if (cellEditor) {
                    columnConfig.getEditor = cellEditor;
                }

                columns.push(columnConfig);
            }


            if (!readOnly) {
                columns.push({
                    xtype: 'actioncolumn',
                    menuText: t('up'),
                    width: 40,
                    hideable: false,
                    items: [
                        {
                            tooltip: t('up'),
                            icon: "/bundles/pimcoreadmin/img/flat-color-icons/up.svg",
                            handler: function (grid, rowIndex) {
                                if (rowIndex > 0) {
                                    var rec = grid.getStore().getAt(rowIndex);
                                    grid.getStore().removeAt(rowIndex);
                                    grid.getStore().insert(rowIndex - 1, [rec]);
                                }
                            }.bind(this)
                        }
                    ]
                });
                columns.push({
                    xtype: 'actioncolumn',
                    menuText: t('down'),
                    width: 40,
                    hideable: false,
                    items: [
                        {
                            tooltip: t('down'),
                            icon: "/bundles/pimcoreadmin/img/flat-color-icons/down.svg",
                            handler: function (grid, rowIndex) {
                                if (rowIndex < (grid.getStore().getCount() - 1)) {
                                    var rec = grid.getStore().getAt(rowIndex);
                                    grid.getStore().removeAt(rowIndex);
                                    grid.getStore().insert(rowIndex + 1, [rec]);
                                }
                            }.bind(this)
                        }
                    ]
                });
            }

            columns.push({
                xtype: 'actioncolumn',
                menuText: t('open'),
                width: 40,
                hideable: false,
                items: [
                    {
                        tooltip: t('open'),
                        icon: "/bundles/pimcoreadmin/img/flat-color-icons/open_file.svg",
                        handler: function (grid, rowIndex) {
                            var data = grid.getStore().getAt(rowIndex);
                            pimcore.helpers.openObject(data.data.id, "object");
                        }.bind(this)
                    }
                ]
            });

        if (!readOnly) {
            columns.push({
                xtype: 'actioncolumn',
                menuText: t('remove'),
                width: 40,
                hideable: false,
                items: [
                    {
                        tooltip: t('remove'),
                        icon: "/bundles/pimcoreadmin/img/flat-color-icons/delete.svg",
                        handler: function (grid, rowIndex) {
                            let data = grid.getStore().getAt(rowIndex);
                            pimcore.helpers.deleteConfirm(t('relation'), data.data.path, function () {
                                grid.getStore().removeAt(rowIndex);
                            }.bind(this));
                        }.bind(this)
                    }
                ]
            });
        }

            let toolbarItems = this.getEditToolbarItems(readOnly);

            this.cellEditing = Ext.create('Ext.grid.plugin.CellEditing', {
                clicksToEdit: 1,
                listeners: {
                    beforeedit: function (editor, context, eOpts) {
                        editor.editors.each(function (e) {
                            try {
                                // complete edit, so the value is stored when hopping around with TAB
                                e.completeEdit();
                                Ext.destroy(e);
                            } catch (exception) {
                                // garbage collector was faster
                                // already destroyed
                            }
                        });

                        editor.editors.clear();
                    }
                }
            });


            this.component = Ext.create('Ext.grid.Panel', {
                store: this.store,
                border: true,
                style: "margin-bottom: 10px",
                enableDragDrop: true,
                ddGroup: 'element',
                trackMouseOver: true,
                selModel: {
                    selType: (this.fieldConfig.enableBatchEdit ? 'checkboxmodel' : 'rowmodel')
                },
                multiSelect: true,
                columnLines: true,
                stripeRows: true,
                columns: {
                    defaults: {
                        sortable: false
                    },
                    items: columns
                },
                viewConfig: {
                    plugins: {
                        ptype: 'gridviewdragdrop',
                        draggroup: 'element'
                    },
                    markDirty: false,
                    enableTextSelection: this.fieldConfig.enableTextSelection,
                    listeners: {
                        afterrender: function (gridview) {
                            this.requestNicePathData(this.store.data, true);
                        }.bind(this),
                        drop: function () {
                            this.dataChanged = true;

                            // this is necessary to avoid endless recursion when long lists are sorted via d&d
                            // TODO: investigate if there this is already fixed 6.2
                            if (this.object.toolbar && this.object.toolbar.items && this.object.toolbar.items.items) {
                                this.object.toolbar.items.items[0].focus();
                            }
                        }.bind(this),
                        // see https://github.com/pimcore/pimcore/issues/979
                        // probably a ExtJS 6.0 bug. without this, dropdowns not working anymore if plugin is enabled
                        // TODO: investigate if there this is already fixed 6.2
                        cellmousedown: function (element, td, cellIndex, record, tr, rowIndex, e, eOpts) {
                            if (this.fieldConfig.noteditable == true || cellIndex >= visibleFields.length) {
                                return false;
                            } else {
                                return true;
                            }
                        }.bind(this)
                    }
                },
                componentCls: this.getWrapperClassNames(),
                width: this.fieldConfig.width,
                height: this.fieldConfig.height,
                tbar: {
                    items: toolbarItems,
                    ctCls: "pimcore_force_auto_width",
                    cls: "pimcore_force_auto_width",
                    minHeight: 32
                },
                autoHeight: autoHeight,
                bodyCls: "pimcore_object_tag_objects pimcore_editable_grid",
                plugins: [
                    this.cellEditing
                ]
            });

            if (!readOnly) {
                this.component.on("rowcontextmenu", this.onRowContextmenu);
            }

            this.component.reference = this;

            if (!readOnly) {
                this.component.on("afterrender", function () {

                    var dropTargetEl = this.component.getEl();
                    var gridDropTarget = new Ext.dd.DropZone(dropTargetEl, {
                        ddGroup: 'element',
                        getTargetFromEvent: function (e) {
                            return this.component.getEl().dom;
                            //return e.getTarget(this.grid.getView().rowSelector);
                        }.bind(this),

                        onNodeOver: function (overHtmlNode, ddSource, e, data) {
                            var returnValue = Ext.dd.DropZone.prototype.dropAllowed;
                            data.records.forEach(function (record) {
                                var fromTree = this.isFromTree(ddSource);
                                if (!this.dndAllowed(record.data, fromTree)) {
                                    returnValue = Ext.dd.DropZone.prototype.dropNotAllowed;
                                }
                            }.bind(this));

                            return returnValue;
                        }.bind(this),

                        onNodeDrop: function (target, dd, e, data) {

                            this.nodeElement = data;
                            var fromTree = this.isFromTree(dd);
                            var toBeRequested = new Ext.util.Collection();

                            data.records.forEach(function (record) {
                                var data = record.data;
                                if (this.dndAllowed(data, fromTree)) {
                                    if (data["grid"] && data["grid"] == this.component) {
                                        var rowIndex = this.component.getView().findRowIndex(e.target);
                                        if (rowIndex !== false) {
                                            var rec = this.store.getAt(data.rowIndex);
                                            this.store.removeAt(data.rowIndex);
                                            toBeRequested.add(this.store.insert(rowIndex, [rec]));
                                            this.requestNicePathData(toBeRequested);
                                        }
                                    } else {
                                        var initData = {
                                            id: data.id,
                                            metadata: '',
                                            fullpath: data.path,
                                            inheritedFields: {}
                                        };

                                        if (this.fieldConfig.allowMultipleAssignments || !this.objectAlreadyExists(initData.id)) {
                                            toBeRequested.add(this.loadObjectData(initData, this.visibleFields));
                                        }
                                    }
                                }
                            }.bind(this));

                            if (toBeRequested.length) {
                                this.requestNicePathData(toBeRequested);
                                return true;
                            }

                            return false;

                        }.bind(this)
                    });

                    if (this.fieldConfig.enableBatchEdit) {
                        let grid = this.component;
                        let menu = grid.headerCt.getMenu();

                        let batchAllMenu = new Ext.menu.Item({
                            text: t("batch_change"),
                            iconCls: "pimcore_icon_table pimcore_icon_overlay_go",
                            handler: function (grid) {
                                var columnDataIndex = menu.activeHeader;
                                this.batchPrepare(columnDataIndex, grid, false, false);
                            }.bind(this, grid)
                        });

                        menu.add(batchAllMenu);

                        let batchSelectedMenu = new Ext.menu.Item({
                            text: t("batch_change_selected"),
                            iconCls: "pimcore_icon_structuredTable pimcore_icon_overlay_go",
                            handler: function (grid) {
                                menu = grid.headerCt.getMenu();
                                var columnDataIndex = menu.activeHeader;
                                this.batchPrepare(columnDataIndex, grid, true, false);
                            }.bind(this, grid)
                        });
                        menu.add(batchSelectedMenu);
                        menu.on('beforeshow', function (batchAllMenu, batchSelectedMenu, grid) {
                            let menu = grid.headerCt.getMenu();
                            let columnDataIndex = menu.activeHeader.dataIndex;
                            let metaIndex = this.fieldConfig.columnKeys.indexOf(columnDataIndex);

                            if (metaIndex < 0) {
                                batchSelectedMenu.hide();
                                batchAllMenu.hide();
                            } else {
                                batchSelectedMenu.show();
                                batchAllMenu.show();
                            }

                        }.bind(this, batchAllMenu, batchSelectedMenu, grid));
                    }
                }.bind(this));
            }
        }

        return this.component;
    },

    getLayoutEdit: function () {
        return this.createLayout(false);
    },

    getLayoutShow: function () {
        return this.createLayout(true);
    },

    dndAllowed: function (data, fromTree) {
        // check if data is a treenode, if not allow drop because of the reordering
        if (!fromTree) {
            if (data["grid"] && data["grid"] == this.component) {
                return true;
            }
            return false;
        }

        // only allow objects not folders
        if (data.type == "folder" || data.elementType != "object") {
            return false;
        }

        var classname = data.className;

        var classStore = pimcore.globalmanager.get("object_types_store");
        var classRecord = classStore.getAt(classStore.findExact("text", classname));
        var isAllowedClass = false;

        if (classRecord) {
            if (this.fieldConfig.allowedClassId == classRecord.data.text) {
                isAllowedClass = true;
            }
        }
        return isAllowedClass;
    },

    cellMousedown: function (key, colType, grid, cell, rowIndex, cellIndex, e) {

        // this is used for the boolean field type

        var store = grid.getStore();
        var record = store.getAt(rowIndex);

        if (colType == "bool") {
            record.set(key, !record.data[key]);
        }
    },

    getGridColumnConfig: function (field) {
        return {
            text: t(field.label), width: 150, sortable: false, dataIndex: field.key,
            getEditor: this.getWindowCellEditor.bind(this, field),
            getRelationFilter: this.getRelationFilter,
            renderer: pimcore.object.helpers.grid.prototype.advancedRelationGridRenderer.bind(this, field, "fullpath")
        };
    },


    getCellEditValue: function () {
        return this.getValue();
    },

});
