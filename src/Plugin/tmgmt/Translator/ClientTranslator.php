<?php

/**
 * @file
 * Contains \Drupal\tmgmt_client\Plugin\tmgmt\Translator\ClientTranslator.
 */

namespace Drupal\tmgmt_client\Plugin\tmgmt\Translator;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorPluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use \Drupal\tmgmt\Translator\AvailableResult;
use \Drupal\tmgmt\Translator\TranslatableResult;

/**
 * Client translator plugin.
 *
 * @TranslatorPlugin(
 *   id = "client",
 *   label = @Translation("Client"),
 *   description = @Translation("Client Translator service."),
 *   ui = "Drupal\tmgmt_client\ClientTranslatorUi"
 * )
 */
class ClientTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface {


  /**
   * Available actions for Client translator.
   *
   * @var array
   */
  protected $availableActions = array('translate', 'languages', 'detect');

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Constructs a LocalActionBase object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The Guzzle HTTP client.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(ClientInterface $client, array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('http_client'),
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::checkAvailable().
   */
  public function checkAvailable(TranslatorInterface $translator) {
  if ($translator->getSetting('remote_url')) {
    return AvailableResult::yes();
  }

  return AvailableResult::no(t('@translator is not available.', [
    '@translator' => $translator->label(),
    ':configured' => $translator->url()
  ]));
}

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::checkTranslatable().
   */
  public function checkTranslatable(TranslatorInterface $translator, JobInterface $job) {
    return parent::checkTranslatable($translator, $job);
  }

  /**
   * Implements TMGMTTranslatorPluginControllerInterface::requestTranslation().
   */
  public function requestTranslation(JobInterface $job) {
    $this->doRequest($job->getTranslator(),'translate', array(
      'from' => $job->getRemoteSourceLanguage(),
      'to' => $job->getRemoteTargetLanguage(),
      'data' => $job->getData(),
    ));
    
    if (!$job->isRejected()) {
      $job->submitted('The translation job has been submitted.');
    }
  }


  /**
   * Overrides TMGMTDefaultTranslatorPluginController::getSupportedRemoteLanguages().
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    $languages = array(
      'en' => 'English (en)',
      'de' => 'German (de)',
    );

    // @todo: Get supported languages
    return $languages;
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::getDefaultRemoteLanguagesMappings().
   */
  public function getDefaultRemoteLanguagesMappings() {
    return array(
      //'zh-hans' => 'zh-CHS',
      //'zh-hant' => 'zh-CHT',
    );
  }


  public function getSupportedLanguagePairs(TranslatorInterface $translator) {
    return parent::getSupportedLanguagePairs($translator);
    // @todo: get the pairs from remote.
  }


    /**
   * Overrides TMGMTDefaultTranslatorPluginController::getSupportedTargetLanguages().
   */
  public function getSupportedTargetLanguages(TranslatorInterface $translator, $source_language) {

    $languages = $this->getSupportedRemoteLanguages($translator);

    // There are no language pairs, any supported language can be translated
    // into the others. If the source language is part of the languages,
    // then return them all, just remove the source language.
    if (array_key_exists($source_language, $languages)) {
      unset($languages[$source_language]);
      return $languages;
    }

    return array();
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::hasCheckoutSettings().
   */
  public function hasCheckoutSettings(JobInterface $job) {
    // @TODO: choose user on client
    return FALSE;
  }

  /**
   * Local method to do request to Google Translate service.
   *
   * @param Translator $translator
   *   The translator entity to get the settings from.
   * @param string $action
   *   Action to be performed [translate, languages, detect]
   * @param array $request_query
   *   (Optional) Additional query params to be passed into the request.
   * @param array $options
   *   (Optional) Additional options that will be passed into drupal_http_request().
   *
   * @return array object
   *   Unserialized JSON response from Google.
   *
   * @throws TMGMTException
   *   - Invalid action provided
   *   - Unable to connect to the Google Service
   *   - Error returned by the Google Service
   */
  protected function doRequest(Translator $translator, $action, array $request_query = array(), array $options = array()) {
    // @TODO: send the job to the remote_url

    $url = $translator->getSetting('remote_url');
    $options['form_params'] = $request_query;
    if (isset($_GET['XDEBUG_SESSION'])) {
      // Add $_GET['XDEBUG_SESSION'] to guzzle request.
      $headers['XDEBUG_SESSION'] = 'PHPSTORM';
    }
    if (isset($_COOKIE['XDEBUG_SESSION'])) {
      // Add $_COOKIE['XDEBUG_SESSION'] to guzzle request.
      $options['Cookie'] = ['XDEBUG_SESSION' => 'PHPSTORM'];
//      $headers['XDEBUG_SESSION'] = 'PHPSTORM';
    }

    if(isset($headers)) {
      $options['headers'] = $headers;
    }


    $response = $this->client->request('POST', $url, $options);

    $data = $response->getBody()->getContents();


    // @todo add support to forward XDEBUG_SESSION
    
  }

  /**
   * We provide translatorUrl setter so that we can override its value
   * in automated testing.
   *
   * @param $translator_url
   */
  final public function setTranslatorURL($translator_url) {
    $this->translatorUrl = $translator_url;
  }

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = reset($job_items)->getJob();
    foreach ($job_items as $job_item) {
      if ($job->isContinuous()) {
        $job_item->active();
      }
      // Pull the source data array through the job and flatten it.
      $data = \Drupal::service('tmgmt.data')
        ->filterTranslatable($job_item->getData());

        try {
          // @todo: call doRequest
          $result = $this->doRequest($job->getTranslator(), 'Translate', array(
              'from' => $job->getRemoteSourceLanguage(),
              'to' => $job->getRemoteTargetLanguage(),
              'data' => $data,
            ));

        } catch (TMGMTException $e) {
          $job->rejected('Translation has been rejected with following error: @error',
            array('@error' => $e->getMessage()), 'error');
        }
      }
    }


}
