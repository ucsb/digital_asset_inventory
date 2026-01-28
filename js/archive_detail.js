/**
 * @file
 * JavaScript for Archive Detail page.
 */

(function () {
  'use strict';

  /**
   * Initialize copy archive record link functionality.
   */
  function initCopyLink() {
    var copyButton = document.querySelector('.dai-archive-copy-link__button');
    var confirmation = document.querySelector('.dai-archive-copy-link__confirmation');

    if (!copyButton || !confirmation) {
      return;
    }

    copyButton.addEventListener('click', function () {
      var archiveUrl = copyButton.getAttribute('data-archive-url');

      // Build absolute URL.
      var absoluteUrl = window.location.origin + archiveUrl;

      // Copy to clipboard.
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(absoluteUrl).then(function () {
          showConfirmation();
        }).catch(function () {
          fallbackCopy(absoluteUrl);
        });
      } else {
        fallbackCopy(absoluteUrl);
      }
    });

    /**
     * Show confirmation message.
     */
    function showConfirmation() {
      confirmation.textContent = Drupal.t('Archive record link copied');

      // Clear after 3 seconds.
      setTimeout(function () {
        confirmation.textContent = '';
      }, 3000);
    }

    /**
     * Fallback copy method for older browsers.
     */
    function fallbackCopy(text) {
      var textArea = document.createElement('textarea');
      textArea.value = text;
      textArea.style.position = 'fixed';
      textArea.style.left = '-9999px';
      document.body.appendChild(textArea);
      textArea.select();

      try {
        document.execCommand('copy');
        showConfirmation();
      } catch (err) {
        console.error('Copy failed:', err);
      }

      document.body.removeChild(textArea);
    }
  }

  // Initialize when DOM is ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCopyLink);
  } else {
    initCopyLink();
  }

})();
