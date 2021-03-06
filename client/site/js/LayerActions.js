/*
 *
 * LayerActions.js -- part of Extended QGIS Web Client
 *
 * Copyright (2010-2015), The QGIS Project and Level2 team All rights reserved.
 * More information at https://github.com/uprel/gisapp
 *
 */

/* global projectData */

function buildLayerContextMenu(node) {

    // prepare the generic context menu for Layer
    var menuCfg = {
        //id: 'layerContextMenu',
        items: [{
            text: contextZoomLayerExtent[lang],
            iconCls: 'x-zoom-icon',
            handler: zoomToLayerExtent
        },{
            itemId: 'contextOpenTable',
            text: contextOpenTable[lang],
            iconCls: 'x-table-icon',
            handler: openAttTable
        },{
            text: contextDataExport[lang],
            iconCls: 'x-export-icon',
            menu: [{
                itemId	: 'SHP',
                text    : 'ESRI Shapefile',
                handler : exportHandler
            },{
                itemId	: 'DXF',
                text    : 'AutoCAD DXF',
                handler : exportHandler
            },{
                itemId	: 'CSV',
                text    : 'Text CSV',
                handler : exportHandler
            }
                ,"-",
                {
                    itemId  : 'currentExtent',
                    text    : contextUseExtent[lang],
                    checked : true,
                    hideOnClick: false
                }]
        }]
    };

    //storefilter
    var filter=[];

    // add same specific menus if exists
    if(projectData.layerSpecifics != null) {
        var layerSpecifics = projectData.layerSpecifics;
        var j = 0;
        for (var i = 0; i < layerSpecifics.storedFilters.length; i++) {
            if (layerSpecifics.storedFilters[i].layer == node.text) {
                j++;
                if (j == 1) {
                    menuCfg.items.push({
                        itemId: "mapFilter",
                        text: layerSpecifics.storedFilters[i].menuTitle,
                        checked: false,
                        hideOnClick: true,
                        menu: [],
                        getFilter: function(){
                            var value=null;
                            this.menu.cascade(function(i){ if(i.checked){
                                value=i.value;
                            } });
                            return value;
                        },
                        listeners: {
                            checkchange: function() {
                                if(!this.checked) {
                                    thematicLayer.params["FILTER"] = "";
                                    thematicLayer.redraw();
                                    this.menu.cascade(function(item) {
                                        if (item.checked) {
                                            item.setChecked(false);
                                        }
                                    })
                                }
                                var t = Ext.getCmp('table_'+node.text);
                                if(typeof t == 'object') {
                                    t.destroy();
                                }
                            }
                        }

                    });
                }
                menuCfg.items[menuCfg.items.length - 1].menu.push({
                    itemId: 'storedFilter_' + j,
                    text: layerSpecifics.storedFilters[i].title,
                    value: layerSpecifics.storedFilters[i].filterValue,
                    checked: false,
                    group: "storedFilters",
                    handler: applyWMSFilter,
                    listeners: {
                        checkchange: function() {
                            if(this.checked) {
                                var m = this.parentMenu.parentMenu.getComponent('mapFilter');
                                m.setChecked(true);
                            }
                            var t = Ext.getCmp('table_'+node.text);
                            if(typeof t == 'object') {
                                t.destroy();
                            }
                        }
                    }
                });

                filter.push({
                    text: layerSpecifics.storedFilters[i].title,
                    value: layerSpecifics.storedFilters[i].filterValue
                });
            }
        }
    }
    node.menu = new Ext.menu.Menu(menuCfg);
    node.filter = filter;
}

function zoomToLayerExtent(item) {
    var myLayerName = layerTree.getSelectionModel().getSelectedNode().text;
    var layerId = wmsLoader.layerTitleNameMapping[myLayerName];
    var bbox = new OpenLayers.Bounds(wmsLoader.layerProperties[layerId].bbox).transform('EPSG:4326', geoExtMap.map.projection);
    geoExtMap.map.zoomToExtent(bbox);
}

function exportHandler(item) {
    var myLayerName = layerTree.getSelectionModel().getSelectedNode().text;
    var myFormat = item.container.menuItemId;

    var exportExtent = item.ownerCt.getComponent('currentExtent');

    if(exportExtent.checked==false) {
        Ext.Msg.alert ('Error','Sorry, currently exporting only with map extent. Try again!');
        exportExtent.setChecked(true);
    } else {
        exportData(myLayerName, myFormat);
    }
}

// Show the menu on right click of the leaf node of the layerTree object
function contextMenuHandler(node) {

    var layerId = wmsLoader.layerTitleNameMapping[node.attributes.text];
    var layer = wmsLoader.layerProperties[layerId];

    //disable option for opentable if layer is not queryableor layer has no attributes (WMS)
    //var contTable = Ext.getCmp('contextOpenTable');
    var contTable = node.menu.getComponent('contextOpenTable');
    if (layer.queryable && typeof(layer.attributes) !== 'undefined')
        contTable.setDisabled(false);
    else
        contTable.setDisabled(true);

    node.select();
    node.menu.show ( node.ui.getAnchor());
}

function zoomHandler(grid, rowIndex, colIndex, item, e) {
    var store = grid.getStore();
    var record = store.getAt(rowIndex);
    var recId = record.id;
    var selectedLayer = grid.itemId;

    grid.getSelectionModel().selectRow(rowIndex);

    //add fields as it would be from search results
    //fix bbox
    var bbox = record.data.bbox;
    record.data.layer = selectedLayer;
    record.data.doZoomToExtent = true;
    record.data.id= recId;
    record.data.bbox = OpenLayers.Bounds.fromArray( [bbox.minx, bbox.miny, bbox.maxx, bbox.maxy] );

    showFeatureSelected(record.data);
}

