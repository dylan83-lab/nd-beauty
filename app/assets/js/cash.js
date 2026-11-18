/* Kas: sesi kasir, mutasi (masuk/keluar/transfer), saldo akun, selisih kas. */
let TAB = CASH_TAB;
const CATS_IN = { setoran_modal: 'Setoran modal', pendapatan_lain: 'Pendapatan lain', saldo_awal: 'Saldo awal' };
const CATS_OUT = { operasional: 'Biaya operasional', pembelian_stok: 'Pembelian stok', gaji: 'Gaji/komisi', prive: 'Penarikan pemilik', lainnya: 'Lainnya' };

async function show() {
  $$('#tabs button').forEach(b => b.classList.toggle('on', b.dataset.t === TAB));
  const el = $('#tab'); el.innerHTML = '<div class="empty">Memuat…</div>';
  try { await ({ sesi: sesi, mutasi: mutasi, selisih: selisih })[TAB](el); } catch (e) { fail(e); }
}
$$('#tabs button').forEach(b => b.onclick = () => { TAB = b.dataset.t; show(); });

async function sesi(el) {
  const { session: c } = await api('cash.status');
  if (!c) { el.innerHTML = `<div class="card stack" style="max-width:480px"><h3>Buka Kas</h3><p class="mut">Hitung uang tunai di laci sebagai kas awal sebelum menerima pembayaran.</p><input class="in" id="oc" type="number" min="0" value="0" inputmode="numeric"><div class="err" id="e"></div><button class="btn" id="ok">Buka Kas</button></div>`;
    $('#ok').onclick = ev => busy(ev.target, async () => { try { await api('cash.open', { opening_cash: +$('#oc').value }); toast('Kas dibuka', 'ok'); show(); } catch (e) { $('#e').textContent = e.message; } }); return; }
  el.innerHTML = `<div class="grid g2"><div class="card stack"><h3>Kas Terbuka</h3><small>Sejak ${esc(c.opened_at.slice(0, 16))}</small>
    <div class="row sp"><span>Kas awal</span><b>${rp(c.opening_cash)}</b></div><div class="row sp"><span>Tunai masuk</span><b>${rp(c.cash_in)}</b></div><div class="row sp"><span>Tunai keluar</span><b>${rp(c.cash_out)}</b></div>
    <div class="row sp"><span><b>Tunai menurut sistem</b></span><b style="font-size:1.4rem;color:var(--bur)">${rp(c.expected_calc)}</b></div>
    <hr style="border:0;border-top:1px solid var(--line)"><div class="row sp"><span>Transfer</span><b>${rp(c.by_method.transfer)}</b></div><div class="row sp"><span>QRIS</span><b>${rp(c.by_method.qris)}</b></div><div class="row sp"><span>Transaksi</span><b>${c.sales_count}</b></div></div>
    <div class="card stack"><h3>Tutup Kas</h3><label class="f">Uang fisik di laci</label><input class="in" id="cc" type="number" min="0" inputmode="numeric"><div id="df" style="font-weight:600"></div><label class="f">Keterangan selisih</label><input class="in" id="cn"><div class="err" id="e"></div><button class="btn" id="ok">Tutup Kas</button></div></div>`;
  $('#cc').oninput = e => { const d = (+e.target.value) - c.expected_calc; $('#df').innerHTML = e.target.value === '' ? '' : d === 0 ? '<span style="color:var(--ok)">Sesuai ✔</span>' : `<span style="color:var(--bad)">Selisih ${d > 0 ? '+' : '−'}${rp(Math.abs(d))}</span>`; };
  $('#ok').onclick = ev => busy(ev.target, async () => { try { const r = await api('cash.close', { counted: +$('#cc').value, note: $('#cn').value }); toast('Kas ditutup' + (r.summary.diff ? ' · selisih ' + rp(r.summary.diff) : ''), 'ok'); show(); } catch (e) { $('#e').textContent = e.message; } });
}

