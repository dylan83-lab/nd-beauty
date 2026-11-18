<?php
$u = Auth::needPage('attendance_self');
$role = $u['role'];
if ($role === 'terapis') {
    page_start(['title' => 'Beranda Terapis', 'area' => 'staff', 'active' => 's/']); ?>
<div class="stack">
  <div id="att"></div>
  <div class="card"><div class="row sp"><h3 style="margin:0">Jadwal Saya</h3><small id="shift"></small></div></div>
  <div id="next"></div>
  <div class="card"><h3>Pelanggan Hari Ini</h3><div class="list" id="today"></div></div>
  <div class="card"><h3>7 Hari ke Depan</h3><div class="list" id="upcoming"></div></div>
</div>
<?php page_end(['area' => 'staff', 'scripts' => ['att.js', 'work.js']]); return;
}
if ($role === 'kasir') {
    page_start(['title' => 'Beranda Kasir', 'area' => 'staff', 'active' => 's/']); ?>
<div class="grid g4" style="margin-bottom:16px">
  <a class="card" href="<?= e(base_url('s/kasir')) ?>" style="text-align:center"><?= icon('cart', 32) ?><br><b>Kasir</b><br><small>Terima pembayaran</small></a>
  <a class="card" href="<?= e(base_url('s/antrean')) ?>" style="text-align:center"><?= icon('list', 32) ?><br><b>Antrean Hari Ini</b><br><small>Check-in & booking baru</small></a>
  <a class="card" href="<?= e(base_url('s/kas')) ?>" style="text-align:center"><?= icon('wallet', 32) ?><br><b>Buka / Tutup Kas</b><br><small id="kasState">…</small></a>
  <a class="card" href="<?= e(base_url('s/absensi')) ?>" style="text-align:center"><?= icon('pin', 32) ?><br><b>Absensi</b><br><small>Masuk / pulang</small></a>
</div>
<div class="pagehead"><h2>Antrean Hari Ini</h2><button class="btn sm" id="newBk">+ Booking Baru</button></div>
<div id="queue" class="stack"></div>
<?php page_end(['area' => 'staff', 'scripts' => ['queue.js'], 'inline' => 'const Q_HOME=true; api("cash.status").then(r=>$("#kasState").textContent=r.session?"Kas terbuka":"Kas belum dibuka").catch(()=>{});']); return;
}
// Admin & manager: dashboard
page_start(['title' => 'Dashboard', 'area' => 'staff', 'active' => 's/']); ?>
<div class="pagehead"><div class="row"><button class="btn sm" id="newBk">+ Booking Baru</button><a class="btn sm ghost" href="<?= e(base_url('s/kasir')) ?>">Buka Kasir</a><a class="btn sm ghost" href="<?= e(base_url('s/kas')) ?>">Kas</a><a class="btn sm ghost" href="<?= e(base_url('s/stok')) ?>">Stok</a><a class="btn sm ghost" href="<?= e(base_url('s/laporan')) ?>">Laporan</a></div>
  <button class="icon-btn" id="refresh" title="Segarkan"><?= icon('refresh') ?></button></div>
<div id="dash"><div class="empty">Memuat…</div></div>
<?php page_end(['area' => 'staff', 'scripts' => ['queue.js', 'dash.js']]); ?>
