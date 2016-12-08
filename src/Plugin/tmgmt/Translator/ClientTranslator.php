<?php

namespace Drupal\tmgmt_client\Plugin\tmgmt\Translator;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Url;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Tests\XdebugRequestTrait;
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
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use \Drupal\tmgmt\Translator\AvailableResult;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Request;

/**
 * Client translator plugin.
 *
 * @TranslatorPlugin(
 *   id = "client",
 *   label = @Translation("Client"),
 *   description = @Translation("Client Translator service."),
 *   ui = "Drupal\tmgmt_client\ClientTranslatorUi",
 *   language_cache = FALSE
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
   *
   * @param TranslatorInterface $translator
   *   The translator.
   *
   * @return AvailableResult
   *   RFeport success or failure.
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
   * Retrieve the callback url for a job item.
   *
   * @param JobItemInterface $item
   *   Job item to pull the url for.
   *
   * @return string
   *   url string.
   */
  public function getCallbackUrl(JobItemInterface $item) {
    return Url::fromRoute('tmgmt_client.callback', array('tmgmt_job_item' => $item->id()),
      array('absolute' => TRUE))->toString();
  }

  /**
   * Retrieves the filtered and structured data array for a single job item.
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
   *
   * @throws TMGMTException
   *   Request not successful.
   */
  public function requestTranslation(JobInterface $job) {
    /** @var array $items */
    /** @var JobItem $item */
    $items = [];
    foreach ($job->getItems() as $item) {
      $items[$item->id()] = $this->getTranslationRequestItemArray($item);
    }

    $transferData = array(
      'label' => (string) $job->label(),
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
    catch (\Exception $e) {
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
    $language_pairs = $this->getSupportedLanguagePairs($translator);

    foreach ($language_pairs as $language_pair) {
      $available_languages[$language_pair['source_language']] = $language_pair['source_language'];
      $available_languages[$language_pair['target_language']] = $language_pair['target_language'];
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
   *
   * Fetch the available language pairs from the remote server.
   *
   * @param TranslatorInterface $translator
   *   The translator.
   *
   * @return array
   *   The available language pairs.
   */
  public function getSupportedLanguagePairs(TranslatorInterface $translator) {
    $language_pairs = [];

    // Continue only if all necessary settings are available.
    if (!$this->checkAvailable($translator)->getSuccess()) {
      return $language_pairs;
    }

    try {
      $response = $this->doRequest($translator, 'GET', 'language-pairs');

      if (!empty($response)) {
        $response_data = Json::decode($response->getBody()->getContents());
        $language_pairs = $response_data['data'];
      }
    }
    catch (ClientException $e) {
      $this->ConnectErrorCode = $e->getCode();
    }
    catch (ServerException $e) {
      $this->ConnectErrorCode = $e->getCode();
    }

    return $language_pairs;
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::getSupportedTargetLanguages().
   *
   * @param TranslatorInterface $translator
   *   The translator.
   * @param string $source_language
   *   Find matches for this language.
   *
   * @return array
   *   The available target languages.
   */
  public function getSupportedTargetLanguages(TranslatorInterface $translator, $source_language) {

    $languages = [];
    $language_pairs = $this->getSupportedLanguagePairs($translator);

    foreach ($language_pairs as $language_pair) {
      if ($language_pair['source_language'] == $source_language) {
        $languages[$language_pair['target_language']] = $language_pair['target_language'];
      }
    }

    return $languages;
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
   * @param TranslatorInterface $translator
   *   The translator entity to get the settings from.
   * @param string $requestType
   *   POST, GET etc.
   * @param string $action
   *   Action to be performed.
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
    $request = \Drupal::request();
    if ($cookies = $this->extractCookiesFromRequest($request)) {
      $cookie_jar = new CookieJar();
      foreach ($cookies as $cookie_name => $values) {
        foreach ($values as $value) {
          $cookie_jar->setCookie(new SetCookie(['Name' => $cookie_name, 'Value' => $value, 'Domain' => $request->getHost()]));
        }
      }
      $options['cookies'] = $cookie_jar;
    }

    $response = $this->client->request($requestType, $url, $options);

    return $response;

  }

  /**
   * We provide translatorUrl setter so that we can override its value.
   *
   * Done for automated testing.
   *
   * @param string $translator_url
   *   Url string.
   */
  final public function setTranslatorUrl($translator_url) {
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
   *
   * @return bool
   *   Report success or failure.
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

  /**
   * Get the translated date from the remote server.
   *
   * @param \Drupal\tmgmt\Entity\JobItem $job_item
   *   Item to pull data for from the remote server.
   */
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

  /**
   * Get Error Code porperty.
   *
   * @return string
   *   Error Code.
   */
  public function getConnectErrorCode() {
    return $this->ConnectErrorCode;
  }

  /**
   * Adds xdebug cookies, from request setup.
   *
   * In order to debug web tests you need to either set a cookie, have the
   * Xdebug session in the URL or set an environment variable in case of CLI
   * requests. If the developer listens to connection on the parent site, by
   * default the cookie is not forwarded to the client side, so you cannot
   * debug the code running on the child site. In order to make debuggers work
   * this bit of information is forwarded. Make sure that the debugger listens
   * to at least three external connections.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   The extracted cookies.
   *
   * @see \Drupal\Tests\XdebugRequestTrait
   */
  protected function extractCookiesFromRequest(Request $request) {
    $cookie_params = $request->cookies;
    $cookies = [];
    if ($cookie_params->has('XDEBUG_SESSION')) {
      $cookies['XDEBUG_SESSION'][] = $cookie_params->get('XDEBUG_SESSION');
    }
    // For CLI requests, the information is stored in $_SERVER.
    $server = $request->server;
    if ($server->has('XDEBUG_CONFIG')) {
      // $_SERVER['XDEBUG_CONFIG'] has the form "key1=value1 key2=value2 ...".
      $pairs = explode(' ', $server->get('XDEBUG_CONFIG'));
      foreach ($pairs as $pair) {
        list($key, $value) = explode('=', $pair);
        // Account for key-value pairs being separated by multiple spaces.
        if (trim($key, ' ') == 'idekey') {
          $cookies['XDEBUG_SESSION'][] = trim($value, ' ');
        }
      }
    }
    return $cookies;
  }

}