async function mutasi(el) {
  const [a, l] = [await api('cash.accounts'), null];
  const f = { from: todayStr().slice(0, 8) + '01', to: todayStr(), account: '' };
  el.innerHTML = `<div class="grid g3" style="margin-bottom:14px">${a.accounts.map(x => `<div class="stat"><small>${esc(x.name)} <em>(${x.type === 'cash' ? 'tunai' : x.type === 'bank' ? 'bank' : 'QRIS'})</em></small><b>${rp(x.balance)}</b></div>`).join('')}</div>
    <div class="row" style="margin-bottom:12px"><button class="btn sm ok" id="mIn">+ Uang Masuk</button><button class="btn sm bad" id="mOut">− Uang Keluar</button><button class="btn sm gold" id="mTr">⇄ Transfer Antarakun</button></div>
    <div class="toolbar"><div><label class="f">Dari</label><input type="date" class="in" id="fd" value="${f.from}"></div><div><label class="f">Sampai</label><input type="date" class="in" id="td" value="${f.to}"></div>
      <div><label class="f">Akun</label><select class="in" id="fa"><option value="">Semua</option>${a.accounts.map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('')}</select></div></div>
    <div class="tbl-wrap"><table class="tbl stack-m"><thead><tr><th>Tanggal</th><th>Akun</th><th>Kategori</th><th>Keterangan</th><th class="r">Masuk</th><th class="r">Keluar</th><th>Petugas</th></tr></thead><tbody id="mrows"></tbody></table></div>`;
  const load = async () => {
    const r = await api('cash.list', { from: $('#fd').value, to: $('#td').value, account: $('#fa').value });
    $('#mrows').innerHTML = r.rows.map(m => `<tr><td>${esc(m.mv_date)}</td><td>${esc(m.account_name)}</td><td>${esc(a.cats[m.category] || m.category)}</td><td>${esc(m.note)}</td><td class="r" style="color:var(--ok)">${m.kind === 'in' ? rp(m.amount) : ''}</td><td class="r" style="color:var(--bad)">${m.kind === 'out' ? rp(m.amount) : ''}</td><td>${esc(m.user_name || '')}</td></tr>`).join('') || '<tr><td colspan="7" class="empty">Tidak ada mutasi.</td></tr>';
  };
  ['fd', 'td', 'fa'].forEach(i => $('#' + i).onchange = () => load().catch(fail)); load().catch(fail);
  const mv = kind => {
    const cats = kind === 'in' ? CATS_IN : CATS_OUT;
    const m = modal(`<h3>${kind === 'in' ? 'Uang Masuk' : 'Uang Keluar'}</h3><div class="stack"><div class="form-grid"><div><label class="f">Akun</label><select class="in" id="ac">${a.accounts.map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('')}</select></div><div><label class="f">Tanggal</label><input type="date" class="in" id="dt" value="${todayStr()}"></div></div>
      <div class="form-grid"><div><label class="f">Kategori</label><select class="in" id="ct">${Object.entries(cats).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></div><div><label class="f">Nominal (Rp)</label><input class="in" id="am" type="number" min="1" inputmode="numeric"></div></div>
      <div><label class="f">Keterangan / referensi bukti</label><input class="in" id="nt"></div><div class="err" id="e"></div></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan</button></div>`);
    $('[data-x]', m).onclick = closeModal;
    $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('cash.move', { kind, account_id: +$('#ac', m).value, date: $('#dt', m).value, category: $('#ct', m).value, amount: +$('#am', m).value, note: $('#nt', m).value }); closeModal(); toast('Mutasi dicatat', 'ok'); show(); } catch (e) { $('#e', m).textContent = e.message; } });
  };
  $('#mIn').onclick = () => mv('in'); $('#mOut').onclick = () => mv('out');
  $('#mTr').onclick = () => {
    const o = a.accounts.map(x => `<option value="${x.id}">${esc(x.name)} (${rp(x.balance)})</option>`).join('');
    const m = modal(`<h3>Transfer Antarakun</h3><p class="mut">Perpindahan saldo — tidak dihitung sebagai omzet atau uang masuk.</p><div class="stack"><div class="form-grid"><div><label class="f">Dari</label><select class="in" id="fr">${o}</select></div><div><label class="f">Ke</label><select class="in" id="to">${o}</select></div></div>
      <div class="form-grid"><div><label class="f">Nominal</label><input class="in" id="am" type="number" min="1"></div><div><label class="f">Tanggal</label><input class="in" type="date" id="dt" value="${todayStr()}"></div></div><div><label class="f">Keterangan</label><input class="in" id="nt" placeholder="Mis. setor tunai ke bank"></div><div class="err" id="e"></div></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Transfer</button></div>`);
    $('#to', m).selectedIndex = Math.min(1, a.accounts.length - 1); $('[data-x]', m).onclick = closeModal;
    $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('cash.transfer', { from: +$('#fr', m).value, to: +$('#to', m).value, amount: +$('#am', m).value, note: $('#nt', m).value, date: $('#dt', m).value }); closeModal(); toast('Transfer dicatat', 'ok'); show(); } catch (e) { $('#e', m).textContent = e.message; } });
  };
}

async function selisih(el) {
  const r = await api('cash.sessions');
  el.innerHTML = `<div class="tbl-wrap"><table class="tbl stack-m"><thead><tr><th>Kasir</th><th>Buka</th><th>Tutup</th><th class="r">Sistem</th><th class="r">Fisik</th><th class="r">Selisih</th><th>Keterangan</th><th></th></tr></thead><tbody>${r.rows.map(s => `<tr><td>${esc(s.user_name)}</td><td>${esc(s.opened_at.slice(0, 16))}</td><td>${esc((s.closed_at || '').slice(0, 16)) || badge('draft', 'Terbuka')}</td><td class="r">${s.expected_cash === null ? '' : rp(s.expected_cash)}</td><td class="r">${s.counted_cash === null ? '' : rp(s.counted_cash)}</td>
    <td class="r" style="color:${+s.diff ? 'var(--bad)' : 'var(--ok)'}"><b>${s.diff === null ? '' : rp(s.diff)}</b></td><td>${esc(s.diff_note)}${s.review_note ? '<br><small>✔ ' + esc(s.reviewer || '') + ': ' + esc(s.review_note) + '</small>' : ''}</td>
    <td class="r">${s.status === 'closed' && +s.diff && !s.reviewed_by ? `<button class="btn sm ghost" data-r="${s.id}">Periksa</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="8" class="empty">Belum ada sesi kas.</td></tr>'}</tbody></table></div>`;
  $$('[data-r]').forEach(b => b.onclick = () => { const m = modal(`<h3>Periksa Selisih Kas</h3><label class="f">Catatan pemeriksaan</label><input class="in" id="n" placeholder="Hasil pemeriksaan / tindak lanjut"><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Tandai Diperiksa</button></div>`);
    $('[data-x]', m).onclick = closeModal; $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('cash.review', { id: +b.dataset.r, note: $('#n', m).value || 'Diperiksa' }); closeModal(); show(); } catch (e) { fail(e); } }); });
}
show();
