const ST = { hadir: 'Hadir', izin: 'Izin', sakit: 'Sakit', cuti: 'Cuti', alpa: 'Tanpa Keterangan' };
async function loadMine() {
  try {
    const r = await api('att.mine');
    $('#mine').innerHTML = r.rows.map(a => `<tr><td>${esc(a.att_date)}</td><td>${esc(a.shift_name || '-')}</td><td>${badge(a.status, ST[a.status] || a.status)}</td><td>${esc((a.in_at || '').slice(11, 16))}</td><td>${esc((a.out_at || '').slice(11, 16))}</td><td class="r">${+a.late_min || ''}</td><td class="r">${+a.early_min || ''}</td><td class="r">${+a.work_min ? Math.floor(a.work_min / 60) + 'j ' + (a.work_min % 60) + 'm' : ''}</td></tr>`).join('') || '<tr><td colspan="8" class="empty">Belum ada riwayat.</td></tr>';
    $('#mycorr').innerHTML = r.corrections.map(c => `<div class="row sp"><span><b>${esc(c.att_date)}</b> · ${c.ctype === 'status' ? 'Status ' + esc(ST[c.req_status] || c.req_status) : 'Jam ' + esc((c.req_in || '').slice(11, 16) || '-') + ' – ' + esc((c.req_out || '').slice(11, 16) || '-')}<br><small>${esc(c.reason)}${c.process_note ? ' → ' + esc(c.process_note) : ''}</small></span>${badge(c.status, { pending: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak' }[c.status])}</div>`).join('') || '<small class="mut">Belum ada pengajuan.</small>';
  } catch (e) { fail(e); }
}
async function loadAll() {
  if (!ALL) return;
  const d = $('#ad').value || todayStr();
  try {
    const r = await api('att.list', { date: d });
    $('#all').innerHTML = r.rows.map(a => `<tr><td><b>${esc(a.name)}</b><br><small>${esc(a.role)}</small></td><td>${esc(a.shift)}</td><td>${a.status ? badge(a.status, ST[a.status] || a.status) : '<small class="mut">—</small>'}</td><td>${esc(a.in)}${a.dist !== null && a.dist !== '' ? ' <small>(' + a.dist + ' m)</small>' : ''}</td><td>${esc(a.out)}</td><td class="r">${a.late || ''}</td><td class="r">${a.work ? Math.floor(a.work / 60) + 'j ' + (a.work % 60) + 'm' : ''}</td><td class="r"><button class="btn sm ghost" data-u="${a.user_id}" data-n="${esc(a.name)}">Set Status</button></td></tr>`).join('');
    $$('[data-u]').forEach(b => b.onclick = () => {
      const m = modal(`<h3>Catat Status — ${esc(b.dataset.n)}</h3><p class="mut">${esc(d)}</p><div class="stack"><select class="in" id="s"><option value="izin">Izin</option><option value="sakit">Sakit</option><option value="cuti">Cuti</option><option value="alpa">Tanpa Keterangan</option></select><input class="in" id="n" placeholder="Keterangan"><div class="err" id="e"></div></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" id="ok">Simpan</button></div>`);
      $('[data-x]', m).onclick = closeModal;
      $('#ok', m).onclick = ev => busy(ev.target, async () => { try { await api('att.setstatus', { user_id: +b.dataset.u, date: d, status: $('#s', m).value, note: $('#n', m).value }); closeModal(); toast('Status dicatat', 'ok'); loadAll(); } catch (e) { $('#e', m).textContent = e.message; } });
    });
  } catch (e) { fail(e); }
}
$('#ad') && ($('#ad').value = todayStr(), $('#ad').onchange = loadAll);
ATT.mount($('#att'), () => { loadMine(); loadAll(); });
loadMine(); loadAll(); livePoll(() => { loadMine(); loadAll(); }, 8000);
