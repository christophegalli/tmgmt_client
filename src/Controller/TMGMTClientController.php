<?php

namespace Drupal\tmgmt_client\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp;
use Drupal\Component\Serialization\Json;

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

    $json_data= '{"tjid":"77","label":"server test","from":"en","to":"de","items":{"87":{"tjiid":"87","item_id":"19","item_type":"node","plugin":"content","data":{"title":{"#label":"Title","0":{"value":{"#text":"server test","#translate":true,"#max_length":255,"#status":0}}},"uid":[{"target_id":{"#text":"1","#translate":false}}],"created":[{"value":{"#text":"1466148891","#translate":false}}],"promote":[{"value":{"#text":"0","#translate":false}}],"sticky":[{"value":{"#text":"0","#translate":false}}],"body":{"#label":"Body","0":{"value":{"#label":"Text","#text":"\u003Cp\u003Emain body\u003C\/p\u003E\r\n","#translate":true,"#format":"basic_html","#status":0},"format":{"#label":"Text format","#text":"basic_html","#translate":false},"summary":{"#label":"Summary","#text":"","#translate":true,"#format":"basic_html"}}},"field_zusatz":{"#label":"Zusatz","0":{"#label":"Delta #0","value":{"#text":"\u003Cp\u003Eadditional one\u003C\/p\u003E\r\n","#translate":true,"#format":"basic_html","#status":0},"format":{"#text":"basic_html","#translate":false}},"1":{"#label":"Delta #1","value":{"#text":"\u003Cp\u003Eadditional two\u003C\/p\u003E\r\n","#translate":true,"#format":"basic_html","#status":0},"format":{"#text":"basic_html","#translate":false}}},"changed":[{"value":{"#text":"1466148932","#translate":false}}],"content_translation_outdated":[{"value":{"#text":"0","#translate":false}}],"default_langcode":[{"value":{"#text":"1","#translate":false}}],"revision_translation_affected":[{"value":{"#text":"1","#translate":false}}],"status":[{"value":{"#text":"1","#translate":false}}]}}}}';
    $array_data = Json::decode($json_data);

    $request_query = $array_data;
    
    
    $url = 'http://localdev64/tmgmt/remote_translation/add';
    $options['form_params'] = $request_query;
    if (isset($_GET['XDEBUG_SESSION'])) {
      // Add $_GET['XDEBUG_SESSION'] to guzzle request.
      $headers['XDEBUG_SESSION'] = 'PHPSTORM';
    }
    if (isset($_COOKIE['XDEBUG_SESSION'])) {
      // Add $_COOKIE['XDEBUG_SESSION'] to guzzle request.
      $headers['Cookie'] = ['XDEBUG_SESSION=PHPSTORM'];
    }

    if(isset($headers)) {
      $options['headers'] = $headers;
    }

  $this->client = new GuzzleHttp\Client();
    $response = $this->client->request('POST', $url, $options);

    $data = $response->getBody()->getContents();

    $array_result = Json::decode($data);



    return [
      '#type' => 'markup',
      '#markup' => $data,
    ];
  }

}
