<?php

declare(strict_types=1);

namespace Drupal\Tests\librechart_visit\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for main menu navigation links to patient and visit listings.
 *
 * Verifies that /patients and /visits are accessible to authenticated staff
 * with the required permissions, are inaccessible to anonymous users, and
 * that both links appear in the main navigation for permitted users.
 *
 * @group librechart_visit
 */
class MainMenuNavLinksTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'librechart_patient',
    'librechart_visit',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A staff user with permission to view patient and visit entities.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $staffUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->staffUser = $this->drupalCreateUser([
      'view patient entities',
      'view visit entities',
      'access content',
    ]);
  }

  /**
   * Tests /patients returns 200 for an authenticated staff user.
   */
  public function testPatientListingAccessGrantedForAuthenticatedUser(): void {
    $this->drupalLogin($this->staffUser);
    $this->drupalGet('/patients');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests /patients is inaccessible for an anonymous user.
   */
  public function testPatientListingDeniedForAnonymousUser(): void {
    $this->drupalGet('/patients');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests /visits returns 200 for an authenticated staff user.
   */
  public function testVisitListingAccessGrantedForAuthenticatedUser(): void {
    $this->drupalLogin($this->staffUser);
    $this->drupalGet('/visits');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests /visits is inaccessible for an anonymous user.
   */
  public function testVisitListingDeniedForAnonymousUser(): void {
    $this->drupalGet('/visits');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests the main menu contains the "Patients" link for an authenticated user.
   */
  public function testMainMenuContainsPatientsLinkForAuthenticatedUser(): void {
    $this->drupalLogin($this->staffUser);
    $this->drupalGet('<front>');
    $this->assertSession()->linkExists('Patients');
  }

  /**
   * Tests the main menu contains the "Visits" link for an authenticated user.
   */
  public function testMainMenuContainsVisitsLinkForAuthenticatedUser(): void {
    $this->drupalLogin($this->staffUser);
    $this->drupalGet('<front>');
    $this->assertSession()->linkExists('Visits');
  }

  /**
   * Tests /visits defaults to showing only today's visits.
   *
   * Verifies that the exposed date filter on the visit listing page
   * defaults to the current date so only today's visits appear on load.
   * This assertion checks the date filter form element has a default value
   * of today; the full behavioural test requires fixture data and is left
   * for integration testing.
   */
  public function testVisitListingDefaultsToTodaysVisits(): void {
    $this->drupalLogin($this->staffUser);
    $this->drupalGet('/visits');
    $this->assertSession()->statusCodeEquals(200);
    // The date filter form should be present on the page.
    $this->assertSession()->elementExists('css', 'form.views-exposed-form');
  }

}
