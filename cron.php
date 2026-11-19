<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* Jadwal harian. Di hPanel, WP-Cron sebaiknya dijalankan oleh cron job sungguhan (lihat README). */
function gpm_jadwalkan() {
  if ( ! wp_next_scheduled( 'gpm_cron_harian' ) ) {
    wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'gpm_cron_harian' );
  }
}
add_action( 'gpm_cron_harian', 'gpm_tugas_harian' );

function gpm_tugas_harian() {
  gpm_cadangkan();
  gpm_cermin_semua(); // jaring pengaman: tabel terstruktur disamakan lagi dengan kv_store
}

/* Folder cadangan: nama acak + .htaccess/index.php supaya tidak bisa dibuka lewat URL */
function gpm_folder_cadangan() {
  $tok = get_option( 'gpm_folder_token' );
  if ( ! $tok ) { $tok = wp_generate_password( 16, false, false ); update_option( 'gpm_folder_token', $tok, false ); }
  $up  = wp_upload_dir();
  $dir = trailingslashit( $up['basedir'] ) . 'gpm-backup-' . $tok;
  if ( ! is_dir( $dir ) ) {
    wp_mkdir_p( $dir );
    file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
    file_put_contents( $dir . '/index.php', "<?php // senyap\n" );
  }
  return $dir;
}

/* Cadangan = seluruh isi kv_store (tanpa rahasia login) dalam satu berkas JSON terkompresi */
function gpm_cadangkan() {
  $dir  = gpm_folder_cadangan();
  $data = gpm_bundle_staf();
  $isi  = gpm_json( array( 'dibuat' => gmdate( 'c' ), 'versi' => GPM_VER, 'data' => (object) $data ) );
  if ( function_exists( 'gzencode' ) ) { $isi = gzencode( $isi, 6 ); $ext = '.json.gz'; } else { $ext = '.json'; }
  $nama = 'gpm-' . gmdate( 'Ymd-His' ) . $ext;
  if ( file_put_contents( $dir . '/' . $nama, $isi ) === false ) return false;

  // simpan 14 cadangan terbaru
  $semua = glob( $dir . '/gpm-*' ) ?: array();
  rsort( $semua );
  foreach ( array_slice( $semua, 14 ) as $lama ) @unlink( $lama );
  update_option( 'gpm_cadangan_terakhir', time(), false );
  return $nama;
}

function gpm_daftar_cadangan() {
  $semua = glob( gpm_folder_cadangan() . '/gpm-*' ) ?: array();
  rsort( $semua );
  return array_map( 'basename', $semua );
}
