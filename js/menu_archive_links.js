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
      var showLabel = settings.digitalAssetInventory?.showArchivedLabel !== false;
      var labelText = settings.digitalAssetInventory?.archivedLabelText || 'Archived';

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

            // Append archived label to the link text if enabled and not already present.
            if (showLabel) {
              var textContent = link.textContent || link.innerText;
              // Check for both custom label and default "Archived".
              if (textContent.indexOf('(' + labelText + ')') === -1 && textContent.indexOf('(Archived)') === -1) {
                // Create the archived label span.
                var archivedLabel = document.createElement('span');
                archivedLabel.className = 'dai-archived-label';
                archivedLabel.textContent = ' (' + labelText + ')';
                link.appendChild(archivedLabel);
              }
            }
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
