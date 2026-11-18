<?php
Auth::needPage('customers');
page_start(['title' => 'Pelanggan & Membership', 'area' => 'staff', 'active' => 's/pelanggan']); ?>
<div class="toolbar"><input class="in" id="q" placeholder="Cari nama / nomor HP…" style="flex:1;min-width:220px"><button class="btn" id="add">+ Pelanggan Baru</button></div>
<div class="tbl-wrap"><table class="tbl stack-m"><thead><tr><th>Nama</th><th>HP</th><th>Membership</th><th class="r">Poin</th><th></th></tr></thead><tbody id="rows"></tbody></table></div>
<?php page_end(['area' => 'staff', 'scripts' => ['customers.js'], 'inline' => 'const CAN_MEMBER=' . json_encode(Auth::can('member_manage')) . ';']); ?>
