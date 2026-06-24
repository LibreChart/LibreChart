/**
 * @file
 * Renders the WHO / MINSALUD growth charts above the Clinical Evaluation
 * fieldset.
 *
 * The server (GrowthStandard service) supplies, per indicator appropriate to
 * the child's age and sex, the seven z-score reference curves plus the LMS
 * parameters needed to score a measurement. This behaviour draws each
 * indicator as an inline SVG inside a tabbed panel — no external charting
 * library, so it works on the offline LAN deployment — and re-plots the
 * patient's point and z-score live as the triage height and weight inputs
 * change.
 */
((Drupal, once, drupalSettings) => {
  const SVGNS = 'http://www.w3.org/2000/svg';

  // Per-z-line stroke styling, mirroring the MINSALUD paper charts: green
  // median, amber ±1, red ±2 (dashed) and ±3 (solid).
  const Z_STYLE = {
    '-3': { color: '#dc2626', dash: '' },
    '-2': { color: '#dc2626', dash: '6 4' },
    '-1': { color: '#ca8a04', dash: '' },
    0: { color: '#16a34a', dash: '' },
    1: { color: '#ca8a04', dash: '' },
    2: { color: '#dc2626', dash: '6 4' },
    3: { color: '#dc2626', dash: '' },
  };

  const VIEW_W = 720;
  const VIEW_H = 440;
  const M = { top: 16, right: 44, bottom: 44, left: 52 };

  const el = (name, attrs, text) => {
    const node = document.createElementNS(SVGNS, name);
    Object.keys(attrs || {}).forEach((k) => node.setAttribute(k, attrs[k]));
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  };

  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

  /**
   * Computes a "nice" rounded tick step for an axis span.
   *
   * @param {number} span
   *   The axis range (max − min).
   * @param {number} target
   *   The desired approximate number of ticks.
   *
   * @return {number}
   *   A rounded step (1, 2, 2.5 or 5 × a power of ten).
   */
  const niceStep = (span, target) => {
    const raw = span / target;
    const mag = 10 ** Math.floor(Math.log10(raw));
    const norm = raw / mag;
    let step = 1;
    if (norm >= 5) {
      step = 5;
    } else if (norm >= 2.5) {
      step = 2.5;
    } else if (norm >= 2) {
      step = 2;
    }
    return step * mag;
  };

  const fmtZ = (z) => {
    if (z === null || z === undefined || Number.isNaN(z)) {
      return '—';
    }
    return (z > 0 ? '+' : z < 0 ? '−' : '') + Math.abs(z).toFixed(2);
  };

  // --- z-score maths, mirroring the GrowthStandard PHP service. ---

  const valueAtZ = (l, m, s, z) =>
    Math.abs(l) < 1e-9 ? m * Math.exp(s * z) : m * (1 + l * s * z) ** (1 / l);

  /**
   * Computes a z-score from LMS parameters, with the WHO tail adjustment.
   *
   * @param {Array} lms
   *   The [L, M, S] parameters at the lookup point.
   * @param {number} y
   *   The measured value.
   *
   * @return {number}
   *   The z-score.
   */
  const zscore = (lms, y) => {
    const [l, m, s] = lms;
    let z =
      Math.abs(l) < 1e-9 ? Math.log(y / m) / s : ((y / m) ** l - 1) / (l * s);
    if (z > 3) {
      const sd3 = valueAtZ(l, m, s, 3);
      const sd2 = valueAtZ(l, m, s, 2);
      z = 3 + (y - sd3) / (sd3 - sd2);
    } else if (z < -3) {
      const sd3 = valueAtZ(l, m, s, -3);
      const sd2 = valueAtZ(l, m, s, -2);
      z = -3 + (y - sd3) / (sd2 - sd3);
    }
    return z;
  };

  /**
   * Linearly interpolates LMS parameters at x from a sampled [x,L,M,S] table.
   *
   * @param {Array} rows
   *   Rows of [x, L, M, S], ascending by x.
   * @param {number} x
   *   The lookup value.
   *
   * @return {Array}
   *   The interpolated [L, M, S].
   */
  const interpLms = (rows, x) => {
    if (x <= rows[0][0]) {
      return [rows[0][1], rows[0][2], rows[0][3]];
    }
    const last = rows[rows.length - 1];
    if (x >= last[0]) {
      return [last[1], last[2], last[3]];
    }
    let lo = 0;
    let hi = rows.length - 1;
    while (hi - lo > 1) {
      const mid = (lo + hi) >> 1;
      if (rows[mid][0] <= x) {
        lo = mid;
      } else {
        hi = mid;
      }
    }
    const t = (x - rows[lo][0]) / (rows[hi][0] - rows[lo][0]);
    return [1, 2, 3].map((i) => rows[lo][i] + t * (rows[hi][i] - rows[lo][i]));
  };

  /**
   * Derives the plotted point and z-score for a chart from live vitals.
   *
   * @param {object} chart
   *   A chart definition from drupalSettings.
   * @param {number} height
   *   The current height/length in centimetres (NaN if unset).
   * @param {number} weight
   *   The current weight in kilograms (NaN if unset).
   *
   * @return {object|null}
   *   {x, y, z} in data coordinates, or NULL when the inputs are insufficient.
   */
  const livePoint = (chart, height, weight) => {
    const bmi = height > 0 && weight > 0 ? weight / (height / 100) ** 2 : NaN;
    const x = chart.inputs.x === 'age' ? chart.ageMonths : height;
    const y =
      chart.inputs.y === 'weight'
        ? weight
        : chart.inputs.y === 'height'
          ? height
          : bmi;
    if (!Number.isFinite(x) || x < 0 || !(y > 0)) {
      return null;
    }
    if (chart.inputs.x === 'height' && !(x > 0)) {
      return null;
    }
    const lms = chart.lms ? chart.lms : interpLms(chart.lmsByX, x);
    return { x, y, z: zscore(lms, y) };
  };

  const readoutHtml = (z) => {
    if (z === null || z === undefined || Number.isNaN(z)) {
      return `<span class="lc-growth-z-missing">${Drupal.t('Enter height and weight to plot this patient.')}</span>`;
    }
    const a = Math.abs(z);
    const band =
      a <= 1
        ? Drupal.t('within ±1 SD')
        : a <= 2
          ? Drupal.t('between ±1 and ±2 SD')
          : a <= 3
            ? Drupal.t('between ±2 and ±3 SD')
            : Drupal.t('beyond ±3 SD');
    return `${Drupal.t('Z-score')}: <strong class="lc-growth-z">${fmtZ(z)}</strong> <span class="lc-growth-z-band">(${band})</span>`;
  };

  /**
   * Draws one indicator chart's static layers (frame, grid, axes, curves) and
   * returns the scales and an empty layer for the live point.
   *
   * @param {object} chart
   *   A chart definition from drupalSettings.
   *
   * @return {object}
   *   {svg, sx, sy, plot, pointGroup}.
   */
  const drawChart = (chart) => {
    const plotW = VIEW_W - M.left - M.right;
    const plotH = VIEW_H - M.top - M.bottom;

    // Pad the y window slightly so the ±3 curves are not flush to the frame.
    const yPad = (chart.yMax - chart.yMin) * 0.04 || 1;
    const yMin = chart.yMin - yPad;
    const yMax = chart.yMax + yPad;
    const xMin = chart.xMin;
    const xMax = chart.xMax;

    const sx = (x) => M.left + ((x - xMin) / (xMax - xMin)) * plotW;
    const sy = (y) => M.top + plotH - ((y - yMin) / (yMax - yMin)) * plotH;

    const svg = el('svg', {
      viewBox: `0 0 ${VIEW_W} ${VIEW_H}`,
      class: 'lc-growth-svg',
      role: 'img',
      'aria-label': `${chart.title} ${chart.band}`,
    });

    // Plot frame.
    svg.appendChild(
      el('rect', {
        x: M.left,
        y: M.top,
        width: plotW,
        height: plotH,
        fill: '#ffffff',
        stroke: '#cbd5e1',
        'stroke-width': 1,
      }),
    );

    // X gridlines + ticks.
    const xStep = niceStep(xMax - xMin, 8);
    for (let x = Math.ceil(xMin / xStep) * xStep; x <= xMax + 1e-9; x += xStep) {
      const px = sx(x);
      svg.appendChild(
        el('line', { x1: px, y1: M.top, x2: px, y2: M.top + plotH, stroke: '#eef2f7', 'stroke-width': 1 }),
      );
      svg.appendChild(
        el('text', { x: px, y: M.top + plotH + 16, 'text-anchor': 'middle', class: 'lc-growth-tick' }, String(Math.round(x * 10) / 10)),
      );
    }

    // Y gridlines + ticks.
    const yStep = niceStep(yMax - yMin, 8);
    for (let y = Math.ceil(yMin / yStep) * yStep; y <= yMax + 1e-9; y += yStep) {
      const py = sy(y);
      svg.appendChild(
        el('line', { x1: M.left, y1: py, x2: M.left + plotW, y2: py, stroke: '#eef2f7', 'stroke-width': 1 }),
      );
      svg.appendChild(
        el('text', { x: M.left - 8, y: py + 4, 'text-anchor': 'end', class: 'lc-growth-tick' }, String(Math.round(y * 10) / 10)),
      );
    }

    // Axis titles.
    svg.appendChild(
      el('text', { x: M.left + plotW / 2, y: VIEW_H - 6, 'text-anchor': 'middle', class: 'lc-growth-axis' }, chart.xLabel),
    );
    svg.appendChild(
      el(
        'text',
        { x: 14, y: M.top + plotH / 2, 'text-anchor': 'middle', class: 'lc-growth-axis', transform: `rotate(-90 14 ${M.top + plotH / 2})` },
        chart.yLabel,
      ),
    );

    // Reference z-lines.
    chart.zLines.forEach((z) => {
      const pts = chart.curves[String(z)];
      if (!pts || !pts.length) {
        return;
      }
      const style = Z_STYLE[String(z)] || { color: '#94a3b8', dash: '' };
      const d = pts.map((p, i) => `${i ? 'L' : 'M'}${sx(p[0]).toFixed(1)} ${sy(p[1]).toFixed(1)}`).join(' ');
      svg.appendChild(
        el('path', {
          d,
          fill: 'none',
          stroke: style.color,
          'stroke-width': z === 0 ? 2.4 : 1.6,
          'stroke-dasharray': style.dash,
          'stroke-linejoin': 'round',
        }),
      );
      // Edge label at the right end of the curve.
      const lastPt = pts[pts.length - 1];
      const ly = clamp(sy(lastPt[1]), M.top + 6, M.top + plotH - 2);
      svg.appendChild(
        el('text', { x: M.left + plotW + 6, y: ly + 3, 'text-anchor': 'start', class: 'lc-growth-zlabel', fill: style.color }, (z > 0 ? '+' : '') + z),
      );
    });

    // Empty layer the live point is drawn into.
    const pointGroup = el('g', { class: 'lc-growth-point' });
    svg.appendChild(pointGroup);

    return {
      svg,
      sx,
      sy,
      plot: { left: M.left, top: M.top, right: M.left + plotW, bottom: M.top + plotH },
      pointGroup,
    };
  };

  /**
   * Draws or clears the patient point for a chart panel.
   *
   * @param {object} refs
   *   The chart's scales and point layer, from drawChart().
   * @param {object|null} point
   *   {x, y} in data coordinates, or NULL to clear.
   */
  const setPoint = (refs, point) => {
    refs.pointGroup.textContent = '';
    if (!point) {
      return;
    }
    // Clamp into the plot box so a measurement beyond ±3 SD stays visible at
    // the frame edge; the readout still reports the precise z-score.
    const px = clamp(refs.sx(point.x), refs.plot.left, refs.plot.right);
    const py = clamp(refs.sy(point.y), refs.plot.top, refs.plot.bottom);
    refs.pointGroup.appendChild(el('line', { x1: refs.plot.left, y1: py, x2: px, y2: py, stroke: '#1d4ed8', 'stroke-width': 1, 'stroke-dasharray': '3 3', opacity: 0.6 }));
    refs.pointGroup.appendChild(el('line', { x1: px, y1: py, x2: px, y2: refs.plot.bottom, stroke: '#1d4ed8', 'stroke-width': 1, 'stroke-dasharray': '3 3', opacity: 0.6 }));
    refs.pointGroup.appendChild(el('circle', { cx: px, cy: py, r: 5.5, fill: '#1d4ed8', stroke: '#ffffff', 'stroke-width': 2 }));
  };

  /**
   * Reads the current height and weight from the form's vital inputs.
   *
   * @param {HTMLElement} container
   *   The growth-chart wrapper.
   *
   * @return {object}
   *   {height, weight} as numbers (NaN when blank or absent).
   */
  const readVitals = (container) => {
    const form = container.closest('form');
    if (!form) {
      return { height: NaN, weight: NaN };
    }
    const find = (attr, name) =>
      form.querySelector(`[data-librechart-bmi-${attr}]`) ||
      form.querySelector(`input[name="${name}[0][value]"]`);
    const h = find('height', 'vital_height');
    const w = find('weight', 'vital_weight');
    return {
      height: h ? parseFloat(h.value) : NaN,
      weight: w ? parseFloat(w.value) : NaN,
      heightInput: h,
      weightInput: w,
    };
  };

  /**
   * Builds the tabbed panel for all charts and wires live re-plotting.
   *
   * @param {HTMLElement} container
   *   The wrapper element (#lc-growth-charts).
   * @param {Array} charts
   *   The chart definitions.
   */
  const render = (container, charts) => {
    container.textContent = '';

    const heading = document.createElement('div');
    heading.className = 'lc-growth-heading';
    heading.textContent = Drupal.t('Growth standards (WHO / MINSALUD)');
    container.appendChild(heading);

    const tabs = document.createElement('div');
    tabs.className = 'lc-growth-tabs';
    tabs.setAttribute('role', 'tablist');
    container.appendChild(tabs);

    const panels = document.createElement('div');
    panels.className = 'lc-growth-panels';
    container.appendChild(panels);

    const buttons = [];
    const panelNodes = [];
    const entries = [];

    const activate = (idx) => {
      buttons.forEach((b, i) => {
        const on = i === idx;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.setAttribute('tabindex', on ? '0' : '-1');
        panelNodes[i].hidden = !on;
      });
    };

    charts.forEach((chart, idx) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'lc-growth-tab';
      btn.setAttribute('role', 'tab');
      btn.textContent = chart.title;
      btn.addEventListener('click', () => activate(idx));
      tabs.appendChild(btn);
      buttons.push(btn);

      const panel = document.createElement('div');
      panel.className = 'lc-growth-panel';
      panel.setAttribute('role', 'tabpanel');

      const sub = document.createElement('div');
      sub.className = 'lc-growth-subtitle';
      sub.textContent = `${chart.title} · ${chart.band}`;
      panel.appendChild(sub);

      const refs = drawChart(chart);
      panel.appendChild(refs.svg);

      const readout = document.createElement('div');
      readout.className = 'lc-growth-readout';
      panel.appendChild(readout);

      panels.appendChild(panel);
      panelNodes.push(panel);
      entries.push({ chart, refs, readout });
    });

    // Re-plot every chart from the current vitals. Falls back to the
    // server-computed point when the inputs are blank or absent.
    const update = () => {
      const { height, weight } = readVitals(container);
      entries.forEach(({ chart, refs, readout }) => {
        const live = livePoint(chart, height, weight);
        if (live) {
          setPoint(refs, live);
          readout.innerHTML = readoutHtml(live.z);
        } else if (chart.point) {
          setPoint(refs, chart.point);
          readout.innerHTML = readoutHtml(chart.z);
        } else {
          setPoint(refs, null);
          readout.innerHTML = readoutHtml(null);
        }
      });
    };

    const { heightInput, weightInput } = readVitals(container);
    [heightInput, weightInput].forEach((input) => {
      if (input) {
        input.addEventListener('input', update);
      }
    });

    activate(0);
    update();
  };

  Drupal.behaviors.librechartGrowthChart = {
    attach(context) {
      const charts = (drupalSettings.librechartVisit || {}).growthCharts;
      if (!charts || !charts.length) {
        return;
      }
      once('lc-growth-chart', '#lc-growth-charts', context).forEach((container) => {
        render(container, charts);
      });
    },
  };
})(Drupal, once, drupalSettings);
