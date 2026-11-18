/* Antrean hari ini (kasir/manager): check-in, pilih terapis, booking baru, jadwal ulang, status. Segar otomatis. */
const Q = { date: (typeof Q_HOME !== 'undefined') ? todayStr() : (new URLSearchParams(location.search).get('date') || todayStr()), data: null };
const canPos = ['admin', 'kasir'].includes(ND.role);

async function loadQueue() {
  if ($('.modal-bg')) return;
  try {
    Q.data = await api('queue', { date: Q.date });
    drawQueue();
  } catch (e) { if (!e.network) fail(e); }
}

function thOpts(sel) { return Q.data.therapists.map(t => `<option value="${t.id}" ${t.id == sel ? 'selected' : ''}>${esc(t.name)}</option>`).join(''); }

function drawQueue() {
  const el = $('#queue'); if (!el) return;
  const d = Q.data, isToday = d.date === todayStr();
  const lbl = $('#qdateLabel'); if (lbl) lbl.textContent = d.date_label;
  if (!d.bookings.length) { el.innerHTML = '<div class="empty card"><b>Belum ada reservasi</b><br><small>Gunakan “Booking Baru” untuk pelanggan telepon/WhatsApp atau datang langsung.</small></div>'; return; }
  el.innerHTML = d.bookings.map(b => {
    const a = [];
    if (b.status === 'booked') {
      if (isToday) a.push(`<button class="btn sm ok" data-act="checkin" data-id="${b.id}">Check-in</button>`);
      a.push(`<button class="btn sm ghost" data-act="resched" data-id="${b.id}">Jadwal Ulang</button>`, `<button class="btn sm ghost" data-act="noshow" data-id="${b.id}">Tidak Hadir</button>`);
    }
    if (['booked', 'checkin'].includes(b.status)) a.push(`<button class="btn sm ghost" data-act="ther" data-id="${b.id}">Ganti Terapis</button>`, `<button class="btn sm ghost" data-act="cancel" data-id="${b.id}">Batalkan</button>`);
    if (canPos && ['done', 'inservice', 'checkin'].includes(b.status) && b.pay_status === 'unpaid') a.push(`<a class="btn sm ${b.status === 'done' ? 'gold' : 'ghost'}" href="${url('s/kasir?booking=' + b.id)}">${b.status === 'done' ? 'Bayar' : 'Buka Transaksi'}</a>`);
    return `<article class="qcard"><div class="row sp"><div class="time">${esc(b.start)}<small class="mut" style="font-size:.8rem;font-family:var(--sans)"> – ${esc(b.end)}</small></div><div class="row">${badge(b.status, b.status_label)}${badge(b.pay_status, b.pay_label)}</div></div>
      <div><b>${esc(b.customer)}</b> <small class="mut">${esc(b.phone || '')}</small><br>${esc(b.services)}</div>
      <div class="row" style="font-size:.84rem;color:var(--mut)"><span class="code">${esc(b.code)}</span><span>Terapis: <b>${esc(b.therapist || '-')}</b></span><span>${esc(b.resource || '')}</span><span>${b.source === 'online' ? 'Online' : b.source === 'walkin' ? 'Datang langsung' : 'Telepon/WA'}</span><b style="margin-left:auto;color:var(--bur)">${rp(b.total)}</b></div>
      ${b.notes ? `<small>Catatan: ${esc(b.notes)}</small>` : ''}<div class="acts">${a.join('')}</div></article>`;
  }).join('');
  $$('[data-act]', el).forEach(b => b.onclick = () => qAct(b.dataset.act, Q.data.bookings.find(x => x.id == b.dataset.id)));
}

