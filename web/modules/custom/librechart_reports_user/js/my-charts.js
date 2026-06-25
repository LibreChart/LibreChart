/**
 * @file
 * Renders the My Dashboard activity charts with Chart.js.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  // Slate/blue palette matching the Reports section.
  const PALETTE = [
    '#1d4ed8', '#0ea5e9', '#14b8a6', '#22c55e', '#eab308',
    '#f97316', '#ef4444', '#ec4899', '#8b5cf6', '#64748b',
    '#0891b2', '#16a34a', '#ca8a04', '#dc2626', '#7c3aed',
  ];

  function barChart(canvas, data, horizontal) {
    return new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: data.labels,
        datasets: [{ data: data.values, backgroundColor: PALETTE }],
      },
      options: {
        indexAxis: horizontal ? 'y' : 'x',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { precision: 0 } },
          y: { ticks: { autoSkip: false } },
        },
      },
    });
  }

  function doughnutChart(canvas, data) {
    return new Chart(canvas.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: data.labels,
        datasets: [{ data: data.values, backgroundColor: PALETTE }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
      },
    });
  }

  function lineChart(canvas, data) {
    return new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels: data.labels,
        datasets: [{
          data: data.values,
          borderColor: '#1d4ed8',
          backgroundColor: 'rgba(29, 78, 216, 0.1)',
          fill: true,
          tension: 0.3,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { ticks: { precision: 0 } } },
      },
    });
  }

  const BUILDERS = {
    activity: function (canvas, data) { return lineChart(canvas, data); },
    specialty: function (canvas, data) { return barChart(canvas, data, true); },
    diagnoses: function (canvas, data) { return barChart(canvas, data, true); },
    age: function (canvas, data) { return barChart(canvas, data, true); },
    sex: function (canvas, data) { return doughnutChart(canvas, data); },
  };

  Drupal.behaviors.librechartUserCharts = {
    attach: function (context) {
      const settings = drupalSettings.librechartReportsUser || {};
      const charts = settings.charts || {};
      once('lc-user-chart', '[data-lc-user-chart]', context).forEach(function (canvas) {
        const key = canvas.getAttribute('data-lc-user-chart');
        const data = charts[key];
        const builder = BUILDERS[key];
        if (!builder || !data || !data.labels || data.labels.length === 0) {
          const empty = document.createElement('p');
          empty.className = 'lc-chart-card__empty';
          empty.textContent = Drupal.t('No data yet.');
          canvas.parentNode.replaceChild(empty, canvas);
          return;
        }
        builder(canvas, data);
      });
    },
  };
})(Drupal, drupalSettings, once);
