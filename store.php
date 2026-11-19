<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* Kunci yang boleh dibaca pengunjung tanpa login (data memang tampil di beranda publik) */
const GPM_KUNCI_PUBLIK = array(
  'gpm_agenda', 'gpm_agendasektor', 'gpm_buletin', 'gpm_ibadah', 'gpm_profil', 'gpm_renungan', 'gpm_saldopub',
  'gpm_rekening', 'gpm_rekap', 'gpm_fotobangun', 'gpm_fotoberanda', 'gpm_panitia', 'gpm_warta', 'gpm_warga',
);

/* Kunci yang disalin ke tabel terstruktur */
const GPM_KUNCI_CERMIN = array(
  'gpm_warga', 'gpm_mutasi', 'gpm_surat', 'gpm_renungan', 'gpm_agenda', 'gpm_agendasektor',
  'gpm_panitia', 'gpm_ibadah', 'gpm_notulen', 'gpm_program', 'gpm_buletin',
);

/* Angka dasar jemaat dari Rekapitulasi Data Jemaat GPM Tihulale (jumlah total semua sektor).
   Angka ini tampil tetap di beranda publik sampai jumlah data warga aktif mencapai jumlah jiwa di atas; setelah itu beranda
   memakai hitungan data warga sebenarnya (jiwa, KK, L, P ikut bertambah). Bisa diganti dengan menyimpan kunci gpm_rekap. */
function gpm_rekap_default() {
  return gpm_json( array( 'sumber' => 'Rekapitulasi Data Jemaat GPM Tihulale', 'jiwa' => 1883, 'kk' => 407, 'lk' => 938, 'pr' => 945, 'sektor' => 6 ) );
}

function gpm_kunci_valid( $k ) {
  return (bool) preg_match( '/^gpm_[a-z0-9_]{1,59}$/', $k ) && ! in_array( $k, array( 'gpm_sid', 'gpm_lock' ), true );
}

/* ---------- Object Cache (versi naik setiap ada penulisan, sehingga cache lama otomatis tidak terpakai) ---------- */
function gpm_versi() { return (string) get_option( 'gpm_kv_ver', '0' ); }
function gpm_versi_naik() { update_option( 'gpm_kv_ver', (string) microtime( true ), true ); }

function gpm_kv_semua() {
  $ck = 'kv_' . gpm_versi();
  $c  = wp_cache_get( $ck, 'gpm' );
  if ( is_array( $c ) ) return $c;
  global $wpdb;
  $out = array();
  foreach ( (array) $wpdb->get_results( 'SELECT k, v FROM ' . gpm_t( 'kv_store' ), ARRAY_A ) as $r ) $out[ $r['k'] ] = $r['v'];
  wp_cache_set( $ck, $out, 'gpm', DAY_IN_SECONDS );
  return $out;
}

/* ---------- Berkas PDF buletin ----------
   PDF disimpan sebagai berkas di wp-content/uploads/gpm-buletin/, bukan di dalam database. Database (kv_store dan tabel
   wp_gpm_buletin) hanya menyimpan penanda "berkas:nama.pdf". Ke peramban dikirim URL lengkapnya. */
function gpm_buletin_dir() {
  $u = wp_upload_dir();
  return array( 'path' => trailingslashit( $u['basedir'] ) . 'gpm-buletin', 'url' => trailingslashit( $u['baseurl'] ) . 'gpm-buletin' );
}

