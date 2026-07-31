/**
 * IdealData storefront pixel — Adobe Commerce cart-tracking bridge.
 *
 * Reports `cart.add` / `cart.remove` to the pixel by DIFFING Magento's `cart`
 * customer-data section, and pins the logged-in customer id via `setCustomerId`.
 * Loaded by view/frontend/templates/pixel/loader.phtml (only when the pixel is
 * enabled), configured through data-attributes on its own <script> tag.
 *
 * WHY THIS EXISTS
 * ---------------
 * The pixel SDK's automatic capture has two layers: native Magento JS events
 * (`ajax:addToCart` & friends) and a customer-data section diff. Both were built
 * against Luma's runtime, and the event layer is jQuery-based. Hyvä ships NO
 * jQuery, NO RequireJS and — critically — dispatches **no add-to-cart event at
 * all** (see the Hyvä JS event reference: cart-related events are `toggle-cart`
 * and `reload-customer-section-data`, nothing for "an item was added"). On a Hyvä
 * store the event layer therefore has nothing to hook and cart activity is
 * invisible, which is exactly what Metal Mafia was seeing.
 *
 * What Hyvä DOES have is the same private-content pipeline as Luma:
 *  - all sections are fetched from `/customer/section/load` and persisted to
 *    `localStorage['mage-cache-storage']` (identical key and shape to Luma);
 *  - a `private-content-loaded` CustomEvent is dispatched on `window` with the
 *    full section map in `event.detail.data`;
 *  - every cart mutation is a POST, which rotates the `private_content_version`
 *    cookie, so Hyvä refetches section data afterwards (its AJAX cart controls
 *    additionally dispatch `reload-customer-section-data` themselves).
 *
 * So instead of instrumenting theme controls one by one — PDP form, listing
 * button, drawer qty, cart page, sliders, quick-add, Algolia, PageBuilder
 * widgets, each of which differs per theme and per extension — this bridge
 * watches the one thing they all necessarily change: the cart section. Any
 * surface that alters the cart is covered by construction, including plain
 * non-AJAX form posts that navigate away (the diff is computed on the next page
 * load, since the baseline is persisted).
 *
 * It is deliberately theme-agnostic: vanilla ES5-ish, no jQuery, no Alpine, no
 * RequireJS, no framework globals. It runs on Luma too, but only where the SDK
 * cannot — see the ownership rule below.
 *
 * WHO OWNS A CART EVENT (do not "simplify" this away)
 * --------------------------------------------------
 * The SDK's cross-layer de-duplication is ONE-DIRECTIONAL: an explicit
 * `track()` call suppresses the auto-capture diff that FOLLOWS it, but an
 * explicit call that arrives AFTER an auto-capture emit is not suppressed. So
 * reporting a change the SDK also reports would double-count it, and the SDK's
 * `cartAutoCapture` switch is delivered per store from ingest — the module
 * cannot turn it off. Ownership is therefore split on the one thing that is
 * observable from here, `window.idealdataPixelCore` (the core bundle's global,
 * present only once the SDK's collectors are running):
 *
 * we report a change only while that global is ABSENT; once it is there, the SDK
 * owns live cart changes and we merely advance our baseline.
 *
 * That single rule is enough, because the SDK can only ever report a change it
 * observes AFTER its core starts — it seeds its cart baseline from the section
 * cache at that moment. So:
 *
 *  - Hyvä's lost add (form POST → navigation → the section refetch that follows
 *    the page load) is normally observed by us before the SDK's core is up: the
 *    SDK's seed then already contains it and it never reports it, so our report
 *    is the only one. This is the gap the whole bridge exists for.
 *  - If instead the SDK's core came up first, it diffs that same refetch itself
 *    and reports it — and we stay silent, because the global is present.
 *  - A live in-page change (mini-cart qty, drawer remove, Luma's click-time
 *    add-to-cart) always happens with the core up, so it is always the SDK's.
 *
 * Net effect: the shopper action Hyvä loses today is reported exactly once,
 * Luma keeps behaving exactly as it does now, and neither side double-counts.
 *
 * ⚠️ COUPLING: this assumes the SDK's auto-capture is enabled (it is — ingest
 * does not emit `cartAutoCapture` today, so the default `true` applies). If
 * IdealData ever sets `cartAutoCapture:false` for an Adobe Commerce store, this
 * bridge must be told as well, or live cart changes will be reported by nobody.
 *
 * SAFETY RULES (each one is a false-event source we have to not fire on)
 * ---------------------------------------------------------------------
 *  - Cart section ABSENT (invalidated, not yet loaded, storage unavailable) is
 *    never read as "the cart was emptied" — we skip instead of diffing.
 *  - The baseline is persisted in localStorage, so it is SHARED between tabs and
 *    survives page loads; whichever tab observes a change first advances the
 *    baseline, and the others see a matching signature and stay quiet.
 *  - Login / logout resets the baseline silently (quote merge on login, cart
 *    disappearing on logout are not shopper cart actions).
 *  - The order-success page resets the baseline silently (the cart is emptied
 *    server-side by placing the order, not by the shopper removing items).
 *  - A baseline older than STALE_MS still reports additions but suppresses
 *    removals: cart expiry, an admin editing the quote, or an order placed on
 *    another device all look exactly like a removal on the next visit, whereas a
 *    phantom addition has no equivalent cause.
 *  - `summary_count` is a line-item COUNT or a total QTY depending on
 *    `checkout/cart_link/use_qty`, so quantities come from the per-line `qty`
 *    values and `summary_count` is only ever a fallback for stores whose section
 *    carries no item list.
 *  - Nothing here throws into the theme: every entry point is wrapped, and the
 *    bridge no-ops when localStorage is unavailable (private mode) except for
 *    the event path, which needs no storage.
 */