function exportData(layer,format) {

    //current view is used as bounding box for exporting data
    var bbox = geoExtMap.map.calculateBounds();
    //Ext.Msg.alert('Info',layer+' ' + bbox);

    var exportUrl = "./admin/export.php?" + Ext.urlEncode({
            map:projectData.project,
            SRS:authid,
            map0_extent:bbox,
            layer:layer,
            format:format
        });

    Ext.Ajax.request({
        url: exportUrl,
        disableCaching : false,
        params: {
          cmd: 'prepare'
        },
        method: 'GET',
        success: function (response) {

            var result = Ext.util.JSON.decode(response.responseText);

            if(result.success) {
                var key = result.message;
                var body = Ext.getBody();
                var frame = body.createChild({
                    tag: 'iframe',
                    cls: 'x-hidden',
                    id: 'hiddenform-iframe',
                    name: 'iframe',
                    src: exportUrl + "&cmd=get&key="+key
                });
            }
            else {
                Ext.Msg.alert("Error",result.message);
            }
        },
        //this doesn't fire, why?
        failure: function(response, opts) {
            Ext.Msg.alert('Error','server-side failure with status code ' + response.status);
        }
    });



}

function openAttTable() {
    var node = layerTree.getSelectionModel().getSelectedNode();
    var myLayerName = node.text;
    var layerId = wmsLoader.layerTitleNameMapping[myLayerName];
    var editable = projectData.use_ids ? projectData.layers[layerId].wfs : false;
    var filter = null;
    var name = myLayerName;

    var m = this.parentMenu.getComponent('mapFilter');
    if (m) {
        filter = m.getFilter();
    }

    name = myLayerName;// + filter;¸

    var layer = new QGIS.SearchPanel({
        useWmsRequest: true,
        wmsFilter: filter,
        queryLayer: myLayerName,
        gridColumns: getLayerAttributes(myLayerName).columns,
        gridLocation: 'bottom',
        gridEditable: editable,
        gridTitle: name,
        gridResults: 2000,
        gridResultsPageSize: 20,
        selectionLayer: myLayerName,
        formItems: [],
        doZoomToExtent: true
    });

    //Ext.getCmp('BottomPanel').setTitle(layer.gridTitle,'x-cols-icon');
    //Ext.get('BottomPanel').setStyle('padding-top', '2px');

    layer.onSubmit();

    //layer.on("featureselected", showFeatureSelected);
    layer.on("featureselectioncleared", clearFeatureSelected);
    layer.on("beforesearchdataloaded", showSearchPanelResults);

}

function clearTableSelection() {
    var selmod = this.ownerCt.ownerCt.getSelectionModel();
    selmod.clearSelections();

    //clears selection in map
    clearFeatureSelected();
}

function applyWMSFilter(item) {
    var idx = item.itemId.split('_')[1]-1;
    var node = layerTree.getSelectionModel().getSelectedNode();
    var filter = node.filter[idx].value;

    thematicLayer.params["FILTER"] = node.text+":"+filter;
    thematicLayer.redraw();

}

/**
 *
 * @param layer
 * @returns {{}}
 */
function getLayerAttributes(layer) {

    var layerId = wmsLoader.layerTitleNameMapping[layer];
    var ret = {};
    ret.columns = [];
    ret.fields = [];

    for (var i=0;i<wmsLoader.layerProperties[layerId].attributes.length;i++) {
        ret.columns[i] = {};
        //ret.fields[i] = {};
        var attribute = wmsLoader.layerProperties[layerId].attributes[i];
        var fieldType = attribute.type;
        if(fieldType=='int' || fieldType=='date' || fieldType=='boolean') {
            ret.fields.push({name: attribute.name,type:fieldType});
        }
        else {
            if (fieldType == 'double') {
                ret.fields.push({name: attribute.name, type: 'float'});
            } else {
                ret.fields.push({name: attribute.name, type: 'string'});
            }
        }

        ret.columns[i].header = attribute.name;
        ret.columns[i].dataIndex = attribute.name;
        ret.columns[i].menuDisabled = false;
        ret.columns[i].sortable = true;
        ret.columns[i].filterable = true;
        if(attribute.type=='double') {
            ret.columns[i].xtype = 'numbercolumn';
            ret.columns[i].format = '0.000,00/i';
            ret.columns[i].align = 'right';
            //no effect
            //ret[i].style = 'text-align:left'
        }
        if(attribute.type=='int') {
            ret.columns[i].xtype = 'numbercolumn';
            ret.columns[i].format = '000';
            ret.columns[i].align = 'right';
        }
    }

    var actionColumn = getActionColumns(layerId);
    if(actionColumn!=null) {
        ret.columns.unshift(actionColumn);
    }

    ret.columns.unshift(new Ext.ux.grid.RowNumberer({width: 32}));

    return ret;
}

function getActionColumns(layerId) {

    var action = new Ext.grid.ActionColumn({
        width: 22,
        items: [{
            icon: iconDirectory + "contextmenu/zoom.png",
            tooltip: TR.show,
            disabled: false,
            handler: zoomHandler
        }]
    });

    return action;
}