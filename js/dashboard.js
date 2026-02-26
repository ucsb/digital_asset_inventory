/**
 * @file
 * Chart.js initialization for the Digital Asset Inventory dashboard.
 *
 * Uses progressive enhancement: fallback tables are always rendered.
 * Each chart section toggles independently — on success, adds
 * .dai-chart-active to that section (hides table, shows canvas).
 * On failure, table remains visible and a warning message is shown.
 */
(function (Drupal, drupalSettings, once) {

  'use strict';

  /**
   * Softer color palette with 3:1+ non-text contrast on white.
   */
  var COLORS = [
    '#1E3A5F', // Very dark slate blue
    '#34527D', // Dark muted blue
    '#4C739B', // Mid gray-blue
    '#6C8EB6', // Soft grayish blue
    '#8FA8CB', // Light blue-gray
    '#C3D4E3'  // Very light gray-blue
  ];

  /**
   * Hover palette (darkened fills for interactive feedback).
   */
  var HOVER_COLORS = [
    '#162D4A',
    '#284367',
    '#3E6085',
    '#587A9E',
    '#7892B4',
    '#A6BFCF'
  ];

  /**
   * Shared chart text and border color.
   */
  var STROKE_COLOR = '#1E3A5F';

  /**
   * Darken a hex color by a fraction (0–1).
   */
  function darkenHex(hex, amount) {
    var num = parseInt(hex.replace('#', ''), 16);
    var r = Math.max(0, Math.round(((num >> 16) & 0xFF) * (1 - amount)));
    var g = Math.max(0, Math.round(((num >> 8) & 0xFF) * (1 - amount)));
    var b = Math.max(0, Math.round((num & 0xFF) * (1 - amount)));
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
  }

  /**
   * Check if reduced motion is preferred.
   */
  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /**
   * Get default chart options.
   */
  function getDefaults(maintainAspect) {
    return {
      responsive: true,
      maintainAspectRatio: maintainAspect !== false,
      animation: prefersReducedMotion() ? false : { duration: 600 },
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            padding: 12,
            usePointStyle: true,
            color: STROKE_COLOR,
            font: { size: 12 }
          }
        }
      }
    };
  }

  /**
   * Mark a chart section as active and add view toggle button.
   */
  function activateSection(section) {
    section.classList.add('dai-chart-active');

    // Add toggle button for keyboard/screen reader users.
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'dai-chart-toggle';
    toggle.textContent = Drupal.t('Show data table');
    toggle.setAttribute('aria-pressed', 'false');
    toggle.addEventListener('click', function () {
      var isTable = section.classList.toggle('dai-chart-table-view');
      toggle.textContent = isTable ? Drupal.t('Show chart') : Drupal.t('Show data table');
      toggle.setAttribute('aria-pressed', isTable ? 'true' : 'false');
    });

    // Insert after the h4 heading.
    var heading = section.querySelector('h4');
    if (heading) {
      heading.parentNode.insertBefore(toggle, heading.nextSibling);
    }
  }

  /**
   * Show a warning message in a chart section on failure.
   */
  function showChartError(section) {
    var msg = document.createElement('div');
    msg.setAttribute('data-drupal-message-type', 'warning');
    msg.className = 'messages messages--warning';
    msg.setAttribute('role', 'alert');
    msg.textContent = Drupal.t('Chart unavailable. The data table below shows the same information.');
    var canvas = section.querySelector('.dai-chart-canvas');
    if (canvas) {
      canvas.parentNode.insertBefore(msg, canvas.nextSibling);
    }
  }

  /**
   * Initialize a pie or doughnut chart.
   */
  function initPieChart(section, data, isDoughnut, customColors) {
    var canvas = section.querySelector('.dai-chart-canvas');
    if (!canvas || !data || !data.labels || data.labels.length === 0) {
      return;
    }

    try {
      var ctx = canvas.getContext('2d');
      var bgColors = customColors || COLORS.slice(0, data.labels.length);
      var hoverColors = customColors
        ? customColors.map(function (c) { return darkenHex(c, 0.15); })
        : HOVER_COLORS.slice(0, data.labels.length);
      new Chart(ctx, {
        type: isDoughnut ? 'doughnut' : 'pie',
        data: {
          labels: data.labels,
          datasets: [{
            data: data.values,
            backgroundColor: bgColors,
            hoverBackgroundColor: hoverColors,
            borderWidth: 2,
            borderColor: STROKE_COLOR
          }]
        },
        options: getDefaults()
      });
      activateSection(section);
    }
    catch (e) {
      showChartError(section);
    }
  }

  /**
   * Initialize a horizontal bar chart.
   */
  function initHorizontalBarChart(section, data, formatAsFn, customColors) {
    var canvas = section.querySelector('.dai-chart-canvas');
    if (!canvas || !data || !data.labels || data.labels.length === 0) {
      return;
    }

    try {
      var ctx = canvas.getContext('2d');
      var bgColors = customColors || COLORS.slice(0, data.labels.length);
      var hoverColors = customColors
        ? customColors.map(function (c) { return darkenHex(c, 0.15); })
        : HOVER_COLORS.slice(0, data.labels.length);
      var defaults = getDefaults(false);
      defaults.indexAxis = 'y';
      defaults.plugins.legend = { display: false };
      defaults.scales = {
        x: {
          beginAtZero: true,
          ticks: formatAsFn ? { callback: formatAsFn } : {}
        }
      };

      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.labels,
          datasets: [{
            data: data.values,
            backgroundColor: bgColors,
            hoverBackgroundColor: hoverColors,
            borderWidth: 2,
            borderColor: STROKE_COLOR
          }]
        },
        options: defaults
      });
      activateSection(section);
    }
    catch (e) {
      showChartError(section);
    }
  }

  Drupal.behaviors.daiDashboard = {
    attach: function (context) {
      var dashboards = once('dai-dashboard', '.dai--dashboard', context);
      if (!dashboards.length) {
        return;
      }

      var dashboard = dashboards[0];
      var data = drupalSettings.digitalAssetInventory && drupalSettings.digitalAssetInventory.dashboard;
      if (!data) {
        return;
      }

      // Verify Chart.js is available.
      if (typeof Chart === 'undefined') {
        return;
      }

      // Section 1: Inventory Overview.
      var categorySection = dashboard.querySelector('[data-dai-chart="category"]');
      if (categorySection) {
        initHorizontalBarChart(categorySection, data.category, null);
      }

      var usageSection = dashboard.querySelector('[data-dai-chart="usage"]');
      if (usageSection) {
        initPieChart(usageSection, data.usage, true, [COLORS[0], COLORS[4]]);
      }

      // Section 2: Location.
      var locationSection = dashboard.querySelector('[data-dai-chart="location"]');
      if (locationSection) {
        initPieChart(locationSection, data.location, false);
      }

      // Section 4: Archive Status (conditional — keys only present when enabled).
      if (data.archiveStatus) {
        var archiveStatusSection = dashboard.querySelector('[data-dai-chart="archiveStatus"]');
        if (archiveStatusSection) {
          initPieChart(archiveStatusSection, data.archiveStatus, false);
        }
      }

      if (data.archiveType) {
        var archiveTypeSection = dashboard.querySelector('[data-dai-chart="archiveType"]');
        if (archiveTypeSection) {
          initHorizontalBarChart(archiveTypeSection, data.archiveType, null, [COLORS[0], COLORS[4]]);
        }
      }

      if (data.archiveReason) {
        var archiveReasonSection = dashboard.querySelector('[data-dai-chart="archiveReason"]');
        if (archiveReasonSection) {
          initPieChart(archiveReasonSection, data.archiveReason, true);
        }
      }
    }
  };

})(Drupal, drupalSettings, once);
