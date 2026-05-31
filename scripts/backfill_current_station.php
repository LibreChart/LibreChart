<?php

/**
 * @file
 * Backfills `current_station` on existing Visit rows after the 9001 update.
 *
 * Rules (per data-model.md):
 *   - status == 'complete' → current_station = 'complete' (the terminal
 *     sentinel; data-model R2 revision per Clarifications 2026-05-19 Q1).
 *   - any other status     → current_station = 'registration'
 *
 * Idempotent: skips rows that already have a non-null current_station.
 * Bypasses revisions (setNewRevision FALSE) — backfill is not a clinical
 * transition and shouldn't pollute the per-visit revision history.
 *
 * Supports --revert: sets current_station back to NULL on every row.
 *
 * Run: ddev drush php:script scripts/backfill_current_station.php
 *      ddev drush php:script scripts/backfill_current_station.php -- --revert
 */

declare(strict_types=1);

$revert = in_array('--revert', $argv ?? [], TRUE);

$storage = \Drupal::entityTypeManager()->getStorage('visit');
$vids = $storage->getQuery()->accessCheck(FALSE)->execute();

$updated = 0;
$skipped = 0;
foreach ($storage->loadMultiple($vids) as $visit) {
  $current = $visit->get('current_station')->value;

  if ($revert) {
    if ($current === NULL) {
      $skipped++;
      continue;
    }
    $visit->set('current_station', NULL);
  }
  else {
    if ($current !== NULL && $current !== '') {
      $skipped++;
      continue;
    }
    $target = $visit->get('status')->value === 'complete'
      ? 'complete'
      : 'registration';
    $visit->set('current_station', $target);
  }

  $visit->setNewRevision(FALSE);
  $visit->save();
  $updated++;
}

echo sprintf(
  "Backfilled current_station: %d visits updated, %d skipped (%s).\n",
  $updated,
  $skipped,
  $revert ? 'already null' : 'already set',
);
