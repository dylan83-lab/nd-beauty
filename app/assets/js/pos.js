/* Kasir dua panel: kiri = pelanggan/antrean/layanan/produk; kanan = rincian, potongan, total, bayar. Di HP menjadi langkah berurutan. */
const P = { sale: null, cat: null, tab: 'q', method: 'cash', idem: null, cash: null, queue: [] };

async function loadCash() {
  try { P.cash = (await api('cash.status')).session; } catch (e) { return fail(e); }
  const c = P.cash, el = $('#cashbar');
  el.innerHTML = c ? `<span>🟢 <b>Kas terbuka</b> sejak ${esc(c.opened_at.slice(11, 16))} · kas awal ${rp(c.opening_cash)} · tunai diharapkan <b>${rp(c.expected_calc)}</b> · ${c.sales_count} transaksi</span><button class="btn sm ghost" id="closeCash">Tutup Kas</button>`
    : `<span>🔴 <b>Kas belum dibuka.</b> Buka kas untuk menerima pembayaran.</span><button class="btn sm" id="openCash">Buka Kas</button>`;
  $('#openCash')?.addEventListener('click', openCashDlg); $('#closeCash')?.addEventListener('click', closeCashDlg);
}
function openCashDlg() {
  const m = modal(`<h3>Buka Kas</h3><label class="f">Uang tunai di laci (kas awal)</label><input class="in" id="oc" type="number" min="0" inputmode="numeric" value="0"><div class="err" id="e"></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Buka Kas</button></div>`);
  $('[data-x]', m).onclick = closeModal;
  $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('cash.open', { opening_cash: +$('#oc', m).value }); closeModal(); toast('Kas dibuka', 'ok'); loadCash(); } catch (e) { $('#e', m).textContent = e.message; } });
}
function closeCashDlg() {
  const c = P.cash;
  const m = modal(`<h3>Tutup Kas</h3><div class="card flat stack"><div class="row sp"><span>Kas awal</span><b>${rp(c.opening_cash)}</b></div><div class="row sp"><span>Tunai masuk (penjualan)</span><b>${rp(c.cash_in)}</b></div><div class="row sp"><span>Tunai keluar (refund, dll.)</span><b>${rp(c.cash_out)}</b></div>
    <div class="row sp"><span><b>Tunai menurut sistem</b></span><b style="font-size:1.2rem">${rp(c.expected_calc)}</b></div><hr style="border:0;border-top:1px solid var(--line)">
    <div class="row sp"><span>Transfer</span><b>${rp(c.by_method.transfer)}</b></div><div class="row sp"><span>QRIS</span><b>${rp(c.by_method.qris)}</b></div></div>
    <label class="f" style="margin-top:12px">Hitung uang fisik di laci</label><input class="in" id="cc" type="number" min="0" inputmode="numeric" placeholder="0"><div id="df" style="margin:8px 0;font-weight:600"></div>
    <label class="f">Keterangan selisih (wajib bila ada selisih)</label><input class="in" id="cn"><div class="err" id="e"></div>
    <div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Tutup Kas</button></div>`);
  $('#cc', m).oninput = e => { const d = (+e.target.value) - c.expected_calc; $('#df', m).innerHTML = e.target.value === '' ? '' : d === 0 ? '<span style="color:var(--ok)">Sesuai ✔</span>' : `<span style="color:var(--bad)">Selisih ${d > 0 ? '+' : '−'}${rp(Math.abs(d))}</span>`; };
  $('[data-x]', m).onclick = closeModal;
  $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('cash.close', { counted: +$('#cc', m).value, note: $('#cn', m).value }); closeModal(); toast('Kas ditutup', 'ok'); P.sale = null; renderCart(); loadCash(); } catch (e) { $('#e', m).textContent = e.message; } });
}

async function loadCatalog() { try { P.cat = await api('pos.catalog'); } catch (e) { fail(e); } }

async function loadQueue() {
  try { P.queue = (await api('pos.queue')).bookings; } catch (e) { return; }
  if (P.tab === 'q') drawBody();
}

