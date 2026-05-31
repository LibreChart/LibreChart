<?php

/**
 * @file
 * Bulk Spanish → English source-string migration across config/sync/*.yml.
 *
 * Skips the config/sync/language/es/ overlay — those entries are intentionally
 * Spanish and back the live UI translation. Works on the top-level YAML files
 * only, where labels and titles must be English source strings.
 *
 * Run from the project root:
 *   php scripts/migrate_spanish_labels.php
 *
 * Idempotent: running again on already-translated files is a no-op (the script
 * looks for exact Spanish substrings, which won't exist after a successful run).
 */

declare(strict_types=1);

$config_dir = __DIR__ . '/../config/sync';

// Map of Spanish source string → English replacement. Each entry is searched
// as an exact substring so quoting / line context in the YAML doesn't matter.
$replacements = [
  // Block & component labels.
  'Enlaces de ayuda a la navegación' => 'Breadcrumbs',
  'Acciones principales de administración' => 'Primary admin actions',
  'Mensajes de estado' => 'Status messages',
  'Marcador de posición' => 'Placeholder',
  'Pestañas' => 'Tabs',
  'Atajos de navegación' => 'Navigation Shortcuts',
  'Vaciar caché' => 'Clear cache',
  'Menú de cuenta de usuario' => 'User account menu',
  'Pie de página' => 'Footer',
  'Inicio de sesión' => 'User login',
  'Cuenta de usuario' => 'User account',
  'Mensajes' => 'Messages',
  'Página de términos de taxonomía' => 'Taxonomy term page',
  'Texto sin formato' => 'Plain text',
  'Inglés' => 'English',

  // Date formats.
  'Formato de fecha de respaldo' => 'Fallback date format',
  'Fecha HTML' => 'HTML Date',
  'Mes HTML' => 'HTML Month',
  'Hora HTML' => 'HTML Time',
  'Año HTML' => 'HTML Year',
  'Fecha anual HTML' => 'HTML Yearless date',
  'Fecha larga por defecto' => 'Default long date',
  'Fecha mediana por defecto' => 'Default medium date',
  'Fecha corta por defecto' => 'Default short date',

  // User roles.
  'Usuario autenticado' => 'Authenticated user',
  'Usuario anónimo' => 'Anonymous user',

  // System actions: role add/remove (each role's name is left intact).
  'Añade el rol Clinician al(los) usuario(s) seleccionado(s)' => 'Add the Clinician role to the selected user(s)',
  'Añade el rol Lab Technician al(los) usuario(s) seleccionado(s)' => 'Add the Lab Technician role to the selected user(s)',
  'Añade el rol Pharmacist al(los) usuario(s) seleccionado(s)' => 'Add the Pharmacist role to the selected user(s)',
  'Añade el rol Physical Therapist al(los) usuario(s) seleccionado(s)' => 'Add the Physical Therapist role to the selected user(s)',
  'Añade el rol Registration Staff al(los) usuario(s) seleccionado(s)' => 'Add the Registration Staff role to the selected user(s)',
  'Añade el rol Teaching Coordinator al(los) usuario(s) seleccionado(s)' => 'Add the Teaching Coordinator role to the selected user(s)',
  'Añade el rol Triage Nurse al(los) usuario(s) seleccionado(s)' => 'Add the Triage Nurse role to the selected user(s)',
  'Elimina el rol Clinician al(los) usuario(s) seleccionado(s)' => 'Remove the Clinician role from the selected user(s)',
  'Elimina el rol Lab Technician al(los) usuario(s) seleccionado(s)' => 'Remove the Lab Technician role from the selected user(s)',
  'Elimina el rol Pharmacist al(los) usuario(s) seleccionado(s)' => 'Remove the Pharmacist role from the selected user(s)',
  'Elimina el rol Physical Therapist al(los) usuario(s) seleccionado(s)' => 'Remove the Physical Therapist role from the selected user(s)',
  'Elimina el rol Registration Staff al(los) usuario(s) seleccionado(s)' => 'Remove the Registration Staff role from the selected user(s)',
  'Elimina el rol Teaching Coordinator al(los) usuario(s) seleccionado(s)' => 'Remove the Teaching Coordinator role from the selected user(s)',
  'Elimina el rol Triage Nurse al(los) usuario(s) seleccionado(s)' => 'Remove the Triage Nurse role from the selected user(s)',

  // System actions: user/content.
  'Bloquear al usuario o usuarios seleccionados' => 'Block the selected user(s)',
  'Desbloquear al usuario o usuarios seleccionados' => 'Unblock the selected user(s)',
  'Cancelar la(s) cuenta(s) de usuario seleccionada(s)' => 'Cancel the selected user account(s)',
  'Eliminar medios' => 'Delete media',
  'Eliminar contenido' => 'Delete content',
  'Promocionar contenido a la página de inicio' => 'Promote content to front page',
  'Eliminar contenido de la página de inicio' => 'Remove content from front page',
  'Anular la publicación del contenido' => 'Unpublish content',
  'Eliminar redirección' => 'Delete redirect',
  'Publicar término de taxonomía' => 'Publish taxonomy term',
  'Despublicar término de taxonomía' => 'Unpublish taxonomy term',

  // captcha.settings.yml.
  'Esta pregunta es para comprobar si usted es un visitante humano y prevenir envíos de spam automatizado.'
    => 'This question is for testing whether or not you are a human visitor and to prevent automated spam submissions.',
  'La respuesta que ingresó para el código de verificación no fue correcta.'
    => 'The answer you entered for the CAPTCHA was not correct.',

  // views.view.watchdog.yml (admin_label + message label).
  "admin_label: Icono" => "admin_label: Icon",
  "label: Mensaje\n" => "label: Message\n",
  'No hay mensajes de registro disponibles.' => 'No log messages available.',
];

$flagged_files = glob($config_dir . '/*.yml');
$updated = 0;
$replacements_made = 0;

foreach ($flagged_files as $path) {
  $contents = file_get_contents($path);
  if ($contents === FALSE) {
    continue;
  }
  $original = $contents;
  foreach ($replacements as $es => $en) {
    if (str_contains($contents, $es)) {
      $count = substr_count($contents, $es);
      $contents = str_replace($es, $en, $contents);
      $replacements_made += $count;
    }
  }
  if ($contents !== $original) {
    file_put_contents($path, $contents);
    $updated++;
    echo "  updated: " . basename($path) . "\n";
  }
}

echo "Total files updated: $updated\n";
echo "Total string replacements: $replacements_made\n";
