/* Pengaturan admin: profil usaha, lokasi absensi (peta), layanan & tarif, promo, membership, pegawai, shift, kursi/ruang, hari libur, akun kas, produk. */
const TABS = [['usaha', 'Profil & Jam'], ['lokasi', 'Lokasi Absensi'], ['services', 'Layanan & Tarif'], ['promo', 'Promo'], ['plans', 'Membership'], ['users', 'Pegawai & Akses'], ['shifts', 'Shift'], ['resources', 'Kursi & Ruang'], ['holidays', 'Hari Libur'], ['accounts', 'Akun Kas'], ['products', 'Produk & Bahan']];
let CUR = location.hash.slice(1) || 'usaha';
function drawTabs() { $('#tabs').innerHTML = TABS.map(([k, l]) => `<button data-t="${k}" class="${k === CUR ? 'on' : ''}">${l}</button>`).join(''); $$('#tabs button').forEach(b => b.onclick = () => { CUR = b.dataset.t; location.hash = CUR; show(); }); }
async function show() { drawTabs(); const p = $('#panel'); p.innerHTML = '<div class="empty">Memuat…</div>'; try { const f = ({ usaha, lokasi, promo })[CUR]; if (f) await f(p); else await crud(p, CUR); } catch (e) { fail(e); } }

// ---------- Profil & jam ----------
async function usaha(p) {
  const { s } = await api('settings.get');
  const days = (s.open_days || '').split(',');
  const F = (k, l, t = 'text', extra = '') => `<div><label class="f">${l}</label><input class="in" data-k="${k}" type="${t}" value="${esc(s[k] || '')}" ${extra}></div>`;
  p.innerHTML = `<div class="grid g2"><div class="card stack"><h3>Profil Usaha</h3>${F('biz_name', 'Nama usaha')}${F('biz_tagline', 'Slogan')}<div><label class="f">Alamat</label><textarea class="in" data-k="biz_address">${esc(s.biz_address)}</textarea></div>${F('biz_wa', 'WhatsApp (08…)')}
      <div><label class="f">Cara booking (satu langkah per baris)</label><textarea class="in" data-k="howto" style="min-height:130px">${esc(s.howto)}</textarea></div>${F('receipt_footer', 'Teks kaki struk')}</div>
    <div class="card stack"><h3>Jam Operasional & Aturan</h3><div class="form-grid">${F('open_time', 'Jam buka', 'time')}${F('close_time', 'Jam tutup', 'time')}</div>
      <div><label class="f">Hari buka</label><div class="row">${[1, 2, 3, 4, 5, 6, 7].map(d => `<label class="chk"><input type="checkbox" data-day="${d}" ${days.includes(String(d)) ? 'checked' : ''}> ${['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'][d - 1]}</label>`).join('')}</div></div>
      <div class="form-grid">${F('slot_step', 'Langkah slot booking (menit)', 'number')}${F('arrive_before', 'Hadir sebelum jadwal (menit)', 'number')}${F('cancel_min_hours', 'Batas batal/ubah online (jam sebelum)', 'number')}${F('noshow_grace', 'Tandai tidak hadir setelah (menit)', 'number')}${F('max_advance_days', 'Booking maksimal (hari ke depan)', 'number')}${F('manual_disc_max_pct', 'Batas potongan manual kasir (%)', 'number')}${F('point_value', '1 poin bernilai (Rp)', 'number')}</div>
      <label class="chk"><input type="checkbox" id="tc" ${s.therapist_choose === '1' ? 'checked' : ''}> Pelanggan boleh memilih terapis</label>
      <button class="btn" id="save">Simpan Pengaturan</button><div class="err" id="e"></div></div></div>`;
  $('#save').onclick = ev => busy(ev.target, async () => {
    const d = {}; $$('[data-k]', p).forEach(i => d[i.dataset.k] = i.value);
    d.open_days = $$('[data-day]:checked', p).map(i => i.dataset.day).join(','); d.therapist_choose = $('#tc').checked ? '1' : '0';
    try { await api('settings.save', { s: d }); toast('Pengaturan disimpan', 'ok'); } catch (e) { $('#e').textContent = e.message; }
  });
}

