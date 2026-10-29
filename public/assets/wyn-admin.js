/* Wyn Analytics dashboard: chart tooltips, live counter, copy buttons, confirmations.
 * Every page works without this file; it only adds convenience. */
(() => {
  'use strict';
  const wynAll = (s, el = document) => [...el.querySelectorAll(s)];
  const wynReduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Copy buttons
  const wynCopy = async (button, text) => {
    const label = button.textContent;
    try {
      await navigator.clipboard.writeText(text);
      button.textContent = 'Copied';
    } catch {
      button.textContent = 'Press Ctrl+C';
    }
    setTimeout(() => { button.textContent = label; }, 1400);
  };
  wynAll('[data-wyn-copy-next]').forEach((b) => b.addEventListener('click', () => wynCopy(b, b.nextElementSibling.textContent.trim())));
  wynAll('[data-wyn-copy]').forEach((b) => b.addEventListener('click', () => {
    const t = document.querySelector(b.dataset.wynCopy);
    t.select?.();
    wynCopy(b, t.value ?? t.textContent);
  }));

  wynAll('form[data-wyn-confirm]').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm(f.dataset.wynConfirm)) e.preventDefault();
  }));

  // Chart tooltip: mouse, touch and keyboard (focus the chart, then use the arrow keys).
  wynAll('[data-wyn-chart]').forEach((svg) => {
    const box = svg.closest('.wyn-chart-box');
    const hits = wynAll('.wyn-hit', svg);
    if (!box || hits.length === 0) return;
    const ns = 'http://www.w3.org/2000/svg';
    const tip = document.createElement('div');
    tip.className = 'wyn-tip';
    tip.hidden = true;
    tip.setAttribute('aria-hidden', 'true');
    box.appendChild(tip);
    const cross = document.createElementNS(ns, 'line');
    cross.setAttribute('class', 'wyn-cross');
    const dot = document.createElementNS(ns, 'circle');
    dot.setAttribute('class', 'wyn-dotmark');
    dot.setAttribute('r', '5');
    let current = -1;

    const wynShow = (i) => {
      current = Math.max(0, Math.min(hits.length - 1, i));
      const h = hits[current];
      const x = +h.dataset.x;
      const y = +h.dataset.y;
      const top = +h.getAttribute('y');
      cross.setAttribute('x1', x); cross.setAttribute('x2', x);
      cross.setAttribute('y1', top); cross.setAttribute('y2', top + +h.getAttribute('height'));
      dot.setAttribute('cx', x); dot.setAttribute('cy', y);
      svg.append(cross, dot);
      const scale = svg.getBoundingClientRect().width / svg.viewBox.baseVal.width;
      tip.innerHTML = '';
      const strong = document.createElement('b');
      strong.textContent = h.dataset.value;
      tip.append(strong, document.createTextNode(h.dataset.label));
      tip.style.left = Math.max(60, Math.min(box.clientWidth - 60, x * scale)) + 'px';
      tip.style.top = (y * scale + 20) + 'px';
      tip.hidden = false;
      svg.setAttribute('aria-valuetext', h.dataset.label + ': ' + h.dataset.value);
    };
    const wynHide = () => { tip.hidden = true; cross.remove(); dot.remove(); };

    hits.forEach((h, i) => {
      h.addEventListener('mouseenter', () => wynShow(i));
      h.addEventListener('touchstart', () => wynShow(i), { passive: true });
    });
    svg.addEventListener('mouseleave', wynHide);
    svg.setAttribute('tabindex', '0');
    svg.addEventListener('focus', () => wynShow(current < 0 ? hits.length - 1 : current));
    svg.addEventListener('blur', wynHide);
    svg.addEventListener('keydown', (e) => {
      const step = { ArrowLeft: -1, ArrowRight: 1, Home: -1e6, End: 1e6 }[e.key];
      if (step === undefined) return;
      e.preventDefault();
      wynShow(current + step);
    });
  });

  // Live visitor count: refreshes every 30 seconds while the tab is visible.
  wynAll('[data-wyn-live]').forEach((el) => {
    const wynTick = async () => {
      if (document.visibilityState !== 'visible') return;
      try {
        const res = await fetch(el.dataset.wynLive, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        el.querySelector('[data-wyn-live-count]').textContent = data.now === 1 ? '1 visitor now' : data.now.toLocaleString() + ' visitors now';
        el.querySelector('.wyn-pip').classList.toggle('is-live', data.now > 0);
      } catch { /* offline: try again next time */ }
    };
    setInterval(wynTick, 30000);
  });

  // Setup page: reload as soon as the first visit arrives.
  wynAll('[data-wyn-wait]').forEach((el) => {
    const wynWait = setInterval(async () => {
      try {
        const res = await fetch(el.dataset.wynWait, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (res.ok && (await res.json()).first_event) {
          clearInterval(wynWait);
          location.reload();
        }
      } catch { /* keep waiting */ }
    }, wynReduced ? 10000 : 4000);
  });

  // Close the custom-range popover when clicking elsewhere or pressing Escape.
  wynAll('details[data-wyn-popover]').forEach((d) => {
    document.addEventListener('click', (e) => { if (d.open && !d.contains(e.target)) d.open = false; });
    d.addEventListener('keydown', (e) => { if (e.key === 'Escape') { d.open = false; d.querySelector('summary').focus(); } });
  });

  // New site: pre-select the browser's time zone.
  wynAll('select[data-wyn-tz]').forEach((s) => {
    try {
      const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (tz && s.dataset.wynTz === 'guess' && [...s.options].some((o) => o.value === tz)) s.value = tz;
    } catch { /* keep the server default */ }
  });
})();
