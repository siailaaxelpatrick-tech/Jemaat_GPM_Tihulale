<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
  add_options_page( 'Portal GPM Tihulale', 'Portal GPM', 'manage_options', 'gpm-tihulale', 'gpm_halaman_admin' );
} );

function gpm_kembali( $pesan, $jenis = 'ok' ) {
  wp_safe_redirect( add_query_arg( array( 'page' => 'gpm-tihulale', 'gpm_p' => rawurlencode( $pesan ), 'gpm_j' => $jenis ), admin_url( 'options-general.php' ) ) );
  exit;
}

/* Impor data awal (hasil konversi gpm_tihulale.sql) */
add_action( 'admin_post_gpm_impor', function () {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Tidak diizinkan.' );
  check_admin_referer( 'gpm_impor' );
  $f = GPM_DIR . 'migration/data-awal.json';
  if ( ! file_exists( $f ) ) gpm_kembali( 'Berkas migration/data-awal.json tidak ditemukan.', 'err' );
  $data = json_decode( (string) file_get_contents( $f ), true );
  if ( ! is_array( $data ) ) gpm_kembali( 'Isi data-awal.json tidak valid.', 'err' );
  $timpa = ! empty( $_POST['timpa'] );
  $ada   = gpm_kv_semua();
  $items = array();
  foreach ( $data as $k => $v ) { if ( $timpa || ! isset( $ada[ $k ] ) ) $items[ $k ] = $v; }
  $hasil = gpm_kv_tulis( $items, true );
  if ( is_wp_error( $hasil ) ) gpm_kembali( $hasil->get_error_message(), 'err' );
  gpm_kembali( count( $items ) . ' kelompok data diimpor.' . ( $hasil ? ' Sebagian tabel terstruktur gagal disalin.' : '' ) );
} );

add_action( 'admin_post_gpm_sinkron', function () {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Tidak diizinkan.' );
  check_admin_referer( 'gpm_sinkron' );
  $h = gpm_cermin_semua();
  $gagal = array_filter( $h, function ( $x ) { return $x !== 'ok'; } );
  gpm_kembali( $gagal ? 'Sebagian gagal: ' . implode( '; ', array_keys( $gagal ) ) : 'Tabel terstruktur berhasil disinkronkan.', $gagal ? 'err' : 'ok' );
} );

add_action( 'admin_post_gpm_cadangkan', function () {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Tidak diizinkan.' );
  check_admin_referer( 'gpm_cadangkan' );
  $n = gpm_cadangkan();
  gpm_kembali( $n ? 'Cadangan dibuat: ' . $n : 'Gagal membuat cadangan (periksa izin folder uploads).', $n ? 'ok' : 'err' );
} );

/* Unduh cadangan lewat PHP (folder aslinya tertutup untuk akses langsung) */
add_action( 'admin_post_gpm_unduh', function () {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Tidak diizinkan.' );
  check_admin_referer( 'gpm_unduh' );
  $nama = basename( (string) ( $_GET['f'] ?? '' ) );
  $path = gpm_folder_cadangan() . '/' . $nama;
  if ( ! preg_match( '/^gpm-[0-9-]+\.json(\.gz)?$/', $nama ) || ! is_file( $path ) ) wp_die( 'Berkas tidak ditemukan.' );
  header( 'Content-Type: application/octet-stream' );
  header( 'Content-Disposition: attachment; filename="' . $nama . '"' );
  header( 'Content-Length: ' . filesize( $path ) );
  readfile( $path );
  exit;
} );