(function () {
    'use strict';

    var SCRIPT = document.currentScript
        || document.querySelector('script[data-idealdata-cart-tracking]');

    /** `/customer/section/load` URL, passed in from PHP (no BASE_URL guessing). */
    var SECTION_LOAD_URL = (SCRIPT && SCRIPT.getAttribute('data-section-load-url')) || '';

    /** Section name owned by TNW\Idealdata\CustomerData\Identity. */
    var IDENTITY_SECTION = (SCRIPT && SCRIPT.getAttribute('data-identity-section'))
        || 'tnw-idealdata-identity';

    var CART_SECTION = 'cart';
    var CUSTOMER_SECTION = 'customer';

    /** Magento's own client-side section cache — same key in Luma and Hyvä. */
    var STORAGE_KEY = 'mage-cache-storage';

    /** Our persisted cart baseline (shared across tabs of the same origin). */
    var STATE_KEY = 'idealdata-pixel-cart-state';

    /**
     * The SDK core bundle's global. Its presence means the SDK's own cart
     * collectors are running and own live cart changes (see the ownership rule
     * in the header).
     */
    var CORE_GLOBAL = 'idealdataPixelCore';

    var POLL_MS = 1000;
    var STALE_MS = 30 * 60 * 1000;
    var MAX_QUEUED_CALLS = 50;
    var MAX_TITLE_LENGTH = 120;

    /** Order-success pages: `checkout/onepage/success`, `multishipping/checkout/success`, … */
    var SUCCESS_PATH = /\/(checkout|multishipping)\/[^?#]*success/i;

    var queuedCalls = [];
    var memoryState = null;
    var lastRawStorage = null;
    var lastCustomerId = null;
    var identityHealAttempted = false;

    function isDebug() {
        return !!(window.idealdataSettings && window.idealdataSettings.debug);
    }

    function log(message, payload) {
        if (!isDebug() || !window.console || !window.console.log) {
            return;
        }
        if (payload === undefined) {
            window.console.log('[idealdata] cart bridge: ' + message);
        } else {
            window.console.log('[idealdata] cart bridge: ' + message, payload);
        }
    }

    /**
     * Calls the pixel's public API, buffering until it exists. The loader is
     * async, so the bridge can easily run first; queued calls are replayed on the
     * next tick after `idealdataPixel` shows up.
     */
    function pixel(args) {
        if (typeof window.idealdataPixel === 'function') {
            try {
                window.idealdataPixel.apply(null, args);
            } catch (e) {
                log('pixel call failed', e);
            }
            return;
        }
        if (queuedCalls.length < MAX_QUEUED_CALLS) {
            queuedCalls.push(args);
        }
    }

    function flushQueuedCalls() {
        if (!queuedCalls.length || typeof window.idealdataPixel !== 'function') {
            return;
        }
        var pending = queuedCalls;
        queuedCalls = [];
        for (var i = 0; i < pending.length; i++) {
            pixel(pending[i]);
        }
    }

    function storage() {
        try {
            var store = window.localStorage;
            // Touch it: Safari private mode throws on access, not on read.
            store.getItem(STORAGE_KEY);
            return store;
        } catch (e) {
            return null;
        }
    }

    function readRawStorage() {
        var store = storage();
        if (!store) {
            return null;
        }
        try {
            return store.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    function qty(value) {
        var number = parseFloat(value);
        if (!number || isNaN(number)) {
            return 0;
        }
        // Cart quantities can be fractional (weight-based products); keep three
        // decimals so float noise never shows up as a phantom delta.
        return Math.round(number * 1000) / 1000;
    }

    function text(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).slice(0, MAX_TITLE_LENGTH);
    }

    function isLoggedIn(sectionData) {
        var customer = sectionData && sectionData[CUSTOMER_SECTION];

        return !!(customer && (customer.firstname || customer.fullname));
    }

    /**
     * Normalises the `cart` section into a comparable state object, or null when
     * the section is not currently readable (which must never be diffed).
     */
    function buildCartState(sectionData) {
        var cart = sectionData && sectionData[CART_SECTION];
        if (!cart || typeof cart !== 'object') {
            return null;
        }

        var lines = Object.prototype.toString.call(cart.items) === '[object Array]' ? cart.items : null;
        var summaryCount = qty(cart.summary_count);
        var items = {};
        var totalQuantity = 0;

        for (var i = 0; lines && i < lines.length; i++) {
            var line = lines[i] || {};
            var key = line.item_id !== null && line.item_id !== undefined
                ? 'i' + String(line.item_id)
                : 'p' + String(line.product_id) + ':' + String(line.product_sku);
            var lineQty = qty(line.qty);

            if (items[key]) {
                items[key].quantity = qty(items[key].quantity + lineQty);
            } else {
                items[key] = {
                    productId: line.product_id !== null && line.product_id !== undefined
                        ? String(line.product_id)
                        : '',
                    variantId: text(line.product_sku),
                    title: text(line.product_name),
                    quantity: lineQty
                };
            }
            totalQuantity = qty(totalQuantity + lineQty);
        }

        // An empty item list next to a non-empty cart means the list is not a
        // usable inventory of the cart (some deployments trim it), so fall back
        // to counting instead of reporting every line as removed.
        var itemsUsable = !!lines && (lines.length > 0 || summaryCount === 0);

        return {
            items: itemsUsable ? items : {},
            itemsUsable: itemsUsable,
            totalQuantity: itemsUsable ? totalQuantity : summaryCount,
            summaryCount: summaryCount,
            loggedIn: isLoggedIn(sectionData)
        };
    }

    /**
     * Stable fingerprint of a cart state. Two tabs computing the same state
     * produce the same signature, which is what keeps them from both reporting
     * the same shopper action.
     */
    function signature(state) {
        var keys = [];
        for (var key in state.items) {
            if (Object.prototype.hasOwnProperty.call(state.items, key)) {
                keys.push(key);
            }
        }
        keys.sort();

        var parts = [];
        for (var i = 0; i < keys.length; i++) {
            parts.push(keys[i] + '=' + state.items[keys[i]].quantity);
        }

        return [
            state.itemsUsable ? 'items' : 'count',
            state.summaryCount,
            state.totalQuantity,
            state.loggedIn ? 'in' : 'out',
            parts.join(',')
        ].join('|');
    }

    function readState() {
        var store = storage();
        if (!store) {
            return memoryState;
        }
        try {
            var raw = store.getItem(STATE_KEY);
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (!parsed || !parsed.state || typeof parsed.sig !== 'string') {
                return null;
            }
            return parsed;
        } catch (e) {
            return null;
        }
    }

    function writeState(state, sig) {
        var record = { state: state, sig: sig, ts: Date.now() };
        memoryState = record;

        var store = storage();
        if (!store) {
            return;
        }
        try {
            store.setItem(STATE_KEY, JSON.stringify(record));
        } catch (e) {
            // Quota / private mode: the in-memory baseline still works per tab.
        }
    }

    /**
     * Why this observed change must NOT be reported as shopper cart activity,
     * or '' when it should be.
     */
    function suppressionReason(previous, next) {
        if (previous.state.loggedIn !== next.loggedIn) {
            return 'customer session changed (quote merge / logout)';
        }
        if (next.totalQuantity === 0 && SUCCESS_PATH.test(String(window.location.pathname))) {
            return 'order placed (cart emptied server-side)';
        }

        return '';
    }

    function isStale(previous) {
        return !previous.ts || (Date.now() - previous.ts) > STALE_MS;
    }

    function diff(previous, next) {
        var events = [];
        var key;

        if (previous.itemsUsable && next.itemsUsable) {
            var keys = {};
            for (key in previous.items) {
                if (Object.prototype.hasOwnProperty.call(previous.items, key)) {
                    keys[key] = true;
                }
            }
            for (key in next.items) {
                if (Object.prototype.hasOwnProperty.call(next.items, key)) {
                    keys[key] = true;
                }
            }

            for (key in keys) {
                if (!Object.prototype.hasOwnProperty.call(keys, key)) {
                    continue;
                }
                var before = previous.items[key];
                var after = next.items[key];
                var delta = qty((after ? after.quantity : 0) - (before ? before.quantity : 0));
                if (delta === 0) {
                    continue;
                }
                events.push({
                    action: delta > 0 ? 'cart.add' : 'cart.remove',
                    item: after || before,
                    quantity: Math.abs(delta)
                });
            }

            return events;
        }

        // No usable item list on either side: report the movement we can prove.
        var countDelta = qty(next.summaryCount - previous.summaryCount);
        if (countDelta !== 0) {
            events.push({
                action: countDelta > 0 ? 'cart.add' : 'cart.remove',
                item: null,
                quantity: Math.abs(countDelta)
            });
        }

        return events;
    }

    function emit(event, cartTotalQuantity) {
        var payload = { quantity: event.quantity };

        if (event.item && event.item.productId) {
            payload.productId = event.item.productId;
        }
        if (event.action === 'cart.add' && event.item) {
            if (event.item.variantId) {
                payload.variantId = event.item.variantId;
            }
            if (event.item.title) {
                payload.title = event.item.title;
            }
        }
        if (cartTotalQuantity !== null) {
            payload.cartTotalQuantity = cartTotalQuantity;
        }

        pixel(['track', event.action, payload]);
        log(event.action + ' captured via section-diff', payload);
    }

    function handleIdentity(sectionData) {
        var section = sectionData && sectionData[IDENTITY_SECTION];
        var customerId = section && section.customer_id !== null && section.customer_id !== undefined
            ? String(section.customer_id)
            : '';

        if (customerId && customerId !== '0') {
            if (customerId !== lastCustomerId) {
                lastCustomerId = customerId;
                pixel(['setCustomerId', customerId]);
                log('customer identity pinned from the ' + IDENTITY_SECTION + ' section');
            }
            return;
        }

        if (isLoggedIn(sectionData)) {
            healIdentity();
        }
    }

    /**
     * A logged-in shopper whose identity section is missing or stale: load just
     * that section directly. Once per page view, only for logged-in shoppers, and
     * only as a repair — themes that preload every section (Luma and Hyvä both
     * do) never reach this.
     */
    function healIdentity() {
        if (identityHealAttempted || !SECTION_LOAD_URL || typeof window.fetch !== 'function') {
            return;
        }
        identityHealAttempted = true;
        log('logged-in shopper without identity data — loading ' + IDENTITY_SECTION);

        var separator = SECTION_LOAD_URL.indexOf('?') === -1 ? '?' : '&';
        var url = SECTION_LOAD_URL + separator + 'sections=' + encodeURIComponent(IDENTITY_SECTION);

        window.fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            if (payload && typeof payload === 'object') {
                handleIdentity(payload);
            }
        })['catch'](function () {
            // Identity stays unset; cart capture is unaffected.
        });
    }

    /**
     * True once the SDK's core bundle is loaded, i.e. its own cart collectors are
     * live. See the ownership rule in the header.
     */
    function sdkOwnsLiveCartEvents() {
        try {
            return !!window[CORE_GLOBAL];
        } catch (e) {
            return false;
        }
    }

    function process(sectionData) {
        handleIdentity(sectionData);

        var next = buildCartState(sectionData);
        if (!next) {
            return;
        }

        var nextSig = signature(next);
        var previous = readState();

        if (!previous) {
            writeState(next, nextSig);
            log('cart baseline established');
            return;
        }
        if (previous.sig === nextSig) {
            return;
        }

        var reason = suppressionReason(previous, next);
        var stale = isStale(previous);
        var events = reason ? [] : diff(previous.state, next);

        // Advance the baseline before reporting, so a second tab observing the
        // same change finds a matching signature and stays quiet.
        writeState(next, nextSig);

        if (reason) {
            log('baseline reset without reporting: ' + reason);
            return;
        }

        // The SDK's collectors are up, so this is a live change and it is theirs:
        // reporting it too would double-count (the SDK's de-dup only suppresses
        // what comes AFTER an explicit call, never before).
        if (events.length && sdkOwnsLiveCartEvents()) {
            log('live change left to the pixel SDK (core loaded), baseline advanced');
            return;
        }

        for (var i = 0; i < events.length; i++) {
            if (stale && events[i].action === 'cart.remove') {
                log('removal suppressed on a stale baseline', events[i]);
                continue;
            }
            emit(events[i], next.totalQuantity);
        }
    }

    function checkStorage() {
        var raw = readRawStorage();
        if (raw === null || raw === lastRawStorage) {
            return;
        }
        lastRawStorage = raw;

        var sectionData;
        try {
            sectionData = JSON.parse(raw);
        } catch (e) {
            return;
        }
        if (sectionData && typeof sectionData === 'object') {
            process(sectionData);
        }
    }

    function guard(callback) {
        return function (argument) {
            try {
                callback(argument);
            } catch (e) {
                log('unexpected error', e);
            }
        };
    }

    function boot() {
        // Hyvä (and any theme following its private-content pattern) hands us the
        // full section map directly, which is both earlier and independent of
        // localStorage being writable.
        window.addEventListener('private-content-loaded', guard(function (event) {
            var data = event && event.detail && event.detail.data;
            if (data && typeof data === 'object') {
                process(data);
            } else {
                checkStorage();
            }
        }));

        // Theme-independent path: Magento's section cache itself. Covers Luma,
        // headless-ish custom themes, and any control that reloads sections
        // without dispatching an event.
        window.addEventListener('storage', guard(function (event) {
            if (!event || !event.key || event.key === STORAGE_KEY) {
                checkStorage();
            }
        }));
        window.addEventListener('pageshow', guard(function () {
            checkStorage();
        }));
        document.addEventListener('visibilitychange', guard(function () {
            if (!document.hidden) {
                checkStorage();
            }
        }));

        window.setInterval(guard(function () {
            flushQueuedCalls();
            if (!document.hidden) {
                checkStorage();
            }
        }), POLL_MS);

        log(
            'active (' + (window.hyva ? 'hyva' : (window.jQuery ? 'luma' : 'unknown')) + ' runtime, '
            + (sdkOwnsLiveCartEvents() ? 'SDK core already up' : 'SDK core not up yet') + ')'
        );

        // Read straight away: after a non-AJAX add-to-cart the shopper is already
        // on the next page by the time we run, and the change is waiting in the
        // section cache.
        checkStorage();
        flushQueuedCalls();
    }

    try {
        boot();
    } catch (e) {
        log('failed to start', e);
    }
})();
