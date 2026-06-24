<?php

/**
 * @file
 * Builds the bundled WHO growth-standard LMS data for librechart_visit.
 *
 * The Colombian MINSALUD growth charts (Resolución 2465 de 2016) are the WHO
 * Child Growth Standards (0–5y) and the WHO 2007 Growth Reference (5–19y). This
 * script converts the published WHO LMS tables into the compact JSON the
 * GrowthStandard service consumes, so production (offline LAN) needs no network
 * access. Re-run locally only when refreshing the source tables.
 *
 * Source tables (downloaded to a temp dir before running):
 *   0–5y, by day   — WorldHealthOrganization/anthro (data-raw/growthstandards):
 *     weianthro.txt  → wfa   (weight-for-age)
 *     lenanthro.txt  → lhfa  (length/height-for-age)
 *     wflanthro.txt  → wfl   (weight-for-length, 45–110 cm)
 *     wfhanthro.txt  → wfh   (weight-for-height, 65–120 cm)
 *     bmianthro.txt  → bfa   (BMI-for-age)
 *   5–19y, by month — hafen/growthstandards (who2007_R):
 *     hfawho2007.txt → hfa2007 (height-for-age, 61–228 mo)
 *     bfawho2007.txt → bfa2007 (BMI-for-age, 61–228 mo)
 *
 * Usage: php scripts/build_who_growth_data.php /path/to/who_src_dir.
 */

declare(strict_types=1);

$src = $argv[1] ?? '/tmp/who_src';
$out = __DIR__ . '/../web/modules/custom/librechart_visit/data/who';

if (!is_dir($src)) {
  fwrite(STDERR, "Source dir not found: $src\n");
  exit(1);
}
if (!is_dir($out) && !mkdir($out, 0755, TRUE) && !is_dir($out)) {
  fwrite(STDERR, "Cannot create output dir: $out\n");
  exit(1);
}

// Indicator => [source file, x-axis machine name, x-axis column index].
$specs = [
  'wfa'     => ['weianthro.txt', 'age_days', 1],
  'lhfa'    => ['lenanthro.txt', 'age_days', 1],
  'wfl'     => ['wflanthro.txt', 'length_cm', 1],
  'wfh'     => ['wfhanthro.txt', 'height_cm', 1],
  'bfa'     => ['bmianthro.txt', 'age_days', 1],
  'hfa2007' => ['hfawho2007.txt', 'age_months', 1],
  'bfa2007' => ['bfawho2007.txt', 'age_months', 1],
];

foreach ($specs as $indicator => [$file, $xaxis, $xcol]) {
  $path = "$src/$file";
  if (!is_readable($path)) {
    fwrite(STDERR, "Skipping $indicator: cannot read $path\n");
    continue;
  }
  // The WHO tables are tab-delimited.
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  $rows = array_map(static fn(string $line) => explode("\t", $line), $lines);
  $header = array_shift($rows);
  // Column layout is always: sex, <x>, l, m, s, [loh]. Locate l/m/s.
  $li = array_search('l', $header, TRUE);
  $mi = array_search('m', $header, TRUE);
  $si = array_search('s', $header, TRUE);
  if ($li === FALSE || $mi === FALSE || $si === FALSE) {
    fwrite(STDERR, "Skipping $indicator: l/m/s columns not found in header\n");
    continue;
  }

  $data = ['1' => [], '2' => []];
  foreach ($rows as $r) {
    if (count($r) < 5) {
      continue;
    }
    $sex = (string) (int) $r[0];
    if (!isset($data[$sex])) {
      continue;
    }
    $x = $r[$xcol] + 0;
    $data[$sex][] = [
      $x,
      (float) $r[$li],
      (float) $r[$mi],
      (float) $r[$si],
    ];
  }

  // Sort each series by x ascending for binary-search lookup.
  foreach ($data as &$series) {
    usort($series, static fn($a, $b) => $a[0] <=> $b[0]);
  }
  unset($series);

  $xmin = min($data['1'][0][0], $data['2'][0][0]);
  $xmax = max(end($data['1'])[0], end($data['2'])[0]);

  $payload = [
    'indicator' => $indicator,
    'x' => $xaxis,
    'xmin' => $xmin,
    'xmax' => $xmax,
    'source' => $file,
    'data' => $data,
  ];

  file_put_contents(
    "$out/$indicator.json",
    json_encode($payload, JSON_UNESCAPED_SLASHES)
  );
  printf("Wrote %s.json (%d boys, %d girls rows)\n", $indicator, count($data['1']), count($data['2']));
}

echo "Done.\n";