/* ---------- Akun Staf: buat akun WordPress + pengguna portal sekaligus ---------- */
function gpm_peran_portal() {
  return array(
    'sekretaris' => 'Sekretaris',
    'bendahara'  => 'Bendahara',
    'ketua'      => 'Ketua Majelis Jemaat',
    'adminsektor'=> 'Admin Sektor',
    'admin'      => 'Admin',
  );
}
function gpm_daftar_sektor() {
  return array( 'Sektor Sion', 'Sektor Galelia', 'Sektor Ebenhaezer', 'Sektor Getsemany', 'Sektor Tiberias', 'Sektor Betlehem' );
}
function gpm_baca_users() {
  $semua = gpm_kv_semua();
  $u = isset( $semua['gpm_users'] ) ? json_decode( $semua['gpm_users'], true ) : array();
  return is_array( $u ) ? $u : array();
}
function gpm_simpan_users( array $u ) {
  return gpm_kv_tulis( array( 'gpm_users' => wp_json_encode( array_values( $u ), JSON_UNESCAPED_UNICODE ) ), true );
}
/* Pastikan ada akun WordPress dengan peran Staf; kembalikan [user_id, password_baru|null] atau WP_Error */
function gpm_pastikan_akun_wp( $login, $email, $nama, $sandi = '' ) {
  $wp = get_user_by( 'email', $email );
  if ( ! $wp && $login ) $wp = get_user_by( 'login', $login );
  if ( $wp ) {
    if ( ! user_can( $wp, 'gpm_kelola' ) ) $wp->add_role( 'gpm_staf' );
    return array( $wp, null );
  }
  $baru = $sandi === '' ? wp_generate_password( 14, false ) : $sandi;
  $id = wp_insert_user( array(
    'user_login'   => $login,
    'user_email'   => $email,
    'display_name' => $nama,
    'first_name'   => $nama,
    'user_pass'    => $baru,
    'role'         => 'gpm_staf',
  ) );
  if ( is_wp_error( $id ) ) return $id;
  return array( get_user_by( 'id', $id ), $sandi === '' ? $baru : null );
}

add_action( 'admin_post_gpm_akun_baru', function () {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Tidak diizinkan.' );
  check_admin_referer( 'gpm_akun_baru' );
  $nama  = sanitize_text_field( wp_unslash( $_POST['nama'] ?? '' ) );
  $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
  $login = sanitize_user( wp_unslash( $_POST['login'] ?? '' ), true );
  $sandi = (string) wp_unslash( $_POST['sandi'] ?? '' );
  $peran = sanitize_key( $_POST['peran'] ?? '' );
  $sektor= sanitize_text_field( wp_unslash( $_POST['sektor'] ?? '' ) );
  if ( ! $nama || ! is_email( $email ) ) gpm_kembali( 'Nama dan email yang valid wajib diisi.', 'err' );
  if ( ! isset( gpm_peran_portal()[ $peran ] ) ) gpm_kembali( 'Peran tidak dikenal.', 'err' );
  if ( $peran === 'adminsektor' && ! in_array( $sektor, gpm_daftar_sektor(), true ) ) gpm_kembali( 'Pilih sektor untuk Admin Sektor.', 'err' );
  if ( $login === '' ) $login = sanitize_user( strstr( $email, '@', true ), true );
  if ( $sandi !== '' && strlen( $sandi ) < 8 ) gpm_kembali( 'Kata sandi minimal 8 karakter (atau kosongkan agar dibuat otomatis).', 'err' );

  $hasil = gpm_pastikan_akun_wp( $login, $email, $nama, $sandi );
  if ( is_wp_error( $hasil ) ) gpm_kembali( 'Gagal membuat akun WordPress: ' . $hasil->get_error_message(), 'err' );
  list( $wp, $sandi_baru ) = $hasil;

  $list = gpm_baca_users(); $ada = false;
  foreach ( $list as &$r ) {
    if ( ! is_array( $r ) ) continue;
    if ( strtolower( (string) ( $r['email'] ?? '' ) ) === strtolower( $email ) || strtolower( (string) ( $r['username'] ?? '' ) ) === strtolower( $wp->user_login ) ) {
      $r['nama'] = $nama; $r['username'] = $wp->user_login; $r['email'] = $email; $r['role'] = $peran;
      $r['sektor'] = $peran === 'adminsektor' ? $sektor : ''; $r['status'] = 'aktif'; $ada = true; break;
    }
  }
  unset( $r );
  if ( ! $ada ) {
    $list[] = array(
      'id' => base_convert( (string) round( microtime( true ) * 1000 ), 10, 36 ) . substr( md5( $email ), 0, 5 ),
      'nama' => $nama, 'username' => $wp->user_login, 'email' => $email, 'role' => $peran,
      'sektor' => $peran === 'adminsektor' ? $sektor : '', 'status' => 'aktif', 'financeAccess' => false,
      'created' => gmdate( 'c' ),
    );
  }
  $h = gpm_simpan_users( $list );
  if ( is_wp_error( $h ) ) gpm_kembali( $h->get_error_message(), 'err' );

  $msg = 'Akun ' . gpm_peran_portal()[ $peran ] . ' siap: login "' . $wp->user_login . '"';
  if ( $sandi_baru ) $msg .= ', kata sandi sementara: ' . $sandi_baru . ' (catat sekarang, tidak ditampilkan lagi)';
  elseif ( $sandi !== '' ) $msg .= ' dengan kata sandi yang Anda isi';
  else $msg .= ' (akun WordPress sudah ada, kata sandi lama tetap dipakai)';
  gpm_kembali( $msg . '.' );
} );

