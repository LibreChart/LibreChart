<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Service;

use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Computes WHO / MINSALUD growth-standard z-scores and reference curves.
 *
 * The Colombian growth charts (Resolución 2465 de 2016) are the WHO Child
 * Growth Standards (0–5y) and the WHO 2007 Growth Reference (5–19y). The LMS
 * parameter tables are bundled under the module's data/who directory; see
 * scripts/build_who_growth_data.php for provenance. A z-score is derived with
 * the standard LMS transform z = ((X/M)^L − 1) / (L·S), with the WHO tail
 * adjustment applied beyond ±3 SD.
 */
final class GrowthStandard {

  /**
   * The seven standard-deviation lines drawn on every chart.
   */
  public const Z_LINES = [-3, -2, -1, 0, 1, 2, 3];

  /**
   * Mean days per month used to convert ages for the month-based references.
   */
  private const DAYS_PER_MONTH = 30.4375;

  /**
   * Loaded LMS tables, keyed by indicator machine name.
   *
   * @var array<string, array<string, mixed>|null>
   */
  private array $tables = [];

  /**
   * The absolute path to the bundled WHO data directory.
   */
  private string $dataDir;

  public function __construct(ModuleExtensionList $module_list) {
    $this->dataDir = $module_list->getPath('librechart_visit') . '/data/who';
  }

  /**
   * Builds the chart definitions appropriate to a child's age and sex.
   *
   * @param int $age_days
   *   Age in completed days at the visit.
   * @param string $sex
   *   The patient sex, 'male' or 'female'.
   * @param float|null $height
   *   Height/length in centimetres, if recorded.
   * @param float|null $weight
   *   Weight in kilograms, if recorded.
   * @param float|null $bmi
   *   Body-mass index, if recorded.
   *
   * @return array<int, array>
   *   An ordered list of chart definitions ready for client-side rendering.
   *   Empty when the child is outside the 0–19y standard range.
   */
  public function applicableCharts(int $age_days, string $sex, ?float $height, ?float $weight, ?float $bmi): array {
    $sex_code = $sex === 'female' ? 2 : 1;
    $months = $age_days / self::DAYS_PER_MONTH;
    if ($months < 0 || $months > 228) {
      return [];
    }

    // Each band selects the indicators and axis windows that match the
    // published MINSALUD sub-charts. The window keeps the plot resolution
    // close to the paper chart rather than zooming out across all ages.
    $charts = [];
    if ($months <= 24) {
      $charts[] = $this->ageChart('wfa', 'Weight-for-age', '0–2 years', 'Weight (kg)', $sex_code, 0, 24, $age_days, $weight);
      $charts[] = $this->ageChart('lhfa', 'Length-for-age', '0–2 years', 'Length (cm)', $sex_code, 0, 24, $age_days, $height);
      $charts[] = $this->measureChart('wfl', 'Weight-for-length', '0–2 years', 'Length (cm)', 'Weight (kg)', $sex_code, 45, 110, $height, $weight);
      $charts[] = $this->ageChart('bfa', 'BMI-for-age', '0–2 years', 'BMI (kg/m²)', $sex_code, 0, 24, $age_days, $bmi);
    }
    elseif ($months <= 60) {
      $charts[] = $this->ageChart('wfa', 'Weight-for-age', '2–5 years', 'Weight (kg)', $sex_code, 24, 60, $age_days, $weight);
      $charts[] = $this->ageChart('lhfa', 'Height-for-age', '2–5 years', 'Height (cm)', $sex_code, 24, 60, $age_days, $height);
      $charts[] = $this->measureChart('wfh', 'Weight-for-height', '2–5 years', 'Height (cm)', 'Weight (kg)', $sex_code, 65, 120, $height, $weight);
      $charts[] = $this->ageChart('bfa', 'BMI-for-age', '2–5 years', 'BMI (kg/m²)', $sex_code, 24, 60, $age_days, $bmi);
    }
    else {
      $charts[] = $this->ageChart('hfa2007', 'Height-for-age', '5–19 years', 'Height (cm)', $sex_code, 60, 228, $age_days, $height);
      $charts[] = $this->ageChart('bfa2007', 'BMI-for-age', '5–19 years', 'BMI (kg/m²)', $sex_code, 60, 228, $age_days, $bmi);
    }

    return array_values(array_filter($charts));
  }

