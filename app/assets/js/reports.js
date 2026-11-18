/* Laporan: filter tanggal, tampilan tabel, ekspor Excel/PDF. */
const q = () => ({ type: $('#type').value, from: $('#from').value, to: $('#to').value });
function links() { const p = new URLSearchParams(q()); $('#xlsx').href = url('api/report?' + p + '&format=xlsx'); $('#pdf').href = url('api/report?' + p + '&format=pdf'); }
async function run() {
  links();
  try {
    const { report: r } = await api('report', q());
    $('#sum').innerHTML = Object.entries(r.summary || {}).map(([k, v]) => `<div class="stat"><small>${esc(k)}</small><b style="font-size:1.25rem">${esc(v)}</b></div>`).join('');
    const money = new Set(r.money || []);
    const fmt = (v, i) => typeof v === 'number' ? (money.has(i) ? rp(v) : v.toLocaleString('id-ID')) : esc(v);
    $('#tbl').innerHTML = `<thead><tr>${r.cols.map((c, i) => `<th class="${money.has(i) ? 'r' : ''}">${esc(c)}</th>`).join('')}</tr></thead><tbody>${r.rows.map(row => `<tr>${row.map((v, i) => `<td class="${money.has(i) || typeof v === 'number' ? 'r' : ''}">${fmt(v, i)}</td>`).join('')}</tr>`).join('') || `<tr><td colspan="${r.cols.length}" class="empty">Tidak ada data pada periode ini.</td></tr>`}</tbody>${r.total ? `<tfoot><tr>${r.total.map((v, i) => `<td class="${money.has(i) ? 'r' : ''}">${fmt(v, i)}</td>`).join('')}</tr></tfoot>` : ''}`;
  } catch (e) { fail(e); }
}
['type', 'from', 'to'].forEach(i => $('#' + i).onchange = run); $('#go').onclick = run;
run();
