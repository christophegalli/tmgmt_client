<?php

namespace Drupal\tmgmt_client\Controller;

use Drupal\Core\Controller\ControllerBase;

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

    $url = $translator->getSetting('remote_url');
    $options['form_params'] = $request_query;
    if (isset($_GET['XDEBUG_SESSION'])) {
      // Add $_GET['XDEBUG_SESSION'] to guzzle request.
      $headers['XDEBUG_SESSION'] = 'PHPSTORM';
    }
    if (isset($_COOKIE['XDEBUG_SESSION'])) {
      // Add $_COOKIE['XDEBUG_SESSION'] to guzzle request.
//      $options['cookies'] = ['XDEBUG_SESSION' => 'PHPSTORM'];
//      $headers['XDEBUG_SESSION'] = 'PHPSTORM';
    }

    if(isset($headers)) {
      $options['headers'] = $headers;
    }


    $response = $this->client->request('POST', $url, $options);

    $data = $response->getBody()->getContents();


    return [
      '#type' => 'markup',
      '#markup' => $this->t('Running testRequest')
    ];
  }

}
