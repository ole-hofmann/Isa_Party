(() => {
  'use strict';

  const PRICE = Number(document.body.dataset.price) || 0;
  const POLL_MS = 5000;

  const $ = (sel) => document.querySelector(sel);
  const el = {
    search: $('#search'), clear: $('#clear'), list: $('#list'),
    empty: $('#empty'), emptyText: $('#empty-text'), suggest: $('#suggest'),
    statIn: $('#stat-in'), statOpen: $('#stat-open'), statCash: $('#stat-cash'),
    offline: $('#offline'), toast: $('#toast'),
    sheet: $('#sheet'), sheetTag: $('#sheet-tag'), sheetName: $('#sheet-name'), sheetInfo: $('#sheet-info'),
    btnPay: $('#btn-pay'), btnUndo: $('#btn-undo'), btnRename: $('#btn-rename'), btnDelete: $('#btn-delete'),
    add: $('#add'), addForm: $('#add-form'), addName: $('#add-name'), btnAddOpen: $('#btn-add-open'),
    addTitle: $('#add-title'), addInfo: $('#add-info'), addSubmit: $('#add-submit'),
  };
  const actionButtons = [el.btnPay, el.btnUndo, el.btnRename, el.btnDelete, el.addSubmit];

  let guests = [];
  let lastJson = '';
  let filter = 'all';
  let currentId = null;
  let nameMode = 'add'; // Namensdialog: 'add' oder 'rename'
  let busy = false;

  // ------------------------------------------------------------ Suche

  // Groß/klein, Umlaute (ä = ae = a), Akzente, Bindestriche egal
  const norm = (s) => s.toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/ß/g, 'ss').replace(/ae/g, 'a').replace(/oe/g, 'o').replace(/ue/g, 'u')
    .replace(/[^a-z0-9]+/g, ' ').trim();

  const collator = new Intl.Collator('de', { sensitivity: 'base' });

  function levenshtein(a, b) {
    const row = Array.from({ length: b.length + 1 }, (_, i) => i);
    for (let i = 1; i <= a.length; i++) {
      let prev = row[0];
      row[0] = i;
      for (let j = 1; j <= b.length; j++) {
        const tmp = row[j];
        row[j] = Math.min(row[j] + 1, row[j - 1] + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
        prev = tmp;
      }
    }
    return row[b.length];
  }

  function search(query) {
    const tokens = norm(query).split(' ').filter(Boolean);
    let result = guests.filter((g) => {
      if (filter === 'open' && g.paid) return false;
      if (filter === 'in' && !g.paid) return false;
      return tokens.every((t) => g._n.includes(t));
    });
    // Treffer am Wortanfang zuerst
    const score = (g) => tokens.filter((t) => g._words.some((w) => w.startsWith(t))).length;
    return result.sort((a, b) => score(b) - score(a) || collator.compare(a.name, b.name));
  }

  /** Bei Tippfehlern: ähnlichste Namen vorschlagen */
  function suggestions(query) {
    const q = norm(query);
    if (q.length < 3) return [];
    const qWords = q.split(' ');
    return guests
      .map((g) => {
        const whole = levenshtein(q, g._n) / Math.max(q.length, g._n.length);
        const perWord = qWords.reduce((sum, qw) => sum + Math.min(...g._words.map((w) =>
          Math.min(levenshtein(qw, w), levenshtein(qw, w.slice(0, qw.length))))), 0) / q.length;
        return { g, d: Math.min(whole, perWord) };
      })
      .filter((x) => x.d <= 0.34)
      .sort((a, b) => a.d - b.d)
      .slice(0, 4)
      .map((x) => x.g);
  }

  // ------------------------------------------------------------ Darstellung

  const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const time = (ts) => new Date(ts * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });

  function guestButton(g) {
    const badge = g.paid ? `<span class="badge in">✓ drin ${time(g.paidAt)}</span>` : `<span class="badge open">${PRICE} €</span>`;
    const extra = g.added ? '<span class="added" title="nachgetragen">＋</span>' : '';
    return `<button type="button" class="guest${g.paid ? ' paid' : ''}" data-id="${esc(g.id)}">
      <span class="name">${esc(g.name)}${extra}</span>${badge}</button>`;
  }

  function render() {
    const query = el.search.value;
    el.clear.hidden = query === '';

    const paid = guests.filter((g) => g.paid).length;
    el.statIn.textContent = `${paid}`;
    el.statOpen.textContent = `${guests.length - paid}`;
    el.statCash.textContent = `${paid * PRICE} €`;

    const result = search(query);
    el.list.innerHTML = result.map((g) => `<li>${guestButton(g)}</li>`).join('');

    const noHits = result.length === 0 && query.trim() !== '';
    el.empty.hidden = !noHits;
    if (noHits) {
      const tokens = norm(query).split(' ').filter(Boolean);
      const filtered = filter !== 'all' && guests.some((g) => tokens.every((t) => g._n.includes(t)));
      el.emptyText.textContent = filtered
        ? 'Keine Treffer mit diesem Filter – schau mal unter „Alle“.'
        : `„${query.trim()}“ steht nicht auf der Liste.`;
      el.btnAddOpen.hidden = filtered;
      const sugg = filtered ? [] : suggestions(query);
      el.suggest.innerHTML = sugg.length
        ? `<p>Meintest du …</p>${sugg.map(guestButton).join('')}`
        : '';
    }

    if (currentId && (el.sheet.open || el.add.open)) fillSheet();
  }

  function fillSheet() {
    const g = guests.find((x) => x.id === currentId);
    if (!g) {
      // Wurde auf einem anderen Handy gelöscht
      if (el.sheet.open) el.sheet.close();
      if (el.add.open && nameMode === 'rename') el.add.close();
      return;
    }
    el.sheetName.textContent = g.name;
    el.sheetTag.textContent = g.added ? 'Nachgetragen' : 'Auf der Gästeliste';
    el.sheetTag.className = 'tag' + (g.added ? ' warn' : '');
    if (g.paid) {
      el.sheetInfo.innerHTML = `<span class="already">⚠️ Schon drin seit ${time(g.paidAt)} Uhr</span>`;
      el.btnPay.hidden = true;
      el.btnUndo.hidden = false;
    } else {
      el.sheetInfo.textContent = `Noch nicht da – ${PRICE} € kassieren.`;
      el.btnPay.hidden = false;
      el.btnUndo.hidden = true;
    }
  }

  function openSheet(id) {
    currentId = id;
    fillSheet();
    el.sheet.showModal();
  }

  function openNameDialog(mode, name) {
    nameMode = mode;
    const rename = mode === 'rename';
    el.addTitle.textContent = rename ? 'Umbenennen' : 'Gast hinzufügen';
    el.addInfo.textContent = rename
      ? 'Zum Beispiel wenn der Name auf der Liste falsch geschrieben ist.'
      : 'Steht nicht auf der Liste – nur wenn Isa oder Laura okay sagen.';
    el.addSubmit.textContent = rename ? 'Speichern' : 'Hinzufügen';
    el.addName.value = name;
    el.add.showModal();
    el.addName.focus();
  }

  let toastTimer;
  function toast(msg, kind = 'ok') {
    el.toast.textContent = msg;
    el.toast.className = `toast ${kind}`;
    el.toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.toast.hidden = true; }, 2800);
  }

  // ------------------------------------------------------------ Server

  function setGuests(list) {
    const json = JSON.stringify(list);
    if (json === lastJson) return;
    lastJson = json;
    guests = list.map((g) => {
      const n = norm(g.name);
      return { ...g, _n: n, _words: n.split(' ') };
    });
    render();
  }

  async function api(action, body) {
    const opts = body
      ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Einlass': '1' }, body: JSON.stringify(body) }
      : { cache: 'no-store' };
    let res;
    try {
      res = await fetch(`api.php?action=${action}`, opts);
    } catch (e) {
      el.offline.hidden = false;
      throw new Error('Keine Verbindung zum Server');
    }
    el.offline.hidden = true;
    if (res.status === 401) {
      location.reload();
      throw new Error('Nicht angemeldet');
    }
    const data = await res.json().catch(() => ({ error: `Serverfehler (${res.status})` }));
    if (data.guests) setGuests(data.guests);
    if (!res.ok) {
      const err = new Error(data.error || `Fehler ${res.status}`);
      err.data = data;
      throw err;
    }
    return data;
  }

  async function refresh() {
    if (document.hidden) return;
    try { await api('list'); } catch (e) { /* Banner zeigt den Zustand */ }
  }

  async function act(fn) {
    if (busy) return;
    busy = true;
    actionButtons.forEach((b) => { b.disabled = true; });
    try {
      await fn();
    } catch (e) {
      if (e.data && e.data.error === 'already') {
        fillSheet();
        toast('Moment! Wurde gerade schon eingelassen.', 'warn');
        navigator.vibrate?.([80, 60, 80]);
      } else {
        toast(e.message, 'err');
      }
    } finally {
      busy = false;
      actionButtons.forEach((b) => { b.disabled = false; });
    }
  }

  // ------------------------------------------------------------ Events

  el.search.addEventListener('input', render);
  el.clear.addEventListener('click', () => { el.search.value = ''; render(); el.search.focus(); });

  document.querySelectorAll('[data-filter]').forEach((btn) => btn.addEventListener('click', () => {
    filter = btn.dataset.filter;
    document.querySelectorAll('[data-filter]').forEach((b) => b.classList.toggle('active', b === btn));
    render();
  }));

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.guest');
    if (btn) openSheet(btn.dataset.id);
    if (e.target.closest('[data-close]')) e.target.closest('dialog').close();
  });

  // Klick auf den abgedunkelten Hintergrund schließt den Dialog
  [el.sheet, el.add].forEach((d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));

  el.btnPay.addEventListener('click', () => act(async () => {
    const { guest } = await api('checkin', { id: currentId });
    el.sheet.close();
    el.search.value = '';
    render();
    toast(`✓ ${guest.name} ist drin – ${PRICE} € kassiert`);
    navigator.vibrate?.(60);
  }));

  el.btnUndo.addEventListener('click', () => act(async () => {
    const g = guests.find((x) => x.id === currentId);
    if (!g || !confirm(`Einlass von ${g.name} wirklich zurücknehmen? (${PRICE} € zurückgeben)`)) return;
    await api('undo', { id: currentId });
    el.sheet.close();
    toast(`${g.name} wieder auf „offen“ gesetzt`, 'warn');
  }));

  el.btnRename.addEventListener('click', () => {
    const g = guests.find((x) => x.id === currentId);
    if (g) openNameDialog('rename', g.name);
  });

  el.btnDelete.addEventListener('click', () => act(async () => {
    const g = guests.find((x) => x.id === currentId);
    if (!g) return;
    const warn = g.paid ? `\n\nAchtung: ${g.name} ist schon drin und hat ${PRICE} € bezahlt – das fehlt dann in der Kasse.` : '';
    if (!confirm(`${g.name} wirklich von der Gästeliste löschen?${warn}`)) return;
    await api('delete', { id: g.id });
    el.sheet.close();
    toast(`${g.name} gelöscht`, 'warn');
  }));

  el.btnAddOpen.addEventListener('click', () => openNameDialog('add', el.search.value.trim()));

  el.addForm.addEventListener('submit', (e) => {
    e.preventDefault();
    if (nameMode === 'rename') {
      act(async () => {
        const { guest } = await api('rename', { id: currentId, name: el.addName.value });
        el.add.close();
        if (el.search.value.trim()) el.search.value = guest.name;
        render();
        toast(`Umbenannt in ${guest.name}`);
      });
      return;
    }
    act(async () => {
      const { guest, existed } = await api('add', { name: el.addName.value });
      el.add.close();
      el.search.value = guest.name;
      render();
      if (existed) toast(`${guest.name} stand schon auf der Liste`, 'warn');
      openSheet(guest.id);
    });
  });

  document.addEventListener('visibilitychange', refresh);
  setInterval(refresh, POLL_MS);
  refresh();
})();
