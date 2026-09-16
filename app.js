'use strict';

const $ = id => document.getElementById(id);
const dom = {
  domain: $('domain'), from: $('from'), to: $('to'), form: $('add-form'), btn: $('add-btn'),
  list: $('list'), note: $('list-note'), table: $('table'), rows: $('rows'), count: $('count'),
  msg: $('message'), fromToggle: $('from-toggle'), fromSuffix: $('from-suffix'), rowTpl: $('row-tpl'),
};

// Namespaced: an unprefixed key would collide with anything else served from this origin.
const STORE_KEY = 'wamx:lastDomain';
// api.php allows 30 s per OVH call; this is the ceiling past which the page stops waiting
// and says so, rather than leaving a spinner up for good.
const REQUEST_TIMEOUT = 40000;
const MSG_TIMEOUT = 4000;
// Catches the empty side and the "user@other.com@domain.tld" case; the rest is OVH's call.
const EMAIL_RE = /^[^@\s]+@[^@\s]+$/;

let domains = {}, selectedDomain = '', msgTimer, fromExpanded = false, listRequest = 0;

// localStorage throws outright in some privacy modes, which would otherwise take the whole
// page down on load. Remembering the last domain is a convenience, never a requirement.
const store = {
  get(key) { try { return localStorage.getItem(key); } catch { return null; } },
  set(key, value) { try { localStorage.setItem(key, value); } catch { /* nothing to do */ } },
};

async function request(url, opts = {}) {
  let r;
  try {
    r = await fetch(url, { ...opts, signal: AbortSignal.timeout?.(REQUEST_TIMEOUT) });
  } catch (err) {
    throw new Error(err.name === 'TimeoutError' ? 'The server did not answer in time' : 'Network error');
  }
  const data = r.headers.get('content-type')?.includes('json') ? await r.json().catch(() => null) : null;
  if (!r.ok) throw new Error(data?.message || `Error ${r.status}`);
  return data;
}

const api = (path, opts) => request(`api.php?ovh=${encodeURIComponent(selectedDomain + '/redirection' + path)}`, opts);

const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

function showMsg(text, kind) {
  clearTimeout(msgTimer);
  const box = document.createElement('div');
  box.className = `msg msg-${kind}`;
  box.textContent = text;
  dom.msg.replaceChildren(box);
  msgTimer = setTimeout(() => dom.msg.replaceChildren(), MSG_TIMEOUT);
}

/** The one line above the table: loading, empty, an error, or a partial-list warning. */
function setNote(text, kind) {
  dom.note.hidden = text === '';
  dom.note.className = kind ? `list-note msg msg-${kind}` : 'list-note';
  dom.note.textContent = text;
}

function setDomain(d) {
  const prev = selectedDomain;
  selectedDomain = d;
  store.set(STORE_KEY, d);
  dom.to.value = domains[d] || '';
  dom.fromSuffix.textContent = '@' + d;
  if (fromExpanded) {
    const val = dom.from.value;
    const at = val.indexOf('@');
    if (at >= 0 && prev) dom.from.value = val.slice(0, at) + '@' + d;
  }
}

dom.fromToggle.addEventListener('click', () => {
  const group = dom.from.closest('.input-group');
  fromExpanded = !fromExpanded;
  dom.fromToggle.setAttribute('aria-pressed', String(fromExpanded));
  if (fromExpanded) {
    const prefix = dom.from.value.trim();
    dom.from.value = prefix ? prefix + '@' + selectedDomain : '@' + selectedDomain;
  } else {
    const val = dom.from.value.trim();
    const at = val.indexOf('@');
    dom.from.value = at > 0 ? val.slice(0, at) : (at === 0 ? '' : val);
  }
  group.classList.toggle('expanded', fromExpanded);
  dom.fromToggle.classList.toggle('active', fromExpanded);
  dom.from.focus();
});

