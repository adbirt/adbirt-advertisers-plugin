/*! @adbirt/web-sdk 0.1.1 | MIT */
(function () {
  'use strict';
/**
 * Adbirt web SDK.
 *
 * Replaces the legacy advertiser.js (an obfuscated jQuery script hard-coded
 * to adbirt.com and one form markup). This is dependency-free and works on any
 * site:
 *
 * 1. On the landing page it reads the Adbirt click id (`adb_clickid`, or the
 *    generic `clickid`) that Adbirt's click-out put on the URL, and keeps it
 *    first-party: a cookie plus localStorage, for a configurable number of days.
 * 2. `track(name, ...)` reports an event to `POST {baseUrl}/api/v1/events`.
 *    It uses navigator.sendBeacon, which survives the page unloading after a
 *    form submit, and falls back to fetch with keepalive.
 *
 * Only the campaign's publishable token (`adb_pk_...`) belongs in a page. The
 * secret token (`adb_sk_...`) is refused here; it is for the advertiser's
 * server (see @adbirt/next/server).
 *
 * This file is the ES module. dist/adbirt.js is the same code built into a
 * classic script for a plain <script> tag (scripts/build.js).
 */

const DEFAULT_BASE_URL = 'https://api.adbirt.com';
const CLICK_ID_COOKIE = '_adb_clickid';
const CLICK_ID_STORAGE_KEY = 'adbirt_clickid';
const DEFAULT_TTL_DAYS = 30;

/** Event names the helpers use. Configure them as payable events on the campaign. */
const EVENTS = Object.freeze({
  registration: 'registration',
  deposit: 'deposit',
  purchase: 'purchase',
});

const CLICK_ID_URL_PARAMETERS = ['adb_clickid', 'clickid'];
const CLICK_ID_PATTERN = /^[A-Za-z0-9_-]{1,128}$/;
const DAY_MS = 24 * 60 * 60 * 1000;

function validClickId(value) {
  return typeof value === 'string' && CLICK_ID_PATTERN.test(value) ? value : null;
}

function amountOrNull(raw) {
  if (raw === undefined || raw === null || raw === '') return null;
  const amount = Number(raw);
  return Number.isFinite(amount) && amount >= 0 ? amount : undefined;
}

function randomEventId(win) {
  const bytes = new Uint8Array(16);
  if (win && win.crypto && typeof win.crypto.getRandomValues === 'function') {
    win.crypto.getRandomValues(bytes);
  } else {
    for (let index = 0; index < bytes.length; index += 1) bytes[index] = Math.floor(Math.random() * 256);
  }
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * @param {object} options
 * @param {string} options.token         the campaign's publishable token
 * @param {string} [options.baseUrl]     defaults to https://api.adbirt.com
 * @param {number} [options.ttlDays]     how long a click id is remembered (30)
 * @param {string} [options.cookieDomain] e.g. '.example.com' to share across subdomains
 * @param {Window} [options.window]      for tests; defaults to the global window
 * @param {() => number} [options.now]
 */
function createAdbirt(options = {}) {
  const {
    token,
    baseUrl = DEFAULT_BASE_URL,
    ttlDays = DEFAULT_TTL_DAYS,
    cookieDomain,
    window: win = typeof window !== 'undefined' ? window : undefined,
    now = Date.now,
  } = options;

  if (typeof token !== 'string' || token.trim() === '') {
    throw new Error('Adbirt: token is required (the campaign publishable token, adb_pk_...)');
  }
  if (token.startsWith('adb_sk_')) {
    throw new Error(
      'Adbirt: that is the secret token, which must never be put in a web page. Use the publishable adb_pk_ token.'
    );
  }
  if (!win) throw new Error('Adbirt: no window; use @adbirt/next/server to send events from a server');

  const endpoint = `${String(baseUrl).replace(/\/+$/, '')}/api/v1/events`;
  const doc = win.document;
  let currentClickId = null;
  let initialized = false;

  function readCookie() {
    const prefix = `${CLICK_ID_COOKIE}=`;
    for (const part of String(doc.cookie || '').split(';')) {
      const trimmed = part.trim();
      if (trimmed.startsWith(prefix)) return validClickId(decodeURIComponent(trimmed.slice(prefix.length)));
    }
    return null;
  }

  function readStorage() {
    try {
      const raw = win.localStorage.getItem(CLICK_ID_STORAGE_KEY);
      if (!raw) return null;
      const saved = JSON.parse(raw);
      if (!saved || now() - Number(saved.savedAt) > ttlDays * DAY_MS) {
        win.localStorage.removeItem(CLICK_ID_STORAGE_KEY);
        return null;
      }
      return validClickId(saved.clickId);
    } catch (_error) {
      // Storage can be disabled (private mode, blocked site data).
      return null;
    }
  }

  function save(clickId) {
    const parts = [
      `${CLICK_ID_COOKIE}=${encodeURIComponent(clickId)}`,
      `Max-Age=${Math.round(ttlDays * 24 * 60 * 60)}`,
      'Path=/',
      'SameSite=Lax',
    ];
    if (cookieDomain) parts.push(`Domain=${cookieDomain}`);
    if (win.location && win.location.protocol === 'https:') parts.push('Secure');
    doc.cookie = parts.join('; ');
    try {
      win.localStorage.setItem(CLICK_ID_STORAGE_KEY, JSON.stringify({ clickId, savedAt: now() }));
    } catch (_error) {
      // The cookie alone is enough.
    }
  }

  function clickIdFromUrl() {
    try {
      const parameters = new URL(win.location.href).searchParams;
      for (const name of CLICK_ID_URL_PARAMETERS) {
        const value = validClickId(parameters.get(name));
        if (value) return value;
      }
    } catch (_error) {
      // An unparseable location has no click id.
    }
    return null;
  }

  function resolveElements(target) {
    if (typeof target === 'string') return Array.from(doc.querySelectorAll(target));
    if (target && typeof target.addEventListener === 'function') return [target];
    if (target && typeof target.length === 'number') return Array.from(target);
    return [];
  }

  function fireAndForget(eventName, trackOptions) {
    // Never let reporting break the page's own submit or click handling.
    api.track(eventName, trackOptions).catch(() => {});
  }

  function sendBeacon(body) {
    const navigatorRef = win.navigator;
    if (!navigatorRef || typeof navigatorRef.sendBeacon !== 'function' || typeof win.Blob !== 'function') {
      return false;
    }
    try {
      // text/plain keeps the beacon a "simple" request, with no CORS
      // preflight. Beacons cannot carry headers, so the token rides inside.
      const blob = new win.Blob([JSON.stringify({ ...body, token })], { type: 'text/plain;charset=UTF-8' });
      return navigatorRef.sendBeacon(endpoint, blob) === true;
    } catch (_error) {
      return false;
    }
  }

  async function sendFetch(body) {
    if (typeof win.fetch !== 'function') return false;
    try {
      const response = await win.fetch(endpoint, {
        method: 'POST',
        mode: 'cors',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify(body),
      });
      return !!response && response.ok === true;
    } catch (_error) {
      return false;
    }
  }

  const api = {
    /** Capture the click id from the URL (or recall it) and bind data-adbirt-event elements. */
    init() {
      initialized = true;
      const fromUrl = clickIdFromUrl();
      if (fromUrl) save(fromUrl);
      currentClickId = fromUrl || readCookie() || readStorage();
      api.bindDeclarative();
      return api;
    },

    clickId() {
      return currentClickId;
    },

    /**
     * Report an event with any name. Resolves true when it was handed to the
     * browser (beacon queued) or accepted by the server (fetch). Resolves
     * false for a visitor Adbirt did not bring: there is nothing to attribute.
     *
     * @param {string} eventName
     * @param {{value?: number, currency?: string, parameters?: object, eventId?: string, transactionId?: string}} [trackOptions]
     *   transactionId: your own id for this occurrence (an order, a transaction). Campaigns that charge
     *   an event every time charge each distinct id once. eventId is only a retry key.
     */
    async track(eventName, trackOptions = {}) {
      if (typeof eventName !== 'string' || eventName.trim() === '') {
        throw new Error('Adbirt: event name must not be empty');
      }
      const value = amountOrNull(trackOptions.value);
      if (value === undefined) throw new Error('Adbirt: value must be a non-negative number');
      // A page can call track before init (e.g. before DOMContentLoaded); read the click id first.
      if (!initialized) api.init();
      if (!currentClickId) return false;

      const body = {
        event_name: eventName.trim(),
        event_id: trackOptions.eventId || randomEventId(win),
        adb_clickid: currentClickId,
        parameters: trackOptions.parameters && typeof trackOptions.parameters === 'object' ? trackOptions.parameters : {},
      };
      const transactionId = trackOptions.transactionId;
      if ((typeof transactionId === 'string' || typeof transactionId === 'number') && String(transactionId).trim()) {
        body.transaction_id = String(transactionId).trim().slice(0, 128);
      }
      if (value !== null) body.value = value;
      if (typeof trackOptions.currency === 'string' && trackOptions.currency.trim()) {
        body.currency = trackOptions.currency.trim().toUpperCase();
      }

      if (sendBeacon(body)) return true;
      return sendFetch(body);
    },

    registration(parameters = {}) {
      return api.track(EVENTS.registration, { parameters });
    },

    deposit(value, currency, parameters = {}, options = {}) {
      return api.track(EVENTS.deposit, { value, currency, parameters, transactionId: options.transactionId });
    },

    purchase(value, currency, parameters = {}, options = {}) {
      return api.track(EVENTS.purchase, { value, currency, parameters, transactionId: options.transactionId });
    },

    /** Report an event whenever the form(s) submit. Field values are never sent. */
    trackForm(target, eventName, trackOptions = {}) {
      for (const element of resolveElements(target)) {
        element.addEventListener('submit', () => fireAndForget(eventName, trackOptions));
      }
      return api;
    },

    /** Report an event whenever the element(s) are clicked. */
    trackClick(target, eventName, trackOptions = {}) {
      for (const element of resolveElements(target)) {
        element.addEventListener('click', () => fireAndForget(eventName, trackOptions));
      }
      return api;
    },

    /**
     * Bind `<form data-adbirt-event="registration">` (on submit) and any other
     * element with data-adbirt-event (on click). Optional data-adbirt-value
     * and data-adbirt-currency. Each element is bound once.
     */
    bindDeclarative(root = doc) {
      for (const element of Array.from(root.querySelectorAll('[data-adbirt-event]'))) {
        if (element.getAttribute('data-adbirt-bound') === 'true') continue;
        element.setAttribute('data-adbirt-bound', 'true');
        const eventName = element.getAttribute('data-adbirt-event');
        const trackOptions = {};
        const value = element.getAttribute('data-adbirt-value');
        const currency = element.getAttribute('data-adbirt-currency');
        if (value !== null && amountOrNull(value) !== undefined) trackOptions.value = Number(value);
        if (currency) trackOptions.currency = currency;
        if (element.tagName === 'FORM') api.trackForm(element, eventName, trackOptions);
        else api.trackClick(element, eventName, trackOptions);
      }
      return api;
    },
  };

  return api;
}

/**
 * Script-tag install: `<script async src=".../adbirt.js" data-token="adb_pk_..."></script>`.
 * Optional data-base-url, data-ttl-days, data-cookie-domain. Calls made
 * before the script loaded (`window.adbirt = window.adbirt || {q: []};
 * adbirt.q.push(['track', 'registration'])`) are replayed once it has.
 */
function autoInstall(win) {
  const doc = win.document;
  const script = doc.currentScript || doc.querySelector('script[data-token^="adb_pk_"]');
  if (!script) return null;

  const queued = win.adbirt && Array.isArray(win.adbirt.q) ? win.adbirt.q : [];
  const ttlDays = Number(script.getAttribute('data-ttl-days'));
  const adbirt = createAdbirt({
    token: script.getAttribute('data-token'),
    baseUrl: script.getAttribute('data-base-url') || undefined,
    ttlDays: Number.isFinite(ttlDays) && ttlDays > 0 ? ttlDays : undefined,
    cookieDomain: script.getAttribute('data-cookie-domain') || undefined,
    window: win,
  });
  win.adbirt = adbirt;

  const run = (call) => {
    const [method, ...args] = Array.isArray(call) ? call : [];
    if (method === 'q' || typeof adbirt[method] !== 'function') return;
    const result = adbirt[method](...args);
    if (result && typeof result.catch === 'function') result.catch(() => {});
  };
  // Pages keep pushing to adbirt.q after the script has loaded (inline snippets
  // further down the page). Until the page is ready those calls wait with the
  // early ones; afterwards they run at once.
  adbirt.q = { push: (...calls) => { queued.push(...calls); return queued.length; } };

  const start = () => {
    adbirt.init();
    adbirt.q = { push: (...calls) => { calls.forEach(run); return 0; } };
    for (const call of queued.splice(0)) run(call);
  };

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start);
  else start();
  return adbirt;
}

  if (typeof window !== 'undefined') autoInstall(window);
})();
