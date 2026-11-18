<?php
Auth::needPage('queue');
page_start(['title' => 'Antrean', 'area' => 'staff', 'active' => 's/antrean']); ?>
<div class="pagehead"><div class="row"><button class="icon-btn" id="qprev" aria-label="Sebelumnya">‹</button><b id="qdateLabel" style="min-width:190px;text-align:center"></b><button class="icon-btn" id="qnext" aria-label="Berikutnya">›</button>
  <button class="btn sm ghost" id="qtoday">Hari ini</button><input type="date" class="in" id="qdate" style="width:auto"></div>
  <button class="btn" id="newBk">+ Booking Baru</button></div>
<div id="queue" class="stack"></div>
<?php page_end(['area' => 'staff', 'scripts' => ['queue.js']]); ?>
