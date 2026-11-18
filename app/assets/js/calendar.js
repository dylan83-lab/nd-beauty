/* Kalender reservasi per terapis (garis waktu harian). */
const C = { date: new URLSearchParams(location.search).get('date') || todayStr() };
const PX = 1; // 1 px per menit → 60px per jam
const mins = hm => { const [h, m] = hm.split(':'); return (+h) * 60 + (+m); };
async function loadCal() {
  if ($('.modal-bg')) return;
  try {
    const d = await api('queue', { date: C.date });
    $('#qdateLabel').textContent = d.date_label;
    const o = mins(d.open), c = mins(d.close), H = (c - o) * PX;
    const th = d.therapists, cols = `60px repeat(${th.length || 1}, minmax(150px,1fr))`;
    let hours = ''; for (let t = o; t <= c; t += 60) hours += `<span style="top:${(t - o) * PX}px">${String(Math.floor(t / 60)).padStart(2, '0')}:00</span>`;
    let body = `<div class="cal-time" style="height:${H}px">${hours}</div>`;
    th.forEach(t => {
      let off = '';
      if (!t.shift) off = `<div class="cal-off" style="top:0;height:${H}px"></div>`;
      else {
        const s = Math.max(o, mins(t.shift.start)), e = Math.min(c, mins(t.shift.end));
        off += `<div class="cal-off" style="top:0;height:${(s - o) * PX}px"></div><div class="cal-off" style="top:${(e - o) * PX}px;height:${(c - e) * PX}px"></div>`;
        if (t.shift.bs) off += `<div class="cal-off" style="top:${(mins(t.shift.bs) - o) * PX}px;height:${(mins(t.shift.be) - mins(t.shift.bs)) * PX}px"></div>`;
      }
      const bl = d.bookings.filter(b => b.therapist_id === t.id && !['cancelled', 'noshow'].includes(b.status)).map(b => {
        const top = (mins(b.start) - o) * PX, h = (mins(b.end) - mins(b.start)) * PX;
        return `<div class="cal-blk s-${b.status}" style="top:${top}px;height:${h - 2}px" data-id="${b.id}" title="${esc(b.services)}"><b>${esc(b.start)}–${esc(b.end)}</b> ${esc(b.customer)}<br>${esc(b.services)}</div>`;
      }).join('');
      body += `<div class="cal-col" style="height:${H}px">${off}${bl}</div>`;
    });
    $('#cal').innerHTML = `<div class="cal" style="grid-template-columns:${cols}"><div class="cal-head" style="display:contents"><div></div>${th.map(t => `<div>${esc(t.name)}<br><small>${t.shift ? esc(t.shift.name) + ' ' + t.shift.start + '–' + t.shift.end : 'Libur'}</small></div>`).join('')}</div>${body}</div>`;
    $$('.cal-blk').forEach(x => x.onclick = () => { const b = d.bookings.find(y => y.id == x.dataset.id); modal(`<h3>${esc(b.customer)}</h3><p>${esc(b.services)}</p><p>${esc(b.start)}–${esc(b.end)} WIB · Terapis ${esc(b.therapist || '-')} · ${esc(b.resource || '')}</p><p>${badge(b.status, b.status_label)} ${badge(b.pay_status, b.pay_label)}</p><p class="mut">Kode ${esc(b.code)} · ${esc(b.phone || '')}</p><div class="acts"><a class="btn ghost" href="${url('s/antrean?date=' + b.date)}">Buka di Antrean</a><button class="btn" onclick="closeModal()">Tutup</button></div>`); });
  } catch (e) { if (!e.network) fail(e); }
}
$('#qprev').onclick = () => { C.date = addDays(C.date, -1); loadCal(); };
$('#qnext').onclick = () => { C.date = addDays(C.date, 1); loadCal(); };
$('#qtoday').onclick = () => { C.date = todayStr(); loadCal(); };
$('#qdate').onchange = e => { C.date = e.target.value || todayStr(); loadCal(); };
loadCal(); livePoll(loadCal, 5000);
