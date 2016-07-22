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
   * Saves translated data in a job item.
   *
   * @param JobItem $item
   *   Job Item to be filled.
   * @param array $data
   *   Translation data received from the server.
   */
  public function processTranslatedData(JobItem $item, array $data) {
    $translation = array();
    foreach (\Drupal::service('tmgmt.data')->flatten($data) as $path => $value) {
      if (isset($value['#translation']['#text'])) {
        $translation[$path]['#text'] = $value['#translation']['#text'];
      }
    }
    $item->addTranslatedData(\Drupal::service('tmgmt.data')->unflatten($translation));
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

    $client = new Client();
    $options = [];

    // Support for debug session, pass on the cookie.
    if (isset($_COOKIE['XDEBUG_SESSION'])) {
      $cookie = 'XDEBUG_SESSION=' . $_COOKIE['XDEBUG_SESSION'];
      $options['headers'] = ['Cookie' => $cookie];
    }

    try {
      $response = $client->request('GET', $url, $options);

      if (!empty($response)) {
        $response_data = Json::decode($response->getBody()->getContents());
        $data = $response_data['data'];
        $this->processTranslatedData($tmgmt_job_item, $data);
        $tmgmt_job_item->addMessage('Translation pulled from remote server.');
      }
    }
    catch (Exception $e) {
      $tmgmt_job_item->addMessage('Unable to pull translation from server: ' .
        $e->getMessage());
    }
    return new Response();
  }

}
