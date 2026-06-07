<?php

/**
 * @file
 * One-off import of the 2026 medication formulary ("Med List - 2026").
 *
 * Wipes all existing drug-related content (prescription items, inventory
 * receipts, drug inventory records, and the taxonomy terms in the 11
 * medication vocabularies), then recreates each formulary drug as a taxonomy
 * term plus a single drug_inventory record holding the parsed on-hand quantity.
 *
 * Run with: ddev drush php:script scripts/import_med_list_2026.php
 *
 * Source data: scripts/med_import.json — [{name, qty, vid, raw_amount}, ...].
 */

declare(strict_types=1);

use Drupal\taxonomy\Entity\Term;

$etm = \Drupal::entityTypeManager();

$json = file_get_contents(__DIR__ . '/med_import.json');
$data = $json !== FALSE ? json_decode($json, TRUE) : NULL;
if (!is_array($data) || $data === []) {
  throw new \RuntimeException('Could not read scripts/med_import.json.');
}

// The single clinic-wide stock pool uses the primary clinic-site term
// (Carmen de Chucurí Clinic, tid 242); the inventory report keys by drug only.
$clinic_site = 242;

// --- 1. Delete dependent content first, then inventory, then drug terms. ---
foreach (['prescription_item', 'inventory_receipt', 'drug_inventory'] as $type) {
  $storage = $etm->getStorage($type);
  $entities = $storage->loadMultiple();
  if ($entities !== []) {
    $storage->delete($entities);
  }
  echo 'Deleted ' . count($entities) . ' ' . $type . " entities\n";
}

$term_storage = $etm->getStorage('taxonomy_term');
$drug_vocabs = array_keys(librechart_pharmacy_drug_vocabularies());
$tids = $term_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', $drug_vocabs, 'IN')
  ->execute();
if ($tids !== []) {
  $term_storage->delete($term_storage->loadMultiple($tids));
}
echo 'Deleted ' . count($tids) . " drug taxonomy terms\n";

// --- 2. Import the formulary: one term + one inventory record per drug. ---
$inv_storage = $etm->getStorage('drug_inventory');
$created = 0;
foreach ($data as $row) {
  $term = Term::create(['vid' => $row['vid'], 'name' => $row['name']]);
  $term->save();

  $inv_storage->create([
    'drug' => $term->id(),
    'clinic_site' => $clinic_site,
    'quantity_on_hand' => (int) $row['qty'],
    'low_stock_threshold' => 10,
  ])->save();
  $created++;
}
echo 'Created ' . $created . " drugs + inventory records\n";
