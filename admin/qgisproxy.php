<?php

/**
 * qgisproxy.php -- part of Server side of Extended QGIS Web Client
 *
 * Copyright (2014-2015), Level2 team All rights reserved.
 * More information at https://github.com/uprel/gisapp
 */

use GuzzleHttp\Client;
use GuzzleHttp\Exception;
use GuzzleHttp\Psr7\Request;
use GisApp\Helpers;

require '../vendor/autoload.php';
require_once("class.Helpers.php");
require_once("settings.php");

/**
 * @param $query_arr
 * @param $client
 */
function doPostRequest($query_arr, $client)
{

    $request_params = $_POST;

    if (empty($request_params)) {

        $data = file_get_contents('php://input');

        if (!empty($data)) {

            $request_params = $data;

        }

    }

    //async request, but calling wait, no difference
//use GuzzleHttp\Exception\RequestException;
//use Psr\Http\Message\ResponseInterface;
//    $promise = $client->requestAsync('POST', QGISSERVERURL, [
//        'query' => $query_arr,
//        'body' => $request_params,
//        'http_errors' => true,
//        //request without SSL verification, read this http://docs.guzzlephp.org/en/latest/request-options.html#verify-option
//        'verify' => false
//    ]);
//
//    $promise->then(
//        function (ResponseInterface $response) {
//            //response
//            $contentType = $response->getHeaderLine('Content-Type');
//            $contentLength = $response->getHeaderLine('Content-Length');
//            $content = $response->getBody();
//
//            header("Content-Length: " . $contentLength);
//            header("Content-Type: " . $contentType);
//            header("Cache-control: max-age=0");
//
//            echo $content;
//        },
//        function (RequestException $e) {
//            //exception
//            $http_ver = $_SERVER["SERVER_PROTOCOL"];
//            header($http_ver . " 500 Error");
//            header("Content-Type: text/html");
//            echo $e->getMessage() . "\n";
//            echo $e->getRequest()->getMethod();
//        }
//    );
//
//    $promise->wait();

    //standard synhrone request
    $new_request = new Request('POST', QGISSERVERURL);


    $response = $client->send($new_request, [
        'query' => $query_arr,
        'body' => $request_params,
        'http_errors' => true,
        //request without SSL verification, read this http://docs.guzzlephp.org/en/latest/request-options.html#verify-option
        'verify' => false
    ]);

    $contentType = $response->getHeaderLine('Content-Type');
    $contentLength = $response->getHeaderLine('Content-Length');
    $content = $response->getBody();

    if ($response->getStatusCode() != 200) {
        throw new Exception\ServerException($content, $new_request);
    }

    header("Content-Length: " . $contentLength);
    header("Content-Type: " . $contentType);
    header("Cache-control: max-age=0");

    echo $content;

}

/**
 * @param $query_arr
 * @param $map
 * @param $client
 * @param $http_ver
 */
