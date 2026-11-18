<?php
Auth::needPage('reports');
page_start(['title' => 'Laporan', 'area' => 'staff', 'active' => 's/laporan']); ?>
<div class="toolbar">
  <div><label class="f">Laporan</label><select class="in" id="type"><?php foreach (Reports::TYPES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div><label class="f">Dari</label><input type="date" class="in" id="from" value="<?= e(date('Y-m-01')) ?>"></div>
  <div><label class="f">Sampai</label><input type="date" class="in" id="to" value="<?= e(today()) ?>"></div>
  <button class="btn" id="go">Tampilkan</button>
  <a class="btn ghost" id="xlsx" href="#">⬇ Excel</a><a class="btn ghost" id="pdf" href="#">⬇ PDF</a>
</div>
<div id="sum" class="grid g3" style="margin-bottom:14px"></div>
<div class="tbl-wrap"><table class="tbl" id="tbl"></table></div>
<?php page_end(['area' => 'staff', 'scripts' => ['reports.js']]); ?>
