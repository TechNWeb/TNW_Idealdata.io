/**
 * Behavioural tests for view/frontend/web/js/cart-tracking.js.
 *
 * The bridge decides what counts as shopper cart activity, and every wrong
 * decision is a false event in the IdealData platform — so the diff rules, the
 * suppression rules and the queueing are covered here rather than left to be
 * discovered on a live storefront.
 *
 * Zero dependencies (Node's own `vm` + `assert`), because the module has no JS
 * build chain and must not grow one:
 *
 *     node Test/Js/cart-tracking.test.js
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SCRIPT_PATH = path.join(__dirname, '..', '..', 'view', 'frontend', 'web', 'js', 'cart-tracking.js');
const SCRIPT_SOURCE = fs.readFileSync(SCRIPT_PATH, 'utf8');

const POLL_MS = 1000;
const STALE_MS = 30 * 60 * 1000;

/**
 * Boots the bridge in an isolated context with a storefront stub, and returns
 * handles to drive it: the fake section cache, the captured pixel calls, and the
 * clock/poll controls.
 */
function boot(options) {
    const settings = Object.assign({ debug: false }, (options || {}).settings);
    const withSdkCore = (options || {}).withSdkCore === true;
    const withHyva = (options || {}).withHyva === true;
    const pathname = (options || {}).pathname || '/';
    const withPixel = (options || {}).withPixel !== false;

    const calls = [];
    const listeners = { window: {}, document: {} };
    const timers = [];
    // NOT copied: passing another harness's storage models two tabs of the same
    // browser sharing one localStorage, which is what keeps them from both
    // reporting the same shopper action.
    const storageData = (options || {}).storage || {};
    let now = (options || {}).now || 1000000;
    let fetchCalls = [];
    let fetchResponse = (options || {}).fetchResponse || null;

    const localStorage = {
        getItem(key) {
            return Object.prototype.hasOwnProperty.call(storageData, key) ? storageData[key] : null;
        },
        setItem(key, value) {
            storageData[key] = String(value);
        },
        removeItem(key) {
            delete storageData[key];
        }
    };

    const windowStub = {
        idealdataSettings: settings,
        location: { pathname: pathname },
        localStorage: localStorage,
        console: { log() {} },
        addEventListener(name, handler) {
            (listeners.window[name] = listeners.window[name] || []).push(handler);
        },
        setInterval(handler) {
            timers.push(handler);
            return timers.length;
        },
        fetch(url) {
            fetchCalls.push(url);
            return Promise.resolve({
                ok: fetchResponse !== null,
                json() {
                    return Promise.resolve(fetchResponse);
                }
            });
        }
    };

    if (withPixel) {
        windowStub.idealdataPixel = function () {
            calls.push(Array.prototype.slice.call(arguments));
        };
    }

    if (withSdkCore) {
        windowStub.idealdataPixelCore = { initCore() {} };
    }

    // Hyvä's runtime global. Its presence flips cart-event ownership to this
    // bridge whatever the SDK core is doing, because the SDK's collectors report
    // nothing on Hyvä.
    if (withHyva) {
        windowStub.hyva = {};
    }

    const documentStub = {
        hidden: false,
        currentScript: {
            getAttribute(name) {
                const attributes = {
                    'data-section-load-url': '/customer/section/load',
                    'data-identity-section': 'tnw-idealdata-identity'
                };
                return Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null;
            }
        },
        querySelector() {
            return null;
        },
        addEventListener(name, handler) {
            (listeners.document[name] = listeners.document[name] || []).push(handler);
        }
    };

    const sandbox = {
        window: windowStub,
        document: documentStub,
        Date: { now: () => now },
        Promise: Promise
    };

    vm.runInNewContext(SCRIPT_SOURCE, sandbox, { filename: SCRIPT_PATH });

    return {
        calls,
        fetchCalls,
        storageData,
        window: windowStub,
        /** Writes a section payload into the fake `mage-cache-storage`. */
        setSections(sections) {
            localStorage.setItem('mage-cache-storage', JSON.stringify(sections));
        },
        /** Advances the bridge the way its 1s interval would. */
        poll() {
            timers.forEach((handler) => handler());
        },
        /** Dispatches Hyvä's private-content-loaded with a section payload. */
        dispatchPrivateContent(sections) {
            (listeners.window['private-content-loaded'] || []).forEach((handler) => {
                handler({ detail: { data: sections } });
            });
        },
        attachPixel() {
            windowStub.idealdataPixel = function () {
                calls.push(Array.prototype.slice.call(arguments));
            };
        },
        /** Simulates the SDK core bundle finishing its load. */
        attachSdkCore() {
            windowStub.idealdataPixelCore = { initCore() {} };
        },
        advanceClock(ms) {
            now += ms;
        },
        setFetchResponse(payload) {
            fetchResponse = payload;
        },
        listeners
    };
}

