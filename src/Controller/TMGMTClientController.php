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

    $json_data= '{"from":"en","to":"de","items":{"95":{"data":{"title":[{"value":{"#text":"aausprobieren","#translate":true,"#max_length":255,"#status":0,"#parent_label":["Title"]}}],"body":[{"value":{"#label":"Text","#text":"\u003Cp\u003Easdfasdfasdf\u003C\/p\u003E\r\n","#translate":true,"#format":"basic_html","#status":0,"#parent_label":["Body","Text"]}}]},"label":"aausprobieren","callback":"\/tmgmt\/tmgmt-drupal-callback\/95"}},"comment":null}';
    $array_data = Json::decode($json_data);

    $url = 'http://localdev64/tmgmt/translation-job';

    
    $request_query = $array_data;
    $options['form_params'] = $request_query;


    $cookie = new \GuzzleHttp\Cookie\SetCookie();
    $cookie->setName('XDEBUG_SESSION');
    $cookie->setValue('PHPSTORM');
    $cookie->setDomain('http://localdev64/');
    $cookie->setPath('tmgmt');

    $jar = new \GuzzleHttp\Cookie\CookieJar();
    $jar->setCookie($cookie);

    $client = new GuzzleHttp\Client([
    // 'cookies' => $jar,
   ]);


    $options['headers'] = array('Cookie' => 'SESS74c63ff78586bfb2e7a85089ded4cc77=RHzTQBsXeW5gD5XBAZ0mptFxgXdW3vLr05hrY1_ck3o; _ga=GA1.1.1593936461.1467706918; SESSfc05598803a32af6205a07a5c1d40307=cmH6QlyL12RXkDMDyXp_RUEvBTiAIo_To34ltF6yDYo; SESS2f220b4e0ebd1e20d71ead763bfa7ba5=0_nVsBJXadJgJlvj1D7BPJCzag8W7STSviAHhrLbmsQ; XDEBUG_SESSION=PHPSTORM');
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
