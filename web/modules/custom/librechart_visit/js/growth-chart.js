/**
 * @file
 * Renders the WHO / MINSALUD growth charts above the Clinical Evaluation
 * fieldset.
 *
 * The server (GrowthStandard service) supplies, per indicator appropriate to
 * the child's age and sex, the seven z-score reference curves and the plotted
 * point. This behaviour draws each indicator as an inline SVG inside a tabbed
 * panel — no external charting library, so it works on the offline LAN
 * deployment. The numeric z-score is shown beneath the active chart.
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
    if (z === null || z === undefined) {
      return '—';
    }
    return (z > 0 ? '+' : z < 0 ? '−' : '') + Math.abs(z).toFixed(2);
  };

  /**
   * Draws one indicator chart into an SVG element.
   *
   * @param {object} chart
   *   A chart definition from drupalSettings.
   *
   * @return {SVGElement}
   *   The populated SVG node.
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
      const last = pts[pts.length - 1];
      const ly = Math.max(M.top + 6, Math.min(M.top + plotH - 2, sy(last[1])));
      svg.appendChild(
        el('text', { x: M.left + plotW + 6, y: ly + 3, 'text-anchor': 'start', class: 'lc-growth-zlabel', fill: style.color }, (z > 0 ? '+' : '') + z),
      );
    });

    // Patient point.
    if (chart.point) {
      const px = sx(chart.point.x);
      const py = sy(chart.point.y);
      // Crosshair to the axes for readability.
      svg.appendChild(el('line', { x1: M.left, y1: py, x2: px, y2: py, stroke: '#1d4ed8', 'stroke-width': 1, 'stroke-dasharray': '3 3', opacity: 0.6 }));
      svg.appendChild(el('line', { x1: px, y1: py, x2: px, y2: M.top + plotH, stroke: '#1d4ed8', 'stroke-width': 1, 'stroke-dasharray': '3 3', opacity: 0.6 }));
      svg.appendChild(el('circle', { cx: px, cy: py, r: 5.5, fill: '#1d4ed8', stroke: '#ffffff', 'stroke-width': 2 }));
    }

    return svg;
  };

  /**
   * Builds the tabbed panel for all charts and appends it to the container.
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

      panel.appendChild(drawChart(chart));

      const readout = document.createElement('div');
      readout.className = 'lc-growth-readout';
      if (chart.z === null || chart.z === undefined) {
        readout.innerHTML = `<span class="lc-growth-z-missing">${Drupal.t('Enter height and weight to plot this patient.')}</span>`;
      } else {
        const band =
          Math.abs(chart.z) <= 1
            ? Drupal.t('within ±1 SD')
            : Math.abs(chart.z) <= 2
              ? Drupal.t('between ±1 and ±2 SD')
              : Math.abs(chart.z) <= 3
                ? Drupal.t('between ±2 and ±3 SD')
                : Drupal.t('beyond ±3 SD');
        readout.innerHTML = `${Drupal.t('Z-score')}: <strong class="lc-growth-z">${fmtZ(chart.z)}</strong> <span class="lc-growth-z-band">(${band})</span>`;
      }
      panel.appendChild(readout);

      panels.appendChild(panel);
      panelNodes.push(panel);
    });

    activate(0);
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
