/**
 * @file
 * Paints completed education-referral rows green on the visit form.
 *
 * Education referrals render with the paragraphs widget in open edit mode, so
 * each referral's `complete` checkbox is visible inline. This behavior tags the
 * referral's table row with `education-row--complete` whenever its checkbox is
 * checked (and on initial load), mirroring the light-green treatment that
 * fulfilled medications receive. The class is toggled live as the box changes
 * so the cue updates without saving.
 */

((Drupal, once) => {
  'use strict';

  // Matches the `complete` checkbox of an education_referrals paragraph, e.g.
  // education_referrals[0][subform][complete][value].
  const COMPLETE_NAME = /^education_referrals\[\d+\]\[subform\]\[complete\]\[value\]$/;

  /**
   * Toggles the completed class on the row owning the given checkbox.
   *
   * @param {HTMLInputElement} checkbox
   *   The referral's `complete` checkbox.
   */
  function syncRow(checkbox) {
    const row = checkbox.closest('tr');
    if (row) {
      row.classList.toggle('education-row--complete', checkbox.checked);
    }
  }

  Drupal.behaviors.librechartEducationComplete = {
    attach(context) {
      once('education-complete', 'input[type="checkbox"]', context)
        .filter((box) => COMPLETE_NAME.test(box.name || ''))
        .forEach((box) => {
          syncRow(box);
          box.addEventListener('change', () => syncRow(box));
        });
    },
  };
})(Drupal, once);
