<?php

/**
 * class.Helpers.php -- part of Server side of Extended QGIS Web Client
 *
 * Copyright (2014-2015), Level2 team All rights reserved.
 *
 * Portions of code from QGIS-WEB-CLIENT - PHP HELPERS
 *
 * More information at https://github.com/uprel/gisapp
 */

namespace GisApp;

use SimpleXMLElement;

class Helpers
{

    public $qgs_layers = [];

    public static function isValidUserProj($project)
    {
        $valid = isset($_SESSION['user_is_logged_in']);

        if (($valid === true) && ($project !== null)) {
            if ($project !== $_SESSION['project']) {
                $valid = false;
                $_SESSION['project'] = $project;
                $_SESSION['user_is_logged_in'] = null;
            }
        }
        return $valid;
    }

    public static function validateExportParams($params)
    {
        if (isset($params['map0_extent'])) {
            $extent = explode(",", $params['map0_extent']);
            $xmin = $extent[0];
            $ymin = $extent[1];
            $xmax = $extent[2];
            $ymax = $extent[3];

            if (!(is_numeric($xmin) && is_numeric($ymin) && is_numeric($xmax) && is_numeric($xmin) && is_numeric($ymax))) {
                return "SQL injection prevention : bad extent";
            }

        } else {
            return "You must provide a valid bounding box";
        }

        if (isset($params['SRS'])) {
            $srid = substr(strrchr($params['SRS'], ':'), 1);

            if (!is_numeric($srid)) {
                return "SQL injection prevention : bad srid";
            }

        } else {
            return "No SRS!";
        }

        if (!(isset($params['format']))) {
            return "No format";
        }

        if (!(isset($params['layer']))) {
            return "No layer";
        }

        if (!(isset($params['map']))) {
            return "No map";
        }

        if (!(isset($params['cmd']))) {
            return "No cmd parameter";
        } else {
            if ($params["cmd"] == 'prepare' || $params["cmd"] == 'get') {
                //OK
            } else
                return "Unknown cmd parameter";
        }

        return 'OK';
    }

    public static function normalize($string)
    {
        $table = array(
            'š' => 's', 'ď' => 'd', 'đ' => 'dj', 'ž' => 'z', 'č' => 'c', 'ć' => 'c',
            'Þ' => 'b', 'ß' => 's', 'ĺ' => 'l', 'ľ' => 'l',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e',
            'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'o', 'ň' => 'n', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o',
            'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ť' => 't', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ý' => 'y', 'þ' => 'b',
            'ÿ' => 'y', 'ŕ' => 'r', '.' => ''
        );

        return strtr(strtolower($string), $table);
    }

    private function msg($status, $data)
    {
        return ["status" => $status, "message" => $data];
    }

    /**
     *
     * Load .qgs file
     *
     * @param $map
     * @return array
     */
    public static function getQgsProject($map)
    {
        libxml_clear_errors();
        libxml_use_internal_errors(true);
        if (file_exists($map) && is_readable($map)) {
            $project = simplexml_load_file($map);
            if (!$project) {
                return self::msg(false, 'Project not valid XML!');
            }
        } else {
            return self::msg(false, 'Project not found or no permission: '.$map);
        }
        return self::msg(true, $project);
    }

    public static function getQgsTimeStamp($map) {
        $time = 0;
        if (file_exists($map)) {
            $time = filemtime($map);
        }
        return $time;
    }

    public function getQgsProjectProperties($map)
    {
        $qgs = self::getQgsProject($map);
        $time = self::getQgsTimeStamp($map);
        $prop = new \stdClass();

        if (!($qgs["status"])) {
            //error in XML, using default CRS but continue
            $prop->crs = "EPSG:3857";
            $prop->proj4 = "";
            $prop->title = "";
            $prop->extent = [];
            $prop->layers = [];
            $prop->use_ids = false;
            $prop->time = $time;
            $prop->message = $qgs["message"];
            //return false;
        } else {
            $prop->crs = (string)$qgs["message"]->properties->SpatialRefSys->ProjectCrs;
            $prop->proj4 = (string)$qgs["message"]->properties->SpatialRefSys->ProjectCRSProj4String;
            $prop->title = (string)$qgs["message"]->title == "" ? basename($map, ".qgs") : (string)$qgs["message"]->title;
            $prop->extent = (array)($qgs["message"]->properties->WMSExtent->value);
            $prop->layers = [];
            //parsing boolean values, be careful (bool)"false" = true!!!
            $prop->use_ids = filter_var($qgs["message"]->properties->WMSUseLayerIDs,FILTER_VALIDATE_BOOLEAN);
            $prop->time = $time;
            try {

                $this->LayersToClientArray($qgs["message"]->xpath('layer-tree-group')[0],$prop->title,0);


                //get wfs layers
                $wfs = (array)($qgs["message"]->properties->WFSLayers->value);
                foreach($this->qgs_layers as $lay) {

                    $lay_object = self::getLayerById($lay->id,$qgs["message"]);
                    if($lay_object["status"]) {
                        $lay_info = self::getLayerInfo($lay_object["message"]);
                        if ($lay_info["status"]) {
                            $lay->provider = (string)$lay_info["message"]["provider"];
                            $lay->geom_type = (string)$lay_info["message"]["type"];
                            $lay->geom_column = (string)$lay_info["message"]["geom_column"];
                            $lay->crs = (string)$lay_info["message"]["crs"];
                        }
                    }

                    //enable wfs just for postgres and spatialite regardless project setting
                    if (in_array($lay->id,$wfs) and ($lay->provider == 'postgres' or $lay->provider == 'spatialite')) {
                        $lay->wfs = true;
                    }


                    $prop->layers[$lay->id] = $lay;
                }

            } catch (\Exception $e) {
                $prop->message = $e->getMessage();
            }


            //$prop->message = $qgs["status"];
        }

        return $prop;
    }

