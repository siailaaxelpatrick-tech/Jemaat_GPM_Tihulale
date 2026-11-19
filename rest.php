<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* Pengganti api.php: /wp-json/gpm/v1/store */
add_action( 'rest_api_init', function () {
  register_rest_route( 'gpm/v1', '/store', array(
    array(
      'methods'             => 'GET',
      'callback'            => 'gpm_rest_baca',
      'permission_callback' => '__return_true', // pengunjung hanya menerima bundel publik (lihat gpm_bundle_publik)
    ),
    array(
      'methods'             => 'POST',
      'callback'            => 'gpm_rest_tulis',
      'permission_callback' => function () { return current_user_can( 'gpm_kelola' ); },
    ),
  ) );
  register_rest_route( 'gpm/v1', '/buletin-pdf', array(
    'methods'             => 'POST',
    'callback'            => 'gpm_rest_unggah_pdf',
    'permission_callback' => function () { return current_user_can( 'gpm_kelola' ); },
  ) );
  register_rest_route( 'gpm/v1', '/media-bangun', array(
    'methods'             => 'POST',
    'callback'            => 'gpm_rest_unggah_video',
    'permission_callback' => function () { return current_user_can( 'gpm_kelola' ); },
  ) );
  register_rest_route( 'gpm/v1', '/sinkron', array(
    'methods'             => 'POST',
    'callback'            => function () { return array( 'ok' => true, 'sinkron' => gpm_cermin_semua() ); },
    'permission_callback' => function () { return current_user_can( 'manage_options' ); },
  ) );
} );

