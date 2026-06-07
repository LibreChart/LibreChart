<?php

/**
 * @file
 * Follow-up fix for the conditional_fields migration: change `values_set: 1`
 * (WIDGET mode — expects a serialized widget value in `value_form`) to
 * `values_set: 2` (AND mode — compares against the `values` array). The
 * legacy config saved `values_set: 1` for boolean checkbox triggers but
 * never populated `value_form`, so the new module crashes when rendering.
 *
 * AND with a single-element values list is the right semantic for "this
 * checkbox is checked" → "the value '1' is present."
 *
 * Run: ddev drush php:script scripts/fix_conditional_values_set.php
 */

declare(strict_types=1);

$config_factory = \Drupal::configFactory();

$targets = [
  'core.entity_form_display.visit.visit.default',
  'core.entity_form_display.paragraph.lab_result.default',
];

$updated = 0;
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
      // Normalize values_set:
      //   0 (legacy "string equality") → 2 (AND with single value).
      //   1 (WIDGET mode) → 2 (AND) when no value_form was set.
      if (isset($settings['values_set']) && in_array((int) $settings['values_set'], [0, 1], TRUE)) {
        $settings['values_set'] = 2;
        // Ensure `values` contains the literal value we want to match.
        if (isset($settings['value']) && $settings['value'] !== '') {
          $settings['values'] = [$settings['value']];
        }
        $updated++;
        $changed = TRUE;
        echo "  fixed: $name / $field_name / $uuid\n";
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

echo "Total entries fixed: $updated\n";
