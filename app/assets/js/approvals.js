/* Persetujuan manager: koreksi absensi dan refund. Semua tindakan tercatat (alasan, waktu, petugas). */
const ST2 = { izin: 'Izin', sakit: 'Sakit', cuti: 'Cuti', hadir: 'Hadir', alpa: 'Tanpa Keterangan' };
async function load() {
  try {
    const [c, r] = await Promise.all([api('corr.list', { status: 'pending' }), api('pos.refund_list', { status: 'pending' })]);
    $('#corr').innerHTML = c.rows.map(x => `<div class="stack"><div class="row sp"><span><b>${esc(x.user_name)}</b> · ${esc(x.att_date)}<br>${x.ctype === 'status' ? 'Status → <b>' + esc(ST2[x.req_status]) + '</b>' : 'Jam masuk <b>' + esc((x.req_in || '').slice(11, 16) || '-') + '</b>, pulang <b>' + esc((x.req_out || '').slice(11, 16) || '-') + '</b>'}<br><small>Alasan: ${esc(x.reason)}</small><br><small class="mut">Semula: ${esc(origTxt(x.original_json))}</small></span>${badge('pending', 'Menunggu')}</div>
      <div class="row"><button class="btn sm ok" data-c="${x.id}" data-a="1">Setujui</button><button class="btn sm bad" data-c="${x.id}" data-a="0">Tolak</button></div></div>`).join('') || '<small class="mut">Tidak ada pengajuan.</small>';
    $('#refund').innerHTML = r.rows.map(x => `<div class="stack"><div class="row sp"><span><b>${esc(x.receipt_no)}</b> · ${rp(x.amount)}<br>${esc(x.lines.join(', '))}<br><small>Alasan: ${esc(x.reason)} · diajukan ${esc(x.requester)}</small></span>${badge('pending', 'Menunggu')}</div>
      <div class="row"><button class="btn sm ok" data-r="${x.id}" data-a="1">Setujui & Proses</button><button class="btn sm bad" data-r="${x.id}" data-a="0">Tolak</button></div></div>`).join('') || '<small class="mut">Tidak ada pengajuan.</small>';
    $$('[data-c]').forEach(b => b.onclick = () => act('corr.process', +b.dataset.c, b.dataset.a === '1'));
    $$('[data-r]').forEach(b => b.onclick = () => act('pos.refund_process', +b.dataset.r, b.dataset.a === '1'));
  } catch (e) { fail(e); }
}
function origTxt(j) { try { const o = JSON.parse(j); return o ? (o.status + ' ' + (o.in_at || '-').slice(11, 16) + '–' + (o.out_at || '-').slice(11, 16)) : 'belum ada catatan'; } catch (e) { return '-'; } }
function act(ep, id, approve) {
  const m = modal(`<h3>${approve ? 'Setujui' : 'Tolak'} pengajuan</h3><label class="f">Catatan${approve ? ' (opsional)' : ' (wajib)'}</label><input class="in" id="n"><div class="err" id="e"></div><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn ${approve ? 'ok' : 'bad'}" id="ok">${approve ? 'Setujui' : 'Tolak'}</button></div>`);
  $('[data-x]', m).onclick = closeModal;
  $('#ok', m).onclick = ev => busy(ev.target, async () => {
    if (!approve && !$('#n', m).value.trim()) { $('#e', m).textContent = 'Isi alasan penolakan.'; return; }
    try { await api(ep, { id, approve, note: $('#n', m).value }); closeModal(); toast('Diproses', 'ok'); load(); } catch (e) { $('#e', m).textContent = e.message; }
  });
}
load(); livePoll(load, 8000);