/** Rows are built as nodes, so no value coming back from OVH is ever parsed as markup. */
function renderRows(items) {
  const frag = document.createDocumentFragment();
  for (const item of items) {
    const row = dom.rowTpl.content.firstElementChild.cloneNode(true);
    row.querySelector('.td-from').textContent = item.from;
    row.querySelector('.td-to').textContent = item.to;
    const btn = row.querySelector('.btn-del');
    btn.dataset.id = String(item.id);
    btn.setAttribute('aria-label', `Delete the redirection from ${item.from}`);
    frag.append(row);
  }
  dom.rows.replaceChildren(frag);
}

async function fetchList() {
  // Switching domains twice in a row must not let the slower response win the race.
  const token = ++listRequest;
  dom.list.setAttribute('aria-busy', 'true');
  dom.table.hidden = true;
  dom.count.textContent = '';
  setNote('Loading…', '');
  try {
    const { items = [], unread = 0 } = await request(`api.php?action=redirections&domain=${encodeURIComponent(selectedDomain)}`) ?? {};
    if (token !== listRequest) return;
    renderRows(items);
    dom.table.hidden = items.length === 0;
    dom.count.textContent = plural(items.length, 'redirection');
    // An incomplete list looks exactly like a correct one, so say it out loud.
    if (unread > 0) setNote(`${plural(unread, 'redirection')} could not be read from OVH — this list is incomplete.`, 'warn');
    else setNote(items.length ? '' : 'No redirections', '');
  } catch (err) {
    if (token !== listRequest) return;
    setNote(err.message, 'err');
  } finally {
    if (token === listRequest) dom.list.setAttribute('aria-busy', 'false');
  }
}

dom.domain.addEventListener('change', () => { setDomain(dom.domain.value); fetchList(); });

dom.form.addEventListener('submit', async e => {
  e.preventDefault();
  const typed = dom.from.value.trim(), to = dom.to.value.trim();
  // Collapsed, the field holds a username and the domain is appended: letting a full address
  // through here used to build "user@other.com@domain.tld" and hand it to OVH to reject.
  if (!fromExpanded && typed.includes('@')) {
    showMsg('Use the pencil button to enter a full address', 'err');
    return;
  }
  const from = fromExpanded ? typed : `${typed}@${selectedDomain}`;
  if (!EMAIL_RE.test(from)) { showMsg('Source is not a valid address', 'err'); return; }

  dom.btn.disabled = true;
  try {
    await api('', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ from, to, localCopy: false }) });
    showMsg('Redirection added', 'ok');
    dom.from.value = fromExpanded ? '@' + selectedDomain : '';
    await fetchList();
  } catch (err) { showMsg(err.message, 'err'); }
  finally { dom.btn.disabled = false; }
});

dom.rows.addEventListener('click', async e => {
  const btn = e.target.closest('.btn-del');
  if (!btn || !confirm('Delete this redirection?')) return;
  btn.disabled = true;
  try {
    await api(`/${btn.dataset.id}`, { method: 'DELETE' });
    showMsg('Redirection deleted', 'ok');
    await fetchList();
  } catch (err) {
    showMsg(err.message, 'err');
    btn.disabled = false;
  }
});

(async () => {
  try {
    const { domains: loaded } = await request('api.php?action=config') ?? {};
    domains = loaded || {};
    const keys = Object.keys(domains);
    if (!keys.length) {
      setNote('No domain configured — add one to the "domains" list in config.php', 'err');
      for (const el of [dom.domain, dom.from, dom.to, dom.btn, dom.fromToggle]) el.disabled = true;
      dom.list.setAttribute('aria-busy', 'false');
      return;
    }
    const saved = store.get(STORE_KEY);
    selectedDomain = keys.includes(saved) ? saved : keys[0];
    dom.domain.replaceChildren(...keys.map(k => new Option(k, k, false, k === selectedDomain)));
    setDomain(selectedDomain);
    await fetchList();
  } catch (err) {
    setNote(err.message || 'Failed to load configuration', 'err');
    dom.list.setAttribute('aria-busy', 'false');
  }
})();
