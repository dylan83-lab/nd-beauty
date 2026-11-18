<?php
require_once APP_ROOT . '/src/views/data.php';
$svcs = cat_services();
$promos = promo_ready();
$ther = S('therapist_choose', '1') === '1' ? DB::all("SELECT id, name FROM users WHERE role='terapis' AND active=1 ORDER BY id") : [];
$u = Auth::user();
$pre = ['service' => (int)($_GET['service'] ?? 0), 'promo' => (int)($_GET['promo'] ?? 0)];
$cfg = ['logged' => $u && $u['role'] === 'pelanggan', 'staff' => $u && Auth::isStaff(), 'name' => $u['name'] ?? '', 'arrive' => (int)S('arrive_before'), 'close' => S('close_time'), 'maxDays' => (int)S('max_advance_days', '60'), 'wa' => S('biz_wa'), 'pre' => $pre];
page_start(['title' => 'Booking', 'area' => 'customer', 'active' => 'booking']); ?>
<div class="pagehead"><h2>Pesan Jadwal</h2><span class="mut">Cek ketersediaan tanpa perlu masuk akun</span></div>

<section class="card"><h3>1. Pilih layanan</h3>
  <div class="pick" id="pick">
    <?php foreach ($promos as $p): foreach ($p['items'] as $it): ?>
      <label><input type="checkbox" name="it" value="p<?= (int)$it['id'] ?>" data-price="<?= (int)$it['promo_price'] ?>" data-dur="<?= (int)$it['duration_min'] ?>" data-name="<?= e($it['label']) ?> (Promo)">
        <span><b>🎁 <?= e($it['label']) ?></b><br><small><?= e($it['info']) ?> · <?= (int)$it['duration_min'] ?> mnt</small></span><span class="p"><?= rp($it['promo_price']) ?></span></label>
    <?php endforeach; endforeach; ?>
    <?php foreach ($svcs as $s): $ok = (int)$s['duration_min'] > 0; ?>
      <label class="<?= $ok ? '' : 'off' ?>"><input type="checkbox" name="it" value="s<?= (int)$s['id'] ?>" <?= $ok ? '' : 'disabled' ?> data-price="<?= (int)$s['price'] ?>" data-dur="<?= (int)$s['duration_min'] ?>" data-name="<?= e($s['name']) ?>">
        <span><b><?= e($s['name']) ?></b><br><small><?= $ok ? (int)$s['duration_min'] . ' mnt' : 'Durasi belum ditetapkan' ?></small></span><span class="p"><?= e(price_label($s)) ?></span></label>
    <?php endforeach; ?>
  </div>
  <p class="mut" style="margin-top:8px;font-size:.84rem">Beberapa layanan dapat digabung dalam satu kunjungan. Durasi dan harga dijumlahkan otomatis.</p>
</section>

<section class="card sec"><h3>2. Pilih tanggal</h3>
  <div class="datestrip" id="dates"></div>
  <div class="row" style="margin-top:10px"><label class="f" style="margin:0">Atau pilih tanggal lain</label><input type="date" class="in" id="dpick" style="max-width:180px" min="<?= e(today()) ?>"></div>
</section>

<section class="card sec"><h3>3. Pilih jam</h3>
  <?php if ($ther): ?><div style="max-width:320px;margin-bottom:12px"><label class="f">Terapis</label><select class="in" id="ther"><option value="">Terapis tersedia (otomatis)</option><?php foreach ($ther as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="legend" style="margin-bottom:10px"><span><i style="background:#9ed3bb"></i>Tersedia</span><span><i style="background:#e6c47a"></i>Terbatas</span><span><i style="background:#ddd"></i>Penuh</span></div>
  <div id="slotinfo" class="mut" style="margin-bottom:8px">Pilih layanan terlebih dahulu.</div>
  <div class="slots" id="slots"></div>
</section>

<div class="sticky-cta"><div class="sumbar"><div><small id="sumtxt">Belum ada pilihan</small><br><b id="sumtot">Rp0</b></div><button class="btn" id="next" disabled>Lanjut Konfirmasi</button></div></div>
<?php page_end(['scripts' => ['booking.js'], 'inline' => 'const CFG=' . json_encode($cfg) . ';']); ?>
