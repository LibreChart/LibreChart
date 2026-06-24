/**
 * @file
 * Pins the patient's age to the bottom-left of the visit form.
 *
 * Self-contained (offline clinic LAN). The age is computed server-side from the
 * referenced patient's date of birth and provided via drupalSettings, mirroring
 * the way recorded allergies are pinned to the bottom of every station's form.
 * Rendered as a single fixed badge so clinicians can see the patient's age at
 * any scroll position.
 */

((Drupal, once, drupalSettings) => {
  'use strict';

  const BADGE_ID = 'lc-age-sticky';

  /**
   * Returns (creating if needed) the fixed age badge element.
   *
   * @return {HTMLElement}
   *   The badge container.
   */
  function getBadge() {
    let badge = document.getElementById(BADGE_ID);
    if (!badge) {
      badge = document.createElement('div');
      badge.id = BADGE_ID;
      badge.className = 'lc-age-sticky';
      badge.setAttribute('aria-label', Drupal.t('Patient age'));
      document.body.appendChild(badge);
    }
    return badge;
  }

  /**
   * Renders the age string in the badge, hiding it when empty.
   *
   * @param {string} age
   *   The formatted age string (already localized server-side).
   */
  function renderAge(age) {
    const value = String(age || '').trim();
    const badge = getBadge();
    badge.textContent = '';
    if (!value.length) {
      badge.classList.remove('lc-age-sticky--visible');
      return;
    }
    const pill = document.createElement('span');
    pill.className = 'lc-age-value';
    pill.textContent = value;
    badge.appendChild(pill);
    badge.classList.add('lc-age-sticky--visible');
  }

  Drupal.behaviors.librechartAgeSticky = {
    attach(context) {
      const settings = drupalSettings.librechartVisit || {};
      once('age-sticky-init', 'body', context).forEach(() => {
        renderAge(settings.patientAge || '');
      });
    },
  };
})(Drupal, once, drupalSettings);
