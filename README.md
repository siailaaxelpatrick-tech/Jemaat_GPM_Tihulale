# Portal GPM Tihulale — plugin WordPress

Portal Jemaat Gereja Beth Eden (GPM Tihulale) di atas stack hosting yang disarankan.
Tampilan dan fitur portal tetap sama; yang berubah adalah tempat data disimpan dan cara login.

## Pemetaan ke stack

| Komponen stack | Di proyek ini |
|---|---|
| PHP | Seluruh logika server (`includes/*.php`) |
| WordPress (framework/CMS) | Plugin `gpm-tihulale`: halaman portal, REST API, peran pengguna, halaman admin, WP-Cron |
| MySQL | 13 tabel berawalan `wp_gpm_` + `kv_store` (menggantikan `api.php` + `config.php`; koneksi DB memakai `wp-config.php`) |
| HTML, CSS, JavaScript | `templates/portal.php`, `assets/css/portal.css`, `assets/js/portal.js`, `assets/js/db-sync.js` |
| Node.js | Tidak diperlukan (JavaScript hanya berjalan di peramban) |
| Git | Repositori ini; deploy lewat hPanel → Git |
| Cron job (hPanel) | Menjalankan `wp-cron.php` → tugas harian: cadangan + sinkron tabel |
| WordPress Object Cache | `wp_cache_get/set` pada data portal; dipakai otomatis bila Redis/Memcached/LiteSpeed Object Cache aktif |
| CDN | Aset memakai `?ver=` (cache-busting); data publik dikirim dengan `Cache-Control` + `ETag`; video di `uploads/gpm-media/` bernama acak dan diberi cache 1 tahun (`.htaccess` otomatis) |
| Backup harian bawaan | Dilengkapi cadangan data portal sendiri (14 salinan terakhir). Video & PDF adalah berkas di `uploads/`, jadi dicakup backup harian hosting (bukan cadangan JSON plugin) |

## Pemasangan

1. Pasang WordPress di hosting (PHP 7.4+).
2. Unggah `gpm-tihulale.zip` lewat **Plugins → Tambah Baru → Unggah**, lalu **Aktifkan**.
   Saat aktif, plugin membuat tabel, peran "Staf Portal GPM", halaman "Portal Jemaat", dan jadwal harian.
   Bila situs belum memakai halaman statis, halaman itu otomatis menjadi beranda
   (atau atur manual di **Pengaturan → Membaca**).
3. **Pengaturan → Portal GPM → Impor data awal** (memuat data dari `gpm_tihulale.sql`: agenda, renungan, buletin, daftar pengguna).
4. Buat akun staf: cara tercepat **Pengaturan → Portal GPM → Akun Staf → Buat akun Sekretaris, Bendahara, Ketua dan Admin Sektor**
   (kata sandi acak tampil sekali). Atau manual di **Pengguna → Tambah Baru**.
   - Administrator WordPress otomatis menjadi **Admin** portal; akun `adminportal` tersedia sebagai Admin cadangan.
   - Sekretaris, bendahara, ketua, dan admin sektor: beri peran WordPress **Staf Portal GPM** dan
     **email yang sama** dengan akun di menu Pengguna portal (peran portal diambil dari sana).

## Login (perubahan penting)

Dulu login berjalan di peramban dan seluruh data (termasuk hash kata sandi dan data warga) bisa dibaca siapa saja lewat `api.php`.
Sekarang:

- Tombol **Login** mengarah ke login WordPress; setelah masuk, dashboard terbuka otomatis.
- Pengunjung hanya menerima data publik; data warga dikirim **tanpa nama, telepon, dan alamat** (hanya status/kategori/jenis kelamin/sektor untuk statistik).
- Hanya staf yang boleh membaca data penuh dan menulis. Perubahan daftar pengguna portal hanya oleh Administrator WordPress.
- Formulir Daftar dan Lupa Password di portal tidak dipakai lagi; kelola akun di WordPress.
- Batasan per peran di dalam dashboard (mis. hanya bendahara melihat keuangan) masih ditegakkan di peramban, bukan di server.

## hPanel

**Git** — hPanel → Git → hubungkan repositori ini ke `wp-content/plugins/gpm-tihulale`. Skema database diperbarui otomatis bila `GPM_DB_VER` naik.
Jangan commit `migration/data-awal.json` (sudah di `.gitignore`); unggah manual sekali untuk impor.

