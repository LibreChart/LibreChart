<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Filter form for the pharmacy inventory report: drug-name search + category.
 *
 * Submits as GET so filter state lives in the URL — bookmarkable, shareable,
 * and consistent with the CSV export link's query string.
 */
class InventoryReportFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'librechart_pharmacy_inventory_report_filters';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param array<string, string> $filters
   *   The active filter values (drug, category).
   *
   * @return array
   *   The filter form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $filters = []): array {
    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'pharmacy-report__filters';

    $form['drug'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Drug name'),
      '#size' => 30,
      '#default_value' => $filters['drug'] ?? '',
    ];

    $form['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#options' => librechart_pharmacy_drug_vocabularies(),
      '#empty_option' => $this->t('- All categories -'),
      '#empty_value' => '',
      '#default_value' => $filters['category'] ?? '',
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

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // No-op: form is GET, browser handles the redirect.
  }

}
