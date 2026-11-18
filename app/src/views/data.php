<?php
declare(strict_types=1);

/** Data katalog untuk halaman pelanggan. */
function cat_services(): array { return DB::all('SELECT * FROM services WHERE active=1 ORDER BY sort, id'); }

/** Item promo yang siap dipesan (promo aktif, dalam periode, layanan/isi paket & durasi sudah ditetapkan). */
function promo_ready(): array
{
    $out = [];
    foreach (DB::all("SELECT * FROM promos WHERE status='active' AND ends >= ? ORDER BY id", [today()]) as $p) {
        $items = [];
        foreach (DB::all('SELECT * FROM promo_items WHERE promo_id=? ORDER BY id', [$p['id']]) as $it) {
            $okRef = (int)$it['is_package'] === 1 ? $it['includes'] !== '' : $it['service_id'] !== null;
            if ((int)$it['duration_min'] > 0 && $okRef) $items[] = $it;
        }
        if ($items) $out[] = $p + ['items' => $items];
    }
    return $out;
}

function maps_url(): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Perumahan Surya Praja Permai Blok C7 No. 13 Jl. Sukahati Cibinong Kabupaten Bogor');
}

function price_label(array $s): string { return ((int)$s['price_from'] ? 'Mulai ' : '') . rp($s['price']); }
