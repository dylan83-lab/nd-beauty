/* Booking pelanggan: pilih layanan → tanggal → jam (slot dihitung server) → konfirmasi. */
const S = { date: todayStr(), time: null, slot: null, therapist: '', total: 0, dur: 0 };
const itemsSel = () => $$('#pick input:checked').map(i => i.value[0] === 'p' ? { promo_item_id: +i.value.slice(1) } : { service_id: +i.value.slice(1) });

function drawDates() {
  const el = $('#dates'); let h = '';
  for (let i = 0; i < 14; i++) {
    const d = addDays(todayStr(), i), dt = new Date(d + 'T12:00:00');
    h += `<button data-d="${d}" class="${d === S.date ? 'on' : ''}"><small>${i === 0 ? 'Hari ini' : HARI[dt.getDay()]}</small><b>${dt.getDate()}</b><small>${BLN[dt.getMonth()]}</small></button>`;
  }
  el.innerHTML = h;
  $$('button', el).forEach(b => b.onclick = () => { S.date = b.dataset.d; $('#dpick').value = ''; drawDates(); loadSlots(); });
}

function summary() {
  const sel = $$('#pick input:checked');
  S.total = sel.reduce((a, i) => a + +i.dataset.price, 0); S.dur = sel.reduce((a, i) => a + +i.dataset.dur, 0);
  $('#sumtxt').textContent = sel.length ? `${sel.length} layanan · ${S.dur} menit` + (S.time ? ` · ${S.date.slice(8)}/${S.date.slice(5, 7)} ${S.time}` : '') : 'Belum ada pilihan';
  $('#sumtot').textContent = rp(S.total);
  $('#next').disabled = !(sel.length && S.time);
}

async function loadSlots() {
  S.time = null; S.slot = null; summary();
  const its = itemsSel(), box = $('#slots'), info = $('#slotinfo');
  if (!its.length) { box.innerHTML = ''; info.textContent = 'Pilih layanan terlebih dahulu.'; return; }
  info.textContent = 'Memeriksa ketersediaan…'; box.innerHTML = '';
  try {
    const r = await api('slots', { items: its, date: S.date, therapist: S.therapist || '' });
    if (r.closed) { info.textContent = (r.closed_note || 'Tutup pada tanggal ini') + '. Pilih tanggal lain.'; return; }
    const ok = r.slots.filter(s => s.free > 0).length;
    info.innerHTML = r.slots.length ? `Durasi <b>${r.duration} menit</b> · perawatan selesai sebelum ${esc(r.close)} WIB. ${ok ? '' : '<b>Semua jam penuh — coba tanggal lain.</b>'}` : 'Tidak ada jam tersedia pada tanggal ini.';
    box.innerHTML = r.slots.map(s => `<button class="slot ${s.status}" data-t="${s.time}" ${s.free ? '' : 'disabled'}><b>${s.time}</b><small>${s.status === 'tersedia' ? 'Tersedia' : s.status === 'terbatas' ? 'Terbatas' : 'Penuh'}</small></button>`).join('');
    $$('.slot', box).forEach(b => b.onclick = () => {
      $$('.slot', box).forEach(x => x.classList.remove('on')); b.classList.add('on');
      S.time = b.dataset.t; S.slot = r.slots.find(s => s.time === S.time); summary();
    });
  } catch (e) { info.textContent = e.message; }
}

