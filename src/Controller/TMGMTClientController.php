<?php

namespace Drupal\tmgmt_client\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp;

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

    $request_query['from'] = 'de';
    $request_query['to'] = 'en';
    $request_query['data'] = 'das ist data test';
    
    
    $url = 'http://localdev64/tmgmt/remote_translation/add';
    $options['form_params'] = $request_query;
    if (isset($_GET['XDEBUG_SESSION'])) {
      // Add $_GET['XDEBUG_SESSION'] to guzzle request.
      $headers['XDEBUG_SESSION'] = 'PHPSTORM';
    }
    if (isset($_COOKIE['XDEBUG_SESSION'])) {
      // Add $_COOKIE['XDEBUG_SESSION'] to guzzle request.
      $headers['Cookie'] = ['XDEBUG_SESSION=PHPSTORM'];
      $headers['XDEBUG_SESSION'] = 'PHPSTORM';
    }

    if(isset($headers)) {
      $options['headers'] = $headers;
    }

$this->client = new GuzzleHttp\Client();
    $response = $this->client->request('POST', $url, $options);

    $data = $response->getBody()->getContents();



    return [
      '#type' => 'markup',
      '#markup' => $this->t('Running testRequest')
    ];
  }

}
