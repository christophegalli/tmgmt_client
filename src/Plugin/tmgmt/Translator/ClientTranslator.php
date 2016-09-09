<?php

/**
 * @file
 * Contains \Drupal\tmgmt_client\Plugin\tmgmt\Translator\ClientTranslator.
 */

namespace Drupal\tmgmt_client\Plugin\tmgmt\Translator;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Url;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorPluginBase;
use Drupal\tmgmt\RemoteMappingInterface;
use Drupal\tmgmt\Entity\RemoteMapping;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use \Drupal\tmgmt\Translator\AvailableResult;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;

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
   * Contains the last error code returned in case of GuzzleClientException.
   *
   * @var string
   */
  protected $ConnectErrorCode;

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
    if ($translator->getSetting('remote_url') &&
      $translator->getSetting('client_id') &&
      $translator->getSetting('client_secret')) {
      return AvailableResult::yes();
    }

    return AvailableResult::no(t('@translator is not available.', [
      '@translator' => $translator->label(),
    ]));
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::checkTranslatable().
   */
  public function checkTranslatable(TranslatorInterface $translator, JobInterface $job) {
    return parent::checkTranslatable($translator, $job);
  }

  /**
   * Retrieve the callback url for a job item.
   */
  public function getCallbackUrl(JobItemInterface $item) {
    return Url::fromRoute('tmgmt_client.callback', array('tmgmt_job_item' => $item->id()),
      array('absolute' => TRUE))->toString();
  }

  /**
   * Retrieves the filtered and structured data array for a single job item in
   * a translation request array.
   *
   * @param JobItemInterface $item
   *   The job item to retrieve the structured data array for.
   *
   * @return array
   *   The structured data array for the passed job item.
   */
  public function getTranslationRequestItemArray(JobItemInterface $item) {

    $filtered_and_flatten_item = \Drupal::service('tmgmt.data')
      ->filterTranslatable($item->getData());
    $data = array(
      'data' => \Drupal::service('tmgmt.data')->unflatten($filtered_and_flatten_item),
      'label' => $item->getSourceLabel(),
      'callback' => $this->getCallbackUrl($item),
    );
    return $data;
  }

  /**
   * Implements TMGMTTranslatorPluginControllerInterface::requestTranslation().
   *
   * @param JobInterface $job
   *   The job to be translated.
   */
  public function requestTranslation(JobInterface $job) {
    /** @var array $items */
    /** @var JobItem $item */
    $items = [];
    foreach ($job->getItems() as $item) {
      $items[$item->id()] = $this->getTranslationRequestItemArray($item);
    }

    $transferData = array(
      'label' => (string)$job->label(),
      'from' => $job->getSourceLangcode(),
      'to' => $job->getTargetLangcode(),
      'items' => $items,
      'comment' => $job->getSetting('job_comment'),
    );

    try {
      $response = $this->doRequest($job->getTranslator(), 'POST', 'translation-job', $transferData);

      $response_data = Json::decode($response->getBody()->getContents());

      foreach ($response_data['data']['remote_mapping'] as $local_key => $remote_key) {
        $item = JobItem::load($local_key);
        $item->addRemoteMapping(NULL, $remote_key);
        $item->save();

      }
      if (!$job->isRejected()) {
        $job->submitted('The translation job has been submitted.');
      }
    }
    catch (Exception $e) {
      throw new TMGMTException($e->getMessage(), NULL, $e->getCode());
    }
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::getSupportedRemoteLanguages().
   *
   * @param TranslatorInterface $translator
   *   Which translator.
   *
   * @return array $languages
   *   Available language pairs.
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    $available_languages = [];

    if (!$this->checkAvailable($translator)->getSuccess()) {
      return $available_languages;
    }

    try {
      $response = $this->doRequest($translator, 'GET', 'language-pairs');

      if (!empty($response)) {
        $response_data = Json::decode($response->getBody()->getContents());
        foreach ($response_data['data'] as $lang_pair) {
          $available_languages[$lang_pair['source_language']] = $lang_pair['source_language'];
          $available_languages[$lang_pair['target_language']] = $lang_pair['target_language'];
        }
      }
    }
    catch (ClientException $e) {
      $this->ConnectErrorCode = $e->getCode();
    }
    catch (ServerException $e) {
      $this->ConnectErrorCode = $e->getCode();
    }

    return $available_languages;
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::getDefaultRemoteLanguagesMappings().
   */
  public function getDefaultRemoteLanguagesMappings() {
    return array(
      // 'zh-hans' => 'zh-CHS',
      // 'zh-hant' => 'zh-CHT',
    );
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::getSupportedLanguagePairs().
   */
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
   * Local method to do request to TMGMT Server.
   *
   * @param Translator $translator
   *   The translator entity to get the settings from.
   * @param string $requestType
   *   POST, GET etc.
   * @param string $action
   *   Action to be performed.
   * @param int $job_item_id
   *   The item for which to do action.
   * @param array $transfer_data
   *   Job and other Data to be sent to the server.
   *
   * @return Response $response
   *   Response retrun from server.
   *
   * @throws TMGMTException.
   *   - Invalid action provided.
   *   - Error returned by the Google Service.
   */
  protected function doRequest(TranslatorInterface $translator,
                               $requestType,
                               $action,
                               $transfer_data = NULL) {

    // Build the url.
    $url = $translator->getSetting('remote_url');
    $url .= '/' . $translator->getSetting('api_version');
    $url .= '/' . $action;

    // Add the job item id if provided.
    if (isset($job_item_id)) {
      $url .= '/' . $job_item_id;
    }

    $options = [];

    // Prepare the body if there is data to transfer.
    if (isset($transfer_data)) {
      $options['form_params'] = $transfer_data;
    }

    // Add the authentication string for identification at the server.
    $options['headers']['Authenticate'] = $this->createAuthString($translator);

    // Support for debug session, pass on the cookie.
    if (isset($_COOKIE['XDEBUG_SESSION'])) {
      $cookie = 'XDEBUG_SESSION=' . $_COOKIE['XDEBUG_SESSION'];
      $options['headers']['Cookie'] = $cookie;
    }

    $response = $this->client->request($requestType, $url, $options);

    return $response;

  }

  /**
   * We provide translatorUrl setter so that we can override its value.
   *
   * Done for automated testing.
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
   * Pull all ready items from remote.
   *
   * @param \Drupal\tmgmt\Entity\Job $job
   *   The job to pull.
   */
  public function pullJobItems(Job $job) {

    try {
      foreach ($job->getItems() as $job_item) {
        $this->pullItemData($job_item);
      }
    }
    catch (\Exception $e) {
      return FALSE;
    }

    return TRUE;
  }

  public function pullItemData(JobItem $job_item) {

    // Find the corresponding remote job item.
    $remote_mappings = $job_item->getRemoteMappings();
    $remote_map = array_shift($remote_mappings);
    $remote_item_id = $remote_map->getRemoteIdentifier1();
    $translator = $job_item->getTranslator();
    $action = 'translation-job/' . $remote_item_id . '/pull/';

    $response = NULL;
    $response = $this->doRequest($translator, 'GET', $action);
    if (!empty($response)) {
      $response_data = Json::decode($response->getBody()->getContents());
      $data = $response_data['data'];
      $this->processTranslatedData($job_item, $data);
    }
  }

  /**
   * Create the hash from id and secret.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *    The used translator entity.
   *
   * @return string
   *   The full authenticate string to be sent to the server.
   */
  public function createAuthString(TranslatorInterface $translator) {
    // Create timestamp.
    list($usec, $sec) = explode(" ", microtime());
    $utime = (float) $usec + (float) $sec;

    // Create hash.
    $secret = Crypt::hmacBase64($utime, $translator->getSetting('client_secret'));
    $auth_string = $translator->getSetting('client_id') . '@' . $secret . '@' . $utime;

    return $auth_string;
  }



}
