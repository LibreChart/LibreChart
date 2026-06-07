<?php

/**
 * @file
 * Converts entity reference autocomplete widgets on the visit form to
 * Select2's `select2_entity_reference` widget with autocomplete (AJAX
 * lazy-load) enabled.
 *
 * Why autocomplete=TRUE: the dx_* taxonomies and patient list each contain
 * hundreds of options. Rendering them all into the DOM on form load would
 * inflate page weight; Select2's autocomplete mode loads matches over AJAX
 * as the user types while still presenting a dropdown UI on focus.
 *
 * Run: ddev drush php:script scripts/convert_to_select2.php
 */

declare(strict_types=1);

$config_factory = \Drupal::configFactory();

$targets = [
  'core.entity_form_display.visit.visit.default',
];

$widget_map = [
  'entity_reference_autocomplete' => 'select2_entity_reference',
  'entity_reference_autocomplete_tags' => 'select2_entity_reference',
];

$converted = 0;
foreach ($targets as $name) {
  $config = $config_factory->getEditable($name);
  $content = $config->get('content') ?? [];
  $changed = FALSE;

  foreach ($content as $field_name => &$field_config) {
    $type = $field_config['type'] ?? '';
    if (!isset($widget_map[$type])) {
      continue;
    }

    $old_settings = $field_config['settings'] ?? [];
    $field_config['type'] = $widget_map[$type];
    $field_config['settings'] = [
      'autocomplete' => TRUE,
      'match_operator' => $old_settings['match_operator'] ?? 'CONTAINS',
      'match_limit' => $old_settings['match_limit'] ?? 10,
      'width' => '100%',
    ];

    $changed = TRUE;
    $converted++;
    echo "  converted: $name / $field_name ($type → select2_entity_reference)\n";
  }
  unset($field_config);

  if ($changed) {
    // Make sure 'select2' is listed as a dependency for the form display.
    $dependencies = $config->get('dependencies') ?? [];
    $dependencies['module'] = array_values(array_unique(
      array_merge($dependencies['module'] ?? [], ['select2'])
    ));
    sort($dependencies['module']);
    $config->set('dependencies', $dependencies);
    $config->set('content', $content)->save();
    echo "Saved: $name\n";
  }
}

echo "Total widgets converted: $converted\n";
