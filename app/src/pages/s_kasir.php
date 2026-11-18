<?php
Auth::needPage('pos');
page_start(['title' => 'Kasir', 'area' => 'staff', 'active' => 's/kasir']); ?>
<div id="cashbar" class="card flat row sp" style="margin-bottom:12px;padding:10px 14px"></div>
<div class="pos-steps"><button class="on" data-s="1">1. Pilih</button><button data-s="2">2. Rincian & Bayar <span id="cartN"></span></button></div>
<div class="pos" id="pos" data-step="1">
  <div class="pos-l">
    <div class="card"><label class="f">Pelanggan</label><input class="in" id="cq" placeholder="Cari nama / nomor HP…" autocomplete="off"><div id="cres" class="list"></div></div>
    <div class="tabs" id="ptabs"><button class="on" data-t="q">Antrean</button><button data-t="s">Layanan</button><button data-t="p">Produk</button><button data-t="m">Membership</button><button data-t="r">Refund</button></div>
    <div id="pbody"></div>
  </div>
  <div class="pos-r" id="cart"><div class="card empty">Pilih reservasi dari antrean atau mulai transaksi baru.</div></div>
</div>
<?php page_end(['area' => 'staff', 'scripts' => ['pos.js'], 'inline' => 'const PRE_BOOKING=' . (int)($_GET['booking'] ?? 0) . ', CAN_APPROVE=' . json_encode(Auth::can('refund_approve')) . ';']); ?>