/* Buat akun WordPress untuk semua pengguna portal yang belum punya, dan aktifkan */
add_action( 'admin_post_gpm_akun_lengkapi', function () {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Tidak diizinkan.' );
  check_admin_referer( 'gpm_akun_lengkapi' );
  $list = gpm_baca_users(); $info = array(); $ubah = false;
  foreach ( $list as &$r ) {
    if ( ! is_array( $r ) || empty( $r['email'] ) || ! is_email( $r['email'] ) ) continue;
    if ( ( $r['role'] ?? '' ) === 'admin' ) continue;
    $login = sanitize_user( $r['username'] ?? strstr( $r['email'], '@', true ), true );
    $hasil = gpm_pastikan_akun_wp( $login, $r['email'], $r['nama'] ?? $login );
    if ( is_wp_error( $hasil ) ) { $info[] = $r['email'] . ': gagal (' . $hasil->get_error_message() . ')'; continue; }
    if ( $hasil[1] ) $info[] = $hasil[0]->user_login . ' / ' . $hasil[1];
    if ( ( $r['status'] ?? '' ) !== 'aktif' ) { $r['status'] = 'aktif'; $ubah = true; }
  }
  unset( $r );
  if ( $ubah ) gpm_simpan_users( $list );
  gpm_kembali( $info ? 'Selesai. Akun baru (login / sandi sementara): ' . implode( '; ', $info ) : 'Semua pengguna portal sudah punya akun WordPress.' );
} );


/* Buat sekaligus akun Admin, Sekretaris, Bendahara, Ketua Majelis Jemaat, dan Admin Sektor (satu per sektor).
   Kata sandi acak dibuat otomatis dan ditampilkan SEKALI di halaman ini (disimpan sementara 5 menit, tidak lewat URL). */
add_action( 'admin_post_gpm_akun_bawaan', function () {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Tidak diizinkan.' );
  check_admin_referer( 'gpm_akun_bawaan' );
  $host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
  if ( $host === '' || strpos( $host, '.' ) === false ) $host = 'gpm.local';
  $daftar = array(
    array( 'admin', 'Admin Portal', 'adminportal', '' ),
    array( 'sekretaris', 'Sekretaris', 'sekretaris', '' ),
    array( 'bendahara', 'Bendahara', 'bendahara', '' ),
    array( 'ketua', 'Ketua Majelis Jemaat', 'ketua', '' ),
  );
  foreach ( gpm_daftar_sektor() as $sek ) {
    $slug = sanitize_title( str_replace( 'Sektor ', '', $sek ) );
    $daftar[] = array( 'adminsektor', 'Admin ' . $sek, 'adminsektor-' . $slug, $sek );
  }
  $list = gpm_baca_users(); $info = array(); $ubah = false;
  foreach ( $daftar as $d ) {
    list( $peran, $nama, $login, $sektor ) = $d;
    $email = $login . '@' . $host;
    $hasil = gpm_pastikan_akun_wp( $login, $email, $nama );
    if ( is_wp_error( $hasil ) ) { $info[] = array( $nama, $login, 'GAGAL: ' . $hasil->get_error_message() ); continue; }
    list( $wp, $sandi ) = $hasil;
    $ada = false;
    foreach ( $list as &$r ) {
      if ( ! is_array( $r ) || ! empty( $r['alias'] ) ) continue;
      if ( strtolower( (string) ( $r['username'] ?? '' ) ) === strtolower( $wp->user_login ) || ( $wp->user_email && strtolower( (string) ( $r['email'] ?? '' ) ) === strtolower( $wp->user_email ) ) ) {
        $r['role'] = $peran; $r['sektor'] = $sektor; $r['status'] = 'aktif'; $ada = true; break;
      }
    }
    unset( $r );
    if ( ! $ada ) {
      $list[] = array(
        'id' => base_convert( (string) round( microtime( true ) * 1000 ), 10, 36 ) . substr( md5( $login ), 0, 5 ),
        'nama' => $nama, 'username' => $wp->user_login, 'email' => $wp->user_email, 'role' => $peran,
        'sektor' => $sektor, 'status' => 'aktif', 'financeAccess' => false, 'created' => gmdate( 'c' ),
      );
    }
    $ubah = true;
    $info[] = array( $nama, $wp->user_login, $sandi ? $sandi : '(akun sudah ada, kata sandi lama tetap berlaku)' );
  }
  if ( $ubah ) { $h = gpm_simpan_users( $list ); if ( is_wp_error( $h ) ) gpm_kembali( $h->get_error_message(), 'err' ); }
  set_transient( 'gpm_bawaan_' . get_current_user_id(), $info, 300 );
  gpm_kembali( 'Akun staf selesai diproses. Catat kata sandi pada tabel di bagian Akun Staf (hanya tampil sekali).' );
} );


