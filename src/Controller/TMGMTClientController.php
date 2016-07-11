<?php

namespace Drupal\tmgmt_client\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp;
use Drupal\Component\Serialization\Json;
use Drupal\tmgmt_server;

/**
 * Class TMGMTClientController.
 *
 * @package Drupal\tmgmt_client\Controller
 */
class TMGMTClientController extends ControllerBase {

  /**
   * Hello.
   *
   * @return string
   *   Return Hello string.
   */
  public function requestTest() {

    $json_data= '{"from":"en","to":"de","items":{"3":{"data":{"title":[{"value":{"#text":"Basic two","#translate":true,"#max_length":255,"#status":0,"#parent_label":["Title"]}}],"body":[{"value":{"#label":"Text","#text":"\u003Cp\u003Ebody two\u003C\/p\u003E\r\n","#translate":true,"#format":"basic_html","#status":0,"#parent_label":["Body","Text"]}}]},"label":"Basic two","callback":"\/tmgmt\/tmgmt-drupal-callback\/3"},"4":{"data":{"title":[{"value":{"#text":"basic one","#translate":true,"#max_length":255,"#status":0,"#parent_label":["Title"]}}],"body":[{"value":{"#label":"Text","#text":"\u003Cp\u003Ebody first\u003C\/p\u003E\r\n","#translate":true,"#format":"basic_html","#status":0,"#parent_label":["Body","Text"]}}]},"label":"basic one","callback":"\/tmgmt\/tmgmt-drupal-callback\/4"}},"comment":null}';
    $array_data = Json::decode($json_data);

    $url = 'http://ubuntudev/tmgmt/translation-job';

    
    $request_query = $array_data;
    $options['form_params'] = $request_query;

     $client = new GuzzleHttp\Client();


    $options['headers'] = array('Cookie' => 'XDEBUG_SESSION=PHPSTORM');
    //
    $response = $client->request('POST', $url, $options);

    $data = $response->getBody()->getContents();

    $array_result = Json::decode($data);

   

    return [
      '#type' => 'markup',
      '#markup' => $data,
    ];
  }

  public function clientCallback($job_item_id) {
    return;
  }
}