/* data URL PDF / URL berkas / penanda "berkas:..." -> penanda "berkas:nama.pdf" ('' bila tidak sah) */
function gpm_buletin_pdf_simpan( $pdf, $uid ) {
  $pdf = trim( (string) $pdf );
  if ( $pdf === '' ) return '';
  $d = gpm_buletin_dir();
  if ( preg_match( '#^data:application/pdf;base64,([A-Za-z0-9+/=\r\n]+)$#', $pdf, $m ) ) {
    $bin = base64_decode( $m[1], true );
    if ( $bin === false || strncmp( $bin, '%PDF', 4 ) !== 0 || strlen( $bin ) > 10 * MB_IN_BYTES ) return '';
    if ( ! wp_mkdir_p( $d['path'] ) ) throw new RuntimeException( 'Folder uploads/gpm-buletin tidak bisa dibuat (periksa izin folder).' );
    if ( ! file_exists( $d['path'] . '/index.php' ) ) file_put_contents( $d['path'] . '/index.php', "<?php // senyap\n" );
    $id   = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $uid ), 0, 30 ) ?: 'b';
    $nama = $id . '-' . substr( hash_hmac( 'sha256', $bin, wp_salt( 'auth' ) ), 0, 20 ) . '.pdf';
    $file = $d['path'] . '/' . $nama;
    if ( ! file_exists( $file ) && file_put_contents( $file, $bin ) === false ) throw new RuntimeException( 'PDF buletin tidak bisa ditulis ke folder uploads.' );
    return 'berkas:' . $nama;
  }
  if ( preg_match( '#^berkas:([A-Za-z0-9_-]+\.pdf)$#', $pdf, $m ) ) $nama = $m[1];
  elseif ( preg_match( '#/gpm-buletin/([A-Za-z0-9_-]+\.pdf)$#', $pdf, $m ) ) $nama = $m[1];
  else return '';
  return file_exists( $d['path'] . '/' . $nama ) ? 'berkas:' . $nama : '';
}

/* Ubah semua PDF base64 di JSON buletin menjadi berkas, hapus berkas yang sudah tidak dipakai */
function gpm_buletin_proses( $json ) {
  $d = json_decode( $json, true );
  if ( ! is_array( $d ) ) return $json;
  $pakai = array();
  foreach ( $d as $i => $r ) {
    if ( ! is_array( $r ) ) continue;
    $r['pdf'] = gpm_buletin_pdf_simpan( $r['pdf'] ?? '', (string) ( $r['id'] ?? '' ) );
    if ( $r['pdf'] !== '' ) $pakai[ substr( $r['pdf'], 7 ) ] = 1;
    $d[ $i ] = $r;
  }
  $dir = gpm_buletin_dir();
  foreach ( (array) glob( $dir['path'] . '/*.pdf' ) as $f ) { if ( ! isset( $pakai[ basename( $f ) ] ) && filemtime( $f ) < time() - HOUR_IN_SECONDS ) @unlink( $f ); }
  return gpm_json( array_values( $d ) );
}

/* Untuk peramban: penanda "berkas:nama.pdf" -> URL lengkap */
function gpm_buletin_untuk_klien( $json ) {
  $d = json_decode( $json, true );
  if ( ! is_array( $d ) ) return $json;
  $dir = gpm_buletin_dir();
  foreach ( $d as $i => $r ) {
    if ( is_array( $r ) && isset( $r['pdf'] ) && strncmp( (string) $r['pdf'], 'berkas:', 7 ) === 0 ) $d[ $i ]['pdf'] = $dir['url'] . '/' . substr( $r['pdf'], 7 );
  }
  return gpm_json( $d );
}

/* ---------- Berkas video pembangunan ----------
   Video disimpan sebagai berkas di wp-content/uploads/gpm-media/, bukan di database. Database (kv_store, kunci gpm_fotobangun)
   hanya menyimpan penanda "berkas:nama.mp4". Ke peramban dikirim URL lengkapnya. Foto tetap disimpan seperti sebelumnya. */
function gpm_media_dir() {
  $u = wp_upload_dir();
  return array( 'path' => trailingslashit( $u['basedir'] ) . 'gpm-media', 'url' => trailingslashit( $u['baseurl'] ) . 'gpm-media' );
}

