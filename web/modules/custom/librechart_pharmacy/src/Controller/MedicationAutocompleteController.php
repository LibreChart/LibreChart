<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\librechart_pharmacy\Entity\DrugInventory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns stock-aware medication matches for the prescribing autocomplete.
 *
 * Sourced from DrugInventory (the single clinic-wide stock pool), each match
 * carries its live stock status so the picker can colour rows and prevent
 * selecting out-of-stock medications. See
 * specs/005-pharmacy-refinement/contracts/medication-autocomplete.md.
 */
final class MedicationAutocompleteController extends ControllerBase {

  /**
   * Maximum number of suggestions returned (FR-001).
   */
  private const MAX_RESULTS = 10;

  /**
   * Handles the autocomplete request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request; the typed string is read from the `q` parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   At most ten matches, each `{value,label,tid,status,qty,selectable}`.
   */
  public function handle(Request $request): JsonResponse {
    $typed = trim((string) $request->query->get('q', ''));
    if ($typed === '') {
      return new JsonResponse([]);
    }

    // Find candidate drug terms by name, then keep only those that have an
    // inventory record (the picker only offers stocked medications).
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $tids = $term_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('name', $typed, 'CONTAINS')
      ->sort('name')
      ->range(0, 50)
      ->execute();
    if (empty($tids)) {
      return new JsonResponse([]);
    }

    $inventory_storage = $this->entityTypeManager()->getStorage('drug_inventory');
    $inventory_ids = $inventory_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('drug', $tids, 'IN')
      ->execute();
    if (empty($inventory_ids)) {
      return new JsonResponse([]);
    }

    // Single clinic-wide pool: one inventory record per drug. Aggregate
    // defensively in case more than one record exists for a drug.
    $on_hand = [];
    $threshold = [];
    foreach ($inventory_storage->loadMultiple($inventory_ids) as $inventory) {
      if (!$inventory instanceof DrugInventory) {
        continue;
      }
      $tid = (int) $inventory->get('drug')->target_id;
      $on_hand[$tid] = ($on_hand[$tid] ?? 0) + (int) $inventory->get('quantity_on_hand')->value;
      // Use the lowest threshold seen for the drug.
      $current = (int) $inventory->get('low_stock_threshold')->value;
      $threshold[$tid] = isset($threshold[$tid]) ? min($threshold[$tid], $current) : $current;
    }

    $results = [];
    foreach ($term_storage->loadMultiple($tids) as $tid => $term) {
      $tid = (int) $tid;
      if (!isset($on_hand[$tid])) {
        continue;
      }
      $qty = $on_hand[$tid];
      $status = $this->stockStatus($qty, $threshold[$tid] ?? 0);
      $label = $term->label();
      $results[] = [
        'value' => $label . ' (' . $tid . ')',
        'label' => $label,
        'tid' => $tid,
        'status' => $status,
        'qty' => $qty,
        'selectable' => $status !== 'out_of_stock',
      ];
      if (count($results) >= self::MAX_RESULTS) {
        break;
      }
    }

    return new JsonResponse($results);
  }

  /**
   * Classifies stock level per FR-028.
   *
   * @param int $qty
   *   Quantity on hand.
   * @param int $threshold
   *   The low-stock threshold.
   *
   * @return string
   *   One of `out_of_stock`, `low_stock`, or `in_stock`.
   */
  private function stockStatus(int $qty, int $threshold): string {
    if ($qty <= 0) {
      return 'out_of_stock';
    }
    if ($qty <= $threshold) {
      return 'low_stock';
    }
    return 'in_stock';
  }

}
