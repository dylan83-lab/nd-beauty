<?php
require_once APP_ROOT . '/src/views/data.php';
$svc = array_slice(array_values(array_filter(cat_services(), fn($s) => (int)$s['duration_min'] > 0)), 0, 6);
$promos = promo_ready();
$wa = S('biz_wa');
page_start(['title' => 'Beranda', 'area' => 'customer', 'active' => '']); ?>
<section class="hero">
  <div>
    <span class="promo">🌸 <?= e(S('biz_tagline')) ?></span>
    <h1 style="margin:10px 0">Japanese Head Spa<br>&amp; Perawatan Kecantikan</h1>
    <p class="mut">Pesan jadwal dari HP, datang, dilayani terapis berpengalaman, lalu bayar di kasir. Satu reservasi untuk semuanya.</p>
  </div>
  <div class="row">
    <a class="btn" href="<?= e(base_url('booking')) ?>">Booking Sekarang</a>
    <a class="btn ghost" href="<?= e(base_url('layanan')) ?>">Lihat Layanan</a>
    <a class="btn ghost" href="<?= e(base_url('bandingkan')) ?>">Bandingkan Head Spa</a>
  </div>
</section>

<?php foreach ($promos as $p): ?>
<section class="sec"><h2>🎁 <?= e($p['name']) ?></h2>
  <p class="mut" style="margin-bottom:10px">Periode <?= e(tgl_id($p['starts'], false)) ?> – <?= e(tgl_id($p['ends'], false)) ?></p>
  <div class="svc-grid">
  <?php foreach ($p['items'] as $it): ?>
    <div class="svc"><h3><?= e($it['label']) ?></h3><p><?= e($it['info']) ?></p>
      <div><s class="mut"><?= $it['normal_price'] ? rp($it['normal_price']) : '' ?></s> <span class="price"><?= rp($it['promo_price']) ?></span></div>
      <div class="meta"><span>⏱ <?= (int)$it['duration_min'] ?> menit</span></div>
      <a class="btn sm" href="<?= e(base_url('booking?promo=' . (int)$it['id'])) ?>">Pilih Jadwal</a></div>
  <?php endforeach; ?>
  </div></section>
<?php endforeach; ?>

<section class="sec"><h2>Layanan Populer</h2>
  <div class="svc-grid">
  <?php foreach ($svc as $s): ?>
    <div class="svc"><h3><?= e($s['name']) ?></h3><p><?= e($s['description']) ?></p>
      <span class="price"><?= e(price_label($s)) ?></span>
      <div class="meta"><span>⏱ <?= (int)$s['duration_min'] ?> menit</span></div>
      <a class="btn sm" href="<?= e(base_url('booking?service=' . (int)$s['id'])) ?>">Pilih Jadwal</a></div>
  <?php endforeach; ?>
  </div>
  <p style="margin-top:12px"><a href="<?= e(base_url('layanan')) ?>">Lihat semua layanan →</a></p>
</section>

<section class="sec grid g2">
  <div class="card"><h3>Cara Booking</h3>
    <ol style="padding-left:20px;font-size:.92rem"><?php foreach (array_filter(array_map('trim', explode("\n", S('howto')))) as $l): ?><li><?= e(preg_replace('/^\d+\.\s*/', '', $l)) ?></li><?php endforeach; ?></ol>
    <div class="arrive" style="margin-top:12px">Mohon hadir di lokasi paling lambat <?= (int)S('arrive_before') ?> menit sebelum jadwal perawatan.</div></div>
  <div class="card"><h3>Lokasi &amp; Kontak</h3>
    <p style="margin-bottom:6px"><?= e(S('biz_address')) ?></p>
    <p class="mut" style="margin-bottom:12px">Buka Senin–Minggu, <?= e(S('open_time')) ?>–<?= e(S('close_time')) ?> WIB</p>
    <div class="row"><a class="btn sm" target="_blank" rel="noopener" href="<?= e(maps_url()) ?>"><?= icon('pin', 18) ?> Petunjuk Lokasi</a>
    <a class="btn sm ok" target="_blank" rel="noopener" href="<?= e(wa_link($wa, 'Halo ND Japanese Head Spa, saya ingin bertanya.')) ?>"><?= icon('phone', 18) ?> Hubungi WhatsApp</a></div></div>
</section>
<?php page_end(); ?>
