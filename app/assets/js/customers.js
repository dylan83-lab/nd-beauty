/* Pelanggan: pencarian, profil, riwayat, membership & poin. */
async function search() {
  try {
    const r = await api('cust.search', { q: $('#q').value });
    $('#rows').innerHTML = r.customers.map(c => `<tr><td><b>${esc(c.name)}</b></td><td>${esc(c.phone || '-')}</td><td>${c.member ? badge('active', c.member) : '<small class="mut">Bukan member</small>'}</td><td class="r">${c.points || 0}</td><td class="r"><button class="btn sm ghost" data-c="${c.id}">Detail</button></td></tr>`).join('') || '<tr><td colspan="5" class="empty">Tidak ada pelanggan.</td></tr>';
    $$('[data-c]').forEach(b => b.onclick = () => detail(+b.dataset.c));
  } catch (e) { fail(e); }
}
async function detail(id) {
  let r; try { r = await api('cust.get', { id }); } catch (e) { return fail(e); }
  const c = r.customer, m = c.member;
  const mdlg = modal(`<h3>${esc(c.name)}</h3>
    <div class="grid g2"><div class="stack"><div><label class="f">Nama</label><input class="in" id="n" value="${esc(c.name)}"></div><div><label class="f">Nomor HP</label><input class="in" id="p" value="${esc(c.phone || '')}"></div>
      <div><label class="f">Email</label><input class="in" id="e" value="${esc(c.email || '')}"></div><div><label class="f">Catatan</label><input class="in" id="no" value="${esc(c.notes || '')}"></div><button class="btn sm" id="save">Simpan Data</button></div>
    <div class="stack"><div class="note ${m.active ? 'ok' : ''}">${m.active ? `<b>${esc(m.plan.name)}</b> aktif sampai ${esc(m.until)}<br>Diskon ${m.plan.discount_pct}% · ${m.plan.points_per_10k} poin/Rp10.000 · ${m.plan.combine_promo == 1 ? 'dapat digabung promo' : 'tidak digabung promo'}` : 'Bukan member aktif.'}</div>
      <div class="stat" style="box-shadow:none"><small>Poin</small><b>${c.points}</b></div>
      ${CAN_MEMBER ? `<div class="row"><select class="in" id="plan" style="flex:1">${r.plans.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select><button class="btn sm gold" id="act">Aktifkan / Perpanjang</button>${m.plan ? '<button class="btn sm ghost" id="rm">Hapus</button>' : ''}</div>` : '<small class="mut">Aktivasi membership dilakukan lewat Kasir (tambah item Membership) atau manager.</small>'}</div></div>
    <h3 style="margin-top:14px">Riwayat Kunjungan</h3><div class="list" style="max-height:180px;overflow:auto">${r.bookings.map(b => `<div class="row sp"><span><b>${esc(b.services)}</b><br><small>${esc(b.date)} ${esc(b.start)} · ${esc(b.code)}</small></span><span>${badge(b.status, b.status_label)} ${badge(b.pay_status, b.pay_label)}</span></div>`).join('') || '<small class="mut">Belum ada.</small>'}</div>
    <h3 style="margin-top:14px">Transaksi</h3><div class="list" style="max-height:140px;overflow:auto">${r.sales.map(s => `<div class="row sp"><a href="${url('s/struk/' + s.receipt_no)}">${esc(s.receipt_no)}</a><span>${esc(s.paid_at.slice(0, 10))}</span><b>${rp(s.total)}</b></div>`).join('') || '<small class="mut">Belum ada.</small>'}</div>
    <div class="acts"><button class="btn" data-x>Tutup</button></div>`, 'lg');
  $('[data-x]', mdlg).onclick = closeModal;
  $('#save', mdlg).onclick = ev => busy(ev.target, async () => { try { await api('cust.save', { id, name: $('#n', mdlg).value, phone: $('#p', mdlg).value, email: $('#e', mdlg).value, notes: $('#no', mdlg).value }); toast('Tersimpan', 'ok'); search(); } catch (e) { fail(e); } });
  $('#act', mdlg)?.addEventListener('click', ev => busy(ev.target, async () => { try { await api('cust.member', { id, plan_id: +$('#plan', mdlg).value }); toast('Membership aktif', 'ok'); detail(id); search(); } catch (e) { fail(e); } }));
  $('#rm', mdlg)?.addEventListener('click', async () => { if (await confirmBox('Hapus membership pelanggan ini?')) { try { await api('cust.member', { id, remove: 1 }); detail(id); search(); } catch (e) { fail(e); } } });
}
$('#add').onclick = () => {
  const m = modal(`<h3>Pelanggan Baru</h3><div class="stack"><input class="in" id="n" placeholder="Nama"><input class="in" id="p" placeholder="Nomor HP" inputmode="tel"><input class="in" id="e" placeholder="Email (opsional)"><div class="err" id="er"></div></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan</button></div>`);
  $('[data-x]', m).onclick = closeModal;
  $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('cust.save', { name: $('#n', m).value, phone: $('#p', m).value, email: $('#e', m).value }); closeModal(); toast('Pelanggan ditambahkan', 'ok'); search(); } catch (e) { $('#er', m).textContent = e.message; } });
};
let tm; $('#q').oninput = () => { clearTimeout(tm); tm = setTimeout(search, 250); };
search();
