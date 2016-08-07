<?php
/**
 * @file
 * Contains \Drupal\tmgmt_client\ClientTranslatorUi.
 */

namespace Drupal\tmgmt_client;

use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\tmgmt\JobInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Client translator UI.
 */
class ClientTranslatorUi extends TranslatorPluginUiBase {

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
        '#submit' => array('_tmgmt_client_pull_submit'),
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

    $form['remote_client_id'] = array(
      '#type' => 'textfield',
      '#title' => t('Remote Client ID'),
      '#default_value' => $translator->getSetting('remote_client_id'),
      '#description' => t('Please enter your public key.'),
      '#required' => TRUE,
    );

    $form['remote_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Remote Secret'),
      '#default_value' => $translator->getSetting('remote_secret'),
      '#description' => t('Please enter your private key.'),
      '#required' => TRUE,
    );

    $api_version = $translator->getSetting('api_version');
    $api_version = isset($api_version) ? $api_version :
      \Drupal::config('tmgmt_client.settings')->get('api_version');

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
    $translator = $form_state->getFormObject()->getEntity();
    $supported_remote_languages = $translator->getPlugin()->getSupportedRemoteLanguages($translator);
    if (empty($supported_remote_languages)) {
      $form_state->setErrorByName('settings][remote_url', t('The URL is not correct'));
    }
  }

}
