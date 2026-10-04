'use strict';

const $ = (id) => document.getElementById(id);
const input = $('q'), results = $('results'), message = $('message');
const status = $('status'), count = $('count'), notice = $('notice'), examples = $('examples');
const money = (digits) => new Intl.NumberFormat('he-IL', {
  style: 'currency', currency: 'ILS', minimumFractionDigits: digits, maximumFractionDigits: digits,
});
const wholePrice = money(0), decimalPrice = money(2);
const formatPrice = (n) => (Number.isInteger(n) ? wholePrice : decimalPrice).format(n);
const countText = (n) => (n === 1 ? 'תוצאה אחת' : `${n} תוצאות`);

let timer, controller;

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function card(p) {
  const li = el('li', 'card');
  const fallback = () => el('div', 'img-fallback', 'אין תמונה');
  const img = p.image_url ? el('img') : fallback();
  if (p.image_url) {
    img.loading = 'lazy';
    img.alt = '';
    img.addEventListener('error', () => img.replaceWith(fallback()), { once: true });
    img.src = p.image_url;
  }
  const body = el('div', 'body');
  const name = el('h2', 'name');
  name.append(el('bdi', '', p.name));
  body.append(name);
  if (p.name_en) body.append(el('p', 'name-en', p.name_en));
  if (p.brand) body.append(el('p', 'brand', p.brand));
  if (p.price !== null) body.append(el('p', 'price', formatPrice(p.price)));
  li.append(img, body);
  return li;
}

function showMessage(text, hint, retry) {
  message.replaceChildren(el('strong', '', text));
  if (hint) message.append(el('p', '', hint));
  if (retry) {
    const btn = el('button', 'retry', 'נסו שוב');
    btn.type = 'button';
    btn.addEventListener('click', () => search(input.value));
    message.append(btn);
  }
  message.hidden = false;
}

function reset() {
  message.hidden = true;
  notice.hidden = true;
  count.hidden = true;
  results.replaceChildren();
}

async function search(raw) {
  const q = raw.trim();
  clearTimeout(timer);
  controller?.abort();
  history.replaceState(null, '', q ? `?q=${encodeURIComponent(q)}` : location.pathname);
  examples.hidden = q !== '';
  if (!q) { reset(); status.textContent = ''; return; }

  controller = new AbortController();
  results.classList.add('loading');
  results.setAttribute('aria-busy', 'true');
  try {
    const res = await fetch(`/api/search?q=${encodeURIComponent(q)}`, { signal: controller.signal });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    reset();
    notice.hidden = data.semantic !== false;
    if (data.results.length === 0) {
      showMessage('לא נמצאו תוצאות', 'נסו מילים אחרות, תיאור כללי יותר, או שם בעברית או באנגלית.');
      status.textContent = 'לא נמצאו תוצאות';
    } else {
      results.append(...data.results.map(card));
      count.textContent = countText(data.results.length);
      count.hidden = false;
      status.textContent = `נמצאו ${countText(data.results.length)}`;
    }
  } catch (err) {
    if (err.name === 'AbortError') return; // a newer request took over
    reset();
    showMessage('אירעה שגיאה בחיפוש', 'לא הצלחנו לטעון את התוצאות.', true);
    status.textContent = 'אירעה שגיאה בחיפוש';
  }
  results.classList.remove('loading');
  results.removeAttribute('aria-busy');
}

input.addEventListener('input', () => {
  clearTimeout(timer);
  timer = setTimeout(() => search(input.value), 250);
});
$('form').addEventListener('submit', (e) => { e.preventDefault(); search(input.value); });
examples.addEventListener('click', (e) => {
  if (e.target.tagName !== 'BUTTON') return;
  input.value = e.target.textContent;
  search(input.value);
});

const initial = new URLSearchParams(location.search).get('q');
if (initial) { input.value = initial; search(initial); }
