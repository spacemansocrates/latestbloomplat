<?php
/**
 * Centralized Page Loader Component
 * Include this file in all pages to enable page loader on navigation
 * Usage: <?php include __DIR__ . '/../components/page_loader.php'; ?>
 */
?>
<style>
  /* Page Loader Styles */
  #page-loader {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease, visibility 0.3s ease;
  }
  
  #page-loader.show {
    opacity: 1;
    visibility: visible;
  }
  
  #page-loader .loader-container {
    text-align: center;
    color: white;
  }
  
  #page-loader .loader-spinner {
    width: 56px;
    height: 56px;
    border: 4px solid rgba(255, 255, 255, 0.1);
    border-top-color: #6366f1;
    border-right-color: #6366f1;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 0 auto 16px;
  }
  
  @keyframes spin {
    to { transform: rotate(360deg); }
  }
  
  #page-loader .loader-text {
    font-size: 14px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.9);
    margin-top: 12px;
  }
  
  /* Prevent body scroll when loader is active */
  body.loading {
    overflow: hidden;
  }
</style>

<div id="page-loader">
  <div class="loader-container">
    <div class="loader-spinner"></div>
    <!-- <div class="loader-text">Loading...</div> -->
  </div>
</div>

<script>
(function() {
  'use strict';
  
  const pageLoader = document.getElementById('page-loader');
  if (!pageLoader) return;
  
  // Show loader function
  function showLoader() {
    pageLoader.classList.add('show');
    document.body.classList.add('loading');
  }
  
  // Hide loader function
  function hideLoader() {
    pageLoader.classList.remove('show');
    document.body.classList.remove('loading');
  }
  
  // Hide loader when page is fully loaded
  if (document.readyState === 'complete') {
    hideLoader();
  } else {
    window.addEventListener('load', hideLoader);
    // Also hide after DOM is ready (faster UX)
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
      setTimeout(hideLoader, 100);
    } else {
      document.addEventListener('DOMContentLoaded', () => {
        setTimeout(hideLoader, 100);
      });
    }
  }
  
  // Show loader ONLY on link clicks that navigate to other pages
  document.addEventListener('click', function(e) {
    // Skip if clicking on form elements (inputs, buttons, textareas, selects, labels, SVGs/icons inside buttons)
    // This prevents loader from showing when submitting forms, clicking buttons, etc.
    const target = e.target;
    const isFormElement = target.tagName === 'INPUT' || 
                          target.tagName === 'BUTTON' || 
                          target.tagName === 'TEXTAREA' || 
                          target.tagName === 'SELECT' ||
                          target.tagName === 'LABEL' ||
                          target.tagName === 'SVG' || // SVG icons inside buttons
                          target.tagName === 'PATH' || // Path elements inside SVG icons
                          target.closest('form') || // Don't show loader for anything inside forms
                          target.closest('button') || // Don't show for button clicks (including icons inside)
                          target.type === 'submit' || // Don't show for submit buttons
                          target.type === 'button' || // Don't show for regular buttons
                          target.getAttribute('type') === 'submit' || // Additional check for submit
                          target.getAttribute('type') === 'button' || // Additional check for button
                          target.closest('[role="button"]'); // Don't show for elements with button role
    
    if (isFormElement) {
      return; // Exit early - don't process form-related clicks
    }
    
    const link = e.target.closest('a[href]');
    if (!link) return;
    
    const href = link.getAttribute('href');
    if (!href) return;
    
    // Skip if:
    // - External links (http/https)
    // - Anchor links (#)
    // - Special protocols (mailto:, tel:, javascript:)
    // - Links with target="_blank"
    // - Links with data-no-loader attribute
    // - Links that are buttons or form-related (not navigation)
    // - Links inside modals or dropdowns (likely action buttons, not navigation)
    // - Query-only links (tabs/filters that only change query parameters on same page)
    if (href.startsWith('#') || 
        href.startsWith('http://') || 
        href.startsWith('https://') ||
        href.startsWith('mailto:') ||
        href.startsWith('tel:') ||
        href.startsWith('javascript:') ||
        href.startsWith('?') || // Query-only links (tabs/filters) - same page navigation
        link.getAttribute('target') === '_blank' ||
        link.hasAttribute('data-no-loader') ||
        link.closest('.modal') || // Don't show for links in modals
        link.getAttribute('role') === 'button' || // Don't show for button-like links
        link.classList.contains('btn') || // Don't show for button-styled links
        link.classList.contains('button')) {
      return;
    }
    
    // Only show loader for actual page navigation (not same-page actions)
    // Check if this is a different page by comparing the pathname
    let isDifferentPage = true;
    try {
      const currentPath = window.location.pathname;
      const currentSearch = window.location.search;
      
      // Try to parse as absolute URL first
      let linkPath, linkSearch;
      if (href.startsWith('/')) {
        // Absolute path
        const url = new URL(href, window.location.origin);
        linkPath = url.pathname;
        linkSearch = url.search;
      } else if (href.includes('?')) {
        // Relative path with query string
        const [path, search] = href.split('?');
        linkPath = new URL(path, window.location.href).pathname;
        linkSearch = '?' + search;
      } else {
        // Simple relative path
        const url = new URL(href, window.location.href);
        linkPath = url.pathname;
        linkSearch = url.search;
      }
      
      // If it's the same page (same pathname), don't show loader
      // Even if query params differ, it's still same-page navigation (tabs/filters)
      if (currentPath === linkPath) {
        isDifferentPage = false;
      }
    } catch (e) {
      // If URL parsing fails, check if it's a simple relative path that might be same page
      if (!href.includes('/') && !href.includes('?')) {
        isDifferentPage = false;
      }
    }
    
    // Only show loader if navigating to a different page
    if (isDifferentPage) {
      showLoader();
      
      // If navigation is prevented or delayed, hide loader after timeout
      setTimeout(() => {
        // Check if we're still on the same page (navigation might have been prevented)
        const currentUrl = window.location.href.split('#')[0];
        const targetUrl = href.split('#')[0];
        if (currentUrl === targetUrl || currentUrl.endsWith(targetUrl) || targetUrl.endsWith(currentUrl)) {
          hideLoader();
        }
      }, 5000); // 5 second timeout
    }
  }, true); // Use capture phase to catch early
  
  // DO NOT show loader on form submissions - forms are for actions, not navigation
  // Forms should handle their own loading states if needed
  
  // Handle browser back/forward buttons
  window.addEventListener('pageshow', function(e) {
    // If page was loaded from cache, hide loader immediately
    if (e.persisted) {
      hideLoader();
    }
  });
  
  // Handle page visibility (when user switches browser tabs)
  // Don't show loader when switching tabs - this is not navigation
  document.addEventListener('visibilitychange', function() {
    // When tab becomes visible again, hide loader if page is already loaded
    // This prevents loader from staying visible after tab switch
    if (!document.hidden && document.readyState === 'complete') {
      hideLoader();
    }
  });
  
  // Expose global functions for manual control if needed
  window.showPageLoader = showLoader;
  window.hidePageLoader = hideLoader;
  
  // Lazy loading for images - add loading="lazy" to images that don't have it
  // Skip images that are above the fold (first 3 images) and images that already have loading attribute
  function addLazyLoadingToImages() {
    const images = document.querySelectorAll('img:not([loading])');
    let aboveFoldCount = 0;
    
    images.forEach((img) => {
      // An image inside a closed modal is not in the viewport, so this used to mark
      // it lazy; when the modal opened the browser could then leave it unloaded and
      // fire no error at all, so any fallback wired to onerror never ran. The tutor's
      // student-profile avatar sat empty for exactly this reason. A modal is the one
      // place where "not visible right now" says nothing about whether it is wanted.
      if (img.closest('.modal-backdrop, .hidden, [hidden]')) return;

      // Skip if it's a logo, avatar in sidebar, or first few images (likely above fold)
      const isLogo = img.src.includes('logo') || img.closest('aside') || img.closest('.sidebar');
      const isAboveFold = aboveFoldCount < 3 && !isLogo;
      
      if (!isAboveFold && !img.hasAttribute('loading')) {
        // Check if image is likely in viewport (rough estimate)
        const rect = img.getBoundingClientRect();
        const isInViewport = rect.top < window.innerHeight && rect.bottom > 0;
        
        // Only add lazy loading if image is below the fold
        if (!isInViewport || isLogo) {
          img.setAttribute('loading', 'lazy');
          // Also add decoding="async" for better performance
          if (!img.hasAttribute('decoding')) {
            img.setAttribute('decoding', 'async');
          }
        } else {
          aboveFoldCount++;
        }
      }
    });
  }
  
  // Run lazy loading setup after DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addLazyLoadingToImages);
  } else {
    // DOM already loaded
    setTimeout(addLazyLoadingToImages, 100);
  }
  
  // Also handle dynamically added images
  if (window.MutationObserver) {
    const imageObserver = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === 1) { // Element node
            const images = node.querySelectorAll ? node.querySelectorAll('img:not([loading])') : [];
            images.forEach((img) => {
              if (img.closest('.modal-backdrop, .hidden, [hidden]')) return;
              if (!img.hasAttribute('loading')) {
                img.setAttribute('loading', 'lazy');
                if (!img.hasAttribute('decoding')) {
                  img.setAttribute('decoding', 'async');
                }
              }
            });
            // Also check if the node itself is an image
            if (node.tagName === 'IMG' && !node.hasAttribute('loading')
                && !node.closest('.modal-backdrop, .hidden, [hidden]')) {
              node.setAttribute('loading', 'lazy');
              if (!node.hasAttribute('decoding')) {
                node.setAttribute('decoding', 'async');
              }
            }
          }
        });
      });
    });
    
    // Start observing
    imageObserver.observe(document.body, {
      childList: true,
      subtree: true
    });
  }
})();
</script>