function doGetRequest($query_arr, $map, $client, $http_ver)
{
    $new_request = new Request('GET', QGISSERVERURL);

    //caching certain requests
    $config = array(
        "path" => TEMP_PATH
    );
    $cache = phpFastCache("files", $config);
    $content = null;
    $contentType = null;
    $cacheKey = null;
    $contentLength = 0;
    $sep = "_x_"; //separator for key generating

    if ($query_arr["REQUEST"] != null) {
        switch ($query_arr["REQUEST"]) {
            case "GetProjectSettings":
                $cacheKey = $map . $sep . "XML" . $sep . $query_arr["REQUEST"];
                $contentType = "text/xml";
                break;
            case "GetLegendGraphics":
                $cacheKey = $map . $sep . "PNG" . $sep . $query_arr["REQUEST"] . $sep . Helpers::normalize($query_arr['LAYERS']);
                $contentType = "image/png";
                break;
//            case "GetFeatureInfo":
//                //skip for now
//                if (array_key_exists("QUERY_LAYERS", $query_arr)) {
//                    if($_SESSION->qgs->layers[$query_arr['QUERY_LAYERS']]->wfs===false) {
//                            $cacheKey = $map . $sep . "XML" . $sep . $query_arr["REQUEST"] . $sep . Helpers::normalize($query_arr['FILTER']);
//                    }
//                }
//                break;
        }
    }

    if ($cacheKey != null) {
        $content = $cache->get($cacheKey);


        if ($content == null) {
            $response = $client->send($new_request, [
                'query' => $query_arr,
                'http_errors' => true,
                //request without SSL verification, read this http://docs.guzzlephp.org/en/latest/request-options.html#verify-option
                'verify' => false
            ]);
            $contentType = $response->getHeaderLine('Content-Type');
            $contentLength = $response->getHeaderLine('Content-Length');
            $content = $response->getBody()->__toString();

            //check GetProjectSettings XML
            if ($query_arr["REQUEST"] == "GetProjectSettings") {
                $contentXml = simplexml_load_string($content);
                if ($contentXml !== false) {
                    if ($contentXml->getName() !== 'WMS_Capabilities') {
                        $m = "Unknown GetCapabilities error";
                        if ($contentXml->ServiceException !== null) {
                            $m = (string)$contentXml->ServiceException;
                        }
                        throw new Exception\ServerException($m, $new_request);
                    }
                } else {
                    throw new Exception\ServerException($content, $new_request);
                }
            }
            if ($response->getStatusCode() == 200) {
                $cache->set($cacheKey, $content);
            } else {
                throw new Exception\ServerException($content, $new_request);
            }
        }
    } else {
        //no caching request
        $response = $client->send($new_request, [
            'query' => $query_arr,
            'http_errors' => true,
            //request without SSL verification, read this http://docs.guzzlephp.org/en/latest/request-options.html#verify-option
            'verify' => false
        ]);

        $contentType = $response->getHeaderLine('Content-Type');
        $contentLength = $response->getHeaderLine('Content-Length');
        $content = $response->getBody();
    }

    //get client headers
    $client_headers = apache_request_headers();

    //generate etag
    $new_etag = md5($content);

    //check if client send etag and compare it
    if (isset($client_headers['If-None-Match']) && strcmp($new_etag, $client_headers['If-None-Match']) == 0) {
        //return code 304 not modified without content
        header($http_ver . " 304 Not Modified");
        header("Cache-control: max-age=0");
        header("Etag: " . $new_etag);
    } else {
        //header("Content-Length: " . $contentLength);
        header("Content-Type: " . $contentType);
        header("Cache-control: max-age=0");
        header("Etag: " . $new_etag);

        echo $content;
    }
}

try {

//parameters, always (post also contains at lest map parameter
    $query_arr = filter_input_array(INPUT_GET, FILTER_UNSAFE_RAW);
    $request_method = $_SERVER['REQUEST_METHOD'];
    $http_ver = $_SERVER["SERVER_PROTOCOL"];

//we have to extend map parameter with path to projects, but first store it into own variable and remove .qgs
    $map = "";
    if (strpos($query_arr["map"], ".") === false) {
        $map = $query_arr["map"];
    } else {
        $map = explode(".", $query_arr["map"])[0];
    }
    $query_arr["map"] = PROJECT_PATH . $query_arr["map"];

//session check
    session_start();

    if (!(Helpers::isValidUserProj($map))) {
        throw new Exception\ClientException("Session time out or unathorized access!", new Request('GET', QGISSERVERURL));
    }

    $client = new Client();

    if ($request_method == 'GET') {

        doGetRequest($query_arr, $map, $client, $http_ver);

    }
    elseif ($request_method == 'POST') {

        //check if user is guest
        $user = null;
        if (isset($_SESSION["user_name"])) {
            $user = $_SESSION["user_name"];
        }
        if ($user != null && $user == 'guest') {
            throw new Exception\ClientException("No permission for guest users!", new Request('GET', QGISSERVERURL));
        }

        doPostRequest($query_arr, $client, $http_ver);
    }

} catch (Exception\ServerException $e) {
    //if ($e->hasResponse()) {
    //    header('', true, $e->getResponse()->getStatusCode());
    //} else {
    header($http_ver . " 500 Server Error");
    header("Content-Type: text/html");
    //}
    echo $e->getMessage();

} catch (Exception\ClientException $e) {
    header($http_ver . " 401 Unathorized");
    header("Content-Type: text/html");
    echo $e->getMessage();

} catch (Exception\RequestException $e) {
    header($http_ver . " 500 Error");
    header("Content-Type: text/html");
    echo $e->getMessage();
}
