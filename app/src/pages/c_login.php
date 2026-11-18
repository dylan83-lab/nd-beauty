<?php
$reg = str_contains($_SERVER['REQUEST_URI'] ?? '', '/daftar');
$next = (string)($_GET['next'] ?? '');
if (Auth::user()) { header('Location: ' . base_url(Auth::isStaff() ? 's/' : '')); exit; }
page_start(['title' => $reg ? 'Daftar' : 'Masuk', 'area' => 'customer', 'active' => 'akun']); ?>
<div class="login card">
  <h2><?= $reg ? 'Buat Akun Pelanggan' : 'Masuk' ?></h2>
  <p class="mut" style="margin:6px 0 14px"><?= $reg ? 'Untuk memesan jadwal dan melihat riwayat kunjungan.' : 'Masuk dengan email atau nomor HP. Pegawai juga masuk dari sini.' ?></p>
  <form id="f" class="stack" autocomplete="on">
    <?php if ($reg): ?>
      <div><label class="f">Nama lengkap</label><input class="in" name="name" required autocomplete="name"></div>
      <div><label class="f">Nomor HP / WhatsApp</label><input class="in" name="phone" inputmode="tel" required autocomplete="tel" placeholder="08xxxxxxxxxx"></div>
      <div><label class="f">Email (opsional)</label><input class="in" name="email" type="email" autocomplete="email"></div>
    <?php else: ?>
      <div><label class="f">Email atau nomor HP</label><input class="in" name="ident" required autocomplete="username"></div>
    <?php endif; ?>
    <div><label class="f">Kata sandi</label><input class="in" name="password" type="password" required minlength="6" autocomplete="<?= $reg ? 'new-password' : 'current-password' ?>"></div>
    <div class="err" id="err" role="alert"></div>
    <button class="btn block" id="go"><?= $reg ? 'Daftar' : 'Masuk' ?></button>
  </form>
  <p style="margin-top:14px;text-align:center"><?= $reg ? 'Sudah punya akun? <a href="' . e(base_url('login')) . '">Masuk</a>' : 'Belum punya akun? <a href="' . e(base_url('daftar')) . '">Daftar</a>' ?></p>
</div>
<?php page_end(['inline' => "
const next=" . json_encode($next) . ", reg=" . ($reg ? 'true' : 'false') . ";
$('#f').addEventListener('submit',async e=>{e.preventDefault();const b=$('#go');const err=$('#err');err.textContent='';
 await busy(b,async()=>{try{const d=Object.fromEntries(new FormData(e.target));const r=await api(reg?'auth.register':'auth.login',d);
 location.href=(next&&next.startsWith('/'))?next:(r.redirect||ND.base+'/');}catch(x){err.textContent=x.message}})});
"]); ?>
