<?php

namespace Drupal\tmgmt_client\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\Client;
use Drupal\Component\Serialization\Json;
use Drupal\tmgmt\Entity\JobItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class TMGMTClientController.
 *
 * @package Drupal\tmgmt_client\Controller
 */
class TMGMTClientController extends ControllerBase {

  /**
   * Testing path for development.
   *
   * @return string
   *   Return Hello string.
   */
  public function requestTest() {

    $json_data = '{"from":"en","to":"de","items":{"3":{"data":{"title":[{"value":{"#text":"Basic two","#translate":true,"#max_length":255,"#status":0,"#parent_label":["Title"]}}],"body":[{"value":{"#label":"Text","#text":"\u003Cp\u003Ebody two\u003C\/p\u003E\r\n","#translate":true,"#format":"basic_html","#status":0,"#parent_label":["Body","Text"]}}]},"label":"Basic two","callback":"\/tmgmt\/tmgmt-drupal-callback\/3"},"4":{"data":{"title":[{"value":{"#text":"basic one","#translate":true,"#max_length":255,"#status":0,"#parent_label":["Title"]}}],"body":[{"value":{"#label":"Text","#text":"\u003Cp\u003Ebody first\u003C\/p\u003E\r\n","#translate":true,"#format":"basic_html","#status":0,"#parent_label":["Body","Text"]}}]},"label":"basic one","callback":"\/tmgmt\/tmgmt-drupal-callback\/4"}},"comment":null}';
    $array_data = Json::decode($json_data);

    $url = 'http://ubuntudev/tmgmt/translation-job';

    $request_query = $array_data;
    $options['form_params'] = $request_query;

    $client = new Client();

    $options['headers'] = array('Cookie' => 'XDEBUG_SESSION=PHPSTORM');
    $response = $client->request('POST', $url, $options);

    $data = $response->getBody()->getContents();

    $array_result = Json::decode($data);

    return [
      '#type' => 'markup',
      '#markup' => $data,
    ];
  }


  /**
   * Callback form server when job item has been translated.
   *
   * @param Request $request
   *   Http request.
   * @param \Drupal\tmgmt\Entity\JobItem $tmgmt_job_item
   *   The job item to which the translation belongs.
   *
   * @return Response
   *   Empty response with code 200.
   */
  public function clientCallback(Request $request, JobItem $tmgmt_job_item) {
    /* @var int $remote_source_id */
    /* @var array $data */
    $remote_source_id = $request->get('id');
    $url = $tmgmt_job_item->getTranslator()->getSetting('remote_url');

    $url .= '/translation-job/' . $remote_source_id . '/item';
    $result = $tmgmt_job_item->getTranslator()->getPlugin()
      ->pullItemData($tmgmt_job_item, $url);

    return new Response('', $result);

  }

}
