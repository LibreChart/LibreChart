<?php

/**
 * @file
 * One-off migration: convert conditional_fields config from the legacy
 * `conditional_fields_enabled` / `conditional_fields_conditions` schema to
 * the UUID-keyed schema used by conditional_fields 4.x.
 *
 * Old:
 *   third_party_settings:
 *     conditional_fields:
 *       conditional_fields_enabled: 1
 *       conditional_fields_conditions:
 *         - condition: { dependee, options: { ... } }
 *
 * New:
 *   third_party_settings:
 *     conditional_fields:
 *       <uuid>:
 *         uuid: <uuid>
 *         entity_type: <type>
 *         bundle: <bundle>
 *         dependee: <name>
 *         settings: { ... }
 *
 * Updates the form-display config entities in place, then writes the new
 * shape back to the active config store. User must run config:export to
 * sync to disk.
 *
 * Run: ddev drush php:script scripts/migrate_conditional_fields.php
 */

declare(strict_types=1);

$config_factory = \Drupal::configFactory();
$uuid_service = \Drupal::service('uuid');

$targets = [
  'core.entity_form_display.visit.visit.default',
  'core.entity_form_display.paragraph.lab_result.default',
];

$total_migrated = 0;
foreach ($targets as $name) {
  $config = $config_factory->getEditable($name);
  $content = $config->get('content') ?? [];
  $entity_type = $config->get('targetEntityType') ?? '';
  $bundle = $config->get('bundle') ?? '';
  $changed = FALSE;

  foreach ($content as $field_name => $field_config) {
    $cf = $field_config['third_party_settings']['conditional_fields'] ?? NULL;
    if (!is_array($cf) || !isset($cf['conditional_fields_enabled'])) {
      continue;
    }

    $conditions = $cf['conditional_fields_conditions'] ?? [];
    $new = [];
    foreach ($conditions as $entry) {
      $cond = $entry['condition'] ?? [];
      if (!isset($cond['dependee'], $cond['options']) || !is_array($cond['options'])) {
        continue;
      }
      $uuid = $uuid_service->generate();
      $new[$uuid] = [
        'uuid' => $uuid,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'dependee' => $cond['dependee'],
        'settings' => $cond['options'],
      ];
    }

    $content[$field_name]['third_party_settings']['conditional_fields'] = $new;
    if ($new === []) {
      unset($content[$field_name]['third_party_settings']['conditional_fields']);
      if ($content[$field_name]['third_party_settings'] === []) {
        $content[$field_name]['third_party_settings'] = [];
      }
    }
    $changed = TRUE;
    $total_migrated++;
    echo "  migrated: $name / $field_name\n";
  }

  if ($changed) {
    $config->set('content', $content)->save();
    echo "Saved: $name\n";
  }
}

echo "Total field migrations: $total_migrated\n";
