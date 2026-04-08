<?php

declare(strict_types=1);

namespace Drupal\librechart_dictation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\user\Entity\Role;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Librechart dictation settings.
 *
 * Controls the whisper.cpp server URL, transcription language,
 * maximum audio duration, and which roles can use dictation.
 */
class DictationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['librechart_dictation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'librechart_dictation_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('librechart_dictation.settings');

    $form['whisper_server_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Whisper Server URL'),
      '#description' => $this->t('URL of the local whisper.cpp HTTP server, e.g. http://127.0.0.1:8080'),
      '#default_value' => $config->get('whisper_server_url') ?? 'http://127.0.0.1:8080',
      '#required' => TRUE,
    ];

    $form['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Transcription Language'),
      '#description' => $this->t('Language for whisper.cpp transcription.'),
      '#options' => [
        'es' => $this->t('Spanish'),
        'en' => $this->t('English'),
      ],
      '#default_value' => $config->get('language') ?? 'es',
    ];

    $form['max_audio_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Recording Duration (seconds)'),
      '#description' => $this->t('Maximum allowed audio recording duration in seconds.'),
      '#default_value' => $config->get('max_audio_duration') ?? 300,
      '#min' => 30,
      '#max' => 600,
    ];

    $roles = Role::loadMultiple();
    $role_options = [];
    foreach ($roles as $role_id => $role) {
      if ($role->id() === 'anonymous') {
        continue;
      }
      $role_options[$role_id] = $role->label();
    }

    $form['enabled_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles with Dictation Access'),
      '#description' => $this->t('Select which roles can use speech-to-text dictation.'),
      '#options' => $role_options,
      '#default_value' => $config->get('enabled_roles') ?? [
        'clinician',
        'triage_nurse',
        'physical_therapist',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('librechart_dictation.settings')
      ->set('whisper_server_url', $form_state->getValue('whisper_server_url'))
      ->set('language', $form_state->getValue('language'))
      ->set('max_audio_duration', (int) $form_state->getValue('max_audio_duration'))
      ->set('enabled_roles', array_filter($form_state->getValue('enabled_roles')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