/* Jenis video dari isi berkas: 'mp4', 'mov', 'webm', atau '' bila bukan video yang didukung */
function gpm_video_jenis( $path ) {
  $h = @fopen( $path, 'rb' ); if ( ! $h ) return '';
  $b = (string) fread( $h, 16 ); fclose( $h );
  if ( strlen( $b ) < 12 ) return '';
  if ( substr( $b, 4, 4 ) === 'ftyp' ) {
    $merek = substr( $b, 8, 4 );
    if ( in_array( $merek, array( 'heic', 'heix', 'hevx', 'mif1', 'msf1', 'avif', 'avis' ), true ) ) return ''; // gambar, bukan video
    return $merek === 'qt  ' ? 'mov' : 'mp4';
  }
  if ( substr( $b, 0, 4 ) === "\x1A\x45\xDF\xA3" ) return 'webm';
  return '';
}

/* URL berkas / penanda "berkas:..." -> nama berkas ('' bila tidak sah atau berkasnya tidak ada) */
function gpm_media_nama( $v ) {
  $v = trim( (string) $v );
  if ( preg_match( '#^berkas:([A-Za-z0-9_-]+\.(?:mp4|webm|mov))$#', $v, $m ) ) $nama = $m[1];
  elseif ( preg_match( '#/gpm-media/([A-Za-z0-9_-]+\.(?:mp4|webm|mov))$#', $v, $m ) ) $nama = $m[1];
  else return '';
  $d = gpm_media_dir();
  return file_exists( $d['path'] . '/' . $nama ) ? $nama : '';
}

/* Saat disimpan: URL video -> penanda "berkas:...", buang baris tanpa foto/video, hapus berkas yang sudah tidak dipakai */
function gpm_media_proses( $json ) {
  $d = json_decode( $json, true );
  if ( ! is_array( $d ) ) return $json;
  $pakai = array(); $baru = array();
  foreach ( $d as $r ) {
    if ( ! is_array( $r ) ) continue;
    $video = gpm_media_nama( $r['video'] ?? '' );
    if ( $video !== '' ) { $r['video'] = 'berkas:' . $video; $pakai[ $video ] = 1; }
    else unset( $r['video'] );
    if ( $video === '' && trim( (string) ( $r['foto'] ?? '' ) ) === '' ) continue;
    $baru[] = $r;
  }
  $dir = gpm_media_dir();
  foreach ( array( 'mp4', 'webm', 'mov' ) as $ext ) {
    foreach ( (array) glob( $dir['path'] . '/*.' . $ext ) as $f ) {
      // berkas baru (< 1 jam) tidak dihapus: mungkin baru diunggah dan belum disimpan
      if ( ! isset( $pakai[ basename( $f ) ] ) && filemtime( $f ) < time() - HOUR_IN_SECONDS ) @unlink( $f );
    }
  }
  return gpm_json( $baru );
}

/* Untuk peramban: penanda "berkas:nama.mp4" -> URL lengkap */
function gpm_media_untuk_klien( $json ) {
  $d = json_decode( $json, true );
  if ( ! is_array( $d ) ) return $json;
  $dir = gpm_media_dir();
  foreach ( $d as $i => $r ) {
    if ( is_array( $r ) && isset( $r['video'] ) && strncmp( (string) $r['video'], 'berkas:', 7 ) === 0 ) $d[ $i ]['video'] = $dir['url'] . '/' . substr( $r['video'], 7 );
  }
  return gpm_json( $d );
}

function gpm_json( $data ) { return wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }

/* Pengunjung hanya menerima data yang memang dipakai beranda publik, tanpa data pribadi warga */
function gpm_saring_publik( $k, $json ) {
  $d = json_decode( $json, true );
  if ( $k === 'gpm_warga' ) {
    if ( ! is_array( $d ) ) return '[]';
    $d = array_values( array_map( function ( $r ) {
      $r = is_array( $r ) ? $r : array();
      return array_intersect_key( $r, array_flip( array( 'status', 'apv', 'kategori', 'jk', 'sektor' ) ) );
    }, $d ) );
    return gpm_json( $d );
  }
  if ( $k === 'gpm_agendasektor' ) {
    if ( ! is_array( $d ) ) return '[]';
    return gpm_json( array_values( array_filter( $d, function ( $r ) {
      return is_array( $r ) && ( $r['tayang'] ?? '' ) === 'Ya' && ( $r['status'] ?? '' ) === 'Disetujui';
    } ) ) );
  }
  if ( $k === 'gpm_fotobangun' ) return gpm_media_untuk_klien( $json );
  if ( $k === 'gpm_buletin' ) {
    if ( ! is_array( $d ) ) return '[]';
    return gpm_buletin_untuk_klien( gpm_json( array_values( array_filter( $d, function ( $r ) {
      return is_array( $r ) && ( $r['status'] ?? '' ) === 'Terbit';
    } ) ) ) );
  }
  return $json;
}

