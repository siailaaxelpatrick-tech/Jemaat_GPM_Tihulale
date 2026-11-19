<?php
/**
 * Plugin Name: Portal GPM Tihulale
 * Description: Portal Jemaat Gereja Beth Eden (GPM Tihulale) di atas WordPress: data tersimpan di MySQL (tabel terstruktur), login memakai akun WordPress, cache lewat WordPress Object Cache, cadangan harian lewat cron.
 * Version: 1.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: gpm-tihulale
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GPM_VER', '1.5.0' );
define( 'GPM_PDF_MAKS', 100 * MB_IN_BYTES ); // batas ukuran satu PDF buletin
define( 'GPM_VIDEO_MAKS', 2048 * MB_IN_BYTES ); // batas ukuran satu video pembangunan
define( 'GPM_DB_VER', '3' );
define( 'GPM_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPM_URL', plugin_dir_url( __FILE__ ) );

require_once GPM_DIR . 'includes/schema.php';
require_once GPM_DIR . 'includes/store.php';
require_once GPM_DIR . 'includes/rest.php';
require_once GPM_DIR . 'includes/portal.php';
require_once GPM_DIR . 'includes/cron.php';
if ( is_admin() ) require_once GPM_DIR . 'includes/admin.php';

/* Aktivasi: tabel, peran, halaman portal, jadwal cron */
register_activation_hook( __FILE__, function () {
  gpm_buat_tabel();
  gpm_siapkan_peran();
  gpm_siapkan_halaman();
  gpm_jadwalkan();
} );

register_deactivation_hook( __FILE__, function () {
  wp_clear_scheduled_hook( 'gpm_cron_harian' );
} );

/* Deploy lewat Git tidak menjalankan aktivasi ulang, jadi skema dicek tiap pembaruan versi */
add_action( 'plugins_loaded', function () {
  if ( get_option( 'gpm_db_ver' ) !== GPM_DB_VER ) {
    gpm_buat_tabel();
    gpm_siapkan_peran();
    // Pindahkan PDF buletin lama (base64 di database) menjadi berkas di uploads, lalu salin ke tabel buletin
    try { $kv = gpm_kv_semua(); if ( isset( $kv['gpm_buletin'] ) ) gpm_kv_tulis( array( 'gpm_buletin' => $kv['gpm_buletin'] ) ); }
    catch ( Throwable $e ) { /* diulang saat buletin disimpan berikutnya */ }
  }
  if ( ! wp_next_scheduled( 'gpm_cron_harian' ) ) gpm_jadwalkan();
} );

/* Peran: Administrator dan "Staf Portal GPM" boleh mengelola data portal */
function gpm_siapkan_peran() {
  $admin = get_role( 'administrator' );
  if ( $admin && ! $admin->has_cap( 'gpm_kelola' ) ) $admin->add_cap( 'gpm_kelola' );
  if ( ! get_role( 'gpm_staf' ) ) {
    add_role( 'gpm_staf', 'Staf Portal GPM', array( 'read' => true, 'gpm_kelola' => true ) );
  }
}

/* Halaman "Portal Jemaat": dibuat sekali; jadi beranda hanya bila situs belum memakai halaman statis */
function gpm_siapkan_halaman() {
  $id = (int) get_option( 'gpm_halaman_id' );
  if ( $id && get_post_status( $id ) ) return;
  $id = wp_insert_post( array(
    'post_title'   => 'Portal Jemaat',
    'post_name'    => 'portal-jemaat',
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => '',
  ) );
  if ( ! $id || is_wp_error( $id ) ) return;
  update_option( 'gpm_halaman_id', (int) $id );
  if ( get_option( 'show_on_front' ) !== 'page' ) {
    update_option( 'show_on_front', 'page' );
    update_option( 'page_on_front', (int) $id );
  }
}