function drawBody() {
  const b = $('#pbody'); $$('#ptabs button').forEach(x => x.classList.toggle('on', x.dataset.t === P.tab));
  if (P.tab === 'q') {
    b.innerHTML = `<div class="row sp" style="margin-bottom:8px"><b>Antrean hari ini (belum dibayar)</b><button class="btn sm ghost" id="walk">+ Transaksi Baru</button></div>` +
      (P.queue.length ? P.queue.map(q => `<div class="qcard" style="margin-bottom:8px"><div class="row sp"><div><span class="time" style="font-size:1.1rem">${esc(q.start)}</span> <b>${esc(q.customer)}</b><br><small>${esc(q.services)} · ${esc(q.therapist || '')}</small></div><div>${badge(q.status, q.status_label)}</div></div>
        <div class="row sp"><b>${rp(q.total)}</b><button class="btn sm ${q.status === 'done' ? 'gold' : 'ghost'}" data-open="${q.id}">${q.status === 'done' ? 'Proses Pembayaran' : 'Buka Transaksi'}</button></div></div>`).join('') : '<div class="empty card">Tidak ada antrean yang menunggu pembayaran.</div>');
    $('#walk').onclick = () => openSale({ walkin: true });
    $$('[data-open]').forEach(x => x.onclick = () => openSale({ booking_id: +x.dataset.open }));
  } else if (P.tab === 's') {
    b.innerHTML = `<div class="tile-grid">${P.cat.services.map(s => `<button class="tile" data-add="service" data-id="${s.id}"><b>${esc(s.name)}</b><span>${s.price_from ? 'Mulai ' : ''}${rp(s.price)}</span></button>`).join('')}</div>`;
  } else if (P.tab === 'p') {
    b.innerHTML = `<div class="tile-grid">${P.cat.products.map(s => `<button class="tile" data-add="product" data-id="${s.id}" ${s.stock <= 0 ? 'disabled style="opacity:.5"' : ''}><b>${esc(s.name)}</b><span>${rp(s.price)}</span><small>Stok ${s.stock}</small></button>`).join('') || '<div class="empty card">Belum ada produk dijual.</div>'}</div>`;
  } else if (P.tab === 'm') {
    b.innerHTML = `<div class="tile-grid">${P.cat.plans.map(s => `<button class="tile" data-add="member" data-id="${s.id}"><b>${esc(s.name)}</b><span>${rp(s.price)}</span><small>Diskon ${s.discount_pct}%</small></button>`).join('')}</div><small class="mut">Pilih pelanggan terlebih dahulu. Membership aktif setelah transaksi lunas.</small>`;
  } else if (P.tab === 'r') refundTab(b);
  $$('[data-add]').forEach(x => x.onclick = () => addLine(x.dataset.add, +x.dataset.id));
}

async function openSale(p) {
  try {
    const r = await api('pos.open', p); P.sale = r.sale; P.idem = crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random();
    renderCart(); step(2);
  } catch (e) { fail(e); }
}