async function qAct(act, b) {
  if (act === 'checkin') {
    const m = modal(`<h3>Check-in ${esc(b.customer)}</h3><p class="mut">${esc(b.services)} · ${esc(b.start)} WIB</p><label class="f">Terapis yang melayani</label><select class="in" id="th">${thOpts(b.therapist_id)}</select><div class="err" id="e"></div>
      <div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn ok" id="ok">Check-in</button></div>`);
    $('[data-x]', m).onclick = closeModal;
    $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('booking.checkin', { id: b.id, therapist_id: +$('#th', m).value }); closeModal(); toast('Check-in berhasil', 'ok'); loadQueue(); } catch (e) { $('#e', m).textContent = e.message; } });
  } else if (act === 'ther') {
    const m = modal(`<h3>Ganti Terapis</h3><select class="in" id="th">${thOpts(b.therapist_id)}</select><div class="err" id="e"></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan</button></div>`);
    $('[data-x]', m).onclick = closeModal;
    $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('booking.assign', { id: b.id, therapist_id: +$('#th', m).value }); closeModal(); toast('Terapis diganti', 'ok'); loadQueue(); } catch (e) { $('#e', m).textContent = e.message; } });
  } else if (act === 'noshow') {
    if (await confirmBox(`Tandai ${b.customer} sebagai Tidak Hadir? Slot akan dibuka kembali.`)) try { await api('booking.noshow', { id: b.id }); loadQueue(); } catch (e) { fail(e); }
  } else if (act === 'cancel') {
    const m = modal(`<h3>Batalkan Reservasi</h3><p>${esc(b.customer)} · ${esc(b.services)} · ${esc(b.start)}</p><label class="f">Alasan</label><input class="in" id="r" placeholder="Mis. pelanggan berhalangan"><div class="err" id="e"></div><div class="acts"><button class="btn ghost" data-x>Kembali</button><button class="btn bad" id="ok">Batalkan Reservasi</button></div>`);
    $('[data-x]', m).onclick = closeModal;
    $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('booking.scancel', { id: b.id, reason: $('#r', m).value }); closeModal(); toast('Reservasi dibatalkan', 'ok'); loadQueue(); } catch (e) { $('#e', m).textContent = e.message; } });
  } else if (act === 'resched') reschedStaff(b);
}

function reschedStaff(b) {
  let date = b.date, time = null;
  const m = modal(`<h3>Jadwal Ulang ${esc(b.code)}</h3><p class="mut">${esc(b.customer)} · ${esc(b.services)}</p>
    <label class="f">Tanggal</label><input type="date" class="in" id="rd" min="${todayStr()}" value="${date}"><label class="f" style="margin-top:10px">Jam</label><div class="slots" id="rs"></div><div class="err" id="re"></div>
    <div class="acts"><button class="btn ghost" data-x>Tutup</button><button class="btn" id="rok" disabled>Simpan</button></div>`);
  const draw = async () => {
    time = null; $('#rok', m).disabled = true;
    try {
      const r = await api('slots', { date, booking: b.id, staff: 1 });
      $('#rs', m).innerHTML = r.closed ? '<small>Tutup pada tanggal ini.</small>' : r.slots.map(s => `<button class="slot ${s.status}" data-t="${s.time}" ${s.free ? '' : 'disabled'}><b>${s.time}</b><small>${s.status}</small></button>`).join('');
      $$('.slot', m).forEach(x => x.onclick = () => { $$('.slot', m).forEach(y => y.classList.remove('on')); x.classList.add('on'); time = x.dataset.t; $('#rok', m).disabled = false; });
    } catch (e) { $('#re', m).textContent = e.message; }
  };
  $('#rd', m).onchange = e => { date = e.target.value; draw(); };
  $('[data-x]', m).onclick = closeModal;
  $('#rok', m).onclick = ev => busy(ev.target, async () => { try { await api('booking.sreschedule', { id: b.id, date, time }); closeModal(); toast('Jadwal diperbarui', 'ok'); loadQueue(); } catch (e) { $('#re', m).textContent = e.message; draw(); } });
  draw();
}

