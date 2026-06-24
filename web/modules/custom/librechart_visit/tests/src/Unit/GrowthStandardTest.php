<?php

declare(strict_types=1);

namespace Drupal\Tests\librechart_visit\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\librechart_visit\Service\GrowthStandard;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the GrowthStandard z-score service.
 *
 * Verifies the LMS z-score maths against published WHO reference values and
 * the age-based selection of applicable indicator charts.
 *
 * @group librechart_visit
 *
 * @coversDefaultClass \Drupal\librechart_visit\Service\GrowthStandard
 */
class GrowthStandardTest extends UnitTestCase {

  /**
   * Builds the service pointed at the module's bundled WHO data directory.
   */
  private function service(): GrowthStandard {
    $list = $this->createMock(ModuleExtensionList::class);
    // The service appends '/data/who'; return the module root so the bundled
    // JSON tables resolve under test.
    $list->method('getPath')->willReturn(dirname(__DIR__, 3));
    return new GrowthStandard($list);
  }

  /**
   * The median measurement must yield a z-score of zero.
   *
   * @covers ::zscoreFor
   */
  public function testMedianIsZero(): void {
    $svc = $this->service();
    // Boy, weight-for-age at day 0: WHO median M = 3.3464 kg.
    $this->assertEqualsWithDelta(0.0, $svc->zscoreFor('wfa', 'male', 0, 3.3464), 0.001);
    // Girl, length-for-age at day 0: WHO median M = 49.1477 cm.
    $this->assertEqualsWithDelta(0.0, $svc->zscoreFor('lhfa', 'female', 0, 49.1477), 0.001);
  }

  /**
   * Standard-deviation boundaries must land on the expected integer z-scores.
   *
   * @covers ::zscoreFor
   */
  public function testStandardDeviationBoundaries(): void {
    $svc = $this->service();
    // Boy weight-for-age, day 0: published −2SD ≈ 2.46 kg, +2SD ≈ 4.42 kg.
    $this->assertEqualsWithDelta(-2.0, $svc->zscoreFor('wfa', 'male', 0, 2.460), 0.01);
    $this->assertEqualsWithDelta(2.0, $svc->zscoreFor('wfa', 'male', 0, 4.420), 0.01);
  }

  /**
   * Values beyond ±3 SD use the WHO tail extrapolation and exceed |3|.
   *
   * @covers ::zscoreFor
   */
  public function testTailExtrapolation(): void {
    $svc = $this->service();
    $this->assertGreaterThan(3.0, $svc->zscoreFor('wfa', 'male', 0, 6.0));
    $this->assertLessThan(-3.0, $svc->zscoreFor('wfa', 'male', 0, 1.5));
  }

  /**
   * An unknown indicator returns NULL rather than erroring.
   *
   * @covers ::zscoreFor
   */
  public function testUnknownIndicator(): void {
    $this->assertNull($this->service()->zscoreFor('nope', 'male', 0, 10));
  }

  /**
   * A child under two years gets the four 0–2y indicator tabs.
   *
   * @covers ::applicableCharts
   */
  public function testApplicableChartsInfant(): void {
    // 18 months ≈ 548 days.
    $charts = $this->service()->applicableCharts(548, 'male', 80.0, 11.0, 17.2);
    $titles = array_column($charts, 'title');
    $this->assertSame(['Weight-for-age', 'Length-for-age', 'Weight-for-length', 'BMI-for-age'], $titles);
    foreach ($charts as $chart) {
      $this->assertSame('0–2 years', $chart['band']);
      $this->assertNotNull($chart['point']);
      $this->assertNotNull($chart['z']);
      $this->assertCount(7, $chart['curves']);
    }
  }

  /**
   * A school-age child gets only the height- and BMI-for-age tabs.
   *
   * @covers ::applicableCharts
   */
  public function testApplicableChartsSchoolAge(): void {
    // 8 years ≈ 2922 days.
    $charts = $this->service()->applicableCharts(2922, 'female', 128.0, 26.0, 15.9);
    $this->assertSame(['Height-for-age', 'BMI-for-age'], array_column($charts, 'title'));
    $this->assertSame('5–19 years', $charts[0]['band']);
  }

  /**
   * Ages outside the 0–19y standard range yield no charts.
   *
   * @covers ::applicableCharts
   */
  public function testOutOfRange(): void {
    // ~20 years.
    $this->assertSame([], $this->service()->applicableCharts(7305, 'male', 175.0, 70.0, 22.9));
  }

  /**
   * Missing vitals still render curves, but with no plotted point or z.
   *
   * @covers ::applicableCharts
   */
  public function testMissingVitals(): void {
    $charts = $this->service()->applicableCharts(548, 'male', NULL, NULL, NULL);
    $this->assertNotEmpty($charts);
    foreach ($charts as $chart) {
      $this->assertNull($chart['point']);
      $this->assertNull($chart['z']);
      $this->assertCount(7, $chart['curves']);
    }
  }

}
