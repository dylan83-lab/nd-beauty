<?php
Auth::needPage('schedule');
page_start(['title' => 'Shift & Jadwal Pegawai', 'area' => 'staff', 'active' => 's/jadwal']); ?>
<div class="tabs" id="tabs"><button class="on" data-t="week">Jadwal Mingguan</button><button data-t="pattern">Pola Default</button></div>
<div class="pagehead" id="wk"><div class="row"><button class="icon-btn" id="prev">‹</button><b id="range" style="min-width:230px;text-align:center"></b><button class="icon-btn" id="next">›</button><button class="btn sm ghost" id="thisw">Minggu ini</button></div><small class="mut">Ubah shift per tanggal (menggantikan pola default). Hari libur usaha diatur di Pengaturan.</small></div>
<div id="grid"></div>
<?php page_end(['area' => 'staff', 'scripts' => ['schedule.js']]); ?>
