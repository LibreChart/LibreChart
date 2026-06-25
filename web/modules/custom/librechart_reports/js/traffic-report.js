/**
 * @file
 * Renders the Traffic report charts with Chart.js.
 *
 * Reads aggregated data from drupalSettings.librechartReports.traffic and draws
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
   * Returns true when a dataset has at least one category to plot.
   */
  function hasData(set) {
    return set && set.labels && set.labels.length > 0;
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
        maintainAspectRatio: false,
        plugins: noLegend,
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0 } },
          // Force every category label; Chart.js otherwise thins them to fit.
          y: { ticks: { autoSkip: false } }
        }
      }
    });
  }

  /**
   * Chart factory keyed by chart id.
   */
  var BUILDERS = {
    // Average minutes spent in each station.
    dwell: function (ctx, set) {
      return new Chart(ctx, {
        type: 'bar',
        data: {
          labels: set.labels,
          datasets: [{ label: Drupal.t('Average minutes'), data: set.values, backgroundColor: colour(0) }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (item) {
                  return item.parsed.y + ' ' + Drupal.t('min');
                }
              }
            }
          },
          scales: {
            x: { ticks: { autoSkip: false, maxRotation: 90, minRotation: 0 } },
            y: { beginAtZero: true, title: { display: true, text: Drupal.t('Minutes') } }
          }
        }
      });
    },
    // Arrivals vs. completions per hour, as grouped bars.
    flow: function (ctx, set) {
      return new Chart(ctx, {
        type: 'bar',
        data: {
          labels: set.labels,
          datasets: [
            { label: Drupal.t('Arrivals'), data: set.arrivals, backgroundColor: colour(0) },
            { label: Drupal.t('Completions'), data: set.completions, backgroundColor: colour(1) }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' } },
          scales: {
            x: { ticks: { autoSkip: false } },
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        }
      });
    },
    // Concurrent patients on site across the day, as a filled line.
    census: function (ctx, set) {
      return new Chart(ctx, {
        type: 'line',
        data: {
          labels: set.labels,
          datasets: [{
            label: Drupal.t('Patients on site'),
            data: set.values,
            borderColor: colour(4),
            backgroundColor: 'rgba(8, 145, 178, 0.15)',
            fill: true,
            tension: 0.3,
            pointRadius: 3
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: noLegend,
          scales: {
            x: { ticks: { autoSkip: false } },
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        }
      });
    },
    // Distinct patients each station handled.
    throughput: function (ctx, set) {
      return horizontalBar(ctx, set);
    }
  };

  Drupal.behaviors.librechartTrafficReport = {
    attach: function (context) {
      var data = (drupalSettings.librechartReports || {}).traffic;
      if (!data || typeof Chart === 'undefined') {
        return;
      }
      once('lc-traffic-report', '[data-lc-chart]', context).forEach(function (canvas) {
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
