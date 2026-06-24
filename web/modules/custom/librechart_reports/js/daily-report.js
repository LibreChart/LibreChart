/**
 * @file
 * Renders the Daily patient report charts with Chart.js.
 *
 * Reads aggregated data from drupalSettings.librechartReports.daily and draws
 * one chart per canvas. No network access is required at runtime; Chart.js is
 * self-hosted in the module for the offline LAN deployment.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  // Slate/blue palette matching the rest of the EMR UI.
  var PALETTE = [
    '#1d4ed8', '#16a34a', '#ca8a04', '#dc2626', '#0891b2',
    '#7c3aed', '#db2777', '#475569', '#65a30d', '#ea580c',
    '#0d9488', '#9333ea', '#e11d48', '#2563eb', '#facc15'
  ];

  /**
   * Returns a palette colour for index i, cycling when needed.
   */
  function colour(i) {
    return PALETTE[i % PALETTE.length];
  }

  /**
   * Builds an array of palette colours of the given length.
   */
  function colours(n) {
    var out = [];
    for (var i = 0; i < n; i++) {
      out.push(colour(i));
    }
    return out;
  }

  var noLegend = { legend: { display: false } };

  /**
   * Returns true when a dataset has at least one value to plot.
   */
  function hasData(set) {
    return set && set.values && set.values.length > 0;
  }

  /**
   * Renders a "no data" message into a canvas's card and skips the chart.
   */
  function renderEmpty(canvas) {
    var msg = document.createElement('p');
    msg.className = 'lc-chart-card__empty';
    msg.textContent = Drupal.t('No data for this day.');
    canvas.replaceWith(msg);
  }

  /**
   * Chart factory keyed by chart id.
   */
  var BUILDERS = {
    age: function (ctx, set) {
      return new Chart(ctx, {
        type: 'bar',
        data: {
          labels: set.labels,
          datasets: [{ label: Drupal.t('Patients'), data: set.values, backgroundColor: colour(0) }]
        },
        options: {
          responsive: true,
          plugins: noLegend,
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
      });
    },
    sex: function (ctx, set) {
      return new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: set.labels,
          datasets: [{ data: set.values, backgroundColor: colours(set.values.length) }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
      });
    },
    municipality: function (ctx, set) {
      return horizontalBar(ctx, set);
    },
    specialty: function (ctx, set) {
      return horizontalBar(ctx, set);
    },
    medications: function (ctx, set) {
      return horizontalBar(ctx, set);
    },
    diagnoses: function (ctx, set) {
      return horizontalBar(ctx, set);
    }
  };

  /**
   * Shared horizontal bar chart used for ranked lists.
   */
  function horizontalBar(ctx, set) {
    return new Chart(ctx, {
      type: 'bar',
      data: {
        labels: set.labels,
        datasets: [{ label: Drupal.t('Count'), data: set.values, backgroundColor: colours(set.values.length) }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        plugins: noLegend,
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    });
  }

  Drupal.behaviors.librechartDailyReport = {
    attach: function (context) {
      var data = (drupalSettings.librechartReports || {}).daily;
      if (!data || typeof Chart === 'undefined') {
        return;
      }
      once('lc-daily-report', '[data-lc-chart]', context).forEach(function (canvas) {
        var key = canvas.getAttribute('data-lc-chart');
        var set = data[key];
        var builder = BUILDERS[key];
        if (!builder) {
          return;
        }
        if (!hasData(set)) {
          renderEmpty(canvas);
          return;
        }
        builder(canvas.getContext('2d'), set);
      });
    }
  };
})(Drupal, drupalSettings, once);
