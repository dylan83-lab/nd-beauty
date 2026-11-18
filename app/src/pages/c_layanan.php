<?php
require_once APP_ROOT . '/src/views/data.php';
$all = cat_services();
$cats = [];
foreach ($all as $s) $cats[$s['category']][] = $s;
$cur = $_GET['k'] ?? '';
page_start(['title' => 'Layanan', 'area' => 'customer', 'active' => 'layanan']); ?>
<div class="pagehead"><h2>Layanan &amp; Tarif</h2><a class="btn ghost sm" href="<?= e(base_url('bandingkan')) ?>"><?= icon('scale', 18) ?> Bandingkan Head Spa</a></div>
<div class="cat-tabs">
  <a href="<?= e(base_url('layanan')) ?>" class="<?= $cur === '' ? 'on' : '' ?>">Semua</a>
  <?php foreach (array_keys($cats) as $k): ?><a href="<?= e(base_url('layanan?k=' . rawurlencode($k))) ?>" class="<?= $cur === $k ? 'on' : '' ?>"><?= e($k) ?></a><?php endforeach; ?>
</div>
<?php foreach ($cats as $k => $list): if ($cur !== '' && $cur !== $k) continue; ?>
<section class="sec" style="margin-top:12px"><h3 style="margin-bottom:10px"><?= e($k) ?></h3>
  <div class="svc-grid">
  <?php foreach ($list as $s): $ok = (int)$s['duration_min'] > 0; ?>
    <div class="svc"><h3><?= e($s['name']) ?></h3><p><?= e($s['description']) ?></p>
      <span class="price"><?= e(price_label($s)) ?></span>
      <div class="meta"><span><?= $ok ? '⏱ ' . (int)$s['duration_min'] . ' menit' : 'Durasi segera diumumkan' ?></span></div>
      <?php if ($ok): ?><a class="btn sm" href="<?= e(base_url('booking?service=' . (int)$s['id'])) ?>">Pilih Jadwal</a>
      <?php else: ?><a class="btn sm ghost" target="_blank" rel="noopener" href="<?= e(wa_link(S('biz_wa'), 'Halo, saya ingin reservasi ' . $s['name'] . '.')) ?>">Tanya via WhatsApp</a><?php endif; ?></div>
  <?php endforeach; ?>
  </div></section>
<?php endforeach; ?>
<?php page_end(); ?>
