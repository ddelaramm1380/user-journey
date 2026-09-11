(function () {
  'use strict';
  if (!window.UJ_CONFIG || navigator.webdriver) return;

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function setCookie(name, value, days) {
    var expires = new Date(Date.now() + days * 864e5).toUTCString();
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 3 | 8)).toString(16);
    });
  }

  function browserName() {
    var ua = navigator.userAgent;
    if (/Edg\//.test(ua)) return 'Edge';
    if (/OPR\//.test(ua)) return 'Opera';
    if (/Chrome\//.test(ua)) return 'Chrome';
    if (/Firefox\//.test(ua)) return 'Firefox';
    if (/Safari\//.test(ua) && !/Chrome\//.test(ua)) return 'Safari';
    return 'Other';
  }

  function deviceType() {
    var ua = navigator.userAgent;
    if (/iPad|Tablet|PlayBook|Silk/i.test(ua) || (/Android/i.test(ua) && !/Mobile/i.test(ua))) return 'Tablet';
    if (/Mobi|Android|iPhone|iPod/i.test(ua)) return 'Mobile';
    return 'Desktop';
  }

  var queueKey = 'uj_pending_visits';

  function readQueue() {
    try {
      var queue = JSON.parse(localStorage.getItem(queueKey) || '[]');
      return Array.isArray(queue) ? queue.slice(-100) : [];
    } catch (e) {
      return [];
    }
  }

  function writeQueue(queue) {
    try { localStorage.setItem(queueKey, JSON.stringify(queue.slice(-100))); } catch (e) {}
  }

  var visitorId = getCookie(UJ_CONFIG.visitorCookie);
  if (!visitorId || !/^[a-f0-9-]{36}$/i.test(visitorId)) visitorId = uuid();
  setCookie(UJ_CONFIG.visitorCookie, visitorId, UJ_CONFIG.cookieDays);

  var visit = {
    url: location.href,
    referrer: document.referrer || null,
    timestamp: new Date().toISOString(),
    browser: browserName(),
    device: deviceType()
  };

  // یک نسخه محدود از آخرین مسیرها در Cookie می‌ماند؛ نسخه کامل در دیتابیس ذخیره می‌شود.
  var journey = [];
  try { journey = JSON.parse(getCookie(UJ_CONFIG.journeyCookie) || '[]'); } catch (e) { journey = []; }
  if (!Array.isArray(journey)) journey = [];
  journey.push(visit);
  while (journey.length > 1 && encodeURIComponent(JSON.stringify(journey)).length > 3500) journey.shift();
  setCookie(UJ_CONFIG.journeyCookie, JSON.stringify(journey), UJ_CONFIG.cookieDays);

  var queue = readQueue();
  queue.push(visit);
  writeQueue(queue);
  var flushing = false;

  function flushQueue() {
    if (flushing) return;
    var pending = readQueue().slice(0, 20);
    if (!pending.length) return;
    flushing = true;
    fetch(UJ_CONFIG.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({visitor_id: visitorId, visits: pending})
    }).then(function (response) {
      if (!response.ok) throw new Error('Request failed');
      var current = readQueue();
      writeQueue(current.slice(pending.length));
    }).catch(function () {
      // صف برای تلاش بعدی باقی می‌ماند.
    }).then(function () {
      flushing = false;
    });
  }

  setTimeout(flushQueue, 1200);
  window.addEventListener('pagehide', flushQueue);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flushQueue();
  });
})();
