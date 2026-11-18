<?php
Auth::needPage('settings');
page_start(['title' => 'Pengaturan', 'area' => 'staff', 'active' => 's/pengaturan']); ?>
<div class="tabs" id="tabs"></div>
<div id="panel"></div>
<?php page_end(['area' => 'staff', 'scripts' => ['settings.js']]); ?>