/* Peran tambahan untuk satu akun Admin: satu login, bisa berpindah ke tampilan Sekretaris/Bendahara/Ketua/Admin Sektor */
add_action( 'admin_post_gpm_peran_alias', function () {
  if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Tidak diizinkan.' );
  check_admin_referer( 'gpm_peran_alias' );
  $nama = wp_get_current_user()->display_name ?: 'Admin';
  $list = gpm_baca_users();
  $ada = array(); foreach ( $list as $r ) if ( is_array( $r ) && ! empty( $r['id'] ) ) $ada[ $r['id'] ] = true;
  $baru = array( 'sekretaris' => array( 'Sekretaris', '' ), 'bendahara' => array( 'Bendahara', '' ), 'ketua' => array( 'Ketua Majelis Jemaat', '' ) );
  foreach ( gpm_daftar_sektor() as $i => $sek ) $baru[ 'adminsektor-' . ( $i + 1 ) ] = array( 'Admin ' . $sek, $sek );
  $n = 0;
  foreach ( $baru as $k => $v ) {
    $id = 'alias-' . $k;
    if ( isset( $ada[ $id ] ) ) continue;
    $role = strpos( $k, 'adminsektor' ) === 0 ? 'adminsektor' : $k;
    $list[] = array( 'id' => $id, 'nama' => $nama . ' (' . $v[0] . ')', 'username' => 'alias-' . $k, 'email' => '',
      'role' => $role, 'sektor' => $v[1], 'status' => 'aktif', 'financeAccess' => $role === 'ketua', 'alias' => true, 'created' => gmdate( 'c' ) );
    $n++;
  }
  if ( $n ) { $h = gpm_simpan_users( $list ); if ( is_wp_error( $h ) ) gpm_kembali( $h->get_error_message(), 'err' ); }
  gpm_kembali( $n ? $n . ' peran ditambahkan. Di portal, pilih "Masuk sebagai" di pojok kanan bawah.' : 'Peran tambahan sudah ada.' );
} );

