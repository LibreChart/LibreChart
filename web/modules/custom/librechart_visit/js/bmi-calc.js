/**
 * @file
 * Live BMI calculation for the Triage vitals.
 *
 * BMI = weight(kg) / height(m)^2, where height is entered in centimetres.
 * The BMI input is read-only; this keeps it in sync as height or weight
 * change. The authoritative value is recomputed server-side in
 * Visit::preSave(), so this is purely a convenience for the data-entry user.
 */
((Drupal, once) => {
  /**
   * Recomputes the BMI input from the height and weight inputs.
   *
   * @param {HTMLInputElement} bmi
   *   The read-only BMI input.
   * @param {HTMLInputElement|null} height
   *   The height input (centimetres).
   * @param {HTMLInputElement|null} weight
   *   The weight input (kilograms).
   */
  const recompute = (bmi, height, weight) => {
    const h = height ? parseFloat(height.value) : NaN;
    const w = weight ? parseFloat(weight.value) : NaN;
    if (h > 0 && w > 0) {
      const meters = h / 100;
      bmi.value = (w / (meters * meters)).toFixed(2);
    } else {
      bmi.value = '';
    }
  };

  Drupal.behaviors.librechartBmiCalc = {
    attach(context) {
      once('librechart-bmi-calc', '[data-librechart-bmi]', context).forEach(
        (bmi) => {
          const form = bmi.closest('form');
          if (!form) {
            return;
          }
          const height = form.querySelector('[data-librechart-bmi-height]');
          const weight = form.querySelector('[data-librechart-bmi-weight]');
          [height, weight].forEach((input) => {
            if (input) {
              input.addEventListener('input', () =>
                recompute(bmi, height, weight),
              );
            }
          });
        },
      );
    },
  };
})(Drupal, once);