  /**
   * Builds an age-on-x-axis chart (age expressed in months).
   *
   * @param string $indicator
   *   The bundled-table machine name.
   * @param string $title
   *   The tab title.
   * @param string $band
   *   The age-band caption.
   * @param string $y_label
   *   The y-axis label.
   * @param int $sex_code
   *   Male is 1, female is 2.
   * @param int $month_min
   *   The window's lower age bound in months.
   * @param int $month_max
   *   The window's upper age bound in months.
   * @param int $age_days
   *   The patient's age in days.
   * @param float|null $value
   *   The measured value to plot, if available.
   *
   * @return array|null
   *   The chart definition, or NULL when the table is unavailable.
   */
  private function ageChart(string $indicator, string $title, string $band, string $y_label, int $sex_code, int $month_min, int $month_max, int $age_days, ?float $value): ?array {
    $table = $this->table($indicator);
    if ($table === NULL) {
      return NULL;
    }
    // The x-axis for an age chart is months; the 0–5y tables are keyed by day,
    // the 5–19y tables by month. xToKey maps a month sample to a table key.
    $by_month = ($table['x'] === 'age_months');
    $x_to_key = static fn(float $m): float => $by_month ? $m : $m * self::DAYS_PER_MONTH;

    $curves = $this->buildCurves($table, $sex_code, $month_min, $month_max, 0.5, $x_to_key);

    $point = NULL;
    $z = NULL;
    if ($value !== NULL && $value > 0) {
      $months = $age_days / self::DAYS_PER_MONTH;
      $z = $this->zscore($table, $sex_code, $x_to_key($months), $value);
      $point = ['x' => round($months, 2), 'y' => $value];
    }

    return $this->assemble($indicator, $title, $band, 'Age (months)', $y_label, $month_min, $month_max, $curves, $point, $z);
  }

  /**
   * Builds a measurement-on-x-axis chart (weight-for-length / -height).
   *
   * @param string $indicator
   *   The bundled-table machine name.
   * @param string $title
   *   The tab title.
   * @param string $band
   *   The age-band caption.
   * @param string $x_label
   *   The x-axis label.
   * @param string $y_label
   *   The y-axis label.
   * @param int $sex_code
   *   Male is 1, female is 2.
   * @param float $x_min
   *   The window's lower bound (cm).
   * @param float $x_max
   *   The window's upper bound (cm).
   * @param float|null $x_value
   *   The measured length/height to plot against, if available.
   * @param float|null $y_value
   *   The measured weight to plot, if available.
   *
   * @return array|null
   *   The chart definition, or NULL when the table is unavailable.
   */
  private function measureChart(string $indicator, string $title, string $band, string $x_label, string $y_label, int $sex_code, float $x_min, float $x_max, ?float $x_value, ?float $y_value): ?array {
    $table = $this->table($indicator);
    if ($table === NULL) {
      return NULL;
    }
    $identity = static fn(float $x): float => $x;
    $curves = $this->buildCurves($table, $sex_code, $x_min, $x_max, 0.5, $identity);

    $point = NULL;
    $z = NULL;
    if ($x_value !== NULL && $x_value > 0 && $y_value !== NULL && $y_value > 0) {
      $z = $this->zscore($table, $sex_code, $x_value, $y_value);
      $point = ['x' => $x_value, 'y' => $y_value];
    }

    return $this->assemble($indicator, $title, $band, $x_label, $y_label, $x_min, $x_max, $curves, $point, $z);
  }

  /**
   * Samples the seven z-lines across an x window.
   *
   * @param array<string, mixed> $table
   *   The loaded LMS table.
   * @param int $sex_code
   *   Male is 1, female is 2.
   * @param float $x_min
   *   The window's lower x bound, in display units.
   * @param float $x_max
   *   The window's upper x bound, in display units.
   * @param float $step
   *   The display-unit sampling step.
   * @param callable $x_to_key
   *   Maps a display-unit x to the table's key space.
   *
   * @return array<array-key, list<array{0: float, 1: float}>>
   *   Map of z-value to a list of [x, y] points.
   */
  private function buildCurves(array $table, int $sex_code, float $x_min, float $x_max, float $step, callable $x_to_key): array {
    $curves = [];
    foreach (self::Z_LINES as $z) {
      $curves[(string) $z] = [];
    }
    for ($x = $x_min; $x <= $x_max + 1e-9; $x += $step) {
      [$l, $m, $s] = $this->lookup($table, $sex_code, $x_to_key($x));
      foreach (self::Z_LINES as $z) {
        $curves[(string) $z][] = [round($x, 3), round($this->valueAtZ($l, $m, $s, $z), 4)];
      }
    }
    return $curves;
  }

  /**
   * Finalises a chart definition, computing the y window from the curves.
   *
   * @param string $indicator
   *   The bundled-table machine name.
   * @param string $title
   *   The tab title.
   * @param string $band
   *   The age-band caption.
   * @param string $x_label
   *   The x-axis label.
   * @param string $y_label
   *   The y-axis label.
   * @param float $x_min
   *   The window's lower x bound.
   * @param float $x_max
   *   The window's upper x bound.
   * @param array<array-key, list<array{0: float, 1: float}>> $curves
   *   The sampled z-line series.
   * @param array{x: float, y: float}|null $point
   *   The plotted patient point, if any.
   * @param float|null $z
   *   The patient's z-score, if computed.
   *
   * @return array<string, mixed>
   *   The chart definition.
   */
  private function assemble(string $indicator, string $title, string $band, string $x_label, string $y_label, float $x_min, float $x_max, array $curves, ?array $point, ?float $z): array {
    $y_values = [];
    foreach ($curves as $points) {
      foreach ($points as [, $y]) {
        $y_values[] = $y;
      }
    }
    $y_min = min($y_values);
    $y_max = max($y_values);
    if ($point !== NULL) {
      $y_min = min($y_min, $point['y']);
      $y_max = max($y_max, $point['y']);
    }

    return [
      'key' => $indicator,
      'title' => $title,
      'band' => $band,
      'xLabel' => $x_label,
      'yLabel' => $y_label,
      'xMin' => $x_min,
      'xMax' => $x_max,
      'yMin' => $y_min,
      'yMax' => $y_max,
      'zLines' => self::Z_LINES,
      'curves' => $curves,
      'point' => $point,
      'z' => $z === NULL ? NULL : round($z, 2),
    ];
  }

