<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Stock-aware medication picker widget.
 *
 * Renders the prescription line item's `drug` reference as a typeahead backed
 * by the pharmacy medication autocomplete route. The client library colours
 * suggestions by stock level and blocks selection of out-of-stock medications.
 * The visible field shows the drug name only; the selected term id travels in a
 * sibling hidden input (populated by the autocomplete JS), so the user never
 * sees the "(tid)" disambiguator.
 */
#[FieldWidget(
  id: 'librechart_medication_autocomplete',
  label: new TranslatableMarkup('Medication autocomplete (stock-aware)'),
  field_types: ['entity_reference'],
)]
final class MedicationAutocompleteWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The field values being edited.
   * @param int $delta
   *   The delta of the item being edited.
   * @param array<string, mixed> $element
   *   The base form element render array.
   * @param array<string, mixed> $form
   *   The full form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array<string, mixed>
   *   The form element render array.
   */
  public function formElement(
    FieldItemListInterface $items,
    $delta,
    array $element,
    array &$form,
    FormStateInterface $form_state,
  ): array {
    $referenced = $items[$delta]->entity ?? NULL;
    $label = $referenced !== NULL ? (string) $referenced->label() : '';
    $tid = $referenced !== NULL ? (string) $referenced->id() : '';

    $url = Url::fromRoute('librechart_pharmacy.medication_autocomplete')->toString();

    // Carry the field's label/description/required onto the visible textfield;
    // the wrapper container only groups the textfield with its hidden term id.
    $title = $element['#title'] ?? NULL;
    $description = $element['#description'] ?? NULL;
    $required = !empty($element['#required']);
    unset($element['#title'], $element['#description']);

    $element['#type'] = 'container';
    $element['#attributes']['class'][] = 'medication-autocomplete-wrapper';
    $element['#element_validate'] = [[static::class, 'validateElement']];

    $element['target_id'] = [
      '#type' => 'textfield',
      '#title' => $title,
      '#description' => $description,
      '#required' => $required,
      '#default_value' => $label,
      '#maxlength' => 1024,
      '#attributes' => [
        'class' => ['medication-autocomplete'],
        'data-medication-autocomplete-url' => $url,
        'autocomplete' => 'off',
      ],
      '#attached' => ['library' => ['librechart_pharmacy/medication_autocomplete']],
    ];
    $element['tid'] = [
      '#type' => 'hidden',
      '#default_value' => $tid,
      '#attributes' => ['class' => ['medication-autocomplete__tid']],
    ];

    return $element;
  }

  /**
   * Validates that a typed medication was actually chosen from the list.
   *
   * A free-typed name with no matching hidden term id means the user did not
   * pick a suggestion, so the selection cannot be resolved.
   *
   * @param array<string, mixed> $element
   *   The wrapper element, with `target_id` and `tid` children, to validate.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public static function validateElement(array $element, FormStateInterface $form_state): void {
    $name = trim((string) ($element['target_id']['#value'] ?? ''));
    $tid = trim((string) ($element['tid']['#value'] ?? ''));
    if ($name === '') {
      return;
    }
    if (!ctype_digit($tid)) {
      $form_state->setError($element['target_id'], new TranslatableMarkup('Please choose a medication from the list.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<int, array<string, mixed>> $values
   *   The submitted field values.
   * @param array<string, mixed> $form
   *   The full form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array<int, array<string, mixed>>
   *   The massaged field values keyed by delta.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as $delta => $value) {
      $tid = trim((string) ($value['tid'] ?? ''));
      if ($tid !== '' && ctype_digit($tid)) {
        $values[$delta] = ['target_id' => (int) $tid];
      }
      else {
        unset($values[$delta]);
      }
    }
    return $values;
  }

}
