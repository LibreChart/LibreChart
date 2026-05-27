<?php

/**
 * @file
 * Bulk seed script: generates 200 additional Patient entities.
 *
 * Uses a fixed PRNG seed so re-runs deterministically produce the same set;
 * combined with cédula uniqueness checks, the script is idempotent.
 *
 * Requires the three locations from seed_patients.php to already exist.
 *
 * Run: ddev drush php:script scripts/seed_patients_bulk.php
 */

declare(strict_types=1);

use Drupal\librechart_patient\Entity\Patient;

mt_srand(20260518);

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$patient_storage = \Drupal::entityTypeManager()->getStorage('patient');

$locations = [
  ['Carmen de Chucurí', 'La Aragua'],
  ['San Vicente de Chucurí', 'Yarima'],
  ['Simacota', 'La Aguada'],
];

$location_ids = [];
foreach ($locations as [$muni, $village]) {
  $m = $term_storage->loadByProperties(['name' => $muni, 'vid' => 'municipalities']);
  $v = $term_storage->loadByProperties(['name' => $village, 'vid' => 'village_town']);
  if (!$m || !$v) {
    throw new \RuntimeException(
      "Missing location term '$muni' or '$village'. Run seed_patients.php first."
    );
  }
  $location_ids[] = [
    'municipality' => (int) reset($m)->id(),
    'village_town' => (int) reset($v)->id(),
  ];
}

$male_first = [
  'Juan', 'Carlos', 'José', 'Luis', 'Diego', 'Andrés', 'Miguel', 'Jorge',
  'Pedro', 'Ricardo', 'Fernando', 'Eduardo', 'Alejandro', 'Manuel', 'Roberto',
  'Sergio', 'Camilo', 'Pablo', 'Mario', 'Ramón', 'Óscar', 'Hernán', 'Javier',
  'Iván', 'Felipe', 'Antonio', 'Francisco', 'Gabriel', 'Rafael', 'Daniel',
  'David', 'Esteban', 'Mateo', 'Santiago', 'Sebastián', 'Nicolás', 'Tomás',
  'Cristian', 'Héctor', 'Álvaro', 'Wilson', 'Edinson', 'Yeison', 'Brayan',
];

$female_first = [
  'María', 'Ana', 'Luisa', 'Carmen', 'Rosa', 'Patricia', 'Sofía', 'Isabel',
  'Gloria', 'Marta', 'Diana', 'Laura', 'Andrea', 'Mónica', 'Sandra', 'Claudia',
  'Yolanda', 'Beatriz', 'Adriana', 'Liliana', 'Camila', 'Valentina', 'Daniela',
  'Paula', 'Catalina', 'Mariana', 'Juliana', 'Natalia', 'Ángela', 'Ximena',
  'Helena', 'Cecilia', 'Margarita', 'Esperanza', 'Pilar', 'Lucía', 'Inés',
  'Teresa', 'Olga', 'Amparo', 'Yulieth', 'Leidy', 'Maribel', 'Yenny',
];

$male_second = [
  'Antonio', 'Mario', 'Alberto', 'Manuel', 'Felipe', 'Andrés', 'Camilo',
  'Pablo', 'Esteban', 'Javier', 'Eduardo', 'Alejandro', 'David', 'Carlos',
  'Luis', 'Ángel', 'José', 'Ernesto', 'Enrique', 'Ramón',
];

$female_second = [
  'Elena', 'Lucía', 'Cristina', 'Sofía', 'Isabel', 'Fernanda', 'Patricia',
  'Mercedes', 'del Pilar', 'del Carmen', 'Inés', 'Helena', 'Camila',
  'Andrea', 'Paula', 'José', 'Eugenia', 'Victoria', 'Alejandra',
];

$surnames = [
  'Rodríguez', 'García', 'Martínez', 'González', 'Hernández', 'López',
  'Pérez', 'Sánchez', 'Ramírez', 'Torres', 'Flores', 'Rivera', 'Gómez',
  'Díaz', 'Cruz', 'Morales', 'Ortiz', 'Gutiérrez', 'Chávez', 'Ramos',
  'Vargas', 'Castillo', 'Jiménez', 'Mendoza', 'Ruiz', 'Álvarez', 'Romero',
  'Suárez', 'Castro', 'Moreno', 'Muñoz', 'Rojas', 'Aguilar', 'Cárdenas',
  'Ospina', 'Pineda', 'Quintero', 'Salazar', 'Vásquez', 'Navarro', 'Reyes',
  'Acosta', 'Cortés', 'Delgado', 'Herrera', 'Medina', 'Núñez', 'Peña',
  'Solís', 'Valencia', 'Zapata', 'Ríos', 'Bermúdez', 'Cabrera', 'Arias',
  'Bernal', 'Buitrago', 'Galvis', 'Cadena', 'Carrillo', 'Pinilla', 'Serrano',
];

$pick = fn(array $a) => $a[mt_rand(0, count($a) - 1)];

// Colombian cédula format approximated by age:
// - Adults (25+ years old): 7-8 digit cédula de ciudadanía.
// - Younger residents: 10-digit NUIP starting with 1.
$gen_cedula = function (int $birth_year): string {
  $age = (int) date('Y') - $birth_year;
  if ($age < 25) {
    return (string) mt_rand(1090000000, 1199999999);
  }
  return (string) mt_rand(10000000, 99999999);
};

$gen_dob = function (string $start, string $end): string {
  $ts = mt_rand(strtotime($start), strtotime($end));
  return date('Y-m-d', $ts);
};

$target = 200;
$created = 0;
$skipped = 0;
$attempts = 0;
$max_attempts = $target * 5;

while ($created < $target && $attempts < $max_attempts) {
  $attempts++;

  $is_female = mt_rand(0, 1) === 1;
  $sex = $is_female ? 'female' : 'male';
  $first = $pick($is_female ? $female_first : $male_first);
  // ~40% compound first names (common in rural Colombia).
  if (mt_rand(1, 100) <= 40) {
    $first .= ' ' . $pick($is_female ? $female_second : $male_second);
  }
  $last = $pick($surnames) . ' ' . $pick($surnames);

  $dob = $gen_dob('1935-01-01', '2024-12-31');
  $cedula = $gen_cedula((int) substr($dob, 0, 4));

  if ($patient_storage->loadByProperties(['cedula' => $cedula])) {
    $skipped++;
    continue;
  }

  $location = $location_ids[mt_rand(0, count($location_ids) - 1)];
  $patient = Patient::create([
    'first_name' => $first,
    'last_name' => $last,
    'date_of_birth' => $dob,
    'sex' => $sex,
    'cedula' => $cedula,
    'municipality' => $location['municipality'],
    'village_town' => $location['village_town'],
  ]);
  $patient->setRevisionLogMessage('Seeded bulk sample patient data.');
  $patient->setRevisionUserId(1);
  $patient->save();
  $created++;
}

echo sprintf(
  "Bulk seed complete: %d patients created, %d skipped (cédula collision), %d total attempts.\n",
  $created,
  $skipped,
  $attempts
);
