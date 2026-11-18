/* Reservasi Saya: status berjalan, batal, jadwal ulang. Menyegarkan otomatis. */
const FLOW = ['booked', 'checkin', 'inservice', 'done'];
const FLOWL = ['Dipesan', 'Check-in', 'Dalam Perawatan', 'Selesai'];
async function load() {
  if ($('.modal-bg')) return;
  try {
    const r = await api('booking.mine');
    const el = $('#list');
    if (!r.bookings.length) { el.innerHTML = `<div class="empty"><h2>Belum ada reservasi</h2><p>Pesan jadwal perawatan pertama Anda.</p><a class="btn" href="${url('booking')}">Booking Sekarang</a></div>`; return; }
    el.innerHTML = r.bookings.map(b => {
      const idx = FLOW.indexOf(b.status);
      const steps = idx >= 0 ? `<div class="steps">${FLOW.map((s, i) => `<i class="${i <= idx ? 'on' : ''}"></i>`).join('')}</div><small class="mut">${FLOWL.join(' → ')}</small>` : '';
      const active = ['booked', 'checkin', 'inservice'].includes(b.status);
      return `<article class="card rsv" id="b${b.id}">
        <div class="top"><div><span class="code">${esc(b.code)}</span><h3 style="margin-top:6px">${esc(b.services)}</h3></div>
          <div style="text-align:right">${badge(b.status, b.status_label)}<br><span style="display:inline-block;margin-top:4px">${badge(b.pay_status, b.pay_label)}</span></div></div>
        <div><b>${esc(b.date_label)}</b> · ${esc(b.start)}–${esc(b.end)} WIB${b.therapist ? ' · Terapis ' + esc(b.therapist) : ''}</div>
        ${b.status === 'booked' ? `<div class="arrive">Jadwal ${esc(b.start)} WIB · Harap tiba paling lambat <b>${esc(b.arrive_by)}</b> WIB</div>` : ''}
        ${steps}
        <div class="row sp"><b>${rp(b.total)}</b>
        <div class="row">${b.can_change ? `<button class="btn ghost sm" data-resched="${b.id}">Jadwal Ulang</button><button class="btn ghost sm" data-cancel="${b.id}">Batalkan</button>` : (b.status === 'booked' ? `<a class="btn ghost sm" target="_blank" rel="noopener" href="https://wa.me/?text=${encodeURIComponent('Halo, saya ingin mengubah reservasi ' + b.code)}">Ubah via WhatsApp</a>` : '')}</div></div>
      </article>`;
    }).join('');
    $$('[data-cancel]').forEach(btn => btn.onclick = () => cancel(btn.dataset.cancel));
    $$('[data-resched]').forEach(btn => btn.onclick = () => resched(r.bookings.find(x => x.id == btn.dataset.resched)));
    if (FOCUS && !load.done) { load.done = 1; const f = r.bookings.find(b => b.code === FOCUS); if (f) $('#b' + f.id)?.scrollIntoView({ block: 'center' }); }
  } catch (e) { if (!e.network) fail(e); }
}
async function cancel(id) {
  if (!await confirmBox('Batalkan reservasi ini? Slot akan dibuka kembali untuk pelanggan lain.', 'Ya, batalkan')) return;
  try { await api('booking.cancel', { id: +id, reason: 'Dibatalkan pelanggan' }); toast('Reservasi dibatalkan', 'ok'); load(); } catch (e) { fail(e); }
}
function resched(b) {
  let date = b.date, time = null;
  const m = modal(`<h3>Jadwal Ulang ${esc(b.code)}</h3><p class="mut">${esc(b.services)}</p>
    <label class="f" style="margin-top:10px">Tanggal</label><input type="date" class="in" id="rd" min="${todayStr()}" value="${date}">
    <label class="f" style="margin-top:10px">Jam tersedia</label><div class="slots" id="rs"></div><div class="err" id="re"></div>
    <div class="acts"><button class="btn ghost" data-x>Tutup</button><button class="btn" id="rok" disabled>Simpan Jadwal Baru</button></div>`);
  const draw = async () => {
    $('#rs', m).innerHTML = '<small>Memuat…</small>'; time = null; $('#rok', m).disabled = true;
    try {
      const r = await api('slots', { date, booking: b.id });
      $('#rs', m).innerHTML = r.closed ? '<small>' + esc(r.closed_note || 'Tutup') + '</small>' : r.slots.map(s => `<button class="slot ${s.status}" ${s.free ? '' : 'disabled'} data-t="${s.time}"><b>${s.time}</b><small>${s.status}</small></button>`).join('') || '<small>Tidak ada jam tersedia.</small>';
      $$('.slot', m).forEach(x => x.onclick = () => { $$('.slot', m).forEach(y => y.classList.remove('on')); x.classList.add('on'); time = x.dataset.t; $('#rok', m).disabled = false; });
    } catch (e) { $('#rs', m).innerHTML = ''; $('#re', m).textContent = e.message; }
  };
  $('#rd', m).onchange = e => { date = e.target.value; draw(); };
  $('[data-x]', m).onclick = closeModal;
  $('#rok', m).onclick = async ev => busy(ev.target, async () => {
    try { await api('booking.reschedule', { id: b.id, date, time }); closeModal(); toast('Jadwal diperbarui', 'ok'); load(); }
    catch (e) { $('#re', m).textContent = e.message + (e.data?.alts?.length ? ' Pilihan: ' + e.data.alts.slice(0, 4).join(', ') : ''); draw(); }
  });
  draw();
}
load();
livePoll(load, 6000);
