<?php
Auth::needPage('queue');
page_start(['title' => 'Kalender Reservasi', 'area' => 'staff', 'active' => 's/kalender']); ?>
<div class="pagehead"><div class="row"><button class="icon-btn" id="qprev">‹</button><b id="qdateLabel" style="min-width:190px;text-align:center"></b><button class="icon-btn" id="qnext">›</button>
  <button class="btn sm ghost" id="qtoday">Hari ini</button><input type="date" class="in" id="qdate" style="width:auto"></div>
  <div class="legend"><span><i style="background:var(--info-bg)"></i>Dipesan</span><span><i style="background:var(--warn-bg)"></i>Check-in</span><span><i style="background:#f3b6c7"></i>Dilayani</span><span><i style="background:var(--ok-bg)"></i>Selesai</span></div></div>
<div id="cal"></div>
<?php page_end(['area' => 'staff', 'scripts' => ['calendar.js']]); ?>
