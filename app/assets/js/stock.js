/* Stok: saldo, peringatan minimum, pembelian, penyesuaian, kartu stok. */
let ACC = [];
const TYPE = { opening: 'Stok awal', purchase: 'Pembelian', sale: 'Penjualan', usage: 'Pemakaian', adjust: 'Penyesuaian', return: 'Retur/Refund' };
async function load() {
  try {
    const r = await api('stock.list'); ACC = r.accounts;
    const low = r.rows.filter(x => x.low);
    $('#lowbox').innerHTML = low.length ? `<div class="note warn" style="margin-bottom:12px"><b>⚠ ${low.length} item menipis:</b> ${low.map(x => esc(x.name) + ' (' + x.on_hand + ' ' + esc(x.unit) + ')').join(', ')}</div>` : '';
    $('#rows').innerHTML = r.rows.map(p => `<tr><td><b>${esc(p.name)}</b></td><td>${p.kind === 'jual' ? 'Dijual' : 'Bahan treatment'}</td><td class="r"><b>${p.on_hand}</b> ${esc(p.unit)}</td><td class="r">${p.min_stock}</td><td>${+p.active ? (p.low ? badge('terbatas', 'Menipis') : badge('active', 'Aman')) : badge('ended', 'Nonaktif')}</td>
      <td class="r"><button class="btn sm ghost" data-card="${p.id}" data-n="${esc(p.name)}">Kartu Stok</button>${CAN_STOCK ? ` <button class="btn sm" data-buy="${p.id}" data-n="${esc(p.name)}">Beli</button> <button class="btn sm ghost" data-adj="${p.id}" data-n="${esc(p.name)}" data-s="${p.on_hand}">Sesuaikan</button>` : ''}</td></tr>`).join('');
    $$('[data-card]').forEach(b => b.onclick = () => card(+b.dataset.card, b.dataset.n));
    $$('[data-buy]').forEach(b => b.onclick = () => buy(+b.dataset.buy, b.dataset.n));
    $$('[data-adj]').forEach(b => b.onclick = () => adj(+b.dataset.adj, b.dataset.n, b.dataset.s));
  } catch (e) { fail(e); }
}
async function card(id, n) {
  const r = await api('stock.card', { id }).catch(e => { fail(e); return null; }); if (!r) return;
  const m = modal(`<h3>Kartu Stok — ${esc(n)}</h3><div class="tbl-wrap" style="max-height:60vh"><table class="tbl"><thead><tr><th>Waktu</th><th>Jenis</th><th class="r">Jumlah</th><th>Keterangan</th><th>Petugas</th></tr></thead><tbody>${r.rows.map(x => `<tr><td>${esc(x.created_at.slice(0, 16))}</td><td>${esc(TYPE[x.move_type] || x.move_type)}</td><td class="r" style="color:${+x.qty > 0 ? 'var(--ok)' : 'var(--bad)'}">${+x.qty > 0 ? '+' : ''}${+x.qty}</td><td>${esc(x.note)}</td><td>${esc(x.user_name || '')}</td></tr>`).join('')}</tbody></table></div><div class="acts"><button class="btn" data-x>Tutup</button></div>`, 'lg');
  $('[data-x]', m).onclick = closeModal;
}
function buy(id, n) {
  const m = modal(`<h3>Pembelian — ${esc(n)}</h3><div class="stack"><div class="form-grid"><div><label class="f">Jumlah</label><input class="in" id="q" type="number" min="0" step="any"></div><div><label class="f">Harga beli per satuan (Rp)</label><input class="in" id="c" type="number" min="0"></div></div>
    <div><label class="f">Catat sebagai uang keluar dari akun (opsional)</label><select class="in" id="a"><option value="">Jangan catat di kas</option>${ACC.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select></div><div><label class="f">Keterangan</label><input class="in" id="n"></div><div class="err" id="e"></div></div>
    <div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan</button></div>`);
  $('[data-x]', m).onclick = closeModal;
  $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('stock.purchase', { product_id: id, qty: +$('#q', m).value, unit_cost: +$('#c', m).value, account_id: $('#a', m).value || null, note: $('#n', m).value }); closeModal(); toast('Pembelian dicatat', 'ok'); load(); } catch (e) { $('#e', m).textContent = e.message; } });
}
function adj(id, n, s) {
  const m = modal(`<h3>Penyesuaian — ${esc(n)}</h3><p class="mut">Sistem mencatat selisih sebagai pergerakan stok.</p><div class="stack"><div><label class="f">Stok fisik sebenarnya</label><input class="in" id="q" type="number" min="0" step="any" value="${s}"></div><div><label class="f">Alasan (wajib)</label><input class="in" id="r" placeholder="Mis. hasil stok opname"></div><div class="err" id="e"></div></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan</button></div>`);
  $('[data-x]', m).onclick = closeModal;
  $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('stock.adjust', { product_id: id, on_hand: +$('#q', m).value, reason: $('#r', m).value }); closeModal(); toast('Stok disesuaikan', 'ok'); load(); } catch (e) { $('#e', m).textContent = e.message; } });
}
load(); livePoll(load, 8000);
