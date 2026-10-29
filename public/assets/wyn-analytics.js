/*! Wyn Analytics tracker | MIT License | github.com/wayancc/wyn-analytics
 * No cookies, no IDs in the browser. Usage:
 * <script defer data-wyn-site="example.com" src="https://stats.example.com/wyn.js"></script>
 */
(function (w, d) {
  'use strict';
  var me = d.currentScript, loc = w.location, nav = w.navigator;
  if (!me) return;
  var at = function (n) { return me.getAttribute('data-wyn-' + n); };
  var api = at('api') || new URL('/wyn/e', me.src).href, site = at('site') || loc.hostname.replace(/^www\./, '');
  var Wyn = w.Wyn = w.Wyn || {}, queue = Wyn.q || [], store, lastUrl, lastPath, shown = 0, active = 0;

  // Opt out on this browser: visit any page with #wyn-ignore (undo with #wyn-track).
  try {
    store = w.localStorage;
    var opt = /#wyn-(ignore|track)/.exec(loc.hash);
    if (opt) {
      opt[1] === 'ignore' ? store.setItem('wyn-ignore', '1') : store.removeItem('wyn-ignore');
      console.info('Wyn Analytics: ' + (opt[1] === 'ignore' ? 'ignoring' : 'counting') + ' visits from this browser.');
    }
  } catch (e) { store = null; }

  function skip() {
    return nav.webdriver || w._phantom || w.__nightmare || w.Cypress || loc.protocol === 'file:' ||
      (/^(localhost|127\.|\[::1\])/.test(loc.hostname) && at('local') === null) ||
      (store && store.getItem('wyn-ignore') === '1');
  }

  function send(name, props, extra) {
    if (skip()) return;
    var body = { n: name, u: loc.href, d: site, w: w.innerWidth };
    if (nav.globalPrivacyControl) body.g = 1;
    if (props) body.p = props;
    for (var k in extra) body[k] = extra[k];
    body = JSON.stringify(body);
    if (!(nav.sendBeacon && nav.sendBeacon(api, body)) && w.fetch) {
      fetch(api, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain' } }).catch(function () {});
    }
  }

  // Engagement: visible time on the current page, sent when the visitor leaves or hides it.
  function engaged() {
    var ms = active + (shown ? Date.now() - shown : 0);
    if (ms > 1000 && lastUrl) send('engagement', null, { e: ms, u: lastUrl });
    active = 0; shown = d.visibilityState === 'visible' ? Date.now() : 0;
  }

  function pageview() {
    if (loc.pathname === lastPath) return;
    if (lastPath) engaged();
    lastPath = loc.pathname; lastUrl = loc.href;
    send('pageview', null, { r: d.referrer || null });
  }

  Wyn.event = function (name, props) { send(String(name), props || null); };
  Wyn.pageview = function () { lastPath = null; pageview(); };

  // Single-page apps: count history changes as pageviews.
  var push = history.pushState;
  if (push) {
    history.pushState = function () { push.apply(this, arguments); pageview(); };
    w.addEventListener('popstate', pageview);
  }

  d.addEventListener('visibilitychange', function () {
    d.visibilityState === 'hidden' ? engaged() : shown = Date.now();
  });

  // Clicks: data-wyn-event="Name" (props from data-wyn-prop-*), outbound links, file downloads.
  function click(e) {
    var el = e.target.closest && e.target.closest('[data-wyn-event],a[href]');
    if (!el || (e.type === 'auxclick' && e.button !== 1)) return;
    var name = el.getAttribute('data-wyn-event'), props = {}, a = el.attributes;
    if (name) {
      for (var i = 0; i < a.length; i++) if (a[i].name.indexOf('data-wyn-prop-') === 0) props[a[i].name.slice(14)] = a[i].value;
      return send(name, props);
    }
    var url = new URL(el.href, loc.href), link = url.origin + url.pathname;
    if (!/^http/.test(url.protocol)) return;
    if (at('downloads') !== null && /\.(pdf|zip|rar|7z|gz|dmg|exe|msi|apk|docx?|xlsx?|pptx?|csv|txt|epub|mp3|mp4|mov|wav)$/i.test(url.pathname)) send('File download', { url: link });
    else if (at('outbound') !== null && url.hostname !== loc.hostname) send('Outbound link', { url: link });
  }
  d.addEventListener('click', click, true);
  d.addEventListener('auxclick', click, true);

  // Wyn Forms: count successful AJAX submissions (the event doesn't bubble, so listen while capturing).
  function form(e) { var f = e.target; send('Form submitted', { form: (f.getAttribute && (f.getAttribute('name') || f.id)) || loc.pathname }); }
  d.addEventListener('wyn:success', form, true);
  d.addEventListener('wyn:submitted', form, true);

  function start() { shown = d.visibilityState === 'visible' ? Date.now() : 0; pageview(); }
  d.visibilityState === 'prerender' ? d.addEventListener('visibilitychange', function once() {
    if (d.visibilityState !== 'prerender') { d.removeEventListener('visibilitychange', once); start(); }
  }) : start();
  for (var i = 0; i < queue.length; i++) Wyn.event.apply(null, queue[i]);
})(window, document);