function gpm_bagian_akun() {
  $peran = gpm_peran_portal(); $sek = gpm_daftar_sektor();
  $list = gpm_baca_users();
  echo '<h2>Akun Staf (Sekretaris, Bendahara, Ketua, Admin Sektor)</h2>';
  echo '<p class="description" style="max-width:720px">Satu formulir membuat akun WordPress sekaligus mendaftarkannya sebagai pengguna portal dengan peran yang dipilih, sehingga bisa langsung login lewat tombol Login di portal.</p>';
  $bawaan = get_transient( 'gpm_bawaan_' . get_current_user_id() );
  if ( is_array( $bawaan ) ) {
    delete_transient( 'gpm_bawaan_' . get_current_user_id() );
    echo '<div class="notice notice-warning" style="max-width:900px;padding:8px 14px"><p><strong>Catat sekarang — kata sandi ini tidak ditampilkan lagi.</strong> Minta tiap pengguna menggantinya setelah login pertama.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Peran</th><th>Username</th><th>Kata sandi sementara</th></tr></thead><tbody>';
    foreach ( $bawaan as $b ) echo '<tr><td>' . esc_html( $b[0] ) . '</td><td><code>' . esc_html( $b[1] ) . '</code></td><td><code>' . esc_html( $b[2] ) . '</code></td></tr>';
    echo '</tbody></table></div>';
  }
  echo '<h3 style="margin-top:24px">Buat semua akun sekaligus</h3><p class="description" style="max-width:720px">Membuat akun login untuk <strong>Admin</strong> (username <code>adminportal</code>), <strong>Sekretaris, Bendahara, Ketua Majelis Jemaat</strong>, dan <strong>Admin Sektor</strong> (satu untuk tiap sektor) dengan kata sandi acak. Administrator WordPress otomatis menjadi Admin portal saat login pertama. Aman diklik ulang: akun yang sudah ada tidak diubah kata sandinya.</p>';
  echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="gpm_akun_bawaan">';
  wp_nonce_field( 'gpm_akun_bawaan' );
  submit_button( 'Buat akun Admin, Sekretaris, Bendahara, Ketua dan Admin Sektor', 'primary', 'submit', false );
  echo '</form>';
  echo '<h3 style="margin-top:24px">Satu akun untuk semua peran</h3><p class="description" style="max-width:720px">Cukup login sebagai Administrator, lalu berpindah tampilan ke Sekretaris, Bendahara, Ketua, atau Admin Sektor lewat pilihan &ldquo;Masuk sebagai&rdquo; di portal.</p>';
  echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="gpm_peran_alias">';
  wp_nonce_field( 'gpm_peran_alias' );
  submit_button( 'Aktifkan peran untuk akun Admin ini', 'primary', 'submit', false );
  echo '</form>';
  echo '<h3 style="margin-top:24px">Atau buat akun terpisah per orang</h3>';
  echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="gpm_akun_baru">';
  wp_nonce_field( 'gpm_akun_baru' );
  echo '<table class="form-table" style="max-width:720px"><tbody>';
  echo '<tr><th>Nama lengkap</th><td><input name="nama" class="regular-text" required></td></tr>';
  echo '<tr><th>Email</th><td><input type="email" name="email" class="regular-text" required></td></tr>';
  echo '<tr><th>Username login</th><td><input name="login" class="regular-text" placeholder="kosongkan = dari email"></td></tr>';
  echo '<tr><th>Kata sandi</th><td><input type="text" name="sandi" class="regular-text" autocomplete="new-password" placeholder="kosongkan = dibuat otomatis"></td></tr>';
  echo '<tr><th>Peran</th><td><select name="peran" id="gpm-peran">';
  foreach ( $peran as $k => $l ) echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
  echo '</select></td></tr>';
  echo '<tr id="gpm-baris-sektor"><th>Sektor</th><td><select name="sektor">';
  foreach ( $sek as $s ) echo '<option>' . esc_html( $s ) . '</option>';
  echo '</select> <span class="description">hanya untuk Admin Sektor</span></td></tr>';
  echo '</tbody></table>';
  submit_button( 'Buat / Perbarui Akun Staf', 'primary', 'submit', false );
  echo '</form>';
  echo '<script>(function(){var p=document.getElementById("gpm-peran"),b=document.getElementById("gpm-baris-sektor");function f(){b.style.display=p.value==="adminsektor"?"":"none"}p.onchange=f;f();})();</script>';

  echo '<h3 style="margin-top:24px">Pengguna portal saat ini</h3>';
  if ( ! $list ) { echo '<p>Belum ada pengguna portal. Impor data awal dulu atau buat akun di atas.</p>'; }
  else {
    echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Status portal</th><th>Akun WordPress</th></tr></thead><tbody>';
    foreach ( $list as $r ) {
      if ( ! is_array( $r ) ) continue;
      $wp = ! empty( $r['email'] ) ? get_user_by( 'email', $r['email'] ) : false;
      $pl = $peran[ $r['role'] ?? '' ] ?? ( $r['role'] ?? '-' );
      if ( ( $r['role'] ?? '' ) === 'adminsektor' && ! empty( $r['sektor'] ) ) $pl .= ' · ' . $r['sektor'];
      $ok = $wp && user_can( $wp, 'gpm_kelola' );
      echo '<tr><td>' . esc_html( $r['nama'] ?? '' ) . '</td><td>' . esc_html( $r['email'] ?? '' ) . '</td><td>' . esc_html( $pl ) . '</td><td>' . esc_html( $r['status'] ?? '' ) . '</td><td>' . ( $ok ? 'ada ✓ (' . esc_html( $wp->user_login ) . ')' : '<b style="color:#b32d2e">belum ada / bukan staf</b>' ) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px"><input type="hidden" name="action" value="gpm_akun_lengkapi">';
    wp_nonce_field( 'gpm_akun_lengkapi' );
    submit_button( 'Lengkapi akun WordPress untuk semua pengguna portal', 'secondary', 'submit', false );
    echo '</form>';
  }
}

/* Status koneksi database: memakai database WordPress yang sudah ada (wp-config.php). Data dan akun lama dipakai apa adanya. */
function gpm_status_database() {
  global $wpdb;
  $wpdb->suppress_errors( true );
  $ada = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'gpm_' ) . '%' ) );
  $ada = is_array( $ada ) ? array_map( 'strtolower', $ada ) : array();
  $baris = array(); $rusak = 0; $hilang = 0;
  foreach ( array( 'kv_store', 'sektor', 'warga', 'surat', 'renungan', 'agenda', 'agenda_sektor', 'jadwal_ibadah', 'mutasi_jemaat', 'notulen', 'notulen_keputusan', 'panitia_pembangunan', 'program_pelkes', 'buletin' ) as $n ) {
    $tbl = gpm_t( $n );
    if ( ! in_array( strtolower( $tbl ), $ada, true ) ) { $baris[] = array( $tbl, 'belum ada (dibuat otomatis saat plugin diaktifkan)', 'warn' ); $hilang++; continue; }
    $wpdb->last_error = '';
    $c = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $tbl . '`' );
    if ( $c === null || $wpdb->last_error !== '' ) { $baris[] = array( $tbl, 'RUSAK / tidak terbaca: ' . $wpdb->last_error, 'err' ); $rusak++; }
    else $baris[] = array( $tbl, (int) $c . ' baris', 'ok' );
  }
  $akun = array();
  if ( ! $rusak && in_array( strtolower( gpm_t( 'kv_store' ) ), $ada, true ) ) {
    foreach ( (array) gpm_baca_users() as $r ) {
      if ( is_array( $r ) && empty( $r['alias'] ) ) { $pr = (string) ( $r['role'] ?? '?' ); $akun[ $pr ] = ( $akun[ $pr ] ?? 0 ) + 1; }
    }
  }
  $wpdb->suppress_errors( false );
  return array( 'db' => DB_NAME, 'host' => DB_HOST, 'prefix' => $wpdb->prefix, 'baris' => $baris, 'rusak' => $rusak, 'hilang' => $hilang, 'akun' => $akun );
}