function confirmModal() {
  const sel = $$('#pick input:checked');
  const body = `<h3>Konfirmasi Booking</h3>
    <div class="stack">
      <div class="card flat"><b>${sel.map(i => esc(i.dataset.name)).join(' + ')}</b><br>
        <span>${esc(tglLabel(S.date))} · ${S.time}–${S.slot.end} WIB</span><br><span class="mut">Durasi ${S.dur} menit</span>
        <div class="row sp" style="margin-top:8px"><span>Total</span><b style="font-size:1.3rem;color:var(--bur)">${rp(S.total)}</b></div></div>
      <div class="arrive">Mohon hadir di lokasi paling lambat ${CFG.arrive} menit sebelum jadwal perawatan.<br><b>Jadwal ${S.time} WIB · Harap tiba paling lambat ${S.slot.arrive_by} WIB</b></div>
      <div><label class="f">Catatan (opsional)</label><input class="in" id="cnote" maxlength="200" placeholder="Mis. kulit kepala sensitif"></div>
      <div id="authbox"></div><div class="err" id="cerr"></div>
    </div>
    <div class="acts"><button class="btn ghost" data-x>Kembali</button><button class="btn" id="cok">Konfirmasi Booking</button></div>`;
  const m = modal(body);
  $('[data-x]', m).onclick = closeModal;
  const authBox = () => {
    if (CFG.logged) return;
    if (CFG.staff) { $('#authbox', m).innerHTML = '<div class="note warn">Anda masuk sebagai pegawai. Gunakan menu Antrean → Booking Baru untuk membuat reservasi pelanggan.</div>'; $('#cok', m).disabled = true; return; }
    $('#authbox', m).innerHTML = `<div class="note"><b>Masuk atau daftar untuk menyelesaikan booking</b>
      <div class="tabs" style="margin:8px 0"><button type="button" class="on" data-m="login">Masuk</button><button type="button" data-m="reg">Daftar</button></div>
      <div class="stack"><div data-reg hidden><input class="in" id="an" placeholder="Nama lengkap" autocomplete="name"></div>
      <input class="in" id="ai" placeholder="Nomor HP / email" autocomplete="username" inputmode="tel">
      <input class="in" id="ap" type="password" placeholder="Kata sandi (min. 6)" autocomplete="current-password"></div></div>`;
    let mode = 'login';
    $$('[data-m]', m).forEach(b => b.onclick = () => { mode = b.dataset.m; $$('[data-m]', m).forEach(x => x.classList.toggle('on', x === b)); $('[data-reg]', m).hidden = mode !== 'reg'; });
    m._mode = () => mode;
  };
  authBox();
  $('#cok', m).onclick = ev => busy(ev.target, async () => {
    const err = $('#cerr', m); err.textContent = '';
    try {
      if (!CFG.logged) {
        const ident = $('#ai', m).value, password = $('#ap', m).value;
        if (m._mode() === 'reg') await api('auth.register', { name: $('#an', m).value, phone: ident, password });
        else await api('auth.login', { ident, password });
        CFG.logged = true;
      }
      const r = await api('booking.create', { items: itemsSel(), date: S.date, time: S.time, therapist_id: S.therapist || null, notes: $('#cnote', m).value });
      done(r.booking);
    } catch (e) {
      if (e.data?.slot_taken) {
        err.innerHTML = esc(e.message) + ' Pilih jam lain: ' + (e.data.alts || []).map(t => `<button class="btn sm ghost" data-alt="${t}" style="margin:2px">${t}</button>`).join(' ');
        $$('[data-alt]', m).forEach(b => b.onclick = () => { closeModal(); loadSlots().then(() => { $(`.slot[data-t="${b.dataset.alt}"]`)?.click(); }); });
        loadSlots();
      } else err.textContent = e.message;
    }
  });
}
const tglLabel = d => { const x = new Date(d + 'T12:00:00'); return ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][x.getDay()] + ', ' + x.getDate() + ' ' + BLN[x.getMonth()] + ' ' + x.getFullYear(); };

function done(b) {
  const msg = `Halo ND Japanese Head Spa, reservasi saya ${b.code}: ${b.services}, ${b.date_label} pukul ${b.start} WIB.`;
  modal(`<div style="text-align:center"><div style="font-size:3rem">🌸</div><h3>Booking Berhasil</h3>
    <p class="mut">Simpan kode booking Anda</p><p style="margin:8px 0"><span class="code" style="font-size:1.5rem">${esc(b.code)}</span></p>
    <div class="card flat" style="text-align:left"><b>${esc(b.services)}</b><br>${esc(b.date_label)} · ${esc(b.start)}–${esc(b.end)} WIB<br>${b.therapist ? 'Terapis ' + esc(b.therapist) + '<br>' : ''}<b>${rp(b.total)}</b> · bayar di kasir setelah perawatan</div>
    <div class="arrive" style="margin:12px 0;text-align:left">Jadwal ${esc(b.start)} WIB · Harap tiba paling lambat <b>${esc(b.arrive_by)}</b> WIB</div></div>
    <div class="acts" style="justify-content:center"><a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/?text=${encodeURIComponent(msg)}">Bagikan via WhatsApp</a><a class="btn" href="${url('reservasi/' + b.code)}">Reservasi Saya</a></div>`);
}

// init
$$('#pick input').forEach(i => i.addEventListener('change', () => { summary(); loadSlots(); }));
$('#ther')?.addEventListener('change', e => { S.therapist = e.target.value; loadSlots(); });
$('#dpick').addEventListener('change', e => { if (!e.target.value) return; S.date = e.target.value; drawDates(); loadSlots(); });
$('#next').onclick = () => S.time && confirmModal();
if (CFG.pre.service) { const i = $(`#pick input[value="s${CFG.pre.service}"]`); if (i && !i.disabled) i.checked = true; }
if (CFG.pre.promo) { const i = $(`#pick input[value="p${CFG.pre.promo}"]`); if (i) i.checked = true; }
drawDates(); summary(); loadSlots();
