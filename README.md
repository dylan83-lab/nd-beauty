# ND Japanese Head Spa — ndbeauty.id

Landing page sementara. Hosting: cPanel (Apache), database nanti via phpMyAdmin (MySQL).

## Deploy lewat cPanel
1. cPanel → **Git™ Version Control** → *Create* → Clone URL `https://github.com/dylan83-lab/nd-beauty.git`, repository path `/home/USERNAME/repositories/nd-beauty` (repo private: pakai deploy key/token).
2. Buka repo tersebut → tab **Pull or Deploy** → **Update from Remote** → **Deploy HEAD Commit**.
   `.cpanel.yml` menyalin `index.html`, `assets/`, `.htaccess` ke `public_html`.
3. Pastikan domain ndbeauty.id mengarah ke `public_html` (Domains) dan aktifkan AutoSSL.

## Auto-update dengan cron (opsional)
cPanel → **Cron Jobs**, setiap 10 menit:

```
*/10 * * * * cd $HOME/repositories/nd-beauty && git pull -q origin main && /bin/cp -R index.html assets .htaccess $HOME/public_html/
```

## Database (tahap aplikasi)
Buat database + user di **MySQL® Databases**, kelola tabel di **phpMyAdmin**. Simpan kredensial di file di luar repo (jangan di-commit).