// ---------- Lokasi absensi (peta) ----------
function loadLeaflet() {
  return new Promise((res, rej) => {
    if (window.L) return res();
    const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(l);
    const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; s.onload = res; s.onerror = () => rej(new Error('Peta gagal dimuat. Anda dapat mengisi koordinat secara manual.')); document.head.appendChild(s);
  });
}
async function lokasi(p) {
  const { s } = await api('settings.get');
  const on = s.att_enabled === '1';
  p.innerHTML = `<div class="grid g2"><div class="card stack"><h3>Titik Tempat Kerja</h3>
    <div class="note ${on ? 'ok' : 'warn'}">${on ? `Absensi <b>AKTIF</b> — diverifikasi ${esc(s.att_verified_by)} (${esc((s.att_verified_at || '').slice(0, 16))})` : 'Absensi <b>BELUM AKTIF</b>. Tentukan titik, periksa di peta, lalu centang verifikasi.'}</div>
    <div id="map" style="height:340px;border-radius:14px;border:1px solid var(--line)"></div>
    <small class="mut">Klik peta atau geser pin untuk menentukan titik. Lingkaran menunjukkan radius absensi.</small>
    <button class="btn sm ghost" id="here">📍 Gunakan lokasi saya sekarang</button></div>
    <div class="card stack"><h3>Parameter</h3><div class="form-grid"><div><label class="f">Latitude</label><input class="in" id="lat" value="${esc(s.att_lat)}" inputmode="decimal"></div><div><label class="f">Longitude</label><input class="in" id="lng" value="${esc(s.att_lng)}" inputmode="decimal"></div>
      <div><label class="f">Radius (meter)</label><input class="in" id="rad" type="number" value="${esc(s.att_radius || 100)}"></div><div><label class="f">Batas akurasi GPS (meter)</label><input class="in" id="acc" type="number" value="${esc(s.att_acc_max || 50)}"></div></div>
      <label class="chk"><input type="checkbox" id="ver" ${on ? 'checked' : ''}> <b>Saya sudah memverifikasi titik ini sesuai lokasi usaha</b></label>
      <button class="btn" id="save">Simpan & ${on ? 'Perbarui' : 'Aktifkan'} Absensi</button><div class="err" id="e"></div></div></div>`;
  const lat0 = parseFloat(s.att_lat) || parseFloat(s.biz_maps_lat) || -6.4756, lng0 = parseFloat(s.att_lng) || parseFloat(s.biz_maps_lng) || 106.8386;
  let map, mk, circ;
  const sync = (la, ln) => { $('#lat').value = (+la).toFixed(7); $('#lng').value = (+ln).toFixed(7); if (mk) { mk.setLatLng([la, ln]); circ.setLatLng([la, ln]); } $('#ver').checked = false; };
  try {
    await loadLeaflet();
    map = L.map('map').setView([lat0, lng0], 18);
    const osm = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap', referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
    const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Tiles © Esri' });
    L.control.layers({ 'Peta': osm, 'Satelit': sat }).addTo(map);
    mk = L.marker([lat0, lng0], { draggable: true }).addTo(map); circ = L.circle([lat0, lng0], { radius: +$('#rad').value || 100, color: '#7a1230', fillOpacity: .12 }).addTo(map);
    mk.on('dragend', () => { const c = mk.getLatLng(); sync(c.lat, c.lng); }); map.on('click', e => sync(e.latlng.lat, e.latlng.lng));
    $('#rad').oninput = () => circ.setRadius(+$('#rad').value || 100);
  } catch (e) { $('#map').innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; }
  $('#here').onclick = () => navigator.geolocation?.getCurrentPosition(pos => { sync(pos.coords.latitude, pos.coords.longitude); map?.setView([pos.coords.latitude, pos.coords.longitude], 19); toast('Titik diisi dari lokasi Anda (±' + Math.round(pos.coords.accuracy) + ' m). Periksa di peta.', 'ok'); }, () => toast('Lokasi tidak tersedia / izin ditolak', 'bad'), { enableHighAccuracy: true, timeout: 15000 });
  $('#save').onclick = ev => busy(ev.target, async () => { try { await api('att.config', { lat: +$('#lat').value, lng: +$('#lng').value, radius: +$('#rad').value, acc_max: +$('#acc').value, verified: $('#ver').checked }); toast($('#ver').checked ? 'Absensi diaktifkan' : 'Disimpan — belum aktif (belum diverifikasi)', 'ok'); show(); } catch (e) { $('#e').textContent = e.message; } });
}