  /**
   * Computes a z-score for a measurement under an indicator and sex.
   *
   * @param string $indicator
   *   The bundled-table machine name.
   * @param string $sex
   *   The patient sex, 'male' or 'female'.
   * @param float $x
   *   The lookup key (age in days/months or length/height in cm) in the
   *   table's native units.
   * @param float $y
   *   The measured value (weight, length/height, or BMI).
   *
   * @return float|null
   *   The z-score, or NULL when the indicator is unknown.
   */
  public function zscoreFor(string $indicator, string $sex, float $x, float $y): ?float {
    $table = $this->table($indicator);
    if ($table === NULL) {
      return NULL;
    }
    return $this->zscore($table, $sex === 'female' ? 2 : 1, $x, $y);
  }

  /**
   * Computes a z-score from a loaded table, with the WHO tail adjustment.
   *
   * @param array<string, mixed> $table
   *   The loaded LMS table.
   * @param int $sex_code
   *   Male is 1, female is 2.
   * @param float $x
   *   The lookup key in the table's native units.
   * @param float $y
   *   The measured value.
   */
  private function zscore(array $table, int $sex_code, float $x, float $y): float {
    [$l, $m, $s] = $this->lookup($table, $sex_code, $x);
    $z = abs($l) < 1e-9
      ? log($y / $m) / $s
      : ((($y / $m) ** $l) - 1) / ($l * $s);

    // WHO caps the distribution beyond ±3 SD with a linear extrapolation based
    // on the distance between the third and second standard-deviation values.
    if ($z > 3) {
      $sd3 = $this->valueAtZ($l, $m, $s, 3);
      $sd2 = $this->valueAtZ($l, $m, $s, 2);
      $z = 3 + ($y - $sd3) / ($sd3 - $sd2);
    }
    elseif ($z < -3) {
      $sd3 = $this->valueAtZ($l, $m, $s, -3);
      $sd2 = $this->valueAtZ($l, $m, $s, -2);
      $z = -3 + ($y - $sd3) / ($sd2 - $sd3);
    }

    return $z;
  }

  /**
   * Returns the measured value at a given z under the LMS parameters.
   */
  private function valueAtZ(float $l, float $m, float $s, float $z): float {
    return abs($l) < 1e-9
      ? $m * exp($s * $z)
      : $m * ((1 + $l * $s * $z) ** (1 / $l));
  }

  /**
   * Looks up LMS parameters at x, linearly interpolating between table rows.
   *
   * @param array<string, mixed> $table
   *   The loaded LMS table.
   * @param int $sex_code
   *   Male is 1, female is 2.
   * @param float $x
   *   The lookup key in the table's native units.
   *
   * @return array{0: float, 1: float, 2: float}
   *   The interpolated [L, M, S].
   */
  private function lookup(array $table, int $sex_code, float $x): array {
    $rows = $table['data'][(string) $sex_code];
    $n = count($rows);

    // Clamp to the table bounds.
    if ($x <= $rows[0][0]) {
      return [$rows[0][1], $rows[0][2], $rows[0][3]];
    }
    if ($x >= $rows[$n - 1][0]) {
      $last = $rows[$n - 1];
      return [$last[1], $last[2], $last[3]];
    }

    // Binary search for the bracketing rows.
    $lo = 0;
    $hi = $n - 1;
    while ($hi - $lo > 1) {
      $mid = intdiv($lo + $hi, 2);
      if ($rows[$mid][0] <= $x) {
        $lo = $mid;
      }
      else {
        $hi = $mid;
      }
    }

    [$x0, $l0, $m0, $s0] = $rows[$lo];
    [$x1, $l1, $m1, $s1] = $rows[$hi];
    $t = ($x - $x0) / ($x1 - $x0);

    return [
      $l0 + $t * ($l1 - $l0),
      $m0 + $t * ($m1 - $m0),
      $s0 + $t * ($s1 - $s0),
    ];
  }

  /**
   * Loads and caches a bundled LMS table.
   *
   * @return array|null
   *   The decoded table, or NULL when the file is missing or malformed.
   */
  private function table(string $indicator): ?array {
    if (array_key_exists($indicator, $this->tables)) {
      return $this->tables[$indicator];
    }
    $path = $this->dataDir . '/' . $indicator . '.json';
    $decoded = NULL;
    if (is_readable($path)) {
      $json = file_get_contents($path);
      $data = $json === FALSE ? NULL : json_decode($json, TRUE);
      if (is_array($data) && isset($data['data']['1'], $data['data']['2'])) {
        $decoded = $data;
      }
    }
    return $this->tables[$indicator] = $decoded;
  }

}
