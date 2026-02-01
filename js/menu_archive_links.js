/**
 * @file
 * JavaScript to route menu links to archived files to Archive Detail Pages.
 *
 * This runs client-side to handle cases where server-side preprocess
 * modifications don't reach the template due to caching layers.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.daiMenuArchiveLinks = {
    attach: function (context, settings) {
      // Get archive mappings from drupalSettings.
      var archiveMappings = settings.digitalAssetInventory?.archiveMappings || {};

      if (Object.keys(archiveMappings).length === 0) {
        return;
      }

      // Get current page path for canonical route check.
      var currentPath = window.location.pathname;

      // Find all links in menus.
      var menuLinks = once('dai-archive-link', 'nav a[href], .menu a[href], .navbar a[href]', context);

      menuLinks.forEach(function (link) {
        var href = link.getAttribute('href');

        // Check each mapping to see if this URL matches an archived file.
        Object.keys(archiveMappings).forEach(function (pattern) {
          // Skip if we're on the canonical route of the archived page.
          // This preserves local task tabs (Edit, Delete, Revisions, etc.).
          if (currentPath === pattern || currentPath.indexOf(pattern + '/') === 0) {
            return;
          }

          // Check if the href exactly matches the pattern, or matches with query string/hash.
          // Do NOT match partial paths like /my-page/edit when pattern is /my-page.
          var isMatch = false;
          if (href) {
            if (href === pattern) {
              // Exact match.
              isMatch = true;
            } else if (href.indexOf(pattern + '?') === 0 || href.indexOf(pattern + '#') === 0) {
              // Match with query string or hash fragment.
              isMatch = true;
            }
          }

          if (isMatch) {
            var archiveUrl = archiveMappings[pattern];

            // Update the href.
            link.setAttribute('href', archiveUrl);
            link.classList.add('dai-archived-link');

            // Append "(Archived)" to the link text if not already present.
            var textContent = link.textContent || link.innerText;
            if (textContent.indexOf('(Archived)') === -1) {
              // Create the archived label span.
              var archivedLabel = document.createElement('span');
              archivedLabel.className = 'dai-archived-label';
              archivedLabel.textContent = ' (Archived)';
              link.appendChild(archivedLabel);
            }
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