function gpm_halaman_admin() {
  if ( ! current_user_can( 'manage_options' ) ) return;
  global $wpdb;
  $id      = (int) get_option( 'gpm_halaman_id' );
  $url     = $id ? get_permalink( $id ) : '';
  $akhir   = (int) get_option( 'gpm_cadangan_terakhir' );
  $jml     = function ( $t ) use ( $wpdb ) { $wpdb->suppress_errors( true ); $c = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . gpm_t( $t ) ); $wpdb->suppress_errors( false ); return $c; };
  $next    = wp_next_scheduled( 'gpm_cron_harian' );
  $cache   = wp_using_ext_object_cache() ? 'aktif (cache persisten)' : 'belum aktif (aktifkan Redis/Memcached/LiteSpeed Object Cache di hPanel)';
  $form    = function ( $aksi, $label, $extra = '' ) {
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0">';
    echo '<input type="hidden" name="action" value="' . esc_attr( $aksi ) . '">';
    wp_nonce_field( $aksi );
    echo $extra; // phpcs:ignore
    submit_button( $label, 'secondary', 'submit', false );
    echo '</form>';
  };

  echo '<div class="wrap"><h1>Portal GPM Tihulale</h1>';
  if ( ! empty( $_GET['gpm_p'] ) ) {
    $cls = ( ( $_GET['gpm_j'] ?? 'ok' ) === 'err' ) ? 'notice-error' : 'notice-success';
    echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( rawurldecode( wp_unslash( $_GET['gpm_p'] ) ) ) . '</p></div>';
  }

  $st = gpm_status_database();
  echo '<h2>Koneksi Database</h2><table class="widefat striped" style="max-width:720px"><tbody>';
  echo '<tr><td>Database terhubung</td><td><code>' . esc_html( $st['db'] ) . '</code> @ ' . esc_html( $st['host'] ) . ' · awalan tabel <code>' . esc_html( $st['prefix'] ) . 'gpm_</code></td></tr>';
  foreach ( $st['baris'] as $b ) echo '<tr><td><code>' . esc_html( $b[0] ) . '</code></td><td style="color:' . ( $b[2] === 'ok' ? '#1a7f37' : ( $b[2] === 'err' ? '#b32d2e' : '#996800' ) ) . '">' . esc_html( $b[1] ) . '</td></tr>';
  $ak = array(); foreach ( $st['akun'] as $pr => $n ) $ak[] = esc_html( $pr ) . ': ' . (int) $n;
  echo '<tr><td>Akun portal tersimpan</td><td>' . ( $ak ? implode( ' · ', $ak ) : 'belum ada' ) . '</td></tr>';
  echo '</tbody></table>';
  if ( $st['rusak'] ) echo '<div class="notice notice-error inline" style="max-width:720px"><p><strong>Ada tabel yang tidak bisa dibaca</strong> (galat #1932 "doesn\'t exist in engine" berarti berkas InnoDB MySQL/XAMPP rusak atau tidak lengkap). Plugin tidak akan menimpa tabel tersebut. Pulihkan dari cadangan folder <code>mysql/data</code> (atau <code>mysql/backup</code> XAMPP), lalu muat ulang halaman ini; data dan akun lama langsung dipakai.</p></div>';
  elseif ( $st['hilang'] ) echo '<p class="description" style="max-width:720px">Tabel yang belum ada akan dibuat otomatis (tanpa menghapus yang sudah ada) saat plugin diaktifkan ulang; data lama tidak disentuh.</p>';

  echo '<h2>Status</h2><table class="widefat striped" style="max-width:720px"><tbody>';
  echo '<tr><td>Halaman portal</td><td>' . ( $url ? '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a>' : 'belum ada' ) . '</td></tr>';
  echo '<tr><td>Data di kv_store</td><td>' . $jml( 'kv_store' ) . ' kelompok</td></tr>';
  echo '<tr><td>Tabel terstruktur</td><td>warga: ' . $jml( 'warga' ) . ' · surat: ' . $jml( 'surat' ) . ' · renungan: ' . $jml( 'renungan' ) . ' · agenda: ' . $jml( 'agenda' ) . ' · jadwal ibadah: ' . $jml( 'jadwal_ibadah' ) . '</td></tr>';
  echo '<tr><td>WordPress Object Cache</td><td>' . esc_html( $cache ) . '</td></tr>';
  echo '<tr><td>Tugas harian berikutnya</td><td>' . ( $next ? esc_html( wp_date( 'd M Y H:i', $next ) ) : 'belum terjadwal' ) . '</td></tr>';
  echo '<tr><td>Cadangan terakhir</td><td>' . ( $akhir ? esc_html( wp_date( 'd M Y H:i', $akhir ) ) : 'belum ada' ) . '</td></tr>';
  echo '</tbody></table>';

  echo '<h2>Tindakan</h2>';
  $form( 'gpm_impor', 'Impor data awal (dari gpm_tihulale.sql)', '<label style="margin-right:8px"><input type="checkbox" name="timpa" value="1"> timpa data yang sudah ada</label>' );
  $form( 'gpm_sinkron', 'Sinkronkan ulang tabel terstruktur' );
  $form( 'gpm_cadangkan', 'Cadangkan sekarang' );

  gpm_bagian_akun();

  echo '<h2>Cadangan</h2>';
  $daf = gpm_daftar_cadangan();
  if ( ! $daf ) echo '<p>Belum ada cadangan.</p>';
  else {
    echo '<ul>';
    foreach ( $daf as $n ) {
      $u = wp_nonce_url( admin_url( 'admin-post.php?action=gpm_unduh&f=' . rawurlencode( $n ) ), 'gpm_unduh' );
      echo '<li><a href="' . esc_url( $u ) . '">' . esc_html( $n ) . '</a></li>';
    }
    echo '</ul>';
  }
  echo '<p class="description">Akun staf portal dibuat di <a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">Pengguna &rarr; Tambah Baru</a> dengan peran &ldquo;Staf Portal GPM&rdquo; dan email yang sama dengan akun di menu Pengguna portal.</p>';
  echo '</div>';
}
