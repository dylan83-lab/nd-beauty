/* Shift & jadwal: ubah per tanggal, atau atur pola mingguan default. */
let from = null, TABX = 'week', D = null;
const DAYN = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
async function load() {
  try { D = await api('sched.get', from ? { from } : {}); from = D.from; draw(); } catch (e) { fail(e); }
}
function opts(sel) { return `<option value="0" ${!sel ? 'selected' : ''}>Libur</option>` + D.shifts.map(s => `<option value="${s.id}" ${s.id == sel ? 'selected' : ''}>${esc(s.name)} ${s.start_time}–${s.end_time}</option>`).join(''); }
function draw() {
  $('#wk').hidden = TABX !== 'week';
  $$('#tabs button').forEach(b => b.classList.toggle('on', b.dataset.t === TABX));
  const hol = new Set(D.holidays.map(h => h.hdate));
  if (TABX === 'week') {
    $('#range').textContent = D.days[0].slice(8) + ' ' + BLN[+D.days[0].slice(5, 7) - 1] + ' – ' + D.days[6].slice(8) + ' ' + BLN[+D.days[6].slice(5, 7) - 1] + ' ' + D.days[6].slice(0, 4);
    $('#grid').innerHTML = `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pegawai</th>${D.days.map((d, i) => `<th>${DAYN[i]} ${d.slice(8)}${hol.has(d) ? ' 🚫' : ''}</th>`).join('')}</tr></thead><tbody>${D.users.map(u => `<tr><td><b>${esc(u.name)}</b><br><small>${esc(u.role)}</small></td>${D.days.map(d => `<td><select class="in" style="min-width:130px;min-height:38px" data-u="${u.id}" data-d="${d}">${opts(D.grid[u.id][d])}</select></td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    $$('select[data-u]').forEach(s => s.onchange = async () => { try { await api('sched.set', { user_id: +s.dataset.u, date: s.dataset.d, shift_id: +s.value }); toast('Jadwal disimpan', 'ok'); } catch (e) { fail(e); load(); } });
  } else {
    $('#grid').innerHTML = `<div class="note" style="margin-bottom:10px">Pola default berlaku setiap minggu bila tidak ada perubahan per tanggal.</div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pegawai</th>${DAYN.map(d => `<th>${d}</th>`).join('')}</tr></thead><tbody>${D.users.map(u => `<tr><td><b>${esc(u.name)}</b><br><small>${esc(u.role)}</small></td>${[1, 2, 3, 4, 5, 6, 7].map(w => `<td><select class="in" style="min-width:130px;min-height:38px" data-pu="${u.id}" data-w="${w}">${opts((D.pattern[u.id] || {})[w] || 0)}</select></td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    $$('select[data-pu]').forEach(s => s.onchange = async () => { try { await api('sched.pattern', { user_id: +s.dataset.pu, weekday: +s.dataset.w, shift_id: +s.value }); toast('Pola disimpan', 'ok'); } catch (e) { fail(e); load(); } });
  }
}
$$('#tabs button').forEach(b => b.onclick = () => { TABX = b.dataset.t; draw(); });
$('#prev').onclick = () => { from = addDays(from, -7); load(); }; $('#next').onclick = () => { from = addDays(from, 7); load(); }; $('#thisw').onclick = () => { from = null; load(); };
load();