    /**
     *
     * Load a layer instance from the project
     *
     * @param $layername
     * @param SimpleXMLElement $project
     * @return array
     */
    public static function getLayer($layername, SimpleXMLElement $project)
    {
        // Caching
        static $layers = array();
        if (array_key_exists($layername, $layers)) {
            return self::msg(true, $layers[$layername]);
        }
        $xpath = '//maplayer/layername[.="' . $layername . '"]/parent::*';
        if (!$layer = $project->xpath($xpath)) {
            return self::msg(false, "layer not found");
        }
        $layers[$layername] = $layer[0];
        return self::msg(true, $layer[0]);
    }

    public static function getLayerById($id, SimpleXMLElement $project)
    {
        $xpath = '//maplayer/id[.="' . $id . '"]/parent::*';
        if (!$layer = $project->xpath($xpath)) {
            return self::msg(false, "layer not found");
        }
        return self::msg(true, $layer[0]);
    }

    /**
     *
     * Get layer connection and geom info
     *
     * @param SimpleXMLElement $layer
     * @return array
     */
    public static function getLayerInfo(SimpleXMLElement $layer)
    {
        // Cache
        static $pg_layer_infos = array();

        //if ((string)$layer->provider != 'postgres' && (string)$layer->provider != 'spatialite') {
        //    return self::msg(false, 'Only postgis or spatialite layers are supported!</br>' . (string)$layer->layername . ': ' . (string)$layer->provider);
        //}

        // Datasource
        $datasource = (string)$layer->datasource;

        if (array_key_exists($datasource, $pg_layer_infos)) {
            return self::msg(true, $pg_layer_infos[$datasource]);
        }

        // Parse datasource
        $ds_parms = array(
            'provider' => (string)$layer->provider,
            'type' => '',
            'geom_column' => '',
            'crs' => (string)$layer->srs->spatialrefsys->authid
        );

        //only for postgres and spatialite layers
        if ((string)$layer->provider == 'postgres' or (string)$layer->provider == 'spatialite') {


            // First extract sql=
            if (preg_match('/sql=(.*)/', $datasource, $matches)) {
                $datasource = str_replace($matches[0], '', $datasource);
                $ds_parms['sql'] = $matches[1];
            }
            foreach (explode(' ', $datasource) as $token) {
                $kvn = explode('=', $token);
                if (count($kvn) == 2) {
                    $ds_parms[$kvn[0]] = $kvn[1];
                } else { // Parse (geom)
                    if (preg_match('/\(([^\)]+)\)/', $kvn[0], $matches)) {
                        $ds_parms['geom_column'] = $matches[1];
                    }
                    // ... maybe other parms ...
                }
            }
            $pg_layer_infos[$datasource] = $ds_parms;
        }
        return self::msg(true, $ds_parms);
    }

    public static function getMapFromUrl()
    {
        $url = filter_input(INPUT_SERVER, "SCRIPT_URL", FILTER_SANITIZE_STRING);
        $ret = null;

        if (strpos($url, "/") !== false) {
            $tmp = explode("/", $url);
            $ret = end($tmp);
        }

        return $ret;

    }

    public function LayersToClientArray($group,$groupname,$cnt)
    {
        foreach ($group->children() as $el) {
            $type = $el->getName();
            $lay = new \stdClass();
            if ($type == 'layer-tree-group') {

                $this->LayersToClientArray($el,(string)$el->attributes()["name"],$cnt);

            } else {

                if ($el->attributes()["id"] > '') {
                    ++$cnt;
                    $lay->topic = 'Topic';
                    $lay->groupname = $groupname;
                    $lay->layername = (string)$el->attributes()["name"];
                    $lay->toclayertitle = (string)$el->attributes()["name"];
                    $lay->visini = (string)$el->attributes()["checked"] == 'Qt::Checked' ? true : false;
                    $lay->id = (string)$el->attributes()["id"];
                    $lay->wms_sort = (900-$cnt);
                    $lay->toc_sort = $cnt;
                    $lay->wfs = false;      //fill later
                    $lay->provider = '';    //fill later
                    $lay->geom_type = '';   //fill later
                    $lay->geom_column = ''; //fill later
                    $lay->crs = ''; //fill later

                    array_push($this->qgs_layers, $lay);
                }
            }
        }
    }

    public static function checkModulexist($name) {
        $dir = dirname(dirname(__FILE__)) . "/plugins/";
        if (file_exists($dir)) {
            $scan = array_slice(scandir($dir), 2);

            foreach ($scan as $item) {
                if ($item == $name) {
                    return true;
                }
            }
        }
        return false;
    }
}
