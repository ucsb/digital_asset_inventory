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

      // Find all links in menus.
      var menuLinks = once('dai-archive-link', 'nav a[href], .menu a[href], .navbar a[href]', context);

      menuLinks.forEach(function (link) {
        var href = link.getAttribute('href');

        // Check each mapping to see if this URL matches an archived file.
        Object.keys(archiveMappings).forEach(function (pattern) {
          // Check if the href contains or matches the pattern.
          if (href && (href === pattern || href.indexOf(pattern) !== -1)) {
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
