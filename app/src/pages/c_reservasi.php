<?php
$u = Auth::user();
if (!$u) { header('Location: ' . base_url('login?next=' . rawurlencode(base_url('reservasi')))); exit; }
if (Auth::isStaff()) { header('Location: ' . base_url('s/antrean')); exit; }
page_start(['title' => 'Reservasi Saya', 'area' => 'customer', 'active' => 'reservasi']); ?>
<div class="pagehead"><h2>Reservasi Saya</h2><a class="btn sm" href="<?= e(base_url('booking')) ?>">+ Booking Baru</a></div>
<div id="list" class="stack"><div class="empty">Memuat…</div></div>
<?php page_end(['scripts' => ['reservasi.js'], 'inline' => 'const FOCUS=' . json_encode($GLOBALS['route_arg'] ?? ($_GET['kode'] ?? null)) . ';']); ?>
