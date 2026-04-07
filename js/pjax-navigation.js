/**
 * pjax-navigation.js
 *
 * Intercepts internal links and loads pages via the Fetch API (Pjax-style).
 *
 * Features:
 *  - Subtle loading bar at the top of the viewport during navigation.
 *  - Fade-out / fade-in animation on the #main-content area.
 *  - Fetches new HTML, parses it with DOMParser, and swaps only the inner
 *    content wrapper inside #main-content so the sidebar, header, and footer
 *    stay in place.
 *  - Updates <title> and browser history via pushState / popstate.
 *  - Re-executes inline and external <script> tags found in the new content.
 *  - Cancels in-flight requests when a new navigation starts before the
 *    previous one completes.
 *  - Falls back to a full page load on any network or parsing error.
 *  - Respects target="_blank", data-no-pjax, download, hash-only, and
 *    cross-origin links (all are left for the browser to handle normally).
 *  - Fires a custom "pjax:complete" event on document after each swap so
 *    other scripts can re-initialise themselves if needed.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* Configuration                                                        */
    /* ------------------------------------------------------------------ */

    /** CSS selector for the element whose innerHTML is swapped on navigate. */
    var CONTENT_SELECTOR = '#main-content > div';

    /** Duration of the content fade-out in milliseconds. */
    var FADE_OUT_MS = 150;

    /** Interval between fake-progress ticks on the loading bar (ms). */
    var BAR_TICK_MS = 200;

    /** The loading bar starts at this fraction (0–1) of total width. */
    var BAR_START = 0.1;

    /* ------------------------------------------------------------------ */
    /* State                                                               */
    /* ------------------------------------------------------------------ */

    var abortCtrl   = null;   // AbortController for the active fetch
    var barTimer    = null;   // setInterval handle for fake-progress ticks
    var barProgress = 0;      // Current bar width as a fraction (0–1)
    var barEl       = null;   // The loading bar DOM element
    var lastPath    = '';     // pathname+search of the last completed navigation

    /* ------------------------------------------------------------------ */
    /* Loading bar                                                         */
    /* ------------------------------------------------------------------ */

    function ensureBar() {
        if (barEl) return;

        var style = document.createElement('style');
        style.textContent = [
            '#pjax-bar {',
            '  position: fixed;',
            '  top: 0; left: 0;',
            '  height: 3px;',
            '  width: 0%;',
            '  z-index: 99999;',
            '  background: linear-gradient(90deg, #15803d, #22c55e);',
            '  transition: width 0.2s ease, opacity 0.4s ease;',
            '  pointer-events: none;',
            '  border-radius: 0 2px 2px 0;',
            '  box-shadow: 0 0 8px rgba(34, 197, 94, 0.6);',
            '}'
        ].join('\n');
        document.head.appendChild(style);

        barEl = document.createElement('div');
        barEl.id = 'pjax-bar';
        document.body.appendChild(barEl);
    }

    function setBarWidth(p) {
        barProgress = p;
        if (!barEl) return;
        barEl.style.width   = (p * 100) + '%';
        barEl.style.opacity = '1';
    }

    function startBar() {
        ensureBar();
        setBarWidth(BAR_START);
        clearInterval(barTimer);
        // Inches towards 90 % slowly – never reaching it until the fetch finishes.
        barTimer = setInterval(function () {
            var gap = 0.9 - barProgress;
            if (gap > 0.01) {
                setBarWidth(barProgress + gap * 0.25);
            }
        }, BAR_TICK_MS);
    }

    function finishBar() {
        clearInterval(barTimer);
        setBarWidth(1);
        setTimeout(function () {
            if (barEl) {
                barEl.style.opacity = '0';
                barEl.style.width   = '0%';
            }
            barProgress = 0;
        }, 400);
    }

    function resetBar() {
        clearInterval(barTimer);
        if (barEl) {
            barEl.style.opacity = '0';
            barEl.style.width   = '0%';
        }
        barProgress = 0;
    }

    /* ------------------------------------------------------------------ */
    /* DOM helpers                                                         */
    /* ------------------------------------------------------------------ */

    function getContentEl() {
        return document.querySelector(CONTENT_SELECTOR);
    }

    /**
     * Re-creates every <script> element inside container so that the browser
     * actually executes the code. innerHTML / DOMParser never runs scripts.
     * Inline scripts are wrapped in an IIFE so that const/let declarations
     * inside page scripts do not collide with each other on repeated PJAX
     * navigations to the same page.
     */
    function runScripts(container) {
        var scripts = container.querySelectorAll('script');
        for (var i = 0; i < scripts.length; i++) {
            var old   = scripts[i];
            var fresh = document.createElement('script');
            for (var j = 0; j < old.attributes.length; j++) {
                fresh.setAttribute(
                    old.attributes[j].name,
                    old.attributes[j].value
                );
            }
            if (!old.src) {
                // Wrap in an IIFE to scope const/let declarations so that
                // re-navigating to the same page via PJAX does not throw
                // "Identifier X has already been declared".
                fresh.textContent = '(function(){\n' + old.textContent + '\n})();';
            }
            old.parentNode.replaceChild(fresh, old);
        }
    }

    /* ------------------------------------------------------------------ */
    /* URL helpers                                                         */
    /* ------------------------------------------------------------------ */

    function isSameOrigin(href) {
        try {
            return new URL(href, location.href).origin === location.origin;
        } catch (e) {
            return false;
        }
    }

    function urlPath(href) {
        try {
            var u = new URL(href, location.href);
            return u.pathname + u.search;
        } catch (e) {
            return '';
        }
    }

    /* ------------------------------------------------------------------ */
    /* Link eligibility check                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Returns true when the given <a> element should be handled by Pjax.
     * Links are skipped when they:
     *  - have no href or use the javascript: pseudo-protocol
     *  - are hash-only anchors (#section)
     *  - have data-no-pjax or download attributes
     *  - open in a new context (target != "" and target != "_self")
     *  - point to a different origin
     */
    var UNSAFE_PROTOCOLS = /^(javascript|data|vbscript):/i;

    function isEligible(anchor) {
        var href = anchor.getAttribute('href');
        // Trim whitespace so leading spaces cannot bypass the protocol check.
        if (!href || UNSAFE_PROTOCOLS.test(href.trim())) { return false; }
        if (href.trim().charAt(0) === '#')               { return false; }
        if (anchor.hasAttribute('data-no-pjax'))         { return false; }
        if (anchor.hasAttribute('download'))             { return false; }
        var target = anchor.getAttribute('target');
        if (target && target !== '_self')                { return false; }
        return isSameOrigin(href);
    }

    /* ------------------------------------------------------------------ */
    /* Core navigation                                                     */
    /* ------------------------------------------------------------------ */

    function navigate(url, push) {
        var path = urlPath(url);

        // Abort any in-flight request so only the latest navigation wins.
        if (abortCtrl) {
            abortCtrl.abort();
        }
        abortCtrl = new AbortController();

        startBar();

        // Fade out the content area and start the fetch at the same time so
        // there is no artificial minimum wait beyond what the network needs.
        var el = getContentEl();
        if (el) {
            el.style.transition = 'opacity ' + FADE_OUT_MS + 'ms ease';
            el.style.opacity    = '0';
        }

        var fadeEnd  = new Promise(function (resolve) { setTimeout(resolve, FADE_OUT_MS); });
        var fetchEnd = fetch(url, {
            headers : { 'X-Requested-With': 'pjax', 'X-Pjax': '1' },
            signal  : abortCtrl.signal
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            // If the server redirected us outside our origin, let the browser handle it.
            if (r.redirected && !isSameOrigin(r.url)) {
                location.href = r.url;
                return null;
            }
            return r.text();
        });

        // Wait for both the fade-out and the network response before swapping.
        Promise.all([fetchEnd, fadeEnd])
            .then(function (results) {
                var html = results[0];
                if (html === null) { return; }   // redirected externally

                var newDoc     = new DOMParser().parseFromString(html, 'text/html');
                var newContent = newDoc.querySelector(CONTENT_SELECTOR);

                if (!newContent) {
                    // Unexpected response structure – fall back to a full load.
                    location.href = url;
                    return;
                }

                // --- Swap content ------------------------------------------
                // Content comes from the same origin (enforced by isSameOrigin
                // and the fetch's same-origin response). Using innerHTML is the
                // standard Pjax pattern; the browser does not apply its
                // XSS auditor to dynamically inserted content regardless of
                // method, so this does not weaken the existing security posture.
                var current = getContentEl();
                if (current) {
                    current.innerHTML = newContent.innerHTML;
                    runScripts(current);
                }

                // --- Update page title --------------------------------------
                if (newDoc.title) {
                    document.title = newDoc.title;
                }

                // --- Update browser history ---------------------------------
                if (push) {
                    history.pushState({ pjax: true }, document.title, url);
                }
                lastPath = path;

                // --- Scroll to the top --------------------------------------
                window.scrollTo(0, 0);

                // --- Fade new content in ------------------------------------
                requestAnimationFrame(function () {
                    var c = getContentEl();
                    if (c) {
                        c.style.transition = 'opacity 200ms ease';
                        c.style.opacity    = '1';
                    }
                });

                finishBar();

                // Recalibrate the topbar height in case the new page is taller.
                // navbarScrollUtils is exposed by navbar-scroll.js (loaded first).
                if (window.navbarScrollUtils) {
                    window.navbarScrollUtils.updateTopbarHeight();
                }

                // Notify other modules that a Pjax navigation finished.
                document.dispatchEvent(
                    new CustomEvent('pjax:complete', { detail: { url: url } })
                );
            })
            .catch(function (err) {
                // AbortError = a newer navigation cancelled this one; ignore it.
                if (err.name === 'AbortError') { return; }

                // Any other error: restore the content and do a full page load.
                resetBar();
                var c = getContentEl();
                if (c) {
                    c.style.transition = '';
                    c.style.opacity    = '1';
                }
                location.href = url;
            });
    }

    /* ------------------------------------------------------------------ */
    /* Click interception                                                  */
    /* ------------------------------------------------------------------ */

    function onClick(e) {
        // Ignore modifier-key clicks so Ctrl+Click still opens a new tab.
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) {
            return;
        }

        var anchor = e.target.closest('a');
        if (!anchor || !isEligible(anchor)) { return; }

        var url = anchor.href;

        // Don't re-fetch the current page (but still allow hash navigation).
        if (url.split('#')[0] === location.href.split('#')[0]) { return; }

        e.preventDefault();
        navigate(url, true);
    }

    /* ------------------------------------------------------------------ */
    /* Back / forward navigation                                           */
    /* ------------------------------------------------------------------ */

    function onPopState() {
        var path = location.pathname + location.search;
        // Skip if only the hash changed (the browser handles that natively).
        if (path === lastPath) { return; }
        navigate(location.href, false);
    }

    /* ------------------------------------------------------------------ */
    /* Initialization                                                      */
    /* ------------------------------------------------------------------ */

    function init() {
        // Record the current page so popstate can detect hash-only changes.
        lastPath = location.pathname + location.search;

        // Tag the current history entry so popstate always fires correctly.
        history.replaceState({ pjax: true }, document.title, location.href);

        // Use event delegation so links added after init are also intercepted.
        document.addEventListener('click', onClick);
        window.addEventListener('popstate', onPopState);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
