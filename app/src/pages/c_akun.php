<?php
$u = Auth::user();
if (!$u) { header('Location: ' . base_url('login?next=' . rawurlencode(base_url('akun')))); exit; }
if (Auth::isStaff()) { header('Location: ' . base_url('s/')); exit; }
$c = Customers::get((int)Auth::customerId());
$m = $c['member'];
$hist = DB::all("SELECT id, code, book_date, start_at, status FROM bookings WHERE customer_id=? AND status='done' ORDER BY start_at DESC LIMIT 20", [$c['id']]);
$hist = Booking::attachItems(array_map(fn($b) => $b + ['customer_name' => $c['name']], $hist));
$pts = DB::all('SELECT points, note, created_at FROM loyalty_moves WHERE customer_id=? ORDER BY id DESC LIMIT 10', [$c['id']]);
$plans = DB::all('SELECT * FROM membership_plans WHERE active=1');
page_start(['title' => 'Akun', 'area' => 'customer', 'active' => 'akun']); ?>
<div class="pagehead"><h2>Akun Saya</h2>
  <form method="post" action="<?= e(base_url('keluar')) ?>"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><button class="btn ghost sm"><?= icon('logout', 18) ?> Keluar</button></form></div>
<div class="grid g2">
  <div class="card"><h3>Profil</h3>
    <form id="pf" class="stack">
      <div><label class="f">Nama</label><input class="in" name="name" value="<?= e($c['name']) ?>" required></div>
      <div><label class="f">Nomor HP</label><input class="in" value="<?= e($c['phone']) ?>" disabled></div>
      <div><label class="f">Email</label><input class="in" name="email" type="email" value="<?= e($c['email']) ?>"></div>
      <button class="btn sm">Simpan Profil</button>
    </form>
    <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
    <form id="pw" class="stack"><b>Ubah kata sandi</b>
      <input class="in" type="password" name="old" placeholder="Kata sandi lama" required autocomplete="current-password">
      <input class="in" type="password" name="new" placeholder="Kata sandi baru (min. 6)" required minlength="6" autocomplete="new-password">
      <button class="btn ghost sm">Ubah Kata Sandi</button></form>
  </div>
  <div class="card"><h3>Membership</h3>
    <?php if ($m['active']): ?>
      <div class="note ok"><b><?= e($m['plan']['name']) ?></b> · aktif sampai <?= e(tgl_id($m['until'], false)) ?></div>
      <ul style="padding-left:18px;margin:10px 0;font-size:.9rem">
        <?php if ((int)$m['plan']['discount_pct']): ?><li>Diskon <?= (int)$m['plan']['discount_pct'] ?>% untuk layanan &amp; produk</li><?php endif; ?>
        <?php if ((int)$m['plan']['points_per_10k']): ?><li><?= (int)$m['plan']['points_per_10k'] ?> poin setiap belanja Rp10.000</li><?php endif; ?>
        <li><?= (int)$m['plan']['combine_promo'] ? 'Dapat digabung dengan harga promo' : 'Tidak digabung dengan harga promo' ?></li>
        <?php if ($m['plan']['benefits']): ?><li><?= e($m['plan']['benefits']) ?></li><?php endif; ?>
      </ul>
      <div class="stat" style="box-shadow:none"><small>Poin Anda</small><b><?= number_format((int)$m['points'], 0, ',', '.') ?></b><em>1 poin = <?= rp(S('point_value')) ?> potongan di kasir</em></div>
    <?php else: ?>
      <p class="mut">Anda belum menjadi member<?= $m['plan'] ? ' (masa berlaku paket ' . e($m['plan']['name']) . ' berakhir ' . e(tgl_id($m['until'], false)) . ')' : '' ?>. Tanyakan keanggotaan di kasir saat berkunjung.</p>
      <?php foreach ($plans as $p): ?><div class="note" style="margin-top:8px"><b><?= e($p['name']) ?></b><?= (int)$p['price'] ? ' · ' . rp($p['price']) : '' ?><br><small><?= e($p['benefits']) ?></small></div><?php endforeach; ?>
    <?php endif; ?>
    <?php if ($pts): ?><h3 style="margin-top:14px">Riwayat Poin</h3><div class="list"><?php foreach ($pts as $p): ?><div class="row sp"><span><?= e($p['note']) ?><br><small><?= e(substr($p['created_at'], 0, 10)) ?></small></span><b style="color:<?= $p['points'] > 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= ($p['points'] > 0 ? '+' : '') . (int)$p['points'] ?></b></div><?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
<div class="card sec"><h3>Riwayat Kunjungan</h3>
  <?php if (!$hist): ?><p class="mut">Belum ada kunjungan selesai.</p><?php endif; ?>
  <div class="list"><?php foreach ($hist as $b): ?><div class="row sp"><span><b><?= e($b['services']) ?></b><br><small><?= e(tgl_id($b['book_date'])) ?> · <?= e($b['start_hm']) ?> · <?= e($b['code']) ?></small></span><b><?= rp($b['total']) ?></b></div><?php endforeach; ?></div>
</div>
<?php page_end(['inline' => "
$('#pf').addEventListener('submit',async e=>{e.preventDefault();try{await api('me.update',Object.fromEntries(new FormData(e.target)));toast('Profil disimpan','ok')}catch(x){fail(x)}});
$('#pw').addEventListener('submit',async e=>{e.preventDefault();try{await api('me.password',Object.fromEntries(new FormData(e.target)));toast('Kata sandi diubah','ok');e.target.reset()}catch(x){fail(x)}});
"]); ?>
