<?php
require_once __DIR__ . '/layout.php';
page_start(['title' => 'Akses ditolak', 'area' => 'customer']); ?>
<section class="empty"><h2>Akses ditolak</h2><p>Akun Anda tidak memiliki kewenangan untuk membuka halaman ini.</p><a class="btn" href="<?= e(base_url(Auth::isStaff() ? 's/' : '')) ?>">Kembali</a></section>
<?php page_end(); ?>
