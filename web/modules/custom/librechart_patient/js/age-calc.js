/**
 * @file
 * Live Age calculation beside the patient's Date of Birth.
 *
 * Age is derived from date_of_birth, so the Age field is read-only; this keeps
 * it in sync as the DOB input changes. Patients 18 and over show whole years;
 * under 18 shows years and months. Mirrors _librechart_patient_format_age() so
 * the live value matches the server-rendered one.
 */
((Drupal, once) => {
  /**
   * Builds the age string from a 'YYYY-MM-DD' date-of-birth value.
   *
   * @param {string} dobValue
   *   The DOB input value.
   *
   * @return {string}
   *   The formatted age, or '' when it cannot be computed.
   */
  const formatAge = (dobValue) => {
    if (!dobValue) {
      return '';
    }
    const dob = new Date(`${dobValue}T00:00:00`);
    if (Number.isNaN(dob.getTime())) {
      return '';
    }
    const today = new Date();
    let years = today.getFullYear() - dob.getFullYear();
    let months = today.getMonth() - dob.getMonth();
    // Borrow a month when today's day is earlier in the month than the DOB.
    if (today.getDate() < dob.getDate()) {
      months -= 1;
    }
    if (months < 0) {
      years -= 1;
      months += 12;
    }
    if (years < 0) {
      return '';
    }
    if (years >= 18) {
      return Drupal.formatPlural(years, '1 year', '@count years');
    }
    return `${Drupal.formatPlural(years, '1 year', '@count years')} ${Drupal.formatPlural(months, '1 month', '@count months')}`;
  };

  Drupal.behaviors.librechartAgeCalc = {
    attach(context) {
      once('librechart-age-calc', '[data-librechart-age]', context).forEach(
        (age) => {
          const form = age.closest('form');
          if (!form) {
            return;
          }
          const dob = form.querySelector('[data-librechart-age-dob]');
          if (!dob) {
            return;
          }
          const update = () => {
            age.value = formatAge(dob.value);
          };
          dob.addEventListener('input', update);
          dob.addEventListener('change', update);
        },
      );
    },
  };
})(Drupal, once);
