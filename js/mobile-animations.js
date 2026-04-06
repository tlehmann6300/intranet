/**
 * mobile-animations.js
 *
 * Enhances the entire website with:
 *  1. Scroll-reveal animations (IntersectionObserver activates the CSS
 *     .reveal-on-scroll / .revealed classes defined in theme.css).
 *  2. Auto-stagger – applies staggered entrance delays to cards inside grid
 *     and list containers so each child animates in sequentially.
 *  3. Swipe gestures – swipe right on the viewport edge to open the sidebar,
 *     swipe left to close it (mobile only).
 *  4. Back-to-top button – appears after scrolling 300 px, smooth-scrolls back.
 *  5. PJAX integration – re-initialises all of the above after each pjax
 *     navigation so content loaded via pjax-navigation.js is also animated.
 *  6. Reduced-motion respect – all animations are skipped when
 *     prefers-reduced-motion: reduce is set.
 */
(function () {
    'use strict';

    /* ─── helpers ─────────────────────────────────────────────────────────── */

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var MD_BREAKPOINT = 768; // must match Tailwind md breakpoint

    function isMobile() {
        return window.innerWidth < MD_BREAKPOINT;
    }

    /* ─── 1. Scroll-reveal via IntersectionObserver ────────────────────────
       Watches every element that carries the class .reveal-on-scroll and adds
       .revealed as soon as ≥10 % of the element enters the viewport.
       Falls back gracefully if IntersectionObserver is unavailable.
    ────────────────────────────────────────────────────────────────────────── */

    var revealObserver = null;

    function initRevealObserver() {
        if (reducedMotion || typeof IntersectionObserver === 'undefined') {
            // Immediately show all hidden elements when motion is reduced
            document.querySelectorAll('.reveal-on-scroll').forEach(function (el) {
                el.classList.add('revealed');
            });
            return;
        }

        revealObserver = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('revealed');
                        revealObserver.unobserve(entry.target); // fire once
                    }
                });
            },
            { threshold: 0.1, rootMargin: '0px 0px -40px 0px' }
        );

        document.querySelectorAll('.reveal-on-scroll').forEach(function (el) {
            revealObserver.observe(el);
        });
    }

    /* ─── 2. Auto-stagger for cards / list items ───────────────────────────
       Automatically adds reveal-on-scroll + stagger-item to every .card,
       .stat-card, .dash-stat-card, .dash-event-card, .dash-blog-card and
       similar elements that are NOT already marked as .reveal-on-scroll.
       Siblings inside the same grid/flex parent get sequential animation delays
       so they cascade in one after another.
    ────────────────────────────────────────────────────────────────────────── */

    // Selectors for elements that should animate in
    var ANIMATE_SELECTORS = [
        '.card:not(.reveal-on-scroll)',
        '.stat-card:not(.reveal-on-scroll)',
        '.dash-stat-card:not(.reveal-on-scroll)',
        '.dash-event-card:not(.reveal-on-scroll)',
        '.dash-blog-card:not(.reveal-on-scroll)',
        '.dash-helper-card:not(.reveal-on-scroll)',
        '.directory-card:not(.reveal-on-scroll)',
        '.staggered-list > *:not(.reveal-on-scroll)'
    ].join(', ');

    // Maximum stagger delay in seconds (so large grids don't take forever)
    var MAX_STAGGER_DELAY = 0.5;
    var STAGGER_STEP      = 0.07; // seconds between siblings

    function autoStagger() {
        if (reducedMotion) return;

        // Group candidates by their immediate parent so we stagger siblings together
        var parents = new Map();

        document.querySelectorAll(ANIMATE_SELECTORS).forEach(function (el) {
            var parent = el.parentElement;
            if (!parents.has(parent)) {
                parents.set(parent, []);
            }
            parents.get(parent).push(el);
        });

        parents.forEach(function (children) {
            children.forEach(function (el, index) {
                el.classList.add('reveal-on-scroll');
                var delay = Math.min(index * STAGGER_STEP, MAX_STAGGER_DELAY);
                el.style.transitionDelay = delay + 's';
                // Observe the newly-marked element
                if (revealObserver) {
                    revealObserver.observe(el);
                }
            });
        });
    }

    /* ─── 3. Swipe gestures for the sidebar ───────────────────────────────
       On touch devices:
         • Swipe right from the left edge (0–48 px) → open sidebar
         • Swipe left anywhere while sidebar is open → close sidebar
       Threshold: 60 px horizontal movement, max 150 px vertical drift.
    ────────────────────────────────────────────────────────────────────────── */

    var SWIPE_EDGE_WIDTH   = 48;  // px from left edge to trigger open
    var SWIPE_MIN_X        = 60;  // px minimum horizontal swipe
    var SWIPE_MAX_Y        = 150; // px maximum vertical drift (to avoid interfering with scroll)

    var touchStartX = 0;
    var touchStartY = 0;
    var swipeActive = false;

    function initSwipeGestures() {
        document.addEventListener('touchstart', function (e) {
            var touch = e.touches[0];
            touchStartX  = touch.clientX;
            touchStartY  = touch.clientY;
            swipeActive  = touchStartX <= SWIPE_EDGE_WIDTH; // only arm from left edge
        }, { passive: true });

        document.addEventListener('touchend', function (e) {
            if (!e.changedTouches.length) return;
            var touch   = e.changedTouches[0];
            var deltaX  = touch.clientX - touchStartX;
            var deltaY  = Math.abs(touch.clientY - touchStartY);

            var sidebar  = document.getElementById('sidebar');
            var isOpen   = sidebar && sidebar.classList.contains('open');

            // Swipe right → open sidebar (must start from left edge)
            if (swipeActive && deltaX > SWIPE_MIN_X && deltaY < SWIPE_MAX_Y && !isOpen && isMobile()) {
                var openSidebar = window.__sidebarOpen;
                if (typeof openSidebar === 'function') {
                    openSidebar();
                }
            }

            // Swipe left → close sidebar (can start anywhere)
            if (deltaX < -SWIPE_MIN_X && deltaY < SWIPE_MAX_Y && isOpen && isMobile()) {
                var closeSidebar = window.__sidebarClose;
                if (typeof closeSidebar === 'function') {
                    closeSidebar();
                }
            }

            swipeActive = false;
        }, { passive: true });
    }

    /* ─── 4. Back-to-top button ───────────────────────────────────────────
       A circular button fixed to the bottom-right corner appears once the user
       has scrolled 300 px.  Clicking / tapping it smoothly scrolls back to the
       top.  On mobile the button sits above the bottom navigation bar
       (bottom: calc(bottom-nav-height + 1rem)).
    ────────────────────────────────────────────────────────────────────────── */

    var backToTopBtn     = null;
    var scrollProgress   = null;
    var BTT_SHOW_AT      = 300; // px
    var ticking          = false;
    var lastScrollY      = 0;

    function onScrollBTT() {
        lastScrollY = window.scrollY;
        if (!ticking) {
            requestAnimationFrame(updateBTT);
            ticking = true;
        }
    }

    function updateBTT() {
        ticking = false;

        // Show / hide button
        if (backToTopBtn) {
            if (lastScrollY > BTT_SHOW_AT) {
                backToTopBtn.classList.add('btt-visible');
            } else {
                backToTopBtn.classList.remove('btt-visible');
            }
        }

        // Update SVG circle progress indicator
        if (scrollProgress) {
            var docHeight  = document.documentElement.scrollHeight - window.innerHeight;
            var progress   = docHeight > 0 ? Math.min(lastScrollY / docHeight, 1) : 0;
            var circumference = 2 * Math.PI * 12; // r=12
            var dashOffset = circumference * (1 - progress);
            scrollProgress.style.strokeDashoffset = dashOffset;
        }
    }

    function initBackToTop() {
        backToTopBtn   = document.getElementById('back-to-top');
        scrollProgress = document.getElementById('btt-progress-circle');
        if (!backToTopBtn) return;

        var circumference = 2 * Math.PI * 12; // r=12 (matches #btt-progress-circle r attribute)
        if (scrollProgress) {
            scrollProgress.style.strokeDasharray  = circumference;
            scrollProgress.style.strokeDashoffset = circumference;
        }

        window.addEventListener('scroll', onScrollBTT, { passive: true });

        backToTopBtn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    /* ─── 5. Page entrance animation ─────────────────────────────────────
       After each PJAX navigation (or on initial load) the main content area
       fades in from a slight downward offset, giving a clean page-transition
       feel.
    ────────────────────────────────────────────────────────────────────────── */

    function triggerPageEntrance() {
        var mainContent = document.getElementById('main-content');
        if (!mainContent || reducedMotion) return;

        mainContent.classList.remove('page-entered');
        // Force reflow so the class removal is registered before we re-add
        void mainContent.offsetWidth;
        mainContent.classList.add('page-entered');
    }

    /* ─── 6. PJAX re-init hook ────────────────────────────────────────────
       pjax-navigation.js fires a `pjax:complete` event on the document after
       new content is injected.  Re-observe scroll elements so the animations
       work on every page visit, not just the first.
    ────────────────────────────────────────────────────────────────────────── */

    function reinitAfterPjax() {
        // Disconnect old observer; create a fresh one for the new DOM
        if (revealObserver) {
            revealObserver.disconnect();
        }
        initRevealObserver();
        autoStagger();
        triggerPageEntrance();
    }

    /* ─── Initialisation ──────────────────────────────────────────────────── */

    function init() {
        initRevealObserver();
        autoStagger();
        initSwipeGestures();
        initBackToTop();
        triggerPageEntrance();

        // Re-init after pjax navigations
        document.addEventListener('pjax:complete', reinitAfterPjax);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
