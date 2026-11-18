<?php
Auth::needPage('attendance_self');
page_start(['title' => 'Absensi', 'area' => 'staff', 'active' => 's/absensi']); ?>
<div class="stack">
  <div id="att"></div>
  <div class="card"><h3>Riwayat Saya</h3><div class="tbl-wrap"><table class="tbl stack-m"><thead><tr><th>Tanggal</th><th>Shift</th><th>Status</th><th>Masuk</th><th>Pulang</th><th class="r">Telat</th><th class="r">Pulang awal</th><th class="r">Durasi</th></tr></thead><tbody id="mine"></tbody></table></div>
    <h3 style="margin-top:14px">Pengajuan Koreksi Saya</h3><div class="list" id="mycorr"></div></div>
  <?php if (Auth::can('attendance_all')): ?>
  <div class="card"><div class="row sp"><h3 style="margin:0">Absensi Semua Pegawai</h3><input type="date" class="in" id="ad" style="width:auto"></div>
    <div class="tbl-wrap" style="margin-top:10px"><table class="tbl stack-m"><thead><tr><th>Pegawai</th><th>Shift</th><th>Status</th><th>Masuk</th><th>Pulang</th><th class="r">Telat</th><th class="r">Durasi</th><th></th></tr></thead><tbody id="all"></tbody></table></div></div>
  <?php endif; ?>
</div>
<?php page_end(['area' => 'staff', 'scripts' => ['att.js', 'absensi.js'], 'inline' => 'const ALL=' . json_encode(Auth::can('attendance_all')) . ';']); ?>
