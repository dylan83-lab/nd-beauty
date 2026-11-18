<?php
$jp = DB::one("SELECT * FROM services WHERE name='Japanese Head Spa'");
$kr = DB::one("SELECT * FROM services WHERE name='Korean Head Spa'");
$steps = fn($s) => $s ? DB::all('SELECT title, detail FROM service_steps WHERE service_id=? ORDER BY step_no', [$s['id']]) : [];
$mj = json_decode((string)($jp['meta'] ?? ''), true) ?: []; $mk = json_decode((string)($kr['meta'] ?? ''), true) ?: [];
page_start(['title' => 'Bandingkan Head Spa', 'area' => 'customer', 'active' => 'layanan']); ?>
<div class="pagehead"><h2>Japanese vs Korean Head Spa</h2></div>
<p class="mut" style="margin-bottom:14px">Pilih perawatan yang paling sesuai kebutuhan Anda.</p>
<div class="cmp">
  <?php foreach ([['jp', $jp, $mj, 'Japanese Head Spa'], ['kr', $kr, $mk, 'Korean Head Spa']] as [$cls, $s, $m, $name]): if (!$s) continue; ?>
  <div class="col <?= $cls ?>">
    <h3><?= e($name) ?></h3>
    <div class="big"><?= e(rp($s['price'])) ?></div>
    <p class="mut" style="margin-bottom:8px">⏱ Durasi <?= (int)$s['duration_min'] ?> menit</p>
    <p style="font-size:.9rem;margin-bottom:10px"><?= e($s['description']) ?></p>
    <b style="font-size:.9rem">Rangkaian Treatment</b>
    <ol><?php foreach ($steps($s) as $st): ?><li><b><?= e($st['title']) ?></b><span><?= e($st['detail']) ?></span></li><?php endforeach; ?></ol>
    <b style="font-size:.9rem">Manfaat</b>
    <ul style="padding-left:18px;font-size:.86rem;margin:4px 0 12px"><?php foreach (($m['manfaat'] ?? []) as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
    <a class="btn block" href="<?= e(base_url('booking?service=' . (int)$s['id'])) ?>">Pilih Jadwal <?= e($name) ?></a>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($jp && $kr): ?>
<h3 style="margin-top:20px">Perbedaan Utama</h3>
<div class="diff">
  <?php foreach ([['Filosofi', 'filosofi'], ['Teknik pijat', 'teknik'], ['Fokus utama', 'fokus'], ['Kesan setelah treatment', 'kesan']] as [$l, $k]): ?>
  <div><span><?= e($mj[$k] ?? '') ?></span><span><?= e($l) ?></span><span><?= e($mk[$k] ?? '') ?></span></div>
  <?php endforeach; ?>
  <div><span><?= e(rp($jp['price'])) ?> · <?= (int)$jp['duration_min'] ?> menit</span><span>Harga &amp; durasi</span><span><?= e(rp($kr['price'])) ?> · <?= (int)$kr['duration_min'] ?> menit</span></div>
</div>
<?php endif; ?>
<?php page_end(); ?>
