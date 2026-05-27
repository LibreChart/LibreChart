<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Filter form for the pharmacy inventory report: clinic site + date range.
 *
 * Submits as GET so filter state lives in the URL — bookmarkable, shareable,
 * and consistent with the CSV export link's query string.
 */
class InventoryReportFilterForm extends FormBase {

  public function getFormId(): string {
    return 'librechart_pharmacy_inventory_report_filters';
  }

  public function buildForm(array $form, FormStateInterface $form_state, array $filters = []): array {
    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'pharmacy-report__filters';

    $clinic_options = ['' => $this->t('- All sites -')];
    foreach ($this->loadClinicSites() as $tid => $label) {
      $clinic_options[$tid] = $label;
    }

    $form['clinic_site'] = [
      '#type' => 'select',
      '#title' => $this->t('Clinic site'),
      '#options' => $clinic_options,
      '#default_value' => $filters['clinic_site'] ?? '',
    ];

    $form['start'] = [
      '#type' => 'date',
      '#title' => $this->t('From'),
      '#default_value' => $filters['start'] ?? '',
    ];

    $form['end'] = [
      '#type' => 'date',
      '#title' => $this->t('To'),
      '#default_value' => $filters['end'] ?? '',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute('librechart_pharmacy.report'),
      '#attributes' => ['class' => ['button']],
    ];

    // Suppress the auto-generated form_id / form_token hidden inputs that
    // would otherwise muddy the URL when submitted via GET.
    $form['form_build_id']['#access'] = FALSE;
    $form['form_token']['#access'] = FALSE;
    $form['form_id']['#access'] = FALSE;

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // No-op: form is GET, browser handles the redirect.
  }

  /**
   * @return array<int, string>
   */
  private function loadClinicSites(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'clinic_sites')
      ->sort('name', 'ASC')
      ->execute();
    $options = [];
    foreach ($storage->loadMultiple($tids) as $term) {
      $options[(int) $term->id()] = $term->label();
    }
    return $options;
  }

}
