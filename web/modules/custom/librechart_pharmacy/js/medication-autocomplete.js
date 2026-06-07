/**
 * @file
 * Stock-aware medication typeahead for the prescribing picker.
 *
 * Self-contained (no external assets) so it works on the offline clinic LAN.
 * Renders up to ten matches from the pharmacy autocomplete route, colours rows
 * by stock level, and prevents selecting out-of-stock medications (FR-002/003).
 */

((Drupal, once, drupalSettings) => {
  'use strict';

  const MIN_CHARS = 1;
  const DEBOUNCE_MS = 200;

  /**
   * Returns the hidden term-id input paired with a medication text input.
   *
   * @param {HTMLElement} input
   *   The medication text input.
   *
   * @return {HTMLElement|null}
   *   The sibling hidden input that carries the selected term id, if present.
   */
  function tidField(input) {
    const wrap = input.closest('.medication-autocomplete-wrapper');
    return wrap ? wrap.querySelector('.medication-autocomplete__tid') : null;
  }

  /**
   * Closes any open suggestion menu attached to an input.
   *
   * @param {HTMLElement} input
   *   The medication text input.
   */
  function closeMenu(input) {
    const menu = input.parentNode.querySelector('.medication-autocomplete__menu');
    if (menu) {
      menu.remove();
    }
    input.setAttribute('aria-expanded', 'false');
  }

  /**
   * Builds and shows the suggestion menu for a set of items.
   *
   * @param {HTMLElement} input
   *   The medication text input.
   * @param {Array} items
   *   Suggestion objects from the autocomplete endpoint.
   */
  function renderMenu(input, items) {
    closeMenu(input);
    if (!items.length) {
      return;
    }
    const menu = document.createElement('ul');
    menu.className = 'medication-autocomplete__menu';
    menu.setAttribute('role', 'listbox');

    items.forEach((item) => {
      const row = document.createElement('li');
      row.className = 'medication-autocomplete__item';
      row.setAttribute('role', 'option');
      row.textContent = `${item.label} (${item.qty} in stock)`;

      if (item.status === 'low_stock') {
        row.classList.add('medication--low-stock');
      } else if (item.status === 'out_of_stock') {
        row.classList.add('medication--out-of-stock');
      }

      if (!item.selectable) {
        row.classList.add('medication-autocomplete__item--disabled');
        row.setAttribute('aria-disabled', 'true');
      } else {
        row.addEventListener('mousedown', (event) => {
          // mousedown (not click) so it fires before the input blur.
          event.preventDefault();
          // Show the drug name only; stash the term id in the hidden input.
          input.value = item.label;
          const tid = tidField(input);
          if (tid) {
            tid.value = item.tid;
          }
          closeMenu(input);
        });
      }
      menu.appendChild(row);
    });

    input.parentNode.appendChild(menu);
    input.setAttribute('aria-expanded', 'true');
  }

  /**
   * Fetches matches for the typed string and renders them.
   *
   * @param {HTMLElement} input
   *   The medication text input.
   */
  function fetchMatches(input) {
    const url = input.getAttribute('data-medication-autocomplete-url');
    const typed = input.value.trim();
    if (!url || typed.length < MIN_CHARS) {
      closeMenu(input);
      return;
    }
    fetch(`${url}?q=${encodeURIComponent(typed)}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then((response) => (response.ok ? response.json() : []))
      .then((items) => renderMenu(input, Array.isArray(items) ? items : []))
      .catch(() => closeMenu(input));
  }

  Drupal.behaviors.librechartMedicationAutocomplete = {
    attach(context) {
      once('medication-autocomplete', 'input.medication-autocomplete', context).forEach(
        (input) => {
          input.setAttribute('role', 'combobox');
          input.setAttribute('aria-autocomplete', 'list');
          input.setAttribute('aria-expanded', 'false');

          let timer = null;
          input.addEventListener('input', () => {
            // Typing invalidates any prior selection: clear the stored term id
            // so only a fresh pick from the list submits a resolvable value.
            const tid = tidField(input);
            if (tid) {
              tid.value = '';
            }
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fetchMatches(input), DEBOUNCE_MS);
          });
          input.addEventListener('blur', () => {
            // Delay so a mousedown selection can complete first.
            window.setTimeout(() => closeMenu(input), 150);
          });
          input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
              closeMenu(input);
            }
          });
        },
      );
    },
  };
})(Drupal, once, drupalSettings);
