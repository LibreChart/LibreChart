/**
 * @file
 * Pins the patient's allergies to the bottom of the visit form as red pills.
 *
 * Self-contained (offline clinic LAN). Allergies are only editable at the
 * triage station, but the warning must stay visible at every station, so the
 * current allergy labels are provided server-side (drupalSettings) and rendered
 * regardless of whether the editable widget is present. When the editable
 * select IS on the page (triage), the pills also update live as allergies are
 * added or removed. The "None" term is excluded by id so the exclusion survives
 * translation of the label (feature 005, FR-018/019/020).
 */

((Drupal, once, drupalSettings) => {
  'use strict';

  const BAR_ID = 'lc-allergy-sticky';

  /**
   * Returns (creating if needed) the fixed sticky bar element.
   *
   * @return {HTMLElement}
   *   The bar container.
   */
  function getBar() {
    let bar = document.getElementById(BAR_ID);
    if (!bar) {
      bar = document.createElement('div');
      bar.id = BAR_ID;
      bar.className = 'lc-allergy-sticky';
      bar.setAttribute('aria-label', Drupal.t('Patient allergies'));
      document.body.appendChild(bar);
    }
    return bar;
  }

  /**
   * Renders a set of allergy labels as red pills in the sticky bar.
   *
   * @param {Array} labels
   *   Allergy label strings (already excluding "None").
   */
  function renderLabels(labels) {
    const list = (labels || []).map((l) => String(l).trim()).filter((l) => l.length);
    const bar = getBar();
    bar.textContent = '';
    if (!list.length) {
      bar.classList.remove('lc-allergy-sticky--visible');
      return;
    }
    list.forEach((label) => {
      const pill = document.createElement('span');
      pill.className = 'lc-allergy-pill';
      pill.textContent = label;
      bar.appendChild(pill);
    });
    bar.classList.add('lc-allergy-sticky--visible');
  }

  /**
   * Derives the labels from a live allergies select and renders them.
   *
   * @param {HTMLSelectElement} select
   *   The allergies select element.
   */
  function renderFromSelect(select) {
    const noneTid = parseInt(
      (drupalSettings.librechartVisit && drupalSettings.librechartVisit.noneAllergyTid) || 0,
      10,
    );
    const labels = [];
    Array.from(select.options).forEach((option) => {
      if (!option.selected) {
        return;
      }
      const tid = parseInt(option.value, 10);
      if (Number.isNaN(tid) || tid === noneTid) {
        return;
      }
      labels.push(option.textContent.trim());
    });
    renderLabels(labels);
  }

  Drupal.behaviors.librechartAllergySticky = {
    attach(context) {
      const settings = drupalSettings.librechartVisit || {};

      // Server-provided state: render once on load so pills appear at every
      // station, even where the editable widget is access-restricted.
      once('allergy-sticky-init', 'body', context).forEach(() => {
        renderLabels(settings.allergyLabels || []);
      });

      // When the editable widget is present (triage), drive live updates from
      // it. select2 fires change through jQuery, so bind both ways.
      once('allergy-sticky', 'select.js-allergy-source, .js-allergy-source select', context).forEach(
        (select) => {
          renderFromSelect(select);
          select.addEventListener('change', () => renderFromSelect(select));
          if (window.jQuery) {
            window.jQuery(select).on('change', () => renderFromSelect(select));
          }
        },
      );
    },
  };
})(Drupal, once, drupalSettings);
