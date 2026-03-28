/**
 * navbar-scroll.js
 *
 * Performant scroll-tracking for the site navigation.
 *
 * Features:
 *  - Uses requestAnimationFrame so scroll handling never blocks the main thread.
 *  - Adds/removes the `.scrolled` class on the mobile menu button once the user
 *    has scrolled past SCROLL_THRESHOLD (50 px).
 *  - Guarantees that every `overflow: hidden` attribute applied to <body> and
 *    <html> by the mobile-menu overlay is fully cleaned up when the menu closes,
 *    preventing the page from staying locked in a non-scrollable state.
 *  - Dynamically measures the actual mobile topbar height (including safe area)
 *    and updates the --topbar-safe-height CSS custom property so that the main
 *    content is always correctly padded, even on devices with Dynamic Island.
 */
(function () {
    'use strict';

    var SCROLL_THRESHOLD = 50;

    var ticking = false;
    var lastScrollY = 0;
    var navbarBtn = null;
    var sidebarEl = null;
    var mobileMenuEl = null;

    /* ------------------------------------------------------------------ */
    /* Dynamic topbar height: measure the real rendered height and update  */
    /* the CSS custom property so main content padding stays accurate.      */
    /* ------------------------------------------------------------------ */

    var mobileHeaderEl = null;

    function updateTopbarHeight() {
        if (!mobileHeaderEl) return;
        var h = mobileHeaderEl.getBoundingClientRect().height;
        if (h > 0) {
            // Use requestAnimationFrame so the CSS-variable write is batched with
            // the browser's next paint, preventing layout thrashing and ensuring
            // the push-down animation of #main-content is perfectly smooth.
            requestAnimationFrame(function () {
                document.documentElement.style.setProperty('--topbar-safe-height', h + 'px');
                document.documentElement.style.setProperty('--mobile-menu-height', h + 'px');
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /* Scroll detection – rAF-based to avoid layout thrashing              */
    /* ------------------------------------------------------------------ */

    function onScroll() {
        lastScrollY = window.scrollY;
        if (!ticking) {
            requestAnimationFrame(updateScrolledState);
            ticking = true;
        }
    }

    function updateScrolledState() {
        if (navbarBtn) {
            if (lastScrollY >= SCROLL_THRESHOLD) {
                navbarBtn.classList.add('scrolled');
            } else {
                navbarBtn.classList.remove('scrolled');
            }
        }
        ticking = false;
    }

    /* ------------------------------------------------------------------ */
    /* Overflow cleanup – removes ALL overflow: hidden locks from the DOM  */
    /* ------------------------------------------------------------------ */

    /**
     * Removes every overflow / position / top / width inline style that the
     * mobile sidebar overlay sets on <body> and <html>, and removes the
     * `sidebar-open` class that the CSS rule targets.
     *
     * This function is idempotent – clearing an already-empty style property or
     * removing a class that is not present are both safe no-ops, so it may be
     * called multiple times in quick succession without adverse effects (e.g.
     * when both the Escape-key handler here and the one in main_layout.php fire
     * for the same event).
     *
     * Call this whenever a mobile menu or overlay is closed so that scrolling
     * is always restored regardless of *how* the menu was dismissed.
     */
    function ensureScrollUnlocked() {
        var body = document.body;
        var html = document.documentElement;

        // Remove the class-based lock (CSS: body.sidebar-open { overflow: hidden; position: fixed; })
        body.classList.remove('sidebar-open');

        // Clear any inline styles that may have been applied directly
        body.style.overflow = '';
        body.style.position = '';
        body.style.top = '';
        body.style.width = '';

        // Also clear from <html> in case any library targets the root element
        html.style.overflow = '';
        html.style.position = '';
        html.style.top = '';
        html.style.width = '';

        // Reset mobile push-down menu height via rAF so the CSS transition on
        // #main-content animates the padding back in sync with the sidebar closing.
        requestAnimationFrame(function () {
            html.style.removeProperty('--mobile-menu-height');
        });
    }


    /* ------------------------------------------------------------------ */
    /* Mobile menu toggle                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Open the slide-down mobile navigation menu (#mobile-menu).
     * Updates ARIA attributes and animates the hamburger icon into an X.
     */
    function openMobileMenu() {
        if (!mobileMenuEl) return;
        mobileMenuEl.classList.remove('hidden');
        mobileMenuEl.removeAttribute('aria-hidden');
        if (navbarBtn) {
            navbarBtn.setAttribute('aria-expanded', 'true');
            navbarBtn.setAttribute('aria-label', 'Menü schließen');
        }
        var top = document.getElementById('menu-icon-top');
        var mid = document.getElementById('menu-icon-middle');
        var bot = document.getElementById('menu-icon-bottom');
        if (top) { top.setAttribute('d', 'M6 18L18 6'); }
        if (mid) { mid.setAttribute('opacity', '0'); }
        if (bot) { bot.setAttribute('d', 'M6 6L18 18'); }
    }

    /**
     * Close the slide-down mobile navigation menu (#mobile-menu).
     * Updates ARIA attributes and restores the hamburger icon.
     */
    function closeMobileMenu() {
        if (!mobileMenuEl) return;
        mobileMenuEl.classList.add('hidden');
        mobileMenuEl.setAttribute('aria-hidden', 'true');
        if (navbarBtn) {
            navbarBtn.setAttribute('aria-expanded', 'false');
            navbarBtn.setAttribute('aria-label', 'Menü öffnen');
        }
        var top = document.getElementById('menu-icon-top');
        var mid = document.getElementById('menu-icon-middle');
        var bot = document.getElementById('menu-icon-bottom');
        if (top) { top.setAttribute('d', 'M4 6h16'); }
        if (mid) { mid.setAttribute('d', 'M4 12h16'); }
        if (mid) { mid.setAttribute('opacity', '1'); }
        if (bot) { bot.setAttribute('d', 'M4 18h16'); }
    }

    /* ------------------------------------------------------------------ */
    /* Initialisation                                                       */
    /* ------------------------------------------------------------------ */

    function init() {
        navbarBtn     = document.getElementById('mobile-menu-btn');
        sidebarEl     = document.getElementById('sidebar');
        mobileHeaderEl = document.getElementById('mobile-header');
        mobileMenuEl   = document.getElementById('mobile-menu');

        // Measure real topbar height immediately and on resize/orientation change
        updateTopbarHeight();
        window.addEventListener('resize', updateTopbarHeight, { passive: true });
        window.addEventListener('orientationchange', function () {
            // Delay slightly to allow the browser to settle after orientation change
            setTimeout(updateTopbarHeight, 250);
        });

        // Run once immediately so state is correct on page load
        lastScrollY = window.scrollY;
        updateScrolledState();

        // Passive listener keeps scroll performance optimal
        window.addEventListener('scroll', onScroll, { passive: true });

        // Attach overflow cleanup to every mechanism that can close the sidebar
        var overlay = document.getElementById('sidebar-overlay');
        if (overlay) {
            overlay.addEventListener('click', ensureScrollUnlocked);
        }

        // Escape key – fires *after* the existing keydown handler in main_layout.php;
        // ensureScrollUnlocked() is idempotent so duplicate calls are safe.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                ensureScrollUnlocked();
            }
        });

        // Clean up any leftover lock if the page becomes visible again
        // (e.g. user navigates back with the sidebar still open)
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                if (sidebarEl && !sidebarEl.classList.contains('open')) {
                    ensureScrollUnlocked();
                }
                // Re-measure topbar in case safe area insets changed
                updateTopbarHeight();
            }
        });

        // Hamburger button toggles the slide-down mobile nav menu (#mobile-menu)
        if (navbarBtn && mobileMenuEl) {
            navbarBtn.addEventListener('click', function () {
                if (mobileMenuEl.classList.contains('hidden')) {
                    openMobileMenu();
                } else {
                    closeMobileMenu();
                }
            });
        }

        // Close mobile menu when a nav link inside it is clicked
        if (mobileMenuEl) {
            mobileMenuEl.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', closeMobileMenu);
            });
        }

        // Close mobile menu when clicking outside of it
        document.addEventListener('click', function (e) {
            if (mobileMenuEl && !mobileMenuEl.classList.contains('hidden') &&
                    !mobileMenuEl.contains(e.target) && navbarBtn && !navbarBtn.contains(e.target)) {
                closeMobileMenu();
            }
        }, { passive: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose utilities for inline scripts or other modules that need to unlock scroll
    // or close the mobile menu programmatically.
    window.navbarScrollUtils = {
        ensureScrollUnlocked: ensureScrollUnlocked,
        updateTopbarHeight: updateTopbarHeight,
        closeMobileMenu: closeMobileMenu
    };

}());
