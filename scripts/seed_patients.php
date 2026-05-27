<?php

/**
 * @file
 * Seed script for sample Patient entities and location taxonomy terms.
 *
 * Creates 3 municipalities (Santander, Colombia), 3 corresponding veredas,
 * and 20 patient records with realistic Colombian demographics.
 *
 * Idempotent: skips terms that already exist by name+vid, and skips patients
 * whose cédula already exists. Safe to re-run.
 *
 * Run: ddev drush php:script scripts/seed_patients.php
 */

declare(strict_types=1);

use Drupal\librechart_patient\Entity\Patient;
use Drupal\taxonomy\Entity\Term;

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$patient_storage = \Drupal::entityTypeManager()->getStorage('patient');

$ensure_term = function (string $name, string $vid) use ($term_storage): int {
  $existing = $term_storage->loadByProperties(['name' => $name, 'vid' => $vid]);
  if ($existing) {
    return (int) reset($existing)->id();
  }
  $term = Term::create(['name' => $name, 'vid' => $vid]);
  $term->save();
  return (int) $term->id();
};

// Three municipalities in Santander, Colombia, each paired with a real vereda.
$locations = [
  ['municipality' => 'Carmen de Chucurí', 'village' => 'La Aragua'],
  ['municipality' => 'San Vicente de Chucurí', 'village' => 'Yarima'],
  ['municipality' => 'Simacota', 'village' => 'La Aguada'],
];

$location_ids = [];
foreach ($locations as $loc) {
  $location_ids[] = [
    'municipality' => $ensure_term($loc['municipality'], 'municipalities'),
    'village_town' => $ensure_term($loc['village'], 'village_town'),
  ];
}

// 20 patients: realistic Colombian names, mixed ages (peds → elderly),
// mixed sex, unique cédulas in plausible Colombian formats.
// Columns: first_name, last_name, dob (Y-m-d), sex, cedula.
$patients = [
  ['María Elena',  'Rodríguez Pérez',     '1958-03-15', 'female', '27845612'],
  ['José Antonio', 'Martínez González',   '1972-08-22', 'male',   '13456789'],
  ['Ana Lucía',    'García López',        '2017-11-03', 'female', '1095678901'],
  ['Carlos Mario', 'Hernández Ramírez',   '1965-01-30', 'male',   '13567812'],
  ['Luisa Fernanda','Sánchez Torres',     '1989-06-10', 'female', '63789012'],
  ['Pedro Pablo',  'López Castro',        '1945-12-05', 'male',   '5234567'],
  ['Sofía',        'Díaz Moreno',         '2021-04-18', 'female', '1098765432'],
  ['Juan Camilo',  'Vargas Jiménez',      '1980-09-25', 'male',   '91345678'],
  ['Carmen Rosa',  'Ruiz Ortiz',          '1962-07-12', 'female', '27456789'],
  ['Andrés Felipe','Gómez Mendoza',       '1995-02-14', 'male',   '1095123456'],
  ['Patricia',     'Castro Rivera',       '1978-10-08', 'female', '63567890'],
  ['Miguel Ángel', 'Ramírez Suárez',      '1953-05-20', 'male',   '13678901'],
  ['Laura Sofía',  'Pérez Vásquez',       '2010-08-30', 'female', '1107654321'],
  ['Diego Alejandro','Torres Romero',     '1987-03-11', 'male',   '91678123'],
  ['Rosa Helena',  'Jiménez Aguilar',     '1948-11-27', 'female', '27234561'],
  ['Fernando',     'Moreno Cárdenas',     '1969-04-02', 'male',   '13789012'],
  ['Isabel Cristina','Ortiz Navarro',     '2003-12-19', 'female', '1098123456'],
  ['Ricardo',      'Mendoza Reyes',       '1991-07-07', 'male',   '91789234'],
  ['Gloria Inés',  'Salazar Quintero',    '1957-02-24', 'female', '27345678'],
  ['Luis Eduardo', 'Cárdenas Ospina',     '1974-06-16', 'male',   '13890123'],
];

$created = 0;
$skipped = 0;
foreach ($patients as $i => [$first, $last, $dob, $sex, $cedula]) {
  $existing = $patient_storage->loadByProperties(['cedula' => $cedula]);
  if ($existing) {
    $skipped++;
    continue;
  }
  $location = $location_ids[$i % count($location_ids)];
  $patient = Patient::create([
    'first_name' => $first,
    'last_name' => $last,
    'date_of_birth' => $dob,
    'sex' => $sex,
    'cedula' => $cedula,
    'municipality' => $location['municipality'],
    'village_town' => $location['village_town'],
  ]);
  $patient->setRevisionLogMessage('Seeded sample patient data.');
  $patient->setRevisionUserId(1);
  $patient->save();
  $created++;
}

echo sprintf(
  "Locations ensured: %d municipalities, %d veredas.\nPatients: %d created, %d skipped (cédula already existed).\n",
  count($locations),
  count($location_ids),
  $created,
  $skipped
);
