<?php
$u = Auth::user();
if (!$u) { header('Location: ' . base_url('login')); exit; }
if (!Auth::can('cash_session') && !Auth::can('cash_mutation')) Auth::needPage('cash_mutation');
page_start(['title' => 'Kas & Keuangan', 'area' => 'staff', 'active' => 's/kas']); ?>
<div class="tabs" id="tabs">
  <?php if (Auth::can('cash_session')): ?><button data-t="sesi" class="on">Kas Saya</button><?php endif; ?>
  <?php if (Auth::can('cash_mutation')): ?><button data-t="mutasi" class="<?= Auth::can('cash_session') ? '' : 'on' ?>">Mutasi & Saldo</button><button data-t="selisih">Selisih Kas</button><?php endif; ?>
</div>
<div id="tab"></div>
<?php page_end(['area' => 'staff', 'scripts' => ['cash.js'], 'inline' => 'const CASH_TAB=' . json_encode(Auth::can('cash_session') ? 'sesi' : 'mutasi') . ', CAN_MUT=' . json_encode(Auth::can('cash_mutation')) . ';']); ?>
