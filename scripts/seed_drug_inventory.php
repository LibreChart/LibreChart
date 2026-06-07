<?php

/**
 * @file
 * Seeds ~100 DrugInventory records across the 3 clinic sites.
 *
 * Pulls drug names from the drug-bearing vocabularies (anti_infective_agents,
 * chronic_diseases, pain_management, vitamins_nutrients_lv) and distributes
 * a sampled subset of (drug × clinic_site) pairs, each with a realistic
 * on-hand quantity and low-stock threshold. Roughly 1 in 8 records is set
 * at or below threshold so the dashboard's low-stock flag has something to
 * surface.
 *
 * Idempotent: deterministic PRNG seed; existing (drug, clinic_site) records
 * are skipped.
 *
 * Run: ddev drush php:script scripts/seed_drug_inventory.php
 */

declare(strict_types=1);

use Drupal\librechart_pharmacy\Entity\DrugInventory;

mt_srand(20260519);

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$inventory_storage = \Drupal::entityTypeManager()->getStorage('drug_inventory');

$site_ids = $term_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', 'clinic_sites')
  ->sort('name', 'ASC')
  ->execute();
$site_ids = array_values(array_map('intval', $site_ids));
if (count($site_ids) === 0) {
  throw new \RuntimeException('No clinic sites found. Run seed_visits.php first.');
}

$drug_vocabs = [
  'anti_infective_agents',
  'chronic_diseases',
  'pain_management',
  'vitamins_nutrients_lv',
];

$drug_ids = $term_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', $drug_vocabs, 'IN')
  ->execute();
$drug_ids = array_values(array_map('intval', $drug_ids));
if (count($drug_ids) === 0) {
  throw new \RuntimeException('No drug terms found.');
}

$target_per_site = (int) ceil(100 / count($site_ids));

// Units pool — picked based on drug name patterns where possible.
$pick_unit = function (string $drug_label): string {
  $lower = strtolower($drug_label);
  return match (TRUE) {
    str_contains($lower, 'suspension') || str_contains($lower, 'liquid') => 'bottle',
    str_contains($lower, 'cream') || str_contains($lower, 'ointment') => 'tube',
    str_contains($lower, 'gel') => 'tube',
    str_contains($lower, 'solution') => 'bottle',
    str_contains($lower, 'packet') => 'packet',
    str_contains($lower, 'tablet') || str_contains($lower, 'capsule') => 'tablet',
    default => 'unit',
  };
};

$created = 0;
$skipped = 0;
$low_stock_count = 0;

foreach ($site_ids as $site_id) {
  // Pick a fresh subset of drugs for each clinic site.
  $shuffled = $drug_ids;
  for ($i = count($shuffled) - 1; $i > 0; $i--) {
    $j = mt_rand(0, $i);
    [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
  }
  $selected = array_slice($shuffled, 0, $target_per_site);

  foreach ($selected as $drug_id) {
    $existing = $inventory_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('drug', $drug_id)
      ->condition('clinic_site', $site_id)
      ->execute();
    if ($existing) {
      $skipped++;
      continue;
    }

    $threshold = mt_rand(10, 25);
    // ~12% of records land at or below threshold (low-stock).
    $is_low = mt_rand(1, 100) <= 12;
    $on_hand = $is_low
      ? mt_rand(0, $threshold)
      : mt_rand($threshold + 20, 500);
    if ($is_low) {
      $low_stock_count++;
    }

    /** @var \Drupal\taxonomy\TermInterface $drug_term */
    $drug_term = $term_storage->load($drug_id);
    $unit = $pick_unit($drug_term->label() ?? '');

    $inventory = DrugInventory::create([
      'drug' => $drug_id,
      'clinic_site' => $site_id,
      'quantity_on_hand' => $on_hand,
      'low_stock_threshold' => $threshold,
      'unit' => $unit,
    ]);
    $inventory->save();
    $created++;
  }
}

echo sprintf(
  "Inventory seeded: %d records created, %d skipped (already existed). %d records below low-stock threshold.\n",
  $created,
  $skipped,
  $low_stock_count,
);
