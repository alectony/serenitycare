/**
 * SerenityCare - UI Enhancements: Theme Controller & Skeleton Loading Engine
 */
(function() {
  'use strict';

  // --- 1. Theme Controller (Dark Mode / Light Mode) ---
  const THEME_KEY = 'serenity_theme';

  function getSystemTheme() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function getCurrentTheme() {
    return localStorage.getItem(THEME_KEY) || getSystemTheme();
  }

  function applyTheme(theme) {
    if (!theme) theme = getCurrentTheme();
    document.documentElement.setAttribute('data-theme', theme);
    document.body && document.body.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_KEY, theme);

    // Update any theme toggles on the page
    const toggles = document.querySelectorAll('.theme-toggle-input, #dark_mode_toggle, #dark_mode_switch');
    toggles.forEach(function(toggle) {
      if (toggle.type === 'checkbox') {
        toggle.checked = (theme === 'dark');
      }
    });

    // Update toggle icons / labels
    const toggleBtns = document.querySelectorAll('.theme-toggle-btn');
    toggleBtns.forEach(function(btn) {
      btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode');
      btn.setAttribute('title', theme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode');
    });

    // Dispatch event for any custom listeners
    window.dispatchEvent(new CustomEvent('serenity-theme-changed', { detail: { theme: theme } }));
  }

  function toggleTheme() {
    const current = getCurrentTheme();
    const next = current === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    return next;
  }

  // --- 2. Skeleton Loading Controller ---
  const SerenitySkeleton = {
    isPreviewing: false,
    timer: null,

    showPreview: function(durationMs) {
      if (!durationMs) durationMs = 3000;
      this.isPreviewing = true;
      document.body.classList.add('skeleton-demo-active');

      const banner = document.getElementById('skeleton-preview-banner') || this.createBanner();
      banner.style.display = 'flex';
      banner.querySelector('.skeleton-banner-countdown').textContent = Math.ceil(durationMs / 1000) + 's';

      let remaining = Math.ceil(durationMs / 1000);
      clearInterval(this.timer);
      this.timer = setInterval(function() {
        remaining--;
        if (remaining <= 0) {
          clearInterval(SerenitySkeleton.timer);
          SerenitySkeleton.hidePreview();
        } else {
          banner.querySelector('.skeleton-banner-countdown').textContent = remaining + 's';
        }
      }, 1000);
    },

    hidePreview: function() {
      this.isPreviewing = false;
      clearInterval(this.timer);
      document.body.classList.remove('skeleton-demo-active');
      const banner = document.getElementById('skeleton-preview-banner');
      if (banner) banner.style.display = 'none';
    },

    togglePreview: function() {
      if (this.isPreviewing) {
        this.hidePreview();
      } else {
        this.showPreview(4000);
      }
    },

    createBanner: function() {
      let banner = document.createElement('div');
      banner.id = 'skeleton-preview-banner';
      banner.className = 'skeleton-preview-banner';
      banner.innerHTML = '<span>Previewing Neumorphic Skeleton Frames (<span class="skeleton-banner-countdown">3s</span>)...</span>'
        + '<button type="button" class="skeleton-banner-close" onclick="window.SerenitySkeleton.hidePreview()">&times;</button>';
      document.body.appendChild(banner);
      return banner;
    }
  };

  // --- 3. Initial Boot & Event Binding ---
  // Apply theme immediately
  applyTheme(getCurrentTheme());

  // Listen for system preference changes if user hasn't set explicit manual override
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
      if (!localStorage.getItem(THEME_KEY)) {
        applyTheme(e.matches ? 'dark' : 'light');
      }
    });
  }

  // Bind DOM events on load
  document.addEventListener('DOMContentLoaded', function() {
    applyTheme(getCurrentTheme());

    // Bind theme toggle buttons
    document.addEventListener('click', function(e) {
      const toggleBtn = e.target.closest('.theme-toggle-btn, [data-theme-toggle]');
      if (toggleBtn) {
        e.preventDefault();
        toggleTheme();
      }

      const skeletonBtn = e.target.closest('[data-skeleton-preview]');
      if (skeletonBtn) {
        e.preventDefault();
        SerenitySkeleton.togglePreview();
      }
    });

    // Bind checkbox switches
    document.addEventListener('change', function(e) {
      if (e.target.matches('.theme-toggle-input, #dark_mode_toggle, #dark_mode_switch')) {
        applyTheme(e.target.checked ? 'dark' : 'light');
      }
    });

    // Tab switching support for settings pages
    const tabButtons = document.querySelectorAll('.settings-tab-btn');
    if (tabButtons.length > 0) {
      tabButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
          const targetId = this.getAttribute('data-target');
          tabButtons.forEach(function(b) { b.classList.remove('active'); });
          this.classList.add('active');

          const sections = document.querySelectorAll('.settings-section-pane');
          sections.forEach(function(s) {
            s.style.display = (s.id === targetId) ? 'block' : 'none';
          });
        });
      });
    }

    // Smooth page entrance: brief simulated skeleton resolve if elements exist
    const skeletonLoaders = document.querySelectorAll('.page-skeleton-overlay');
    if (skeletonLoaders.length > 0) {
      setTimeout(function() {
        skeletonLoaders.forEach(function(el) {
          el.style.opacity = '0';
          setTimeout(function() { el.style.display = 'none'; }, 300);
        });
      }, 250);
    }
  });

  // Expose global API
  window.SerenityTheme = {
    get: getCurrentTheme,
    set: applyTheme,
    toggle: toggleTheme
  };
  window.SerenitySkeleton = SerenitySkeleton;

})();