/* Unggah PDF buletin (hingga GPM_PDF_MAKS) langsung sebagai berkas, tanpa lewat JSON/localStorage */
function gpm_rest_unggah_pdf( WP_REST_Request $req ) {
  $fp = $req->get_file_params();
  $f  = $fp['file'] ?? null;
  if ( ! is_array( $f ) || ! isset( $f['error'] ) ) {
    return new WP_Error( 'gpm_pdf', 'Berkas tidak diterima server. Naikkan post_max_size dan upload_max_filesize di php.ini (mis. 110M dan 100M), lalu restart Apache.', array( 'status' => 413 ) );
  }
  if ( $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ) {
    return new WP_Error( 'gpm_pdf', 'Ukuran berkas melebihi batas PHP (upload_max_filesize = ' . ini_get( 'upload_max_filesize' ) . '). Naikkan di php.ini lalu restart Apache.', array( 'status' => 413 ) );
  }
  if ( $f['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file( $f['tmp_name'] ) ) {
    return new WP_Error( 'gpm_pdf', 'Unggahan gagal (kode ' . (int) $f['error'] . ').', array( 'status' => 400 ) );
  }
  if ( (int) $f['size'] > GPM_PDF_MAKS ) {
    return new WP_Error( 'gpm_pdf', 'Ukuran PDF maksimal ' . ( GPM_PDF_MAKS / MB_IN_BYTES ) . ' MB.', array( 'status' => 413 ) );
  }
  $h = fopen( $f['tmp_name'], 'rb' ); $kepala = $h ? fread( $h, 4 ) : ''; if ( $h ) fclose( $h );
  if ( $kepala !== '%PDF' ) return new WP_Error( 'gpm_pdf', 'Berkas bukan PDF yang sah.', array( 'status' => 415 ) );

  $d = gpm_buletin_dir();
  if ( ! wp_mkdir_p( $d['path'] ) ) return new WP_Error( 'gpm_pdf', 'Folder uploads/gpm-buletin tidak bisa dibuat (periksa izin folder).', array( 'status' => 500 ) );
  if ( ! file_exists( $d['path'] . '/index.php' ) ) file_put_contents( $d['path'] . '/index.php', "<?php // senyap\n" );
  $nama = 'b' . strtolower( wp_generate_password( 24, false, false ) ) . '.pdf';
  if ( ! move_uploaded_file( $f['tmp_name'], $d['path'] . '/' . $nama ) ) {
    return new WP_Error( 'gpm_pdf', 'PDF tidak bisa disimpan ke folder uploads (periksa izin folder).', array( 'status' => 500 ) );
  }
  return array( 'ok' => true, 'pdf' => $d['url'] . '/' . $nama, 'berkas' => sanitize_file_name( wp_basename( (string) $f['name'] ) ), 'ukuran' => (int) $f['size'] );
}

/* Unggah video pembangunan (hingga GPM_VIDEO_MAKS) langsung sebagai berkas di uploads/gpm-media/.
   Jenis berkas dicek dari isinya (bukan dari nama/Content-Type): MP4/MOV (kotak "ftyp") atau WebM (header EBML). */
function gpm_rest_unggah_video( WP_REST_Request $req ) {
  $fp = $req->get_file_params();
  $f  = $fp['file'] ?? null;
  if ( ! is_array( $f ) || ! isset( $f['error'] ) ) {
    return new WP_Error( 'gpm_video', 'Berkas tidak diterima server. Naikkan post_max_size dan upload_max_filesize di php.ini (mis. 2100M dan 2048M), lalu restart Apache.', array( 'status' => 413 ) );
  }
  if ( $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ) {
    return new WP_Error( 'gpm_video', 'Ukuran video melebihi batas PHP (upload_max_filesize = ' . ini_get( 'upload_max_filesize' ) . '). Naikkan di php.ini lalu restart Apache, atau kecilkan video.', array( 'status' => 413 ) );
  }
  if ( $f['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file( $f['tmp_name'] ) ) {
    return new WP_Error( 'gpm_video', 'Unggahan gagal (kode ' . (int) $f['error'] . ').', array( 'status' => 400 ) );
  }
  if ( (int) $f['size'] > GPM_VIDEO_MAKS ) {
    return new WP_Error( 'gpm_video', 'Ukuran video maksimal ' . size_format( GPM_VIDEO_MAKS ) . '.', array( 'status' => 413 ) );
  }
  $jenis = gpm_video_jenis( $f['tmp_name'] );
  if ( $jenis === '' ) return new WP_Error( 'gpm_video', 'Berkas bukan video yang didukung (MP4, WebM, atau MOV).', array( 'status' => 415 ) );

  $d = gpm_media_dir();
  if ( ! wp_mkdir_p( $d['path'] ) ) return new WP_Error( 'gpm_video', 'Folder uploads/gpm-media tidak bisa dibuat (periksa izin folder).', array( 'status' => 500 ) );
  if ( ! file_exists( $d['path'] . '/index.php' ) ) file_put_contents( $d['path'] . '/index.php', "<?php // senyap\n" );
  // Nama berkas acak dan tidak pernah ditimpa, jadi aman di-cache lama oleh peramban/CDN
  if ( ! file_exists( $d['path'] . '/.htaccess' ) ) {
    file_put_contents( $d['path'] . '/.htaccess', "Options -Indexes\n<IfModule mod_expires.c>\nExpiresActive On\nExpiresByType video/mp4 \"access plus 1 year\"\nExpiresByType video/webm \"access plus 1 year\"\nExpiresByType video/quicktime \"access plus 1 year\"\n</IfModule>\n<IfModule mod_headers.c>\nHeader set Cache-Control \"public, max-age=31536000, immutable\"\nHeader set X-Content-Type-Options \"nosniff\"\n</IfModule>\n" );
  }
  $nama = 'v' . strtolower( wp_generate_password( 24, false, false ) ) . '.' . $jenis;
  if ( ! move_uploaded_file( $f['tmp_name'], $d['path'] . '/' . $nama ) ) {
    return new WP_Error( 'gpm_video', 'Video tidak bisa disimpan ke folder uploads (periksa izin folder).', array( 'status' => 500 ) );
  }
  return array( 'ok' => true, 'video' => $d['url'] . '/' . $nama, 'berkas' => sanitize_file_name( wp_basename( (string) $f['name'] ) ), 'ukuran' => (int) $f['size'] );
}

function gpm_rest_baca( WP_REST_Request $req ) {
  $staf = is_user_logged_in() && current_user_can( 'gpm_kelola' );
  $etag = '"' . md5( gpm_versi() . ( $staf ? ':s:' . get_current_user_id() : ':p' ) ) . '"';

  $nol = $req->get_header( 'if_none_match' );
  if ( $nol && trim( $nol ) === $etag ) {
    $r = new WP_REST_Response( null, 304 );
    $r->header( 'ETag', $etag );
    return $r;
  }

  $data = $staf ? gpm_bundle_staf() : gpm_bundle_publik();
  $r = new WP_REST_Response( array( 'ok' => true, 'auth' => $staf, 'data' => (object) $data ), 200 );
  $r->header( 'ETag', $etag );
  // Publik boleh disimpan CDN/peramban sebentar; data staf tidak pernah disimpan bersama.
  $r->header( 'Cache-Control', $staf ? 'private, no-store' : 'public, max-age=60, s-maxage=300' );
  return $r;
}

function gpm_rest_tulis( WP_REST_Request $req ) {
  $in = $req->get_json_params();
  if ( ! is_array( $in ) || ! isset( $in['items'] ) || ! is_array( $in['items'] ) ) {
    return new WP_Error( 'gpm_format', 'Format salah', array( 'status' => 400 ) );
  }
  $hasil = gpm_kv_tulis( $in['items'], current_user_can( 'manage_options' ) );
  if ( is_wp_error( $hasil ) ) return $hasil;
  return array( 'ok' => true, 'cermin_gagal' => (object) $hasil );
}
