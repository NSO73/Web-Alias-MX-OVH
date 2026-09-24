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

let domains = {}, selectedDomain = '', msgTimer, listRequest = 0;

// localStorage throws outright in some privacy modes, which would otherwise take the whole
// page down on load. Remembering the last domain is a convenience, never a requirement.
const store = {
  get(key) { try { return localStorage.getItem(key); } catch { return null; } },
  set(key, value) { try { localStorage.setItem(key, value); } catch { /* nothing to do */ } },
};

async function request(url, opts = {}) {
  let r;
  try {
    r = await fetch(url, { ...opts, signal: AbortSignal.timeout(REQUEST_TIMEOUT) });
  } catch (err) {
    throw new Error(err.name === 'TimeoutError' ? 'The server did not answer in time' : 'Network error');
  }
  const data = r.headers.get('content-type')?.includes('json') ? await r.json().catch(() => null) : null;
  if (!r.ok) throw new Error(data?.message || `Error ${r.status}`);
  return data;
}

const post = (action, data) => request(`api.php?action=${action}`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ domain: selectedDomain, ...data }),
});

const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

// The pressed state of the pencil button is the only record of which mode the field is in.
const isExpanded = () => dom.fromToggle.ariaPressed === 'true';

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
  selectedDomain = d;
  store.set(STORE_KEY, d);
  dom.to.value = domains[d];
  dom.fromSuffix.textContent = '@' + d;
  const at = dom.from.value.indexOf('@');
  if (isExpanded() && at >= 0) dom.from.value = dom.from.value.slice(0, at + 1) + d;
}

dom.fromToggle.addEventListener('click', () => {
  const expand = !isExpanded();
  const val = dom.from.value.trim();
  dom.fromToggle.ariaPressed = String(expand);
  if (expand) dom.from.value = val.includes('@') ? val : `${val}@${selectedDomain}`;
  else dom.from.value = val.split('@')[0];
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
    btn.dataset.id = item.id;
    btn.title = `Delete the redirection from ${item.from}`;
    frag.append(row);
  }
  dom.rows.replaceChildren(frag);
}

async function fetchList() {
  // Switching domains twice in a row must not let the slower response win the race.
  const token = ++listRequest;
  dom.list.ariaBusy = 'true';
  dom.table.hidden = true;
  dom.count.textContent = '';
  setNote('Loading…', '');
  try {
    const { items, unread } = await request(`api.php?action=list&domain=${encodeURIComponent(selectedDomain)}`);
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
    if (token === listRequest) dom.list.ariaBusy = 'false';
  }
}

dom.domain.addEventListener('change', () => { setDomain(dom.domain.value); fetchList(); });

dom.form.addEventListener('submit', async e => {
  e.preventDefault();
  const expanded = isExpanded();
  const typed = dom.from.value.trim(), to = dom.to.value.trim();
  // Collapsed, the field holds a username and the domain is appended: letting a full address
  // through here used to build "user@other.com@domain.tld" and hand it to OVH to reject.
  if (!expanded && typed.includes('@')) {
    showMsg('Use the pencil button to enter a full address', 'err');
    return;
  }
  const from = expanded ? typed : `${typed}@${selectedDomain}`;
  if (!EMAIL_RE.test(from)) { showMsg('Source is not a valid address', 'err'); return; }

  dom.btn.disabled = true;
  try {
    await post('add', { from, to });
    showMsg('Redirection added', 'ok');
    dom.from.value = expanded ? '@' + selectedDomain : '';
    await fetchList();
  } catch (err) { showMsg(err.message, 'err'); }
  finally { dom.btn.disabled = false; }
});

dom.rows.addEventListener('click', async e => {
  const btn = e.target.closest('.btn-del');
  if (!btn || !confirm('Delete this redirection?')) return;
  btn.disabled = true;
  try {
    await post('delete', { id: Number(btn.dataset.id) });
    showMsg('Redirection deleted', 'ok');
    await fetchList();
  } catch (err) {
    showMsg(err.message, 'err');
    btn.disabled = false;
  }
});

// api.php refuses to start without at least one domain, so an answer here always has one.
try {
  ({ domains } = await request('api.php?action=config'));
  const keys = Object.keys(domains);
  const saved = store.get(STORE_KEY);
  dom.domain.replaceChildren(...keys.map(k => new Option(k, k, false, k === saved)));
  setDomain(keys.includes(saved) ? saved : keys[0]);
  await fetchList();
} catch (err) {
  setNote(err.message, 'err');
  dom.list.ariaBusy = 'false';
}