function renderCart() {
  const el = $('#cart'), s = P.sale;
  $('#cartN').textContent = s && s.items.length ? '(' + s.items.length + ')' : '';
  if (!s) { el.innerHTML = '<div class="card empty">Pilih reservasi dari antrean atau mulai transaksi baru.</div>'; return; }
  const paid = s.paid_at !== null && s.paid_at !== undefined;
  if (paid) return renderReceipt(s);
  const c = s.customer, mem = c && c.member.active;
  const manager = ['admin', 'manager'].includes(ND.role);
  const bk = s.booking;
  const needDone = bk && bk.status !== 'done';
  el.innerHTML = `<div class="card stack">
    <div class="row sp"><div><h3 style="margin:0">Rincian Transaksi</h3><small>${bk ? 'Reservasi ' + esc(bk.code) + ' · ' + esc(bk.start_hm) + ' · terapis ' + esc(bk.therapist_name || '-') : 'Transaksi langsung'}</small></div>${bk ? badge(bk.status, bk.status_label) : ''}</div>
    ${needDone ? '<div class="note warn">Perawatan belum selesai (status: ' + esc(bk.status_label) + '). Pembayaran dapat diproses setelah terapis menekan Selesaikan Perawatan.</div>' : ''}
    <div class="row sp"><span>${c ? '<b>' + esc(c.name) + '</b> <small>' + esc(c.phone || '') + '</small> ' + (mem ? badge('active', c.member.plan.name) : '') : '<small class="mut">Pelanggan umum — cari pelanggan untuk member/poin</small>'}</span>${c && !bk ? '<button class="btn sm ghost" id="noCust">Lepas</button>' : ''}</div>
    <div id="lines">${s.items.map(i => `<div class="line"><div><b>${esc(i.name)}</b><br><small>${rp(i.price)}${i.disc > 0 ? ' · potongan ' + rp(i.disc) : ''}</small>${i.item_type === 'service' && i.locked && priceVar(i) ? `<br><input class="in" type="number" style="width:140px;min-height:34px;margin-top:4px" data-price="${i.id}" value="${i.price}" title="Harga variabel (mulai dari)">` : ''}</div>
      ${i.locked ? '<span></span>' : `<div class="qty"><button data-q="${i.id}" data-d="-1">−</button><span>${i.qty}</span><button data-q="${i.id}" data-d="1">+</button></div>`}<b>${rp(i.price * i.qty)}</b></div>`).join('') || '<div class="empty">Belum ada item. Tambah layanan atau produk.</div>'}</div>
    <div class="totals">
      <div><span>Subtotal</span><span>${rp(s.subtotal)}</span></div>
      ${s.disc_member > 0 ? `<div style="color:var(--ok)"><span>Diskon member</span><span>−${rp(s.disc_member)}</span></div>` : ''}
      ${s.disc_manual > 0 ? `<div style="color:var(--ok)"><span>Potongan manual</span><span>−${rp(s.disc_manual)}</span></div>` : ''}
      ${s.disc_points > 0 ? `<div style="color:var(--ok)"><span>Tukar ${s.points_used} poin</span><span>−${rp(s.disc_points)}</span></div>` : ''}
      <div class="grand"><span>Total</span><span>${rp(s.total)}</span></div></div>
    <details><summary style="cursor:pointer;font-weight:500">Potongan & poin</summary><div class="stack" style="margin-top:8px">
      <div class="form-grid"><div><label class="f">Potongan manual (Rp)${manager ? '' : ' — maks ' + P.cat.manual_max_pct + '%'}</label><input class="in" id="dm" type="number" min="0" value="${s.disc_manual || ''}"></div><div><label class="f">Alasan</label><input class="in" id="dr" value="${esc(s.manual_reason || '')}"></div></div>
      ${c && c.points > 0 ? `<div><label class="f">Tukar poin (punya ${c.points}; 1 poin = ${rp(P.cat.point_value)})</label><input class="in" id="dp" type="number" min="0" max="${c.points}" value="${s.points_used || ''}"></div>` : ''}
      <button class="btn sm ghost" id="applyD">Terapkan</button></div></details>
    <div><label class="f">Metode pembayaran</label><div class="paym"><button data-m="cash" class="${P.method === 'cash' ? 'on' : ''}">💵 Tunai</button><button data-m="transfer" class="${P.method === 'transfer' ? 'on' : ''}">🏦 Transfer</button><button data-m="qris" class="${P.method === 'qris' ? 'on' : ''}">▦ QRIS</button></div></div>
    <div id="payx"></div><div class="err" id="perr"></div>
    <button class="btn block gold" id="payBtn" style="min-height:52px;font-size:1.05rem" ${(!P.cash || needDone || !s.items.length) ? 'disabled' : ''}>Bayar ${rp(s.total)}</button>
    ${!P.cash ? '<small class="err">Buka kas terlebih dahulu.</small>' : ''}</div>`;
  payExtra();
  $$('[data-q]').forEach(b => b.onclick = () => qty(+b.dataset.q, +b.dataset.d));
  $$('[data-price]').forEach(i => i.onchange = () => saveSale({ lines: linesOf(s, { [i.dataset.price]: { price: +i.value } }) }));
  $$('.paym button').forEach(b => b.onclick = () => { P.method = b.dataset.m; renderCart(); });
  $('#applyD').onclick = () => saveSale({ disc_manual: +($('#dm').value || 0), manual_reason: $('#dr').value, points_used: +($('#dp')?.value || 0) });
  $('#noCust')?.addEventListener('click', () => saveSale({ customer_id: null }));
  $('#payBtn').onclick = ev => busy(ev.target, pay);
}
const priceVar = i => P.cat.services.find(x => x.id == i.ref_id)?.price_from;
const linesOf = (s, ov) => s.items.map(i => ({ id: i.id, qty: i.qty, ...(ov && ov[i.id] || {}) }));