// ---------- CRUD generik ----------
const fmtCell = (def, f, row) => {
  const v = row[f];
  if (f === 'active' || f === 'combine_promo') return +v ? badge('active', f === 'active' ? 'Aktif' : 'Ya') : badge('ended', f === 'active' ? 'Nonaktif' : 'Tidak');
  const fd = def.fields.find(x => x[0] === f);
  if (fd && fd[2] === 'select') return esc(fd[4][v] ?? v);
  if (['price', 'cost'].includes(f)) return rp(v) + (+row.price_from && f === 'price' ? ' <small>(mulai)</small>' : '');
  if (f === 'duration_min') return +v ? v + ' mnt' : '<span style="color:var(--bad)">belum diisi</span>';
  return esc(v ?? '');
};
const colLabel = (def, f) => (def.fields.find(x => x[0] === f) || [f, f])[1].replace(/ —.*$/, '');
async function crud(p, e) {
  const r = await api('crud.list', { e }), def = r.def;
  const warn = e === 'services' ? r.rows.filter(x => +x.active && !+x.duration_min).length : 0;
  p.innerHTML = `<div class="pagehead"><h2>${esc(def.title)}</h2><button class="btn" id="add">+ Tambah</button></div>${warn ? `<div class="note warn" style="margin-bottom:12px">${warn} layanan aktif belum punya durasi — belum bisa dibooking. Klik layanan untuk mengisi durasi.</div>` : ''}
    <div class="tbl-wrap"><table class="tbl stack-m"><thead><tr>${def.list.map(f => `<th>${esc(colLabel(def, f))}</th>`).join('')}<th></th></tr></thead><tbody>${r.rows.map(row => `<tr>${def.list.map(f => `<td>${fmtCell(def, f, row)}</td>`).join('')}<td class="r"><button class="btn sm ghost" data-id="${esc(row[def.pk])}">Ubah</button></td></tr>`).join('') || `<tr><td colspan="${def.list.length + 1}" class="empty">Belum ada data.</td></tr>`}</tbody></table></div>`;
  $('#add').onclick = () => form(e, def, {}, r);
  $$('[data-id]', p).forEach(b => b.onclick = () => form(e, def, r.rows.find(x => String(x[def.pk]) === b.dataset.id), r));
}
function form(e, def, row, r) {
  const isNew = !row.id && !(def.pk !== 'id' && row[def.pk]);
  const html = def.fields.map(([n, l, t, req, opt]) => {
    const v = row[n] ?? (t === 'check' && isNew ? 1 : '');
    if (t === 'textarea') return `<div style="grid-column:1/-1"><label class="f">${esc(l)}</label><textarea class="in" data-f="${n}">${esc(v)}</textarea></div>`;
    if (t === 'select') return `<div><label class="f">${esc(l)}</label><select class="in" data-f="${n}">${Object.entries(opt).map(([k, x]) => `<option value="${esc(k)}" ${k == v ? 'selected' : ''}>${esc(x)}</option>`).join('')}</select></div>`;
    if (t === 'check') return `<label class="chk" style="align-self:end"><input type="checkbox" data-f="${n}" ${+v ? 'checked' : ''}> ${esc(l)}</label>`;
    if (t === 'multi') return `<div style="grid-column:1/-1" data-skills><label class="f">${esc(l)}</label><div class="pick">${(r.services || []).map(s => `<label><input type="checkbox" data-sk="${s.id}" ${(row.skills || []).includes(s.id) ? 'checked' : ''}><span>${esc(s.name)}</span></label>`).join('')}</div></div>`;
    return `<div><label class="f">${esc(l)}${req ? ' *' : ''}</label><input class="in" data-f="${n}" type="${t === 'number' ? 'number' : t === 'password' ? 'password' : t === 'time' ? 'time' : t === 'date' ? 'date' : 'text'}" ${t === 'number' ? 'step="any"' : ''} value="${t === 'password' ? '' : esc(v)}" ${t === 'password' ? 'autocomplete="new-password"' : ''} ${def.pk === n && !isNew ? 'readonly' : ''}></div>`;
  }).join('');
  const m = modal(`<h3>${isNew ? 'Tambah' : 'Ubah'} — ${esc(def.title)}</h3><div class="form-grid">${html}</div><div class="err" id="e"></div>
    <div class="acts">${e === 'services' && !isNew ? '<button class="btn ghost" id="det">Rincian Treatment & Bahan</button>' : ''}${def.deletable && !isNew ? '<button class="btn bad" id="del">Hapus</button>' : ''}<button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan</button></div>`, 'lg');
  $('[data-x]', m).onclick = closeModal;
  $('#ok', m).onclick = ev => busy(ev.target, async () => {
    const d = { ...(isNew ? {} : { id: row.id }) };
    $$('[data-f]', m).forEach(i => d[i.dataset.f] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value);
    if ($('[data-skills]', m)) d.skills = $$('[data-sk]:checked', m).map(i => +i.dataset.sk);
    try { await api('crud.save', { e, data: d }); closeModal(); toast('Tersimpan', 'ok'); show(); } catch (x) { $('#e', m).textContent = x.message; }
  });
  $('#del', m)?.addEventListener('click', async () => { if (await confirmBox('Hapus data ini?')) try { await api('crud.delete', { e, id: String(row[def.pk]) }); closeModal(); show(); } catch (x) { fail(x); } });
  $('#det', m)?.addEventListener('click', () => svcDetail(row));
}
async function svcDetail(row) {
  const d = await api('svc.detail', { id: row.id });
  const steps = d.steps.length ? d.steps : [{ title: '', detail: '' }];
  const mats = d.materials;
  const m = modal(`<h3>${esc(row.name)} — Rincian</h3><b>Urutan treatment</b><div id="st" class="stack" style="margin:8px 0">${steps.map((s, i) => `<div class="form-grid"><input class="in" data-st="t" placeholder="Langkah ${i + 1}" value="${esc(s.title)}"><input class="in" data-st="d" placeholder="Keterangan" value="${esc(s.detail)}"></div>`).join('')}</div><button class="btn sm ghost" id="addSt">+ Langkah</button>
    <hr style="border:0;border-top:1px solid var(--line);margin:14px 0"><b>Bahan yang dipakai per layanan (mengurangi stok saat perawatan selesai)</b>
    <div id="mt" class="stack" style="margin:8px 0">${d.products.map(p => { const x = mats.find(y => y.product_id == p.id); return `<div class="row sp"><span>${esc(p.name)} <small>(${esc(p.unit)})</small></span><input class="in" style="width:100px" type="number" step="any" min="0" data-mp="${p.id}" value="${x ? x.qty : ''}" placeholder="0"></div>`; }).join('') || '<small class="mut">Belum ada bahan (tambahkan di tab Produk & Bahan).</small>'}</div>
    <div class="acts"><button class="btn ghost" data-x>Tutup</button><button class="btn" id="ok">Simpan</button></div>`, 'lg');
  $('#addSt', m).onclick = () => $('#st', m).insertAdjacentHTML('beforeend', `<div class="form-grid"><input class="in" data-st="t" placeholder="Langkah baru"><input class="in" data-st="d" placeholder="Keterangan"></div>`);
  $('[data-x]', m).onclick = closeModal;
  $('#ok', m).onclick = ev => busy(ev.target, async () => {
    const rows = $$('#st .form-grid', m).map(g => ({ title: $('[data-st=t]', g).value, detail: $('[data-st=d]', g).value }));
    const materials = $$('[data-mp]', m).map(i => ({ product_id: +i.dataset.mp, qty: +i.value })).filter(x => x.qty > 0);
    try { await api('svc.detail_save', { id: row.id, steps: rows, materials }); closeModal(); toast('Rincian disimpan', 'ok'); } catch (x) { fail(x); }
  });
}

