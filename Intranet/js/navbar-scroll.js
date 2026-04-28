/**
 * navbar-scroll.js
 *
 * Performant scroll-tracking for the site navigation.
 *
 * Features:
 *  - Uses requestAnimationFrame so scroll handling never blocks the main thread.
 *  - Adds/removes the `.scrolled` class on the mobile topbar and menu button once
 *    the user has scrolled past SCROLL_THRESHOLD (50 px).
 *  - Guarantees that every `overflow: hidden` attribute applied to <body> and
 *    <html> by the mobile-menu overlay is fully cleaned up when the menu closes,
 *    preventing the page from staying locked in a non-scrollable state.
 *  - Dynamically measures the actual mobile topbar height (including safe area)
 *    and updates the --topbar-safe-height CSS custom property so that the main
 *    content is always correctly padded, even on devices with Dynamic Island.
 *  - Runs an IntersectionObserver-based scroll-reveal for elements marked with
 *    the `.animate-on-scroll` class, adding `.in-view` once they enter the
 *    viewport. Falls back gracefully when the API is unavailable.
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
        // Also add/remove 'scrolled' class on the mobile topbar for shadow enhancement
        if (mobileHeaderEl) {
            if (lastScrollY >= SCROLL_THRESHOLD) {
                mobileHeaderEl.classList.add('scrolled');
            } else {
                mobileHeaderEl.classList.remove('scrolled');
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

        // Remove scroll lock applied when the mobile slide-down menu is open
        body.classList.remove('overflow-hidden');

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
        document.body.classList.add('overflow-hidden');
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
        document.body.classList.remove('overflow-hidden');
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

        // ── Scroll-reveal (IntersectionObserver) ──────────────────────────────
        // Elements with .animate-on-scroll get .in-view added when they enter the
        // viewport. CSS handles the actual fade/slide animation.
        initScrollReveal();
    }

    /* ------------------------------------------------------------------ */
    /* Scroll-reveal with IntersectionObserver                             */
    /* ------------------------------------------------------------------ */

    function initScrollReveal() {
        // Skip if the user prefers reduced motion or the API is unavailable
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        if (!('IntersectionObserver' in window)) {
            // Fallback: just show everything immediately
            document.querySelectorAll('.animate-on-scroll').forEach(function (el) {
                el.classList.add('in-view');
            });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in-view');
                    // Unobserve after animating – no need to re-trigger
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.12,
            rootMargin: '0px 0px -40px 0px'
        });

        document.querySelectorAll('.animate-on-scroll').forEach(function (el) {
            observer.observe(el);
        });
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
        closeMobileMenu: closeMobileMenu,
        initScrollReveal: initScrollReveal
    };

    // ════════════════════════════════════════════════════════════════════
    //  GLOBAL MODAL OBSERVER — body.has-open-modal toggle
    //
    //  Different modules (vCards, Rechnungen, Inventar, Ideenbox, Bug-Melden,
    //  Bewerbungen, Events, Mitglieder, Checkout, …) use slightly different
    //  modal class conventions. The CSS in `assets/css/ui-fixes.css` already
    //  has dozens of `:has()` selectors to detect open modals, but
    //  (a) `:has()` is not supported on Safari < 15.4 and Firefox < 121,
    //  (b) some templates wrap `.open` further inside the modal container.
    //
    //  This observer catches everything: whenever any element with a
    //  `*-modal-overlay` / `vc-modal` / `prm-modal` class gains/loses the
    //  `.open` class (or any descendant of <body> matches `.open` AND looks
    //  like a modal), we toggle `body.has-open-modal`. The matching CSS
    //  rules then unconditionally hide the global footer + mobile-bottom-nav.
    // ════════════════════════════════════════════════════════════════════
    (function setupGlobalModalObserver() {
        if (!window.MutationObserver || !document.body) return;

        var MODAL_SELECTORS = [
            '[class*="-modal-overlay"]',
            '.vc-modal-overlay',
            '.vc-modal',
            '.prm-modal',
            '.prm-modal-overlay',
            '.idea-modal-overlay',
            '.inv-modal-overlay',
            '.evv-modal-overlay',
            '.emg-modal-overlay',
            '.jb-modal-overlay',
            '.appl-modal-overlay',
            '.rech-modal-overlay',
            '.bug-modal-overlay',
            '.checkout-modal-overlay',
            '.modal'
        ];
        var SELECTOR = MODAL_SELECTORS.join(',');

        function anyOpen() {
            // dialog[open]
            if (document.querySelector('dialog[open]')) return true;
            // class-based
            var nodes = document.querySelectorAll(SELECTOR);
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                if (el.classList.contains('open') ||
                    el.classList.contains('show') ||
                    el.classList.contains('is-open') ||
                    el.classList.contains('active')) {
                    // ignore hidden helpers
                    var cs = window.getComputedStyle(el);
                    if (cs.display !== 'none' && cs.visibility !== 'hidden') {
                        return true;
                    }
                }
            }
            return false;
        }

        function refresh() {
            var open = anyOpen();
            var hadFlag = document.body.classList.contains('has-open-modal');
            if (open && !hadFlag) document.body.classList.add('has-open-modal');
            else if (!open && hadFlag) document.body.classList.remove('has-open-modal');
        }

        var rafId = 0;
        function schedule() {
            if (rafId) return;
            rafId = window.requestAnimationFrame(function () {
                rafId = 0;
                refresh();
            });
        }

        var mo = new MutationObserver(function (mutations) {
            // We only care about class/style/open mutations and DOM additions.
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type === 'attributes' &&
                    (m.attributeName === 'class' || m.attributeName === 'style' || m.attributeName === 'open')) {
                    schedule();
                    return;
                }
                if (m.type === 'childList' &&
                    (m.addedNodes.length || m.removedNodes.length)) {
                    schedule();
                    return;
                }
            }
        });
        mo.observe(document.body, {
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style', 'open'],
            childList: true
        });

        // Initial pass + window-level safety nets
        refresh();
        window.addEventListener('hashchange', refresh);
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') schedule();
        });

        // Public helper
        window.refreshModalFlag = refresh;
    }());

}());
