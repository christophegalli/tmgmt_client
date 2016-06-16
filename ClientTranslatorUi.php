<?php
/**
 * @file
 * Contains \Drupal\tmgmt_microsoft\ClientTranslatorUi.
 */

namespace Drupal\tmgmt_client;

use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Client translator UI.
 */
class ClientTranslatorUi extends TranslatorPluginUiBase {

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