/** Booking baru oleh kasir: telepon/WhatsApp atau datang langsung. */
async function newBooking(prefill) {
  let cat; try { cat = await api('book.catalog'); } catch (e) { return fail(e); }
  const st = { cust: null, date: todayStr(), time: null };
  const m = modal(`<h3>Booking Baru</h3><div class="stack">
    <div><label class="f">Sumber</label><select class="in" id="src"><option value="walkin">Datang langsung</option><option value="kasir">Telepon / WhatsApp</option></select></div>
    <div><label class="f">Pelanggan</label><input class="in" id="cq" placeholder="Cari nama / nomor HP…" autocomplete="off"><div id="cres" class="list" style="margin-top:6px"></div>
      <div id="cnew" class="form-grid" style="margin-top:8px" hidden><input class="in" id="nn" placeholder="Nama pelanggan baru"><input class="in" id="np" placeholder="Nomor HP" inputmode="tel"></div><div id="csel" class="note ok" hidden></div></div>
    <div><label class="f">Layanan</label><div class="pick" id="pk">${cat.items.map(i => `<label><input type="checkbox" value="${i.key}"><span><b>${esc(i.name)}</b><br><small>${i.dur} mnt</small></span><span class="p">${rp(i.price)}</span></label>`).join('')}</div></div>
    <div class="form-grid"><div><label class="f">Tanggal</label><input type="date" class="in" id="bd" min="${todayStr()}" value="${st.date}"></div>
      <div><label class="f">Terapis</label><select class="in" id="bt"><option value="">Otomatis</option>${cat.therapists.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select></div></div>
    <div><label class="f">Jam</label><div id="bi" class="mut">Pilih layanan terlebih dahulu.</div><div class="slots" id="bs"></div></div>
    <div><label class="f">Catatan</label><input class="in" id="bn" maxlength="200"></div><div class="err" id="be"></div></div>
    <div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="bok" disabled>Simpan Booking</button></div>`, 'lg');
  const sel = () => $$('#pk input:checked', m).map(i => i.value[0] === 'p' ? { promo_item_id: +i.value.slice(1) } : { service_id: +i.value.slice(1) });
  const ready = () => { $('#bok', m).disabled = !(st.time && sel().length && (st.cust || ($('#nn', m).value.trim() && $('#np', m).value.trim()))); };
  const slots = async () => {
    st.time = null; ready(); const its = sel(); const box = $('#bs', m); box.innerHTML = '';
    if (!its.length) { $('#bi', m).textContent = 'Pilih layanan terlebih dahulu.'; return; }
    try {
      const r = await api('slots', { items: its, date: st.date, therapist: $('#bt', m).value, staff: 1 });
      $('#bi', m).textContent = r.closed ? 'Tutup pada tanggal ini.' : `Durasi ${r.duration} menit · total ${rp(r.total)}`;
      box.innerHTML = r.slots.map(s => `<button class="slot ${s.status}" data-t="${s.time}" ${s.free ? '' : 'disabled'}><b>${s.now ? 'Sekarang' : s.time}</b><small>${s.now ? s.time : s.status}</small></button>`).join('');
      $$('.slot', box).forEach(x => x.onclick = () => { $$('.slot', box).forEach(y => y.classList.remove('on')); x.classList.add('on'); st.time = x.dataset.t; ready(); });
    } catch (e) { $('#bi', m).textContent = e.message; }
  };
  $$('#pk input', m).forEach(i => i.onchange = slots);
  $('#bt', m).onchange = slots; $('#bd', m).onchange = e => { st.date = e.target.value; slots(); };
  let tm; $('#cq', m).oninput = e => { clearTimeout(tm); tm = setTimeout(async () => {
    const q = e.target.value.trim(); if (q.length < 2) { $('#cres', m).innerHTML = ''; $('#cnew', m).hidden = true; return; }
    const r = await api('cust.search', { q }).catch(() => ({ customers: [] }));
    $('#cres', m).innerHTML = r.customers.map(c => `<div class="row sp"><span><b>${esc(c.name)}</b> <small>${esc(c.phone || '')}</small> ${c.member ? badge('active', c.member) : ''}</span><button class="btn sm ghost" data-c="${c.id}" data-n="${esc(c.name)}">Pilih</button></div>`).join('') || '<small class="mut">Tidak ditemukan.</small>';
    $('#cnew', m).hidden = false; $('#nn', m).value = /\d/.test(q) ? '' : q; $('#np', m).value = /\d/.test(q) ? q : '';
    $$('[data-c]', m).forEach(b => b.onclick = () => { st.cust = +b.dataset.c; $('#csel', m).hidden = false; $('#csel', m).textContent = 'Pelanggan: ' + b.dataset.n; $('#cres', m).innerHTML = ''; $('#cnew', m).hidden = true; ready(); });
    ready(); }, 250); };
  $('#nn', m).oninput = $('#np', m).oninput = () => { st.cust = null; $('#csel', m).hidden = true; ready(); };
  $('[data-x]', m).onclick = closeModal;
  $('#bok', m).onclick = ev => busy(ev.target, async () => {
    try {
      const r = await api('sbooking.create', { source: $('#src', m).value, customer_id: st.cust, name: $('#nn', m).value, phone: $('#np', m).value, items: sel(), date: st.date, time: st.time, therapist_id: $('#bt', m).value || null, notes: $('#bn', m).value });
      closeModal(); toast('Booking dibuat: ' + r.booking.code + ' · tiba paling lambat ' + r.booking.arrive_by, 'ok'); loadQueue();
    } catch (e) { $('#be', m).textContent = e.message + (e.data?.alts?.length ? ' Alternatif: ' + e.data.alts.slice(0, 5).join(', ') : ''); if (e.data?.slot_taken) slots(); }
  });
}

$('#newBk')?.addEventListener('click', () => newBooking());
$('#qprev')?.addEventListener('click', () => { Q.date = addDays(Q.date, -1); loadQueue(); });
$('#qnext')?.addEventListener('click', () => { Q.date = addDays(Q.date, 1); loadQueue(); });
$('#qtoday')?.addEventListener('click', () => { Q.date = todayStr(); loadQueue(); });
$('#qdate')?.addEventListener('change', e => { Q.date = e.target.value || todayStr(); loadQueue(); });
if ($('#queue')) { loadQueue(); livePoll(loadQueue, 4000); }
