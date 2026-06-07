<?php

/**
 * @file
 * Seed script: generates Visit entities for existing patients.
 *
 * Layout:
 * - Creates 3 clinic sites (one per municipality) if missing.
 * - 220 patients × ~1.05 visits = 230 visits across a 3-day window
 *   (2026-05-16 / 17 / 18). Each day runs at one clinic site.
 * - 10 randomly-selected patients receive 2 visits (on different days).
 *   The remaining patients receive 1 visit each.
 * - patient_type derived from age (<18 = pediatric, else adult).
 * - status: complete (these are historical records).
 *
 * Idempotent: skips patients who already have the expected number of
 * visits, so the script is safe to re-run. Uses a fixed PRNG seed so
 * the same patients always land in the "two visits" cohort.
 *
 * Run: ddev drush php:script scripts/seed_visits.php
 */

declare(strict_types=1);

use Drupal\librechart_visit\Entity\Visit;
use Drupal\taxonomy\Entity\Term;

mt_srand(20260518);

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$patient_storage = \Drupal::entityTypeManager()->getStorage('patient');
$visit_storage = \Drupal::entityTypeManager()->getStorage('visit');

$ensure_term = function (string $name, string $vid) use ($term_storage): int {
  $existing = $term_storage->loadByProperties(['name' => $name, 'vid' => $vid]);
  if ($existing) {
    return (int) reset($existing)->id();
  }
  $term = Term::create(['name' => $name, 'vid' => $vid]);
  $term->save();
  return (int) $term->id();
};

// Each day of the 3-day mission runs at one clinic site.
$days = [
  ['date' => '2026-05-16', 'site' => 'Carmen de Chucurí Clinic'],
  ['date' => '2026-05-17', 'site' => 'San Vicente de Chucurí Clinic'],
  ['date' => '2026-05-18', 'site' => 'Simacota Clinic'],
];
foreach ($days as &$day) {
  $day['site_id'] = $ensure_term($day['site'], 'clinic_sites');
}
unset($day);

$patient_ids = $patient_storage->getQuery()->accessCheck(FALSE)->execute();
$patient_ids = array_values($patient_ids);
sort($patient_ids);
if (count($patient_ids) === 0) {
  throw new \RuntimeException('No patients found. Run seed_patients*.php first.');
}

// Pick 10 random patients (deterministic via fixed PRNG seed) for two visits.
$shuffled = $patient_ids;
shuffle_with_mt($shuffled);
$two_visit_ids = array_slice($shuffled, 0, 10);
$two_visit_set = array_flip(array_map('intval', $two_visit_ids));

$patients = $patient_storage->loadMultiple($patient_ids);

$created = 0;
$skipped = 0;
foreach ($patients as $patient) {
  $pid = (int) $patient->id();
  $expected = isset($two_visit_set[$pid]) ? 2 : 1;

  $existing = $visit_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('patient', $pid)
    ->count()
    ->execute();
  $existing = (int) $existing;

  if ($existing >= $expected) {
    $skipped += $expected;
    continue;
  }

  // Derive patient_type from age as of the mission window.
  $dob = $patient->get('date_of_birth')->value;
  $age = age_on($dob, $days[count($days) - 1]['date']);
  $patient_type = $age < 18 ? 'pediatric' : 'adult';

  // Choose distinct days for this patient's visits.
  $day_indexes = range(0, count($days) - 1);
  shuffle_with_mt($day_indexes);
  $needed = $expected - $existing;
  $chosen = array_slice($day_indexes, 0, $needed);

  foreach ($chosen as $day_index) {
    $day = $days[$day_index];
    $visit_datetime = $day['date'] . sprintf(
      'T%02d:%02d:00',
      mt_rand(7, 16),
      mt_rand(0, 59)
    );

    $visit = Visit::create([
      'patient' => $pid,
      'visit_date' => $visit_datetime,
      'patient_type' => $patient_type,
      'clinic_site' => $day['site_id'],
      'status' => 'complete',
    ]);
    $visit->setRevisionLogMessage('Seeded sample visit.');
    $visit->setRevisionUserId(1);
    $visit->save();
    $created++;
  }
}

echo sprintf(
  "Visits: %d created, %d already existed. Clinic sites: %d.\n",
  $created,
  $skipped,
  count($days)
);

/**
 * Compute age in whole years on a given reference date.
 */
function age_on(string $dob, string $on_date): int {
  $birth = new \DateTimeImmutable($dob);
  $ref = new \DateTimeImmutable($on_date);
  return (int) $birth->diff($ref)->y;
}

/**
 * In-place Fisher-Yates shuffle using mt_rand so PRNG seed governs order.
 *
 * PHP's built-in shuffle() does not honor mt_srand on all platforms.
 */
function shuffle_with_mt(array &$arr): void {
  $n = count($arr);
  for ($i = $n - 1; $i > 0; $i--) {
    $j = mt_rand(0, $i);
    [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
  }
}