/** A `cart` section with the given lines, mirroring Magento's payload shape. */
function cart(lines, extra) {
    return Object.assign({
        summary_count: lines.reduce((total, line) => total + Number(line.qty), 0),
        items: lines
    }, extra);
}

function line(itemId, productId, qty, name) {
    return {
        item_id: itemId,
        product_id: productId,
        product_sku: 'SKU-' + productId,
        product_name: name || ('Product ' + productId),
        qty: qty
    };
}

/**
 * Payloads are built inside the vm context, so they carry that realm's
 * Object.prototype and deepStrictEqual would reject them on identity alone.
 * Round-tripping through JSON brings them into this realm.
 */
function trackCalls(harness) {
    return JSON.parse(JSON.stringify(harness.calls.filter((call) => call[0] === 'track')));
}

const tests = {
    'first observation only establishes a baseline'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'a new cart line reports cart.add with product, quantity and cart total'() {
        const harness = boot();
        harness.setSections({ cart: cart([]) });
        harness.poll();

        harness.setSections({ cart: cart([line(1, 100, 2, 'Blue T-Shirt')]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), [[
            'track',
            'cart.add',
            {
                quantity: 2,
                productId: '100',
                variantId: 'SKU-100',
                title: 'Blue T-Shirt',
                cartTotalQuantity: 2
            }
        ]]);
    },

    'a quantity increase reports only the delta'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        harness.setSections({ cart: cart([line(1, 100, 4)]) });
        harness.poll();

        const calls = trackCalls(harness);
        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0][1], 'cart.add');
        assert.strictEqual(calls[0][2].quantity, 3);
        assert.strictEqual(calls[0][2].cartTotalQuantity, 4);
    },

    'a quantity decrease reports cart.remove with the delta'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 3)]) });
        harness.poll();

        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), [[
            'track',
            'cart.remove',
            { quantity: 2, productId: '100', cartTotalQuantity: 1 }
        ]]);
    },

    'a removed line reports cart.remove for its whole quantity'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 2), line(2, 200, 1)]) });
        harness.poll();

        harness.setSections({ cart: cart([line(2, 200, 1)]) });
        harness.poll();

        const calls = trackCalls(harness);
        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0][1], 'cart.remove');
        assert.strictEqual(calls[0][2].productId, '100');
        assert.strictEqual(calls[0][2].quantity, 2);
        assert.strictEqual(calls[0][2].cartTotalQuantity, 1);
    },

    'simultaneous add and remove report separately'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        harness.setSections({ cart: cart([line(2, 200, 1)]) });
        harness.poll();

        const actions = trackCalls(harness).map((call) => call[1] + ':' + call[2].productId).sort();
        assert.deepStrictEqual(actions, ['cart.add:200', 'cart.remove:100']);
    },

    'fractional quantities do not produce float-noise deltas'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 0.3)]) });
        harness.poll();

        harness.setSections({ cart: cart([line(1, 100, 0.1), line(2, 100, 0.2)]) });
        harness.poll();

        const calls = trackCalls(harness);
        // 0.3 -> 0.1 + 0.2 across two lines: a genuine per-line move, but the
        // totals must stay exact rather than drifting by 1e-17.
        assert.strictEqual(calls.length, 2);
        calls.forEach((call) => assert.strictEqual(call[2].cartTotalQuantity, 0.3));
    },

    'an unchanged cart is silent, however often it is polled'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();
        harness.setSections({ cart: cart([line(1, 100, 2)]) });
        harness.poll();
        harness.poll();
        harness.setSections({ cart: cart([line(1, 100, 2)]) });
        harness.poll();

        assert.strictEqual(trackCalls(harness).length, 1);
    },

    'a missing cart section is never read as an emptied cart'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 2)]) });
        harness.poll();

        // Section invalidated / not yet refetched.
        harness.setSections({ customer: {} });
        harness.poll();
        // …and back, unchanged.
        harness.setSections({ cart: cart([line(1, 100, 2)]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'logging in resets the baseline silently (quote merge)'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 1)]), customer: {} });
        harness.poll();

        harness.setSections({
            cart: cart([line(1, 100, 1), line(2, 200, 3)]),
            customer: { firstname: 'Sam' }
        });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'logging out resets the baseline silently'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 2)]), customer: { firstname: 'Sam' } });
        harness.poll();

        harness.setSections({ cart: cart([]), customer: {} });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'placing an order does not report the emptied cart as removals'() {
        const harness = boot({ pathname: '/checkout/onepage/success/' });
        harness.setSections({ cart: cart([line(1, 100, 2)]) });
        harness.poll();

        harness.setSections({ cart: cart([]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'multishipping success is treated the same way'() {
        const harness = boot({ pathname: '/multishipping/checkout/success/' });
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        harness.setSections({ cart: cart([]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'emptying the cart by hand IS reported (not a success page)'() {
        const harness = boot({ pathname: '/checkout/cart/' });
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        harness.setSections({ cart: cart([]) });
        harness.poll();

        const calls = trackCalls(harness);
        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0][1], 'cart.remove');
        assert.strictEqual(calls[0][2].cartTotalQuantity, 0);
    },

    'a stale baseline still reports additions'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        harness.advanceClock(STALE_MS + POLL_MS);
        harness.setSections({ cart: cart([line(1, 100, 1), line(2, 200, 1)]) });
        harness.poll();

        const calls = trackCalls(harness);
        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0][1], 'cart.add');
        assert.strictEqual(calls[0][2].productId, '200');
    },

    'a stale baseline suppresses removals (cart expiry / admin edits)'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 1), line(2, 200, 1)]) });
        harness.poll();

        harness.advanceClock(STALE_MS + POLL_MS);
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'a fresh baseline reports removals'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 1), line(2, 200, 1)]) });
        harness.poll();

        harness.advanceClock(POLL_MS);
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        assert.strictEqual(trackCalls(harness).length, 1);
    },

    'a cart section with no item list falls back to the summary count'() {
        const harness = boot();
        harness.setSections({ cart: { summary_count: 2 } });
        harness.poll();

        harness.setSections({ cart: { summary_count: 5 } });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), [[
            'track',
            'cart.add',
            { quantity: 3, cartTotalQuantity: 5 }
        ]]);
    },

    'an empty item list next to a non-empty cart does not report phantom removals'() {
        const harness = boot();
        harness.setSections({ cart: cart([line(1, 100, 2)]) });
        harness.poll();

        // A deployment that trims the item list: 2 items still in the cart.
        harness.setSections({ cart: { summary_count: 2, items: [] } });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'the baseline is shared between tabs so an action is reported once'() {
        const first = boot();
        first.setSections({ cart: cart([line(1, 100, 1)]) });
        first.poll();

        // Second tab of the same browser: same localStorage contents.
        const second = boot({ storage: first.storageData });
        first.setSections({ cart: cart([line(1, 100, 2)]) });
        second.setSections({ cart: cart([line(1, 100, 2)]) });

        first.poll();
        second.poll();

        assert.strictEqual(trackCalls(first).length, 1);
        assert.deepStrictEqual(trackCalls(second), []);
    },

    'Hyvä private-content-loaded payloads are processed without storage'() {
        const harness = boot();
        harness.dispatchPrivateContent({ cart: cart([line(1, 100, 1)]) });
        harness.dispatchPrivateContent({ cart: cart([line(1, 100, 3)]) });

        const calls = trackCalls(harness);
        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0][2].quantity, 2);
    },

    'calls made before the loader defines the API are replayed afterwards'() {
        const harness = boot({ withPixel: false });
        harness.setSections({ cart: cart([]) });
        harness.poll();
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        assert.deepStrictEqual(harness.calls, [], 'nothing can be sent yet');

        harness.attachPixel();
        harness.poll();

        assert.strictEqual(trackCalls(harness).length, 1);
    },

    'the customer id is pinned once from the identity section'() {
        const harness = boot();
        harness.setSections({
            cart: cart([]),
            customer: { firstname: 'Sam' },
            'tnw-idealdata-identity': { customer_id: 42 }
        });
        harness.poll();
        harness.setSections({
            cart: cart([line(1, 100, 1)]),
            customer: { firstname: 'Sam' },
            'tnw-idealdata-identity': { customer_id: 42 }
        });
        harness.poll();

        const identityCalls = harness.calls.filter((call) => call[0] === 'setCustomerId');
        assert.deepStrictEqual(identityCalls, [['setCustomerId', '42']]);
        assert.deepStrictEqual(harness.fetchCalls, [], 'no repair needed');
    },

    'a guest never triggers an identity repair request'() {
        const harness = boot();
        harness.setSections({ cart: cart([]), customer: {} });
        harness.poll();

        assert.deepStrictEqual(harness.calls.filter((call) => call[0] === 'setCustomerId'), []);
        assert.deepStrictEqual(harness.fetchCalls, []);
    },

    'a logged-in shopper with no identity section is repaired once'() {
        const harness = boot({ fetchResponse: { 'tnw-idealdata-identity': { customer_id: 7 } } });
        harness.setSections({ cart: cart([]), customer: { firstname: 'Sam' } });
        harness.poll();
        harness.setSections({ cart: cart([line(1, 100, 1)]), customer: { firstname: 'Sam' } });
        harness.poll();

        assert.deepStrictEqual(harness.fetchCalls, [
            '/customer/section/load?sections=tnw-idealdata-identity'
        ], 'exactly one repair request per page view');

        return Promise.resolve().then(() => Promise.resolve()).then(() => {
            assert.deepStrictEqual(
                harness.calls.filter((call) => call[0] === 'setCustomerId'),
                [['setCustomerId', '7']]
            );
        });
    },

    'a change is left to the SDK once its core is loaded (no double counting)'() {
        const harness = boot({ withSdkCore: true });
        harness.setSections({ cart: cart([]) });
        harness.poll();

        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'the baseline still advances while the SDK owns reporting'() {
        const harness = boot({ withSdkCore: true });
        harness.setSections({ cart: cart([]) });
        harness.poll();
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        // The SDK reported that add. If the baseline had not advanced, the next
        // observation would re-report the whole cart.
        harness.setSections({ cart: cart([line(1, 100, 2)]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    },

    'a change observed before the SDK core is up IS reported (the Hyvä gap)'() {
        const harness = boot();
        harness.setSections({ cart: cart([]) });
        harness.poll();

        // Hyvä's post-navigation section refetch, before the SDK core has loaded.
        harness.dispatchPrivateContent({ cart: cart([line(1, 100, 1)]) });

        assert.strictEqual(trackCalls(harness).length, 1);

        // Once the core is up, live changes are the SDK's again.
        harness.attachSdkCore();
        harness.setSections({ cart: cart([line(1, 100, 3)]) });
        harness.poll();

        assert.strictEqual(trackCalls(harness).length, 1);
    },

    'on Hyvä a change IS reported even with the SDK core up (the Metal Mafia gap)'() {
        const harness = boot({ withHyva: true, withSdkCore: true });
        harness.setSections({ cart: cart([]) });
        harness.poll();

        // Hyvä's post-navigation section refetch, arriving after the core loaded
        // — which is what happens on a real store every time. Deferring here is
        // what dropped every cart event on Metal Mafia: the SDK's collectors
        // cannot see Hyvä, so nobody reported it.
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), [
            ['track', 'cart.add', {
                productId: '100',
                variantId: 'SKU-100',
                title: 'Product 100',
                quantity: 1,
                cartTotalQuantity: 1
            }]
        ]);
    },

    'on Hyvä the baseline still advances, so a change is reported once'() {
        const harness = boot({ withHyva: true, withSdkCore: true });
        harness.setSections({ cart: cart([]) });
        harness.poll();
        harness.setSections({ cart: cart([line(1, 100, 1)]) });
        harness.poll();
        harness.poll();

        assert.strictEqual(trackCalls(harness).length, 1);
    },

    'identity is still pinned while the SDK owns cart reporting'() {
        const harness = boot({ withSdkCore: true });
        harness.setSections({
            cart: cart([]),
            customer: { firstname: 'Sam' },
            'tnw-idealdata-identity': { customer_id: 42 }
        });
        harness.poll();

        assert.deepStrictEqual(
            harness.calls.filter((call) => call[0] === 'setCustomerId'),
            [['setCustomerId', '42']]
        );
    },

    'a broken section cache does not throw or report'() {
        const harness = boot();
        harness.window.localStorage.setItem('mage-cache-storage', '{not json');
        harness.poll();

        assert.deepStrictEqual(trackCalls(harness), []);
    }
};

const names = Object.keys(tests);
let failures = 0;

(async function run() {
    for (const name of names) {
        try {
            await tests[name]();
            console.log('  ok   ' + name);
        } catch (error) {
            failures++;
            console.log('  FAIL ' + name);
            console.log('       ' + (error && error.message ? error.message.split('\n').join('\n       ') : error));
        }
    }

    console.log('\n' + (names.length - failures) + '/' + names.length + ' passed');
    if (failures) {
        process.exitCode = 1;
    }
})();
