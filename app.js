const $ = id => document.getElementById(id);
const dom = { domain: $('domain'), from: $('from'), to: $('to'), form: $('add-form'), btn: $('add-btn'), list: $('list'), msg: $('message'), count: $('count'), fromToggle: $('from-toggle'), fromSuffix: $('from-suffix') };

let domains = {}, selectedDomain = '', msgTimer, fromExpanded = false, listRequest = 0;

const TRASH_SVG = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 4h12"/><path d="M5.3 4V2.7A1.3 1.3 0 016.7 1.3h2.6a1.3 1.3 0 011.4 1.4V4"/><path d="M12.7 4v9.3a1.3 1.3 0 01-1.4 1.4H4.7a1.3 1.3 0 01-1.4-1.4V4h9.4z"/></svg>';

const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

async function request(url, opts) {
  const r = await fetch(url, opts);
  const data = r.headers.get('content-type')?.includes('json') ? await r.json() : null;
  if (!r.ok) throw new Error(data?.message || `Error ${r.status}`);
  return data;
}

const api = (path, opts) => request(`api.php?ovh=${encodeURIComponent(selectedDomain + '/redirection' + path)}`, opts);

function showMsg(text, ok) {
  clearTimeout(msgTimer);
  dom.msg.innerHTML = `<div class="msg ${ok ? 'msg-ok' : 'msg-err'}">${esc(text)}</div>`;
  msgTimer = setTimeout(() => dom.msg.innerHTML = '', 4000);
}

function getFromValue() {
  const val = dom.from.value.trim();
  return fromExpanded ? val : val + '@' + selectedDomain;
}

function setDomain(d) {
  const prev = selectedDomain;
  selectedDomain = d;
  localStorage.setItem('lastDomain', d);
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
    group.classList.add('expanded');
    dom.fromToggle.classList.add('active');
  } else {
    const val = dom.from.value.trim();
    const at = val.indexOf('@');
    dom.from.value = at > 0 ? val.slice(0, at) : (at === 0 ? '' : val);
    group.classList.remove('expanded');
    dom.fromToggle.classList.remove('active');
  }
  dom.from.focus();
});

async function fetchList() {
  // Switching domains twice in a row must not let the slower response win the race.
  const token = ++listRequest;
  dom.list.setAttribute('aria-busy', 'true');
  dom.list.innerHTML = '<div class="spinner">Loading...</div>';
  dom.count.textContent = '';
  try {
    const items = await request(`api.php?action=redirections&domain=${encodeURIComponent(selectedDomain)}`);
    if (token !== listRequest) return;
    dom.count.textContent = `${items.length} redirection${items.length !== 1 ? 's' : ''}`;
    if (!items.length) { dom.list.innerHTML = '<div class="empty">No redirections</div>'; return; }

    dom.list.innerHTML = '<table><thead><tr><th scope="col">Source</th><th scope="col">Destination</th>'
      + '<th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>' + items.map(r =>
      `<tr><td>${esc(r.from)}</td><td>${esc(r.to)}</td><td class="td-actions"><button class="btn-square btn-del del-btn" data-id="${esc(String(r.id))}" aria-label="Delete the redirection from ${esc(r.from)}" title="Delete">${TRASH_SVG}</button></td></tr>`
    ).join('') + '</tbody></table>';
  } catch (err) {
    if (token !== listRequest) return;
    console.error(err);
    dom.list.innerHTML = `<div class="msg msg-err">${esc(err.message)}</div>`;
  } finally {
    if (token === listRequest) dom.list.setAttribute('aria-busy', 'false');
  }
}

dom.domain.addEventListener('change', () => { setDomain(dom.domain.value); fetchList(); });

dom.form.addEventListener('submit', async e => {
  e.preventDefault();
  const from = getFromValue(), to = dom.to.value.trim();
  const at = from.indexOf('@');
  if (at < 1) { showMsg(`Source must have a username before @`, false); return; }

  dom.btn.disabled = true;
  try {
    await api('', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ from, to, localCopy: false }) });
    showMsg('Redirection added', true);
    dom.from.value = fromExpanded ? '@' + selectedDomain : '';
    fetchList();
  } catch (err) { showMsg(err.message, false); }
  finally { dom.btn.disabled = false; }
});

dom.list.addEventListener('click', async e => {
  const btn = e.target.closest('.del-btn');
  if (!btn || !confirm('Delete this redirection?')) return;
  const prev = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '&hellip;';
  try { await api(`/${btn.dataset.id}`, { method: 'DELETE' }); showMsg('Redirection deleted', true); fetchList(); }
  catch (err) { showMsg(err.message, false); btn.disabled = false; btn.innerHTML = prev; }
});

(async () => {
  try {
    const { domains: d } = await request('api.php?action=config');
    domains = d;
    const keys = Object.keys(d);
    const saved = localStorage.getItem('lastDomain');
    selectedDomain = keys.includes(saved) ? saved : keys[0];
    dom.domain.innerHTML = keys.map(k => `<option value="${esc(k)}"${k === selectedDomain ? ' selected' : ''}>${esc(k)}</option>`).join('');
    setDomain(selectedDomain);
    fetchList();
  } catch { dom.list.innerHTML = '<div class="msg msg-err">Failed to load configuration</div>'; }
})();
