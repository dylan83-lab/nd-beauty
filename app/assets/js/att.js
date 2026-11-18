/* Widget absensi: Absen Masuk / Pulang dengan lokasi (diambil hanya saat tombol ditekan). */
const ATT = {
  async mount(el, onChange) {
    el.innerHTML = '<div class="card"><small>Memuat absensi…</small></div>';
    let t;
    try { t = await api('att.today'); } catch (e) { el.innerHTML = '<div class="note bad">' + esc(e.message) + '</div>'; return; }
    const r = t.row, sh = t.shift;
    const hasIn = r && r.in_at, hasOut = r && r.out_at, off = r && ['izin', 'sakit', 'cuti'].includes(r.status);
    const fmt = s => s ? s.slice(11, 16) : '–';
    el.innerHTML = `<div class="card stack">
      <div class="row sp"><div><h3 style="margin:0">Absensi Hari Ini</h3><small>${sh ? `Shift ${esc(sh.name)} · ${esc(sh.start_time)}–${esc(sh.end_time)} WIB` : 'Tidak ada shift terjadwal hari ini'}</small></div>
        ${r ? badge(r.status, { hadir: 'Hadir', izin: 'Izin', sakit: 'Sakit', cuti: 'Cuti', alpa: 'Tanpa Keterangan' }[r.status] || r.status) : badge('draft', 'Belum absen')}</div>
      ${!t.enabled ? '<div class="note warn">Absensi belum diaktifkan. Admin perlu menetapkan dan memverifikasi titik lokasi kerja terlebih dahulu.</div>' : ''}
      <div class="grid g2">
        <button class="bigbtn in" id="aIn" ${(!t.enabled || hasIn || off) ? 'disabled' : ''}>${icon_pin()} Absen Masuk<small style="color:#fff;opacity:.85">${hasIn ? 'Pukul ' + fmt(r.in_at) + ' · ' + (r.in_dist ?? '-') + ' m' + (+r.late_min ? ' · telat ' + r.late_min + ' mnt' : '') : 'Radius ' + t.radius + ' m dari lokasi'}</small></button>
        <button class="bigbtn out" id="aOut" ${(!t.enabled || !hasIn || hasOut || off) ? 'disabled' : ''}>${icon_pin()} Absen Pulang<small style="color:#fff;opacity:.85">${hasOut ? 'Pukul ' + fmt(r.out_at) + (+r.early_min ? ' · pulang awal ' + r.early_min + ' mnt' : '') + ' · ' + Math.floor(r.work_min / 60) + 'j ' + (r.work_min % 60) + 'm' : 'Setelah absen masuk'}</small></button>
      </div>
      <div id="aMsg"></div>
      <div class="row"><button class="btn ghost sm" id="aCor">Ajukan Koreksi (lupa absen / GPS bermasalah)</button></div></div>`;
    $('#aIn', el).onclick = e => ATT.punch('in', e.target.closest('button'), el, onChange);
    $('#aOut', el).onclick = e => ATT.punch('out', e.target.closest('button'), el, onChange);
    $('#aCor', el).onclick = () => ATT.correction();
  },
  punch(type, btn, el, onChange) {
    const msg = $('#aMsg', el);
    if (!navigator.geolocation) { msg.innerHTML = '<div class="note bad">Perangkat tidak mendukung lokasi. Gunakan Ajukan Koreksi.</div>'; return; }
    busy(btn, () => new Promise(done => {
      msg.innerHTML = '<div class="note">Mengambil lokasi terbaru…</div>';
      navigator.geolocation.getCurrentPosition(async pos => {
        try {
          const r = await api('att.punch', { type, lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy });
          toast((type === 'in' ? 'Absen masuk' : 'Absen pulang') + ' berhasil · ' + r.distance + ' m dari lokasi', 'ok');
          msg.innerHTML = `<div class="note ok"><b>${type === 'in' ? 'Absen masuk' : 'Absen pulang'} tercatat ${esc(r.time.slice(11, 16))} WIB</b><br>Jarak ${r.distance} m · akurasi ±${r.accuracy} m${r.shift ? ' · shift ' + esc(r.shift) : ''}</div>`;
          setTimeout(() => { ATT.mount(el, onChange); onChange && onChange(); }, 1200);
        } catch (e) { ATT.fail(msg, e, type, el, onChange); }
        done();
      }, async err => {
        const why = err.code === 1 ? 'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini di pengaturan browser/HP, lalu coba lagi.' : err.code === 3 ? 'Waktu pengambilan lokasi habis. Pindah ke area terbuka lalu coba lagi.' : 'Lokasi tidak tersedia. Pastikan GPS aktif.';
        msg.innerHTML = `<div class="note bad">${esc(why)}<div class="row" style="margin-top:8px"><button class="btn sm" id="aRetry">Coba Lagi</button><button class="btn sm ghost" id="aCor2">Ajukan Koreksi</button></div></div>`;
        $('#aRetry', el).onclick = () => ATT.punch(type, btn, el, onChange); $('#aCor2', el).onclick = () => ATT.correction();
        done();
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }));
  },
  fail(msg, e, type, el, onChange) {
    const a = e.data?.att || {};
    let extra = '';
    if (a.reason === 'outside') extra = `<div style="font-size:1.6rem;font-weight:700;margin:6px 0">${a.distance} m <small>dari lokasi kerja (maks. ${a.radius} m)</small></div>`;
    msg.innerHTML = `<div class="note bad"><b>Absensi ditolak</b><br>${esc(e.message)}${extra}<div class="row" style="margin-top:8px"><button class="btn sm" id="aRetry">Coba Lagi</button><button class="btn sm ghost" id="aCor2">Ajukan Koreksi</button></div></div>`;
    $('#aRetry', el).onclick = () => ATT.punch(type, $(type === 'in' ? '#aIn' : '#aOut', el), el, onChange);
    $('#aCor2', el).onclick = () => ATT.correction();
  },
  correction() {
    const m = modal(`<h3>Ajukan Koreksi Absensi</h3><div class="stack">
      <div><label class="f">Tanggal</label><input type="date" class="in" id="cd" value="${todayStr()}" max="${todayStr()}"></div>
      <div><label class="f">Jenis koreksi</label><select class="in" id="ct"><option value="waktu">Jam masuk/pulang (lupa absen / GPS bermasalah)</option><option value="status">Izin / Sakit / Cuti</option></select></div>
      <div class="form-grid" id="cw"><div><label class="f">Jam masuk yang benar</label><input type="time" class="in" id="ci"></div><div><label class="f">Jam pulang yang benar</label><input type="time" class="in" id="co"></div></div>
      <div id="cs" hidden><label class="f">Status</label><select class="in" id="cst"><option value="izin">Izin</option><option value="sakit">Sakit</option><option value="cuti">Cuti</option><option value="hadir">Hadir</option></select></div>
      <div><label class="f">Alasan</label><textarea class="in" id="cr" placeholder="Jelaskan kejadiannya"></textarea></div><div class="err" id="ce"></div></div>
      <div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="cok">Kirim ke Manager</button></div>`);
    $('#ct', m).onchange = e => { $('#cw', m).hidden = e.target.value !== 'waktu'; $('#cs', m).hidden = e.target.value !== 'status'; };
    $('[data-x]', m).onclick = closeModal;
    $('#cok', m).onclick = ev => busy(ev.target, async () => {
      try {
        await api('att.correction', { date: $('#cd', m).value, ctype: $('#ct', m).value, req_in: $('#ci', m).value, req_out: $('#co', m).value, req_status: $('#cst', m).value, reason: $('#cr', m).value });
        closeModal(); toast('Pengajuan koreksi terkirim', 'ok');
      } catch (e) { $('#ce', m).textContent = e.message; }
    });
  },
};
function icon_pin() { return '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6 7-12a7 7 0 10-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>'; }
