/* Dashboard manager/admin: angka dari transaksi aktual, segar otomatis. */
async function loadDash() {
  try {
    const { d } = await api('dash');
    const max = Math.max(1, ...d.week.map(w => w.v));
    const need = d.pending_corrections + d.pending_refunds;
    $('#dash').innerHTML = `
    <div class="grid g4">
      <div class="stat"><small>Penjualan hari ini (neto)</small><b>${rp(d.sales_net)}</b><em>${d.refund ? 'setelah refund ' + rp(d.refund) : 'bruto ' + rp(d.sales_gross)}</em></div>
      <div class="stat"><small>Transaksi lunas</small><b>${d.paid_count}</b><em>Tunai ${rp(d.by_method.cash)} · TF ${rp(d.by_method.transfer)} · QRIS ${rp(d.by_method.qris)}</em></div>
      <div class="stat"><small>Reservasi hari ini</small><b>${d.bookings_today}</b><em>${d.by_status.booked || 0} dipesan · ${d.by_status.checkin || 0} check-in · ${d.by_status.done || 0} selesai</em></div>
      <div class="stat"><small>Sedang dilayani</small><b>${d.serving.length}</b><em>Terapis tersedia: ${d.therapists_available.length ? esc(d.therapists_available.join(', ')) : 'tidak ada'}</em></div>
      <div class="stat"><small>Uang masuk hari ini</small><b style="color:var(--ok)">${rp(d.cash_in)}</b><em>tanpa transfer antarakun</em></div>
      <div class="stat"><small>Uang keluar hari ini</small><b style="color:var(--bad)">${rp(d.cash_out)}</b><em>termasuk refund & biaya</em></div>
      <div class="stat"><small>Stok menipis</small><b>${d.low_stock.length}</b><em>${d.low_stock.slice(0, 2).map(s => esc(s.name)).join(', ') || 'semua aman'}</em></div>
      <a class="stat" href="${url('s/persetujuan')}"><small>Menunggu persetujuan</small><b>${need}</b><em>${d.pending_corrections} koreksi absen · ${d.pending_refunds} refund</em></a>
    </div>
    <div class="grid g2" style="margin-top:14px">
      <div class="card"><div class="row sp"><h3>Reservasi Berikutnya</h3><a href="${url('s/antrean')}">Semua →</a></div><div class="list">${d.upcoming.map(b => `<div class="row sp"><span><b>${esc(b.start_at.slice(11, 16))}</b> ${esc(b.customer_name)}<br><small>${esc(b.therapist_name || '-')}</small></span>${badge(b.status, b.status === 'checkin' ? 'Check-in' : 'Dipesan')}</div>`).join('') || '<small class="mut">Tidak ada antrean.</small>'}</div>
        ${d.serving.length ? '<h3 style="margin-top:14px">Sedang Dilayani</h3><div class="list">' + d.serving.map(b => `<div class="row sp"><span><b>${esc(b.customer_name)}</b><br><small>Terapis ${esc(b.therapist_name || '-')} · selesai ${esc(b.end_at.slice(11, 16))}</small></span>${badge('inservice', 'Dilayani')}</div>`).join('') + '</div>' : ''}</div>
      <div class="card"><h3>Penjualan 7 Hari</h3><div class="bar" style="margin-bottom:26px">${d.week.map(w => `<i style="height:${Math.max(3, w.v / max * 100)}%" title="${rp(w.v)}"><span>${w.d.slice(8)}</span></i>`).join('')}</div>
        <h3>Saldo Akun</h3><div class="list">${d.accounts.map(a => `<div class="row sp"><span>${esc(a.name)}</span><b>${rp(a.balance)}</b></div>`).join('')}</div></div>
      <div class="card"><div class="row sp"><h3>Transaksi Terbaru</h3><a href="${url('s/laporan')}">Laporan →</a></div><div class="list">${d.recent.map(s => `<div class="row sp"><span><b>${esc(s.receipt_no)}</b> ${esc(s.customer_name || 'Umum')}<br><small>${esc(s.paid_at.slice(11, 16))} · ${({ cash: 'Tunai', transfer: 'Transfer', qris: 'QRIS' })[s.method] || ''}</small></span><b>${rp(s.total)}</b></div>`).join('') || '<small class="mut">Belum ada transaksi hari ini.</small>'}</div></div>
      <div class="card"><h3>Perlu Perhatian</h3><div class="list">
        ${d.low_stock.map(s => `<div class="row sp"><span>${esc(s.name)}</span>${badge('terbatas', s.on_hand + ' ' + s.unit + ' (min ' + s.min_stock + ')')}</div>`).join('')}
        ${d.cash_diffs.map(c => `<div class="row sp"><span>Selisih kas ${esc(c.user_name)}</span><a href="${url('s/kas')}">${rp(c.diff)} · periksa</a></div>`).join('')}
        ${(!d.low_stock.length && !d.cash_diffs.length) ? '<small class="mut">Tidak ada peringatan.</small>' : ''}</div></div>
    </div>`;
  } catch (e) { if (!e.network) fail(e); }
}
$('#refresh')?.addEventListener('click', loadDash);
loadDash(); livePoll(loadDash, 6000);