function gpm_bundle_publik() {
  $ck = 'pub_' . gpm_versi();
  $c  = wp_cache_get( $ck, 'gpm' );
  if ( is_array( $c ) ) return $c;
  $semua = gpm_kv_semua();
  $out   = array();
  foreach ( GPM_KUNCI_PUBLIK as $k ) {
    if ( isset( $semua[ $k ] ) ) $out[ $k ] = gpm_saring_publik( $k, $semua[ $k ] );
  }
  if ( ! isset( $out['gpm_rekap'] ) ) $out['gpm_rekap'] = gpm_rekap_default();
  wp_cache_set( $ck, $out, 'gpm', DAY_IN_SECONDS );
  return $out;
}

/* Staf menerima semua data; kata sandi/jawaban keamanan lama tidak pernah dikirim (login kini lewat WordPress) */
function gpm_bundle_staf() {
  $semua = gpm_kv_semua();
  if ( isset( $semua['gpm_users'] ) ) {
    $u = json_decode( $semua['gpm_users'], true );
    if ( is_array( $u ) ) {
      foreach ( $u as &$r ) { if ( is_array( $r ) ) unset( $r['hash'], $r['salt'], $r['answer'], $r['question'] ); }
      unset( $r );
      $semua['gpm_users'] = gpm_json( $u );
    }
  }
  if ( isset( $semua['gpm_buletin'] ) ) $semua['gpm_buletin'] = gpm_buletin_untuk_klien( $semua['gpm_buletin'] );
  if ( isset( $semua['gpm_fotobangun'] ) ) $semua['gpm_fotobangun'] = gpm_media_untuk_klien( $semua['gpm_fotobangun'] );
  if ( ! isset( $semua['gpm_rekap'] ) ) $semua['gpm_rekap'] = gpm_rekap_default();
  return $semua;
}

/* ---------- Penulisan ---------- */
function gpm_kv_tulis( array $items, $boleh_users = false ) {
  global $wpdb;
  $tabel = gpm_t( 'kv_store' );
  $ditulis = array();
  $wpdb->query( 'START TRANSACTION' );
  foreach ( $items as $k => $v ) {
    $k = (string) $k;
    if ( ! gpm_kunci_valid( $k ) ) continue;
    if ( $k === 'gpm_users' && ! $boleh_users ) continue;
    if ( $k === 'gpm_buletin' && $v !== null ) {
      try { $v = gpm_buletin_proses( (string) $v ); }
      catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'gpm_pdf', $e->getMessage(), array( 'status' => 500 ) ); }
    }
    if ( $k === 'gpm_fotobangun' && $v !== null ) $v = gpm_media_proses( (string) $v );
    if ( $v === null ) {
      $r = $wpdb->query( $wpdb->prepare( "DELETE FROM $tabel WHERE k = %s", $k ) );
    } else {
      $r = $wpdb->query( $wpdb->prepare( "INSERT INTO $tabel (k, v) VALUES (%s, %s) ON DUPLICATE KEY UPDATE v = VALUES(v)", $k, (string) $v ) );
    }
    if ( $r === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'gpm_db', 'Gagal menyimpan ke database.', array( 'status' => 500 ) ); }
    $ditulis[ $k ] = $v;
  }
  $wpdb->query( 'COMMIT' );
  gpm_versi_naik();

  // Setelah aman di kv_store, salin ke tabel terstruktur (kegagalan di sini tidak membatalkan simpanan)
  $gagal = array();
  foreach ( $ditulis as $k => $v ) {
    if ( ! in_array( $k, GPM_KUNCI_CERMIN, true ) ) continue;
    try { gpm_cermin( $k, $v === null ? '[]' : (string) $v ); }
    catch ( Throwable $e ) { $gagal[ $k ] = $e->getMessage(); }
  }
  return $gagal;
}

