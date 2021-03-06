<?php

/**
 * @file
 * Contains \Drupal\tmgmt_client\ClientTranslatorUi.
 */

namespace Drupal\tmgmt_client;

use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt_client\Plugin\tmgmt\Translator\ClientTranslator;

/**
 * Client translator UI.
 */
class ClientTranslatorUi extends TranslatorPluginUiBase {

  /**
   * Api version for new client.
   *
   * @const DEFAULT_API_VERSION
   */
  const DEFAULT_API_VERSION = 'api/v1';

  /**
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job) {

    $form['job_comment'] = array(
      '#type' => 'textarea',
      '#title' => t('Comment for the remote translation service'),
      '#description' => t('You can provide a comment so that the assigned user will better understand your requirements.'),
      '#default_value' => $job->getSetting('job_comment'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutInfo(JobInterface $job) {
    $form = array();

    if ($job->isActive()) {
      $form['actions']['pull'] = array(
        '#type' => 'submit',
        '#value' => t('Pull translations from remote server'),
        '#submit' => [
          [$this, 'submitPullTranslations'],
        ],
        '#weight' => -10,
      );
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    $form['remote_url'] = array(
      '#type' => 'textfield',
      '#title' => t('Remote URL'),
      '#required' => TRUE,
      '#default_value' => $translator->getSetting('remote_url'),
      '#description' => t('Please enter the URL of the remote provider installation'),
    );

    $form['client_id'] = array(
      '#type' => 'textfield',
      '#title' => t('Client ID'),
      '#default_value' => $translator->getSetting('client_id'),
      '#description' => t('Please enter your public key.'),
      '#required' => TRUE,
    );

    $form['client_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Client Secret'),
      '#default_value' => $translator->getSetting('client_secret'),
      '#description' => t('Please enter your private key.'),
      '#required' => TRUE,
    );

    $api_version = $translator->getSetting('api_version');
    $api_version = isset($api_version) ? $api_version : $this::DEFAULT_API_VERSION;

    $form['api_version'] = array(
      '#type' => 'textfield',
      '#title' => t('API Version prefix'),
      '#default_value' => $api_version,
      '#description' => t('Prefix to be used when calling the remote server.'),
      '#required' => TRUE,
    );

    $form += parent::addConnectButton();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    /** @var \Drupal\tmgmt_client\Plugin\tmgmt\Translator\ClientTranslator $plugin */
    $translator = $form_state->getFormObject()->getEntity();
    $plugin = $translator->getPlugin();

    // Fill the settings from the fields to ke translator available.
    $settings = $form_state->getValue('settings');
    $translator->setSettings(array(
      'remote_url' => $settings['remote_url'],
      'client_id' => $settings['client_id'],
      'client_secret' => $settings['client_secret'],
      'api_version' => $settings['api_version'],

    ));
    $supported_remote_languages = $plugin->getSupportedRemoteLanguages($translator);

    if (empty($supported_remote_languages)) {
      $error_code = $plugin->getConnectErrorCode();

      // Set message depending on the operation that triggered the validation.
      $message = (string) $form_state->getTriggeringElement()['#value'] == t('Save') ?
        t('Saving not possible. ') : '';
      $message .= t('Connection failed, Error ') . $error_code;

      $form_state->setErrorByName('settings', $message);
    }
  }

  public function submitPullTranslations(array $form, FormStateInterface $form_state) {

    /** @var Job $job */
    /** @var ClientTranslator $plugin */

    $job = $form_state->getFormObject()->getEntity();
    $plugin = $job->getTranslator()->getPlugin();

    // Fetch everything for this job.
    if (!$plugin->pullJobItems($job)) {
      drupal_set_message(t('Failed to pull translations from server'),'error');
    }

  }

}
