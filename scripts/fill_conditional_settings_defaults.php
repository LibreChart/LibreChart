<?php

/**
 * @file
 * Backfill missing optional keys (`selector`, `effect_options`) into the
 * conditional_fields settings arrays. The contrib module's form helper reads
 * `$options['selector']` without checking isset() (line 258 of
 * ConditionalFieldsFormHelper.php), so an unset value triggers a PHP 8
 * undefined-key warning. The module handles an empty/null selector via
 * buildJquerySelectorForField(), so '' is a safe default.
 *
 * Run: ddev drush php:script scripts/fill_conditional_settings_defaults.php
 */

declare(strict_types=1);

$config_factory = \Drupal::configFactory();

$targets = [
  'core.entity_form_display.visit.visit.default',
  'core.entity_form_display.paragraph.lab_result.default',
];

$touched = 0;
foreach ($targets as $name) {
  $config = $config_factory->getEditable($name);
  $content = $config->get('content') ?? [];
  $changed = FALSE;

  foreach ($content as $field_name => &$field_config) {
    $cf = &$field_config['third_party_settings']['conditional_fields'] ?? NULL;
    if (!is_array($cf)) {
      continue;
    }
    foreach ($cf as $uuid => &$entry) {
      $settings = &$entry['settings'];
      if (!is_array($settings)) {
        continue;
      }
      $defaults = [
        'selector' => '',
        'effect_options' => [],
        'reset' => FALSE,
      ];
      foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $settings)) {
          $settings[$key] = $default;
          $changed = TRUE;
          $touched++;
        }
      }
    }
    unset($entry);
  }
  unset($field_config);

  if ($changed) {
    $config->set('content', $content)->save();
    echo "Saved: $name\n";
  }
}

echo "Total settings keys backfilled: $touched\n";