function payExtra() {
  const s = P.sale, el = $('#payx'); if (!el) return;
  if (P.method === 'cash') {
    const q = [s.total, Math.ceil(s.total / 50000) * 50000, Math.ceil(s.total / 100000) * 100000].filter((v, i, a) => v > 0 && a.indexOf(v) === i);
    el.innerHTML = `<label class="f">Uang diterima</label><input class="in" id="recv" type="number" inputmode="numeric" min="0" value="${s.total}"><div class="quick" style="margin-top:6px">${q.map(v => `<button data-r="${v}">${rp(v)}</button>`).join('')}</div><div id="chg" style="margin-top:6px;font-weight:600"></div>`;
    const upd = () => { const d = (+$('#recv').value) - s.total; $('#chg').innerHTML = d >= 0 ? `Kembalian: <span style="color:var(--ok)">${rp(d)}</span>` : `<span style="color:var(--bad)">Kurang ${rp(-d)}</span>`; };
    $('#recv').oninput = upd; $$('[data-r]').forEach(b => b.onclick = () => { $('#recv').value = b.dataset.r; upd(); }); upd();
  } else {
    const accs = P.cat.accounts.filter(a => a.type === (P.method === 'qris' ? 'qris' : 'bank'));
    el.innerHTML = `${P.method === 'transfer' && accs.length > 1 ? `<label class="f">Rekening tujuan</label><select class="in" id="acc">${accs.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select>` : ''}
      <label class="f" style="margin-top:6px">Referensi / 4 digit terakhir (opsional)</label><input class="in" id="pref" maxlength="40">
      <label class="chk" style="margin-top:6px"><input type="checkbox" id="conf"> <b>Dana ${P.method === 'qris' ? 'QRIS' : 'transfer'} sudah diterima</b></label>`;
  }
}

async function saveSale(d) {
  try { const r = await api('pos.update', { id: P.sale.id, ...d }); P.sale = r.sale; renderCart(); } catch (e) { fail(e); renderCart(); }
}
function qty(id, d) { const s = P.sale; saveSale({ lines: s.items.map(i => ({ id: i.id, qty: i.id === id ? Math.max(0, i.qty + d) : i.qty })).filter(l => l.qty > 0 || s.items.find(i => i.id === l.id).locked) }); }
function addLine(type, id) {
  if (!P.sale) { toast('Mulai transaksi dahulu (pilih antrean atau Transaksi Baru).'); return; }
  if (type === 'member' && !P.sale.customer) { toast('Pilih pelanggan dahulu.', 'bad'); return; }
  const ex = type === 'product' ? P.sale.items.find(i => i.item_type === 'product' && i.ref_id == id) : null;
  const lines = linesOf(P.sale).map(l => ex && l.id === ex.id ? { ...l, qty: l.qty + 1 } : l);
  if (!ex) lines.push({ type, ref_id: id, qty: 1 });
  saveSale({ lines }); toast('Ditambahkan', 'ok');
}

async function pay() {
  const s = P.sale, err = $('#perr'); err.textContent = '';
  const body = { id: s.id, method: P.method, idem_key: P.idem };
  if (P.method === 'cash') body.received = +$('#recv').value; else { body.confirmed = $('#conf').checked; body.reference = $('#pref').value; if ($('#acc')) body.account_id = +$('#acc').value; }
  try { const r = await api('pos.pay', body); P.sale = r.sale; toast(r.sale.already ? 'Transaksi sudah lunas' : 'Pembayaran berhasil', 'ok'); renderCart(); loadCash(); loadQueue(); }
  catch (e) { err.textContent = e.message; if (e.network) err.textContent += ' (Aman dicoba lagi: pembayaran tidak akan ganda.)'; }
}

function renderReceipt(s) {
  const el = $('#cart');
  const wa = s.customer?.phone ? 'https://wa.me/' + s.customer.phone.replace(/^0/, '62') + '?text=' + encodeURIComponent(`Terima kasih telah berkunjung ke ND Japanese Head Spa.\nStruk ${s.receipt_no}\nTotal ${rp(s.total)}\nLihat: ${location.origin}${url('s/struk/' + s.receipt_no)}`) : '';
  el.innerHTML = `<div class="card stack"><div class="note ok" style="text-align:center"><b>✔ Pembayaran Lunas</b>${s.change_amt ? '<br>Kembalian ' + rp(s.change_amt) : ''}</div>
    <div class="receipt">${receiptHtml(s)}</div>
    <div class="row"><button class="btn" onclick="window.print()">Cetak Struk</button>${wa ? `<a class="btn ok" target="_blank" rel="noopener" href="${wa}">Kirim WhatsApp</a>` : ''}<button class="btn ghost" id="newSale">Transaksi Baru</button></div></div>`;
  $('#newSale').onclick = () => { P.sale = null; renderCart(); P.tab = 'q'; drawBody(); step(1); };
}
function receiptHtml(s) {
  return `<div class="c"><b>ND JAPANESE HEAD SPA</b><br>Perumahan Surya Praja Permai Blok C7 No.13<br>Cibinong, Bogor · WA 0851-9674-1600</div><hr>
  <div class="r"><span>${esc(s.receipt_no)}</span><span>${esc(s.paid_at.slice(0, 16))}</span></div><div>Kasir: ${esc(s.cashier_name || '')}${s.customer ? '<br>Pelanggan: ' + esc(s.customer.name) : ''}</div><hr>
  ${s.items.map(i => `<div>${esc(i.name)}</div><div class="r"><span>${i.qty} x ${rp(i.price)}</span><span>${rp(i.price * i.qty)}</span></div>`).join('')}<hr>
  <div class="r"><span>Subtotal</span><span>${rp(s.subtotal)}</span></div>${s.disc_member ? `<div class="r"><span>Diskon member</span><span>-${rp(s.disc_member)}</span></div>` : ''}${s.disc_manual ? `<div class="r"><span>Potongan</span><span>-${rp(s.disc_manual)}</span></div>` : ''}${s.disc_points ? `<div class="r"><span>Tukar poin</span><span>-${rp(s.disc_points)}</span></div>` : ''}
  <div class="r"><b>TOTAL</b><b>${rp(s.total)}</b></div><div class="r"><span>${esc(s.method_label)}</span><span>${s.cash_received ? rp(s.cash_received) : ''}</span></div>${s.change_amt ? `<div class="r"><span>Kembali</span><span>${rp(s.change_amt)}</span></div>` : ''}
  ${s.points_earned ? `<div>Poin didapat: +${s.points_earned}</div>` : ''}<hr><div class="c">Terima kasih<br>Relax • Revitalize • Rebalance</div>`;
}

// ---- Refund / koreksi ----
function refundTab(b) {
  b.innerHTML = `<div class="card stack"><b>Cari transaksi lunas</b><input class="in" id="rq" placeholder="No. struk atau nama pelanggan"><div id="rres" class="list"></div></div>`;
  let tm; $('#rq').oninput = e => { clearTimeout(tm); tm = setTimeout(async () => {
    const r = await api('pos.receipts', { q: e.target.value }).catch(() => ({ sales: [] }));
    $('#rres').innerHTML = r.sales.map(s => `<div class="row sp"><span><b>${esc(s.receipt_no)}</b> ${esc(s.customer_name || 'Umum')}<br><small>${esc(s.paid_at.slice(0, 16))} · ${rp(s.total)} · ${esc(s.status)}</small></span><button class="btn sm ghost" data-rs="${s.id}">Pilih</button></div>`).join('') || '<small class="mut">Tidak ditemukan.</small>';
    $$('[data-rs]').forEach(x => x.onclick = () => refundDlg(+x.dataset.rs));
  }, 250); };
}
async function refundDlg(id) {
  const s = (await api('pos.get', { id })).sale;
  const m = modal(`<h3>${CAN_APPROVE ? 'Refund' : 'Ajukan Refund / Koreksi'} ${esc(s.receipt_no)}</h3><p class="mut">Pilih jumlah yang dikembalikan. ${CAN_APPROVE ? 'Dieksekusi langsung (manager/admin).' : 'Menunggu persetujuan manager.'}</p>
    <div class="stack">${s.items.map(i => { const left = i.qty - i.refunded_qty; return `<div class="row sp"><span>${esc(i.name)}<br><small>net ${rp(i.net)} · sisa ${left}</small></span><input class="in" style="width:80px" type="number" min="0" max="${left}" value="0" data-ri="${i.id}" ${left ? '' : 'disabled'}></div>`; }).join('')}
    <div><label class="f">Alasan (wajib)</label><input class="in" id="rr"></div><div class="err" id="e"></div></div>
    <div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn bad" id="ok">${CAN_APPROVE ? 'Proses Refund' : 'Kirim Pengajuan'}</button></div>`);
  $('[data-x]', m).onclick = closeModal;
  $('#ok', m).onclick = ev => busy(ev.target, async () => {
    const lines = $$('[data-ri]', m).map(i => ({ sale_item_id: +i.dataset.ri, qty: +i.value })).filter(l => l.qty > 0);
    try { await api(CAN_APPROVE ? 'pos.refund_now' : 'pos.refund_request', { sale_id: id, lines, reason: $('#rr', m).value }); closeModal(); toast(CAN_APPROVE ? 'Refund diproses' : 'Pengajuan dikirim ke manager', 'ok'); loadCash(); }
    catch (e) { $('#e', m).textContent = e.message; }
  });
}

// ---- pelanggan ----
let ctm; $('#cq').oninput = e => { clearTimeout(ctm); ctm = setTimeout(async () => {
  const q = e.target.value.trim(); if (q.length < 2) { $('#cres').innerHTML = ''; return; }
  const r = await api('cust.search', { q }).catch(() => ({ customers: [] }));
  $('#cres').innerHTML = r.customers.map(c => `<div class="row sp"><span><b>${esc(c.name)}</b> <small>${esc(c.phone || '')}</small> ${c.member ? badge('active', c.member) : ''} ${c.points ? '<small>' + c.points + ' poin</small>' : ''}</span><button class="btn sm ghost" data-c="${c.id}">Pilih</button></div>`).join('') + `<div class="row sp"><small class="mut">Pelanggan baru?</small><button class="btn sm ghost" id="newC">+ Tambah</button></div>`;
  $$('[data-c]').forEach(b => b.onclick = async () => { $('#cres').innerHTML = ''; $('#cq').value = ''; if (!P.sale) await openSale({ walkin: true, customer_id: +b.dataset.c }); else saveSale({ customer_id: +b.dataset.c }); });
  $('#newC').onclick = () => { const m = modal(`<h3>Pelanggan Baru</h3><div class="stack"><input class="in" id="nn" placeholder="Nama" value="${/\d/.test(q) ? '' : esc(q)}"><input class="in" id="np" placeholder="Nomor HP" inputmode="tel" value="${/\d/.test(q) ? esc(q) : ''}"><div class="err" id="e"></div></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan & Pilih</button></div>`);
    $('[data-x]', m).onclick = closeModal;
    $('#ok', m).onclick = ev => busy(ev.target, async () => { try { const r = await api('cust.save', { name: $('#nn', m).value, phone: $('#np', m).value }); closeModal(); $('#cres').innerHTML = ''; $('#cq').value = ''; if (!P.sale) await openSale({ walkin: true, customer_id: r.id }); else saveSale({ customer_id: r.id }); } catch (x) { $('#e', m).textContent = x.message; } }); };
}, 250); };

function step(n) { $('#pos').dataset.step = n; $$('.pos-steps button').forEach(b => b.classList.toggle('on', b.dataset.s == n)); if (n === 2) window.scrollTo({ top: 0, behavior: 'smooth' }); }
$$('.pos-steps button').forEach(b => b.onclick = () => step(b.dataset.s));
$$('#ptabs button').forEach(b => b.onclick = () => { P.tab = b.dataset.t; drawBody(); });

(async () => {
  await Promise.all([loadCash(), loadCatalog(), loadQueue()]);
  drawBody();
  if (PRE_BOOKING) openSale({ booking_id: PRE_BOOKING });
  livePoll(() => { loadQueue(); if (P.sale && !P.sale.paid_at && P.sale.booking_id) api('pos.get', { id: P.sale.id }).then(r => { if (JSON.stringify(r.sale.booking) !== JSON.stringify(P.sale.booking)) { P.sale = r.sale; renderCart(); } }).catch(() => { }); }, 4000);
})();
