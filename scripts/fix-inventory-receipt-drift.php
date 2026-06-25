<?php

/**
 * @file
 * One-off correction for inventory that drifted under the old "create-only"
 * inventory-receipt behaviour (commit 1cfbf05).
 *
 * Before the fix, editing a receipt's quantity or changing its drug never
 * touched DrugInventory, so two drugs ended up out of step with their
 * receipts:
 *  - Rifaximin 550 mg (term 1058): on hand 5, receipt total 25.
 *  - Simethicone 200 mg (term 1085): no inventory record, receipt total 36.
 *
 * Both have zero dispensed and no opening balance, so the correct on-hand
 * value equals the total received. This sets those absolute values, so the
 * script is idempotent — running it again is a no-op.
 *
 * Run on the target site (read [[project_deploy_process.md]] for prod):
 *   vendor/bin/drush php:script scripts/fix-inventory-receipt-drift.php
 */

declare(strict_types=1);

use Drupal\librechart_pharmacy\Entity\DrugInventory;

// Drug taxonomy term id => correct quantity on hand.
$corrections = [
  1058 => 25,
  1085 => 36,
];

$etm = \Drupal::entityTypeManager();
$inventory_storage = $etm->getStorage('drug_inventory');
$receipt_storage = $etm->getStorage('inventory_receipt');
$term_storage = $etm->getStorage('taxonomy_term');

foreach ($corrections as $drug_id => $target) {
  $term = $term_storage->load($drug_id);
  if ($term === NULL) {
    echo "SKIP  drug {$drug_id}: taxonomy term not found.\n";
    continue;
  }
  $label = (string) $term->label();

  $inventory = DrugInventory::primaryForDrug($drug_id);
  if ($inventory === NULL) {
    // No record yet: open one, seeded from the drug's most recent receipt's
    // clinic site (matches what a fresh receipt would have created).
    $rids = $receipt_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('drug', $drug_id)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $clinic_site = NULL;
    if ($rids) {
      $receipt = $receipt_storage->load((int) reset($rids));
      $clinic_site = $receipt ? (int) $receipt->get('clinic_site')->target_id : NULL;
    }
    if (!$clinic_site) {
      echo "SKIP  {$label} (drug {$drug_id}): no receipt to seed the clinic site.\n";
      continue;
    }
    $inventory = $inventory_storage->create([
      'drug' => $drug_id,
      'clinic_site' => $clinic_site,
      'quantity_on_hand' => 0,
    ]);
    echo "CREATE inventory record for {$label} (drug {$drug_id}), clinic site {$clinic_site}.\n";
  }

  $before = (int) $inventory->get('quantity_on_hand')->value;
  if ($before === $target && !$inventory->isNew()) {
    echo "OK    {$label}: already {$target}, no change.\n";
    continue;
  }
  $inventory->set('quantity_on_hand', $target);
  $inventory->save();
  echo "SET   {$label} (record {$inventory->id()}): {$before} -> {$target}.\n";
}

echo "Done.\n";
