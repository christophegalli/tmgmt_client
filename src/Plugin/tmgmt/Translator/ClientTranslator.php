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
    if ($translator->getSetting('remote_url')) {
      return AvailableResult::yes();
    }

    return AvailableResult::no(t('@translator is not available.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->url(),
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
      'label' => $job->label(),
      'from' => $job->getSourceLangcode(),
      'to' => $job->getTargetLangcode(),
      'items' => $items,
      'comment' => $job->getSetting('job_comment'),
    );

    $response = $this->doRequest($job->getTranslator(), 'POST', 'translation-job', NULL, $transferData);

    foreach ($response['data']['remote_mapping'] as $local_key => $remote_key) {
      $item = JobItem::load($local_key);
      $item->addRemoteMapping(NULL, $remote_key);
      $item->save();

    }
    if (!$job->isRejected()) {
      $job->submitted('The translation job has been submitted.');
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
    $url = $translator->getSetting('remote_url');
    $client_id = $translator->getSetting('client_id');
    $client_secret = $translator->getSetting('client_secret');

    if (isset($url) && isset($client_id) && isset($client_secret)) {
      $url .= '/' . $translator->getSetting('api_version');
      $url .= '/language-pairs';

      $options['headers']['Authenticate'] = $this->createAuthString($translator);

      // Support for debug session, pass on the cookie.
      if (isset($_COOKIE['XDEBUG_SESSION'])) {
        $cookie = 'XDEBUG_SESSION=' . $_COOKIE['XDEBUG_SESSION'];
        $options['headers']['Cookie'] = $cookie;
      }

      try {
        $response = $this->client->request('GET', $url, $options);

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
   * @param JobItem $job_item
   *   The item for which to do action.
   * @param array $transfer_data
   *   Job and other Data to be sent to the server.
   *
   * @return array object
   *   Unserialized JSON response from Server.
   *
   * @throws TMGMTException.
   *   - Invalid action provided.
   *   - Error returned by the Google Service.
   */
  protected function doRequest(Translator $translator,
                               $requestType,
                               $action,
                               JobItem $job_item = NULL,
                               $transfer_data = NULL) {

    // Build the url.
    $url = $translator->getSetting('remote_url');
    $url .= '/' . $translator->getSetting('api_version');
    $url .= '/' . $action;

    // Add the job item id if provided.
    if (isset($job_item)) {
      $url .= '/' . $job_item->id();
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

    try {
      $response = $this->client->request($requestType, $url, $options);
    }
    catch (Exception $e) {
      return $e->getCode();
    }

    $data = $response->getBody()->getContents();
    return Json::decode($data);
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
   * Get data from remote and add it to the local item.
   *
   * @param \Drupal\tmgmt\Entity\JobItem $job_item
   *    Job item for which to get data.
   * @param string $url
   *    Which callback to use.
   *
   * @return int|mixed
   *    Http return code.
   */
  public function pullItemData(JobItem $job_item, $url) {

    // Get data from remote and add it to the local item.
    $options = [];

    // Support for debug session, pass on the cookie.
    if (isset($_COOKIE['XDEBUG_SESSION'])) {
      $cookie = 'XDEBUG_SESSION=' . $_COOKIE['XDEBUG_SESSION'];
      $options['headers'] = ['Cookie' => $cookie];
    }

    try {
      $response = $this->client->request('GET', $url, $options);

      if (!empty($response)) {
        $response_data = Json::decode($response->getBody()->getContents());
        $data = $response_data['data'];
        $this->processTranslatedData($job_item, $data);
      }
    }
    catch (Exception $e) {
      $job_item->addMessage('Unable to pull translation for @item from server: ' .
        $e->getMessage(),
        array(
          '@item' => $job_item->getSourceLabel(),
        ));
      return $e->getCode();
    }
    return 200;
  }

  /**
   * Pull all ready items from remote.
   *
   * @param \Drupal\tmgmt\Entity\Job $job
   *   The job to pull.
   */
  public function pullJobItems(Job $job) {
    /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_map */
    /** @var array $remote_mappings */
    $url = $job->getTranslator()->getSetting('remote_url') . '/remote-item/';

    foreach ($job->getItems() as $job_item) {
      // Find the corresponding remote job item.
      $remote_mappings = $job_item->getRemoteMappings();
      if (count($remote_mappings) == 0) {
        throw new TMGMTException('The item was not properly submitted to the server');
      }
      $remote_map = array_shift($remote_mappings);
      $remote_item_id = $remote_map->getRemoteIdentifier1();
      $this->pullItemData($job_item, $url . $remote_item_id);
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

  /**
   * Get Error Code porperty.
   *
   * @return string
   *   Error Code.
   */
  public function getConnectErrorCode() {
    return $this->ConnectErrorCode;
  }

  public function tmgmtClientPullSubmit(array $form, FormStateInterface $form_state) {

    /**
     * @var Job $job
     */
    $job = $form_state->getFormObject()->getEntity();

    // Fetch everything for this job.
    $this->pullJobItems($job);

  }

}