// ---------- Promo ----------
async function promo(p) {
  const r = await api('promo.list');
  const svcOpts = sel => '<option value="">— pilih layanan —</option>' + r.services.map(s => `<option value="${s.id}" ${s.id == sel ? 'selected' : ''}>${esc(s.name)} (${rp(s.price)}, ${s.duration_min || '?'} mnt)</option>`).join('');
  p.innerHTML = `<div class="pagehead"><h2>Kampanye Promo</h2><button class="btn" id="addP">+ Promo Baru</button></div>
    <div class="note" style="margin-bottom:14px">Promo disimpan sebagai <b>draf</b> sampai Anda menetapkan layanan/varian, isi paket, dan durasi untuk tiap item. Harga promo tidak otomatis menjadi harga layanan reguler. Harga yang disetujui dibekukan pada tiap reservasi.</div>
    ${r.promos.map(pr => `<div class="card sec" style="margin-top:14px"><div class="row sp"><div><h3 style="margin:0">${esc(pr.name)} ${badge(pr.status, { draft: 'Draf', active: 'Aktif', ended: 'Selesai' }[pr.status])}</h3><small>${esc(pr.starts)} s/d ${esc(pr.ends)} · ${esc(pr.note)}</small></div>
      <div class="row"><button class="btn sm ghost" data-pe="${pr.id}">Ubah</button>${pr.status !== 'active' ? `<button class="btn sm ok" data-ps="${pr.id}" data-s="active">Aktifkan</button>` : `<button class="btn sm ghost" data-ps="${pr.id}" data-s="draft">Jadikan Draf</button>`}${pr.status !== 'ended' ? `<button class="btn sm ghost" data-ps="${pr.id}" data-s="ended">Akhiri</button>` : ''}</div></div>
      <div class="tbl-wrap" style="margin-top:10px"><table class="tbl stack-m"><thead><tr><th>Item</th><th class="r">Harga promo</th><th>Layanan / isi paket</th><th>Durasi</th><th>Siap?</th><th></th></tr></thead><tbody>${pr.items.map(it => { const ok = +it.duration_min > 0 && (+it.is_package ? it.includes : it.service_id); return `<tr><td><b>${esc(it.label)}</b>${+it.is_package ? ' <small>(paket)</small>' : ''}<br><small>${esc(it.info)}</small></td><td class="r">${rp(it.promo_price)}<br><small><s>${it.normal_price ? rp(it.normal_price) : ''}</s></small></td><td>${+it.is_package ? esc((it.includes || '').split(',').filter(Boolean).map(i => (r.services.find(s => s.id == i) || {}).name).join(' + ') || '— belum diisi —') : esc((r.services.find(s => s.id == it.service_id) || {}).name || '— belum dipilih —')}</td><td>${+it.duration_min ? it.duration_min + ' mnt' : '—'}</td><td>${ok ? badge('active', 'Siap') : badge('draft', 'Belum lengkap')}</td><td class="r"><button class="btn sm ghost" data-ie="${it.id}" data-pp="${pr.id}">Atur</button></td></tr>`; }).join('')}</tbody></table></div>
      <button class="btn sm ghost" style="margin-top:8px" data-ia="${pr.id}">+ Item</button></div>`).join('')}`;
  const pform = pr => { const m = modal(`<h3>${pr ? 'Ubah' : 'Tambah'} Promo</h3><div class="stack"><input class="in" id="n" placeholder="Nama promo" value="${esc(pr?.name || '')}"><div class="form-grid"><div><label class="f">Mulai</label><input class="in" type="date" id="s" value="${pr?.starts || ''}"></div><div><label class="f">Berakhir</label><input class="in" type="date" id="e" value="${pr?.ends || ''}"></div></div><input class="in" id="no" placeholder="Catatan" value="${esc(pr?.note || '')}"><div class="err" id="er"></div></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan</button></div>`);
    $('[data-x]', m).onclick = closeModal; $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('promo.save', { id: pr?.id, name: $('#n', m).value, starts: $('#s', m).value, ends: $('#e', m).value, note: $('#no', m).value }); closeModal(); show(); } catch (x) { $('#er', m).textContent = x.message; } }); };
  $('#addP').onclick = () => pform(null);
  $$('[data-pe]', p).forEach(b => b.onclick = () => pform(r.promos.find(x => x.id == b.dataset.pe)));
  $$('[data-ps]', p).forEach(b => b.onclick = async () => { try { await api('promo.status', { id: +b.dataset.ps, status: b.dataset.s }); toast('Status promo diperbarui', 'ok'); show(); } catch (x) { fail(x); } });
  const iform = (pid, it) => {
    const inc = (it?.includes || '').split(',').filter(Boolean).map(Number);
    const m = modal(`<h3>${it ? 'Atur' : 'Tambah'} Item Promo</h3><div class="stack"><div class="form-grid"><div><label class="f">Nama item</label><input class="in" id="l" value="${esc(it?.label || '')}"></div><div><label class="f">Harga promo (Rp)</label><input class="in" id="pp" type="number" value="${it?.promo_price || ''}"></div>
      <div><label class="f">Harga normal pembanding (Rp)</label><input class="in" id="np" type="number" value="${it?.normal_price || ''}"></div><div><label class="f">Durasi total (menit) *</label><input class="in" id="du" type="number" value="${it?.duration_min || ''}"></div></div>
      <div><label class="f">Keterangan</label><input class="in" id="in" value="${esc(it?.info || '')}"></div><label class="chk"><input type="checkbox" id="pk" ${+it?.is_package ? 'checked' : ''}> Ini paket (beberapa layanan)</label>
      <div id="one"><label class="f">Layanan/varian yang berlaku *</label><select class="in" id="sv">${svcOpts(it?.service_id)}</select></div>
      <div id="many" hidden><label class="f">Isi paket *</label><div class="pick">${r.services.map(s => `<label><input type="checkbox" data-i="${s.id}" ${inc.includes(+s.id) ? 'checked' : ''}><span>${esc(s.name)}</span></label>`).join('')}</div></div><div class="err" id="er"></div></div>
      <div class="acts">${it ? '<button class="btn bad" id="del">Hapus</button>' : ''}<button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan</button></div>`, 'lg');
    const tog = () => { $('#one', m).hidden = $('#pk', m).checked; $('#many', m).hidden = !$('#pk', m).checked; }; $('#pk', m).onchange = tog; tog();
    $('[data-x]', m).onclick = closeModal;
    $('#del', m)?.addEventListener('click', async () => { await api('promo.item_delete', { id: it.id }); closeModal(); show(); });
    $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('promo.item_save', { id: it?.id, promo_id: pid, label: $('#l', m).value, promo_price: +$('#pp', m).value, normal_price: +$('#np', m).value, duration_min: +$('#du', m).value, info: $('#in', m).value, is_package: $('#pk', m).checked ? 1 : 0, service_id: $('#sv', m).value || null, includes: $$('[data-i]:checked', m).map(i => +i.dataset.i) }); closeModal(); show(); } catch (x) { $('#er', m).textContent = x.message; } });
  };
  $$('[data-ia]', p).forEach(b => b.onclick = () => iform(+b.dataset.ia, null));
  $$('[data-ie]', p).forEach(b => b.onclick = () => iform(+b.dataset.pp, r.promos.find(x => x.id == b.dataset.pp).items.find(i => i.id == b.dataset.ie)));
}
show();