function gpm_cermin_semua() {
  $hasil = array();
  foreach ( gpm_kv_semua() as $k => $v ) {
    if ( ! in_array( $k, GPM_KUNCI_CERMIN, true ) ) continue;
    try { gpm_cermin( $k, $v ); $hasil[ $k ] = 'ok'; }
    catch ( Throwable $e ) { $hasil[ $k ] = 'gagal: ' . $e->getMessage(); }
  }
  return $hasil;
}

/* ---------- Pembantu penyaringan ---------- */
function gpm_str( $v ) { return is_array( $v ) ? implode( "\n", array_map( 'strval', $v ) ) : trim( (string) ( $v ?? '' ) ); }
function gpm_tx( $v, $max = 0 ) { $s = gpm_str( $v ); return $max ? mb_substr( $s, 0, $max ) : $s; }
function gpm_txn( $v, $max = 0 ) { $s = gpm_tx( $v, $max ); return $s === '' ? null : $s; }
function gpm_tgl( $v ) { $s = (string) $v; return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ? $s : null; }
function gpm_jam( $v ) { $s = (string) $v; return preg_match( '/^\d{2}:\d{2}/', $s ) ? substr( $s, 0, 5 ) . ':00' : null; }
function gpm_pilih( $v, array $daftar, $default ) { return in_array( $v, $daftar, true ) ? $v : $default; }

function gpm_sektor_id( $nama ) {
  global $wpdb;
  static $cache = array();
  $nama = gpm_tx( $nama, 40 );
  if ( $nama === '' ) return null;
  if ( isset( $cache[ $nama ] ) ) return $cache[ $nama ];
  $tabel = gpm_t( 'sektor' );
  $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tabel WHERE nama = %s", $nama ) );
  if ( ! $id ) { $wpdb->insert( $tabel, array( 'nama' => $nama ) ); $id = $wpdb->insert_id; }
  return $cache[ $nama ] = (int) $id;
}

function gpm_masukkan( $tabel, array $data ) {
  global $wpdb;
  if ( $wpdb->insert( gpm_t( $tabel ), $data ) === false ) throw new RuntimeException( $wpdb->last_error ?: 'insert gagal' );
  return (int) $wpdb->insert_id;
}

