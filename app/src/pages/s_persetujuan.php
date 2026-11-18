<?php
Auth::needPage('corrections');
page_start(['title' => 'Persetujuan', 'area' => 'staff', 'active' => 's/persetujuan']); ?>
<div class="grid g2">
  <div class="card"><h3>Koreksi Absensi</h3><div class="list" id="corr"></div></div>
  <div class="card"><h3>Refund / Koreksi Transaksi</h3><div class="list" id="refund"></div></div>
</div>
<?php page_end(['area' => 'staff', 'scripts' => ['approvals.js']]); ?>
