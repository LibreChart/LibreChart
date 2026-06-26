/**
 * @file
 * Toggles the red issue flag on station-strip steps.
 *
 * Each step in an interactive station strip (one rendered for a saved visit) is
 * clickable. Clicking — or pressing Enter/Space while it is focused — toggles a
 * persistent red flag that marks an unresolved issue or a required return at
 * that station. The toggle POSTs to the visit's flag endpoint and reflects the
 * server's authoritative state; the visual change is applied optimistically and
 * rolled back if the request fails.
 */

((Drupal, drupalSettings, once) => {
  'use strict';

  // Drupal's CSRF token for same-origin write requests. Fetched once on first
  // use and reused for the life of the page.
  let csrfToken = null;

  /**
   * Returns Drupal's session CSRF token, fetching and caching it on first call.
   *
   * @return {Promise<string>}
   *   The CSRF token.
   */
  function getToken() {
    if (csrfToken) {
      return Promise.resolve(csrfToken);
    }
    const url = `${drupalSettings.path.baseUrl}session/token`;
    return fetch(url, { credentials: 'same-origin' })
      .then((response) => response.text())
      .then((token) => {
        csrfToken = token;
        return token;
      });
  }

  /**
   * Sends the flag toggle to the server and resolves with its new state.
   *
   * @param {string} visitId
   *   The visit's entity id.
   * @param {string} station
   *   The station machine name to toggle.
   *
   * @return {Promise<boolean>}
   *   The resulting flag state reported by the server.
   */
  function postToggle(visitId, station) {
    return getToken().then((token) => {
      const url = `${drupalSettings.path.baseUrl}visit/${visitId}/flag/${station}`;
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': token },
      }).then((response) => {
        if (!response.ok) {
          throw new Error(`Flag toggle failed: ${response.status}`);
        }
        return response.json();
      }).then((data) => Boolean(data.flagged));
    });
  }

  /**
   * Reflects a flag state on a step's classes and ARIA.
   *
   * @param {HTMLElement} step
   *   The station-strip step element.
   * @param {boolean} flagged
   *   Whether the station is flagged.
   */
  function paint(step, flagged) {
    step.classList.toggle('station-strip__step--flagged', flagged);
    step.setAttribute('aria-pressed', flagged ? 'true' : 'false');
  }

  /**
   * Toggles the flag on a step, optimistically and with rollback on failure.
   *
   * @param {HTMLElement} step
   *   The station-strip step element.
   * @param {string} visitId
   *   The visit's entity id.
   */
  function toggle(step, visitId) {
    // Ignore re-entrant clicks while a request for this step is in flight.
    if (step.dataset.flagBusy === '1') {
      return;
    }
    const station = step.getAttribute('data-station');
    const optimistic = !step.classList.contains('station-strip__step--flagged');
    step.dataset.flagBusy = '1';
    paint(step, optimistic);

    postToggle(visitId, station)
      .then((flagged) => {
        paint(step, flagged);
      })
      .catch(() => {
        // Roll back to the pre-click state and let the user retry.
        paint(step, !optimistic);
      })
      .finally(() => {
        delete step.dataset.flagBusy;
      });
  }

  Drupal.behaviors.librechartStationFlag = {
    attach(context) {
      once('station-flag', '.station-strip--interactive', context).forEach((strip) => {
        const visitId = strip.getAttribute('data-visit-id');
        if (!visitId) {
          return;
        }
        strip.querySelectorAll('.station-strip__step').forEach((step) => {
          step.addEventListener('click', () => toggle(step, visitId));
          step.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              toggle(step, visitId);
            }
          });
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
