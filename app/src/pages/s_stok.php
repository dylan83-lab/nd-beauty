<?php
Auth::needPage('stock_view');
page_start(['title' => 'Stok', 'area' => 'staff', 'active' => 's/stok']); ?>
<div id="lowbox"></div>
<div class="tbl-wrap"><table class="tbl stack-m"><thead><tr><th>Produk / Bahan</th><th>Jenis</th><th class="r">Stok</th><th class="r">Minimum</th><th>Status</th><th></th></tr></thead><tbody id="rows"></tbody></table></div>
<?php page_end(['area' => 'staff', 'scripts' => ['stock.js'], 'inline' => 'const CAN_STOCK=' . json_encode(Auth::can('stock_manage')) . ';']); ?>
