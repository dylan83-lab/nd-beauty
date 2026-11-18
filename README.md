# ND Japanese Head Spa — ndbeauty.id

Landing page (`index.html`) + **aplikasi bisnis** di `app/` (PHP 8.1+ & MySQL, cocok untuk cPanel/phpMyAdmin/cron):
reservasi pelanggan, antrean, kasir, terapis, absensi lokasi (radius 100 m), stok, membership, kas/keuangan, dashboard & laporan.

Zona waktu **Asia/Jakarta (WIB)**, mata uang **Rupiah**, bahasa Indonesia.

## Memasang di server (cPanel)
1. **Select PHP Version**: pilih PHP 8.1+ dan aktifkan `pdo_mysql`, `mbstring`, `zip`.
2. **Git™ Version Control** → clone repo ini → *Deploy HEAD Commit* (`.cpanel.yml` menyalin ke `public_html`).
3. **MySQL® Databases**: buat database + pengguna (beri semua hak).
4. Di **File Manager** buat berkas kosong `public_html/app/storage/.allow-install`, lalu buka `https://ndbeauty.id/app/install.php`, isi data database dan akun admin. Berkas kunci itu terhapus otomatis setelah berhasil. (Alternatif: impor `app/sql/schema.mysql.sql` lewat phpMyAdmin.)
5. **Cron Jobs** (tiap 10 menit) — menandai pelanggan tidak hadir & membersihkan data login:
   `*/10 * * * * /usr/local/bin/php $HOME/public_html/app/cron/maintenance.php`
6. Aktifkan **AutoSSL** lalu hapus tanda `#` pada blok HTTPS di `.htaccess`.

`app/config.local.php` (berisi password database) dibuat installer dan **tidak** masuk Git.

## Masuk (akun contoh, bila opsi "akun contoh" dicentang) — kata sandi `ND-Demo2026`
| Peran | Masuk dengan |
|---|---|
| Admin | email yang Anda isi saat pemasangan |
| Manager | `manager@ndbeauty.id` |
| Kasir | `kasir@ndbeauty.id` |
| Terapis | `terapis1@ndbeauty.id` … `terapis3@ndbeauty.id` |
| Pelanggan | `pelanggan@ndbeauty.id` / `081200000099` (atau daftar sendiri di `/app/daftar`) |

**Ganti semua kata sandi contoh** di Pengaturan → Pegawai & Akses sebelum dipakai nyata.

## Yang harus diisi pemilik usaha pertama kali (Pengaturan, admin)
1. **Lokasi Absensi**: tentukan pin di peta, radius 100 m, lalu centang verifikasi — absensi baru aktif setelah itu.
2. **Layanan & Tarif**: isi **durasi** tiap layanan (selain Japanese 90 mnt & Korean 60 mnt masih kosong sesuai poster) — layanan tanpa durasi belum bisa dibooking. Isi juga bahan per layanan.
3. **Promo**: *Promo Launching* tersimpan sebagai **draf**. Tetapkan varian/layanan, isi paket, dan durasi tiap item, lalu aktifkan. (Harga promo tidak otomatis menjadi harga reguler.)
4. **Pegawai**: ganti nama terapis contoh, atur keahlian, **Shift & Jadwal**, kursi/ruang, hari libur, akun kas/bank, produk & stok awal, membership.
5. Profil usaha, jam operasional, dan aturan (batas batal, tenggang tidak hadir, potongan manual kasir).

## Pengembangan lokal
```bash
ND_FAKE_NOW=13:00 php app/tools/setup-local.php   # SQLite + data awal (jam simulasi opsional)
php -S localhost:8080 -t . app/tools/dev-router.php
php app/tests/scenario.php        # skenario lengkap (booking → bayar → refund → absensi)
php app/tests/http.php            # uji kewenangan per peran lewat HTTP (server harus berjalan)
```
SQLite hanya untuk uji lokal; produksi memakai MySQL.

## Catatan teknis
- Ketersediaan slot dihitung di server (jam buka, libur, shift & keahlian terapis, istirahat, kursi/ruang). Konfirmasi booking diperiksa ulang di dalam transaksi terkunci per-hari sehingga hanya satu pemesan yang menang untuk slot terakhir.
- Pembayaran, mutasi kas, stok, dan poin idempoten (kunci unik) — klik ganda / kirim ulang tidak menggandakan.
- Layar kasir/terapis menyegarkan otomatis (polling ringan, kompatibel shared hosting).
- Satu reservasi dipakai dari booking → check-in → terapis → kasir tanpa ketik ulang.
- Satu reservasi dilayani satu terapis dan satu kursi/meja berurutan; paket gabungan dihitung sebagai satu blok durasi.
