<?php

namespace Drupal\tmgmt_client\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tmgmt\Entity\JobItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\tmgmt_client\Plugin\tmgmt\Translator\ClientTranslator;

/**
 * Class TMGMTClientController.
 *
 * @package Drupal\tmgmt_client\Controller
 */
class TMGMTClientController extends ControllerBase {

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
  public function clientCallback(JobItem $tmgmt_job_item) {
    /* @var int $remote_source_id */
    /* @var array $data */
    /** @var ClientTranslator $plugin */

    // return '404';
    // Get the remote item data.
    $plugin = $tmgmt_job_item->getTranslator()->getPlugin();

    try {
      $plugin->pullItemData($tmgmt_job_item);

      return new Response('');
    }
    catch (\Exception $e) {
      return $e->getCode();
    }
  }

}