/* ---------- Cermin: salin JSON aplikasi ke tabel terstruktur ---------- */
function gpm_cermin( $kunci, $json ) {
  global $wpdb;
  $data = json_decode( $json, true );
  if ( ! is_array( $data ) ) return;
  $rows = array_values( array_filter( $data, 'is_array' ) );
  $wpdb->query( 'START TRANSACTION' );
  try {
    switch ( $kunci ) {
      case 'gpm_warga':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'warga' ) );
        foreach ( $rows as $r ) {
          $sid = gpm_sektor_id( $r['sektor'] ?? '' ); $nama = gpm_tx( $r['nama'] ?? '', 150 );
          if ( ! $sid || $nama === '' ) continue;
          gpm_masukkan( 'warga', array(
            'nama' => $nama, 'sektor_id' => $sid,
            'kategori' => gpm_pilih( $r['kategori'] ?? '', array( 'Kepala Keluarga', 'Istri', 'Janda', 'Duda', 'Pemuda', 'Anak' ), 'Kepala Keluarga' ),
            'jenis_kelamin' => gpm_pilih( $r['jk'] ?? '', array( 'Laki-laki', 'Perempuan' ), null ),
            'status' => gpm_pilih( $r['status'] ?? '', array( 'Aktif', 'Pindah', 'Meninggal' ), 'Aktif' ),
            'tgl_lahir' => gpm_tgl( $r['tglLahir'] ?? '' ), 'telp' => gpm_txn( $r['telp'] ?? '', 30 ), 'alamat' => gpm_txn( $r['alamat'] ?? '' ),
            'persetujuan' => gpm_pilih( $r['apv'] ?? '', array( 'Disetujui', 'Menunggu', 'Ditolak' ), 'Disetujui' ),
          ) );
        }
        break;

      case 'gpm_mutasi':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'mutasi_jemaat' ) );
        foreach ( $rows as $r ) {
          $tg = gpm_tgl( $r['tanggal'] ?? '' ); $nama = gpm_tx( $r['nama'] ?? '', 150 );
          if ( ! $tg || $nama === '' ) continue;
          gpm_masukkan( 'mutasi_jemaat', array(
            'tanggal' => $tg, 'nama' => $nama, 'jenis' => gpm_pilih( $r['jenis'] ?? '', array( 'Pindah Masuk', 'Pindah Keluar' ), 'Pindah Masuk' ),
            'sektor_id' => gpm_sektor_id( $r['sektor'] ?? '' ), 'asal_tujuan' => gpm_txn( $r['asal'] ?? '', 150 ), 'alasan' => gpm_txn( $r['alasan'] ?? '', 255 ),
          ) );
        }
        break;

      case 'gpm_surat':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'surat' ) );
        $pakai = array();
        foreach ( $rows as $r ) {
          $tg = gpm_tgl( $r['tanggal'] ?? '' ); $no = gpm_tx( $r['nomor'] ?? '', 70 );
          if ( ! $tg || $no === '' ) continue;
          $asli = $no; $n = 2;
          while ( isset( $pakai[ $no ] ) ) { $no = $asli . ' (' . $n++ . ')'; }
          $pakai[ $no ] = 1;
          $pihak = $r['pihak'] ?? $r['dari'] ?? $r['kepada'] ?? $r['pengirim'] ?? $r['tujuan'] ?? '';
          gpm_masukkan( 'surat', array(
            'nomor' => $no, 'tanggal' => $tg, 'jenis' => gpm_pilih( $r['jenis'] ?? '', array( 'Surat Masuk', 'Surat Keluar' ), 'Surat Masuk' ),
            'pihak' => gpm_txn( $pihak, 200 ), 'diterima' => gpm_txn( $r['diterima'] ?? '', 150 ), 'kategori' => gpm_txn( $r['kategori'] ?? '', 60 ),
            'perihal' => gpm_txn( $r['perihal'] ?? '', 255 ), 'keterangan' => gpm_txn( $r['ket'] ?? '' ),
            'persetujuan' => gpm_pilih( $r['status'] ?? '', array( 'Menunggu', 'Disetujui', 'Ditolak' ), 'Menunggu' ),
          ) );
        }
        break;

      case 'gpm_renungan':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'renungan' ) );
        foreach ( $rows as $r ) {
          $tg = gpm_tgl( $r['tanggal'] ?? '' ); $j = gpm_tx( $r['judul'] ?? '', 200 );
          if ( ! $tg || $j === '' ) continue;
          gpm_masukkan( 'renungan', array(
            'tanggal' => $tg, 'judul' => $j, 'ayat' => gpm_txn( $r['ayat'] ?? '', 100 ), 'teks' => gpm_txn( $r['teks'] ?? '' ),
            'isi' => gpm_txn( $r['isi'] ?? '' ), 'doa' => gpm_txn( $r['doa'] ?? '' ), 'penulis' => gpm_txn( $r['penulis'] ?? '', 120 ),
          ) );
        }
        break;

      case 'gpm_agenda':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'agenda' ) );
        foreach ( $rows as $r ) {
          $j = gpm_tx( $r['judul'] ?? '', 200 ); if ( $j === '' ) continue;
          gpm_masukkan( 'agenda', array(
            'judul' => $j, 'kategori' => gpm_tx( $r['kategori'] ?? '', 40 ) ?: '-',
            'waktu' => gpm_txn( $r['waktu'] ?? '', 120 ), 'deskripsi' => gpm_txn( $r['deskripsi'] ?? '' ),
          ) );
        }
        break;

      case 'gpm_agendasektor':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'agenda_sektor' ) );
        foreach ( $rows as $r ) {
          $sid = gpm_sektor_id( $r['sektor'] ?? '' ); $j = gpm_tx( $r['judul'] ?? '', 200 );
          if ( ! $sid || $j === '' ) continue;
          gpm_masukkan( 'agenda_sektor', array(
            'sektor_id' => $sid, 'judul' => $j, 'kategori' => gpm_tx( $r['kategori'] ?? '', 40 ) ?: '-',
            'waktu' => gpm_txn( $r['waktu'] ?? '', 120 ), 'deskripsi' => gpm_txn( $r['deskripsi'] ?? '' ),
            'tayang' => ( ( $r['tayang'] ?? '' ) === 'Ya' ) ? 1 : 0,
            'publikasi' => gpm_pilih( $r['status'] ?? '', array( 'Menunggu', 'Disetujui', 'Ditolak' ), 'Menunggu' ),
            'dibuat' => gpm_tgl( $r['dibuat'] ?? '' ),
          ) );
        }
        break;

      case 'gpm_panitia':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'panitia_pembangunan' ) );
        foreach ( $rows as $i => $r ) {
          $nama = gpm_tx( $r['nama'] ?? '', 150 ); if ( $nama === '' ) continue;
          gpm_masukkan( 'panitia_pembangunan', array( 'jabatan' => gpm_tx( $r['jabatan'] ?? '', 100 ) ?: '-', 'nama' => $nama, 'urut' => $i ) );
        }
        break;

      case 'gpm_ibadah':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'jadwal_ibadah' ) );
        foreach ( $rows as $r ) {
          $tg = gpm_tgl( $r['tanggal'] ?? '' ); if ( ! $tg ) continue;
          $tempat = ( ( $r['tempat'] ?? '' ) === 'Lainnya' ) ? ( $r['tempatLain'] ?? '' ) : ( $r['tempat'] ?? '' );
          gpm_masukkan( 'jadwal_ibadah', array(
            'tanggal' => $tg, 'jam' => gpm_jam( $r['jam'] ?? '' ), 'tempat' => gpm_tx( $tempat, 150 ) ?: '-',
            'jenis' => gpm_tx( $r['jenis'] ?? '', 60 ) ?: '-', 'unit' => gpm_txn( $r['unit'] ?? '', 100 ),
            'tema' => gpm_txn( $r['tema'] ?? '', 255 ), 'pelayan' => gpm_txn( $r['pelayan'] ?? '', 150 ),
          ) );
        }
        break;

      case 'gpm_notulen':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'notulen_keputusan' ) );
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'notulen' ) );
        foreach ( $rows as $r ) {
          $tg = gpm_tgl( $r['tanggal'] ?? '' ); $j = gpm_tx( $r['judul'] ?? '', 200 );
          if ( ! $tg || $j === '' ) continue;
          $nid = gpm_masukkan( 'notulen', array(
            'jenis' => gpm_tx( $r['jenis'] ?? '', 60 ) ?: '-', 'judul' => $j, 'tanggal' => $tg,
            'tempat' => gpm_txn( $r['tempat'] ?? '', 150 ), 'pimpinan' => gpm_txn( $r['pimpinan'] ?? '', 120 ),
            'notulis' => gpm_txn( $r['notulis'] ?? '', 120 ), 'peserta' => gpm_txn( $r['peserta'] ?? '' ),
            'agenda' => gpm_txn( $r['agenda'] ?? '' ), 'isi' => gpm_txn( $r['isi'] ?? '' ),
          ) );
          foreach ( (array) ( $r['keputusan'] ?? array() ) as $k ) {
            if ( ! is_array( $k ) || gpm_tx( $k['teks'] ?? '' ) === '' ) continue;
            gpm_masukkan( 'notulen_keputusan', array(
              'notulen_id' => $nid, 'teks' => gpm_tx( $k['teks'] ), 'pj' => gpm_txn( $k['pj'] ?? '', 120 ),
              'target' => gpm_tgl( $k['target'] ?? '' ), 'status' => gpm_tx( $k['status'] ?? '', 20 ) ?: 'Belum',
            ) );
          }
        }
        break;

      case 'gpm_buletin':
        // Upsert per buletin (kunci: uid), supaya id & waktu dibuat tidak berubah tiap simpan; yang dihapus di portal ikut dihapus.
        $tbl = gpm_t( 'buletin' );
        $pakai = array();
        foreach ( $rows as $i => $r ) {
          $tg = gpm_tgl( $r['tanggal'] ?? '' );
          $edisi = gpm_tx( $r['edisi'] ?? $r['judul'] ?? '', 120 );
          if ( ! $tg || $edisi === '' ) continue;
          $uid = gpm_tx( $r['id'] ?? '', 40 ) ?: substr( md5( $tg . $edisi . $i ), 0, 20 );
          $asli = $uid; $n = 2;
          while ( isset( $pakai[ $uid ] ) ) { $uid = mb_substr( $asli, 0, 36 ) . '-' . $n++; }
          $pakai[ $uid ] = 1;
          $ok = $wpdb->query( $wpdb->prepare(
            "INSERT INTO $tbl (uid, tanggal, edisi, judul, deskripsi, status, berkas, pdf) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE tanggal = VALUES(tanggal), edisi = VALUES(edisi), judul = VALUES(judul), deskripsi = VALUES(deskripsi),
             status = VALUES(status), berkas = VALUES(berkas), pdf = VALUES(pdf)",
            $uid, $tg, $edisi, gpm_tx( $r['judul'] ?? '', 200 ) ?: $edisi, gpm_tx( $r['deskripsi'] ?? '' ),
            gpm_tx( $r['status'] ?? '', 20 ) ?: 'Terbit', gpm_tx( $r['berkas'] ?? '', 255 ), gpm_tx( $r['pdf'] ?? '', 120 )
          ) );
          if ( $ok === false ) throw new RuntimeException( $wpdb->last_error ?: 'simpan buletin gagal (cek max_allowed_packet MySQL)' );
        }
        if ( $pakai ) {
          $in = implode( ',', array_fill( 0, count( $pakai ), '%s' ) );
          $wpdb->query( $wpdb->prepare( "DELETE FROM $tbl WHERE uid NOT IN ($in)", array_keys( $pakai ) ) );
        } else {
          $wpdb->query( "DELETE FROM $tbl" );
        }
        break;

      case 'gpm_program':
        $wpdb->query( 'DELETE FROM ' . gpm_t( 'program_pelkes' ) );
        foreach ( $rows as $r ) {
          $nama = gpm_tx( $r['nama'] ?? '', 200 ); $thn = (int) ( $r['tahun'] ?? 0 );
          if ( $nama === '' || $thn < 1901 || $thn > 2155 ) continue;
          gpm_masukkan( 'program_pelkes', array(
            'tahun' => $thn, 'unit' => gpm_tx( $r['unit'] ?? '', 100 ) ?: '-', 'nama' => $nama, 'target' => gpm_txn( $r['target'] ?? '', 255 ),
            'capaian' => max( 0, min( 100, (int) ( $r['capaian'] ?? 0 ) ) ), 'status' => gpm_txn( $r['status'] ?? '', 30 ),
            'anggaran' => (float) ( $r['anggaran'] ?? 0 ), 'hasil' => gpm_txn( $r['hasil'] ?? '' ),
          ) );
        }
        break;
    }
    $wpdb->query( 'COMMIT' );
  } catch ( Throwable $e ) {
    $wpdb->query( 'ROLLBACK' );
    throw $e;
  }
}
