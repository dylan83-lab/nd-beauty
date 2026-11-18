<?php
declare(strict_types=1);

/**
 * Kerangka halaman. area=customer: navigasi bawah di HP (Beranda · Layanan · Booking · Reservasi Saya · Akun).
 * area=staff: sidebar di desktop, bilah atas + tindakan cepat di HP.
 */
function asset(string $p): string { return base_url('assets/' . $p) . '?v=' . (@filemtime(APP_ROOT . '/assets/' . $p) ?: 1); }

function staff_menu(): array
{
    $m = [
        ['s/', 'Beranda', 'home', null],
        ['s/antrean', 'Antrean', 'list', 'queue'],
        ['s/kalender', 'Kalender', 'cal', 'queue'],
        ['s/kasir', 'Kasir', 'cart', 'pos'],
        ['s/pelanggan', 'Pelanggan', 'users', 'customers'],
        ['s/kas', 'Kas & Keuangan', 'wallet', 'cash_session'],
        ['s/stok', 'Stok', 'box', 'stock_view'],
        ['s/jadwal', 'Shift & Jadwal', 'clock', 'schedule'],
        ['s/absensi', 'Absensi', 'pin', 'attendance_self'],
        ['s/persetujuan', 'Persetujuan', 'check', 'corrections'],
        ['s/laporan', 'Laporan', 'chart', 'reports'],
        ['s/pengaturan', 'Pengaturan', 'cog', 'settings'],
    ];
    return array_values(array_filter($m, fn($x) => $x[3] === null || Auth::can($x[3]) || ($x[0] === 's/kas' && Auth::can('cash_mutation'))));
}

function page_start(array $o): void
{
    $area = $o['area'] ?? 'customer';
    $title = $o['title'] ?? 'ND Japanese Head Spa';
    $u = Auth::user();
    $biz = S('biz_name', 'ND Japanese Head Spa');
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · <?= e($biz) ?></title>
<meta name="csrf" content="<?= e(Auth::csrf()) ?>">
<meta name="theme-color" content="#7a1230">
<link rel="icon" href="<?= e(asset('img/logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script>try{var t=localStorage.getItem('nd-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
</head>
<body class="area-<?= e($area) ?>" data-base="<?= e(base_url('')) ?>" data-role="<?= e($u['role'] ?? '') ?>">
<?php if ($area === 'staff' && $u): $menu = staff_menu(); $active = $o['active'] ?? ''; ?>
<div class="shell">
  <aside class="side" id="side">
    <a class="brand" href="<?= e(base_url('s/')) ?>"><img src="<?= e(asset('img/logo.png')) ?>" alt=""><span><b>ND</b> Japanese<br>Head Spa</span></a>
    <nav>
      <?php foreach ($menu as [$href, $label, $ic]): ?>
        <a href="<?= e(base_url($href)) ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span><?php if ($href === 's/persetujuan'): ?><em class="dot" id="pendDot" hidden></em><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot"><a href="<?= e(base_url('')) ?>" target="_blank">Lihat situs pelanggan ↗</a></div>
  </aside>
  <div class="main">
    <header class="top">
      <button class="icon-btn only-m" id="menuBtn" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="top-title"><h1><?= e($title) ?></h1><small id="clock"><?= e(tgl_id(today())) ?></small></div>
      <div class="top-actions">
        <button class="icon-btn" id="themeBtn" aria-label="Mode gelap"><?= icon('moon') ?></button>
        <div class="who"><span class="avatar"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span><span class="who-t"><b><?= e($u['name']) ?></b><small><?= e(Auth::ROLES[$u['role']] ?? '') ?></small></span></div>
        <form method="post" action="<?= e(base_url('keluar')) ?>"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><button class="icon-btn" title="Keluar" aria-label="Keluar"><?= icon('logout') ?></button></form>
      </div>
    </header>
    <main class="content">
<?php elseif ($area === 'customer'): $active = $o['active'] ?? ''; ?>
<header class="chead">
  <div class="wrap chead-in">
    <a class="brand" href="<?= e(base_url('')) ?>"><img src="<?= e(asset('img/logo.png')) ?>" alt=""><span><?= e($biz) ?></span></a>
    <nav class="cnav">
      <?php foreach ([[ '', 'Beranda', 'home'], ['layanan', 'Layanan', 'sparkle'], ['booking', 'Booking', 'cal'], ['reservasi', 'Reservasi Saya', 'list'], ['akun', 'Akun', 'user']] as [$h, $l, $ic]): ?>
        <a href="<?= e(base_url($h)) ?>" class="<?= $active === $h ? 'on' : '' ?><?= $h === 'booking' ? ' cta' : '' ?>"><?= icon($ic, 22) ?><span><?= e($l) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <button class="icon-btn" id="themeBtn" aria-label="Mode gelap"><?= icon('moon') ?></button>
  </div>
</header>
<main class="wrap cmain">
<?php else: ?>
<main class="wrap cmain">
<?php endif;
}

function page_end(array $o = []): void
{
    $area = $o['area'] ?? 'customer';
    $u = Auth::user();
    if ($area === 'staff' && $u): ?>
    </main>
  </div>
</div>
<?php else: ?>
</main>
<footer class="cfoot wrap"><b><?= e(S('biz_name')) ?></b><br><?= e(S('biz_address')) ?><br>Jam buka: Senin–Minggu <?= e(S('open_time')) ?>–<?= e(S('close_time')) ?> WIB</footer>
<?php endif; ?>
<div id="toasts" aria-live="polite"></div>
<div id="modal-root"></div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if (!empty($o['inline'])): ?><script><?= $o['inline'] ?></script><?php endif; ?>
<?php foreach (($o['scripts'] ?? []) as $s): ?><script src="<?= e(strpos($s, 'http') === 0 ? $s : asset('js/' . $s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
