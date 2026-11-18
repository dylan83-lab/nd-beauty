<?php
Auth::needPage('refund_request');
$s = Pos::receiptByNo((string)($GLOBALS['route_arg'] ?? ''));
page_start(['title' => 'Struk', 'area' => 'staff', 'active' => 's/kasir']);
if (!$s): ?><div class="empty card"><h2>Struk tidak ditemukan</h2></div>
<?php else: ?>
<div class="receipt">
  <div class="c"><b>ND JAPANESE HEAD SPA</b><br><?= e(S('biz_address')) ?><br>WA <?= e(S('biz_wa')) ?></div><hr>
  <div class="r"><span><?= e($s['receipt_no']) ?></span><span><?= e(substr((string)$s['paid_at'], 0, 16)) ?></span></div>
  <div>Kasir: <?= e($s['cashier_name']) ?><?= $s['customer'] ? '<br>Pelanggan: ' . e($s['customer']['name']) : '' ?></div><hr>
  <?php foreach ($s['items'] as $i): ?><div><?= e($i['name']) ?></div><div class="r"><span><?= (int)$i['qty'] ?> x <?= rp($i['price']) ?></span><span><?= rp($i['price'] * $i['qty']) ?></span></div><?php endforeach; ?><hr>
  <div class="r"><span>Subtotal</span><span><?= rp($s['subtotal']) ?></span></div>
  <?php if ($s['disc_member']): ?><div class="r"><span>Diskon member</span><span>-<?= rp($s['disc_member']) ?></span></div><?php endif; ?>
  <?php if ($s['disc_manual']): ?><div class="r"><span>Potongan</span><span>-<?= rp($s['disc_manual']) ?></span></div><?php endif; ?>
  <?php if ($s['disc_points']): ?><div class="r"><span>Tukar poin</span><span>-<?= rp($s['disc_points']) ?></span></div><?php endif; ?>
  <div class="r"><b>TOTAL</b><b><?= rp($s['total']) ?></b></div>
  <div class="r"><span><?= e($s['method_label']) ?></span><span><?= $s['cash_received'] ? rp($s['cash_received']) : '' ?></span></div>
  <?php if ($s['change_amt']): ?><div class="r"><span>Kembali</span><span><?= rp($s['change_amt']) ?></span></div><?php endif; ?>
  <hr><div class="c"><?= e(S('receipt_footer')) ?></div>
</div>
<p style="text-align:center;margin-top:14px"><button class="btn" onclick="window.print()">Cetak</button></p>
<?php endif;
page_end(['area' => 'staff']); ?>