**Cron job** — di `wp-config.php` tambahkan `define('DISABLE_WP_CRON', true);`, lalu di hPanel → Cron Jobs buat (tiap 15 menit):

```
*/15 * * * * /usr/bin/php /home/uXXXXXXXXX/domains/DOMAINANDA/public_html/wp-cron.php >/dev/null 2>&1
```

Sesuaikan path dengan akun Anda. Tugas `gpm_cron_harian` berjalan sekali sehari.

**Object cache & CDN** — aktifkan object cache dari paket hosting (mis. LiteSpeed Cache → Object Cache) dan CDN bawaan.
Status cache tampil di **Pengaturan → Portal GPM**. Halaman portal untuk pengunjung boleh di-cache;
untuk pengguna yang login halaman otomatis tidak di-cache.

**Backup** — backup harian hosting tetap dipakai. Cadangan plugin tersimpan di `wp-content/uploads/gpm-backup-*`
(folder tertutup, unduh lewat halaman admin) dan berada di server yang sama, jadi bukan pengganti backup di luar server.

## Foto hero & Foto/Video Pembangunan (v1.5.0)

- **Hero beranda**: dua foto (`assets/img/hero-1.jpg`, `hero-2.jpg`) berganti otomatis tiap 6 detik dengan efek crossfade. Untuk mengganti foto, timpa kedua berkas itu (disarankan lebar 1600 px, JPEG).
- **Pembangunan**: Admin (login) melihat tombol **Tambah Foto** dan **Tambah Video** di bagian Foto & Video Pembangunan (juga lewat menu dashboard). Pengunjung hanya melihat galerinya.
- **Video** (MP4, WebM, MOV; maks. 2 GB, `GPM_VIDEO_MAKS`) diunggah langsung sebagai berkas ke `wp-content/uploads/gpm-media/`; database hanya menyimpan penandanya (`berkas:nama.mp4`). Jenis berkas dicek dari isinya. Berkas yang tidak lagi dipakai dihapus otomatis (setelah lebih dari 1 jam).
- Untuk video 2 GB, atur di php.ini/hPanel: `upload_max_filesize` ≥ 2048M, `post_max_size` ≥ 2100M, `max_execution_time` dan `max_input_time` ≥ 600. Bila memakai Cloudflare (proxy), batas unggah per berkas hanya 100 MB di paket gratis/Pro; unggah lewat alamat server langsung atau nonaktifkan proxy sementara. Di Nginx, naikkan `client_max_body_size`. Folder `gpm-media` ikut di-backup bersama uploads.
- Tips: video dari ponsel biasanya besar; kecilkan dulu (720p) agar muat dan cepat dibuka di jaringan seluler.

## Catatan

- Buletin disimpan di `kv_store` dan otomatis disalin ke tabel `wp_gpm_buletin` setiap kali diunggah, diubah, atau dihapus.
- PDF buletin disimpan sebagai berkas di `wp-content/uploads/gpm-buletin/`; database hanya menyimpan namanya (`berkas:nama.pdf`), sehingga tidak terkena batas `max_allowed_packet`. Batas ukuran satu PDF: 100 MB (`GPM_PDF_MAKS`); pastikan `upload_max_filesize` ≥ 100M dan `post_max_size` ≥ 110M di php.ini. Folder ini perlu ikut di-backup bersama uploads.
- Menghapus plugin tidak menghapus tabel data.

## Memakai database yang sudah ada (gpm_tihulale)

Plugin tidak punya koneksi sendiri: ia memakai database WordPress dari `wp-config.php`. Untuk memakai database `gpm_tihulale`:

```php
define( 'DB_NAME', 'gpm_tihulale' );
define( 'DB_USER', 'root' );   // XAMPP bawaan
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
$table_prefix = 'wp_';          // tabel plugin: wp_gpm_*
```

- Tabel dibuat dengan `CREATE TABLE IF NOT EXISTS`, jadi data dan akun yang sudah ada **tidak ditimpa**.
- "Impor data awal" hanya mengisi kelompok data yang belum ada (jangan centang "timpa").
- Panel **Pengaturan → Portal GPM → Koneksi Database** menampilkan database yang terhubung, status dan jumlah baris tiap tabel, serta jumlah akun portal yang tersimpan.
