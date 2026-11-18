/* Beranda terapis: absen, jadwal hari ini, pelanggan berikutnya, Mulai/Selesaikan Perawatan. */
async function loadWork() {
  try {
    const r = await api('work.list');
    const todayList = r.bookings.filter(b => b.date === r.today);
    const upcoming = r.bookings.filter(b => b.date !== r.today);
    const act = todayList.find(b => b.status === 'inservice') || todayList.find(b => b.status === 'checkin') || todayList.find(b => b.status === 'booked');
    $('#shift').innerHTML = r.shift ? `Shift <b>${esc(r.shift.name)}</b> · ${esc(r.shift.start)}–${esc(r.shift.end)} WIB` : 'Tidak ada shift hari ini';
    $('#next').innerHTML = act ? `<div class="next"><div class="row sp"><small>${act.status === 'inservice' ? 'SEDANG DILAYANI' : act.status === 'checkin' ? 'SUDAH CHECK-IN — SIAP DILAYANI' : 'PELANGGAN BERIKUTNYA'}</small>${badge(act.status, act.status_label)}</div>
      <div class="t" style="margin:6px 0">${esc(act.start)}</div><h3 style="margin:0">${esc(act.customer)}</h3>
      <p>${esc(act.services)}</p><p class="mut">Selesai ${esc(act.end)} WIB${act.room ? ' · ' + esc(act.room) : ''}</p>${act.notes ? `<div class="note" style="margin:8px 0">Catatan: ${esc(act.notes)}</div>` : ''}
      <div style="margin-top:10px">${act.status === 'checkin' ? `<button class="btn block ok" data-start="${act.id}">▶ Mulai Perawatan</button>` : act.status === 'inservice' ? `<button class="btn block" data-finish="${act.id}">✔ Selesaikan Perawatan</button>` : '<small class="mut">Menunggu pelanggan check-in di kasir.</small>'}</div></div>`
      : '<div class="card empty" style="padding:22px"><b>Tidak ada pelanggan menunggu</b><br><small>Jadwal hari ini kosong atau sudah selesai.</small></div>';
    $('#today').innerHTML = todayList.length ? todayList.map(b => `<div class="row sp"><div><b>${esc(b.start)}–${esc(b.end)}</b> · ${esc(b.customer)}<br><small>${esc(b.services)}</small></div>${badge(b.status, b.status_label)}</div>`).join('') : '<small class="mut">Belum ada penugasan hari ini.</small>';
    $('#upcoming').innerHTML = upcoming.length ? upcoming.map(b => `<div class="row sp"><div><b>${esc(b.date_label)} · ${esc(b.start)}</b> · ${esc(b.customer)}<br><small>${esc(b.services)}</small></div>${badge(b.status, b.status_label)}</div>`).join('') : '<small class="mut">Tidak ada penugasan 7 hari ke depan.</small>';
    $$('[data-start]').forEach(b => b.onclick = () => busy(b, async () => { try { await api('work.start', { id: +b.dataset.start }); toast('Perawatan dimulai', 'ok'); loadWork(); } catch (e) { fail(e); } }));
    $$('[data-finish]').forEach(b => b.onclick = () => finishDlg(+b.dataset.finish));
  } catch (e) { fail(e); }
}
async function finishDlg(id) {
  let mats = [];
  try { mats = (await api('work.materials', { id })).materials; } catch (e) { return fail(e); }
  const m = modal(`<h3>Selesaikan Perawatan</h3><p class="mut">Konfirmasi bahan yang dipakai (stok berkurang sekali saat disimpan). Ubah jumlah bila berbeda.</p>
    <div class="stack">${mats.length ? mats.map((x, i) => `<div class="row sp"><span>${esc(x.name)} <small>(${esc(x.unit)})</small></span><input class="in" style="width:100px" type="number" min="0" step="any" data-m="${x.product_id}" value="${x.qty}"></div>`).join('') : '<small class="mut">Tidak ada bahan terdaftar untuk layanan ini.</small>'}</div>
    <div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="fok">Selesaikan</button></div>`);
  $('[data-x]', m).onclick = closeModal;
  $('#fok', m).onclick = ev => busy(ev.target, async () => {
    try { await api('work.finish', { id, materials: $$('[data-m]', m).map(i => ({ product_id: +i.dataset.m, qty: +i.value })) }); closeModal(); toast('Perawatan selesai. Pelanggan dapat membayar di kasir.', 'ok'); loadWork(); }
    catch (e) { fail(e); }
  });
}
ATT.mount($('#att'));
loadWork();
livePoll(loadWork, 5000);
