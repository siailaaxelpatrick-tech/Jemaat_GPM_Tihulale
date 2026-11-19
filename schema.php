<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* Nama tabel dengan awalan WordPress, mis. wp_gpm_warga */
function gpm_t( $nama ) {
  global $wpdb;
  return $wpdb->prefix . 'gpm_' . $nama;
}

function gpm_buat_tabel() {
  global $wpdb;
  $cs = $wpdb->get_charset_collate();
  $t  = 'gpm_t';

  $ddl = array(
    'kv_store' => "k VARCHAR(64) NOT NULL, v LONGTEXT NOT NULL,
      diperbarui TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (k)",
    'sektor' => "id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT, nama VARCHAR(40) NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY uq_sektor_nama (nama)",
    'warga' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, nama VARCHAR(150) NOT NULL, sektor_id TINYINT UNSIGNED NOT NULL,
      kategori ENUM('Kepala Keluarga','Istri','Janda','Duda','Pemuda','Anak') NOT NULL,
      jenis_kelamin ENUM('Laki-laki','Perempuan') DEFAULT NULL,
      status ENUM('Aktif','Pindah','Meninggal') NOT NULL DEFAULT 'Aktif',
      tgl_lahir DATE DEFAULT NULL, telp VARCHAR(30) DEFAULT NULL, alamat TEXT DEFAULT NULL,
      persetujuan ENUM('Disetujui','Menunggu','Ditolak') NOT NULL DEFAULT 'Disetujui',
      dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      diubah_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id), KEY idx_warga_sektor (sektor_id), KEY idx_warga_status (status, persetujuan), KEY idx_warga_nama (nama)",
    'mutasi_jemaat' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, tanggal DATE NOT NULL, nama VARCHAR(150) NOT NULL,
      jenis ENUM('Pindah Masuk','Pindah Keluar') NOT NULL, sektor_id TINYINT UNSIGNED DEFAULT NULL,
      asal_tujuan VARCHAR(150) DEFAULT NULL, alasan VARCHAR(255) DEFAULT NULL,
      PRIMARY KEY (id), KEY idx_mutasi_tanggal (tanggal)",
    'surat' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, nomor VARCHAR(80) NOT NULL, tanggal DATE NOT NULL,
      jenis ENUM('Surat Masuk','Surat Keluar') NOT NULL, pihak VARCHAR(200) DEFAULT NULL, diterima VARCHAR(150) DEFAULT NULL,
      kategori VARCHAR(60) DEFAULT NULL, perihal VARCHAR(255) DEFAULT NULL, keterangan TEXT DEFAULT NULL,
      persetujuan ENUM('Menunggu','Disetujui','Ditolak') NOT NULL DEFAULT 'Menunggu',
      dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id), UNIQUE KEY uq_surat_nomor (nomor), KEY idx_surat_tanggal (tanggal)",
    'renungan' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, tanggal DATE NOT NULL, judul VARCHAR(200) NOT NULL,
      ayat VARCHAR(100) DEFAULT NULL, teks TEXT DEFAULT NULL, isi LONGTEXT DEFAULT NULL, doa TEXT DEFAULT NULL, penulis VARCHAR(120) DEFAULT NULL,
      PRIMARY KEY (id), KEY idx_renungan_tanggal (tanggal)",
    'agenda' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, judul VARCHAR(200) NOT NULL, kategori VARCHAR(40) NOT NULL,
      waktu VARCHAR(120) DEFAULT NULL, deskripsi TEXT DEFAULT NULL, dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)",
    'agenda_sektor' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, sektor_id TINYINT UNSIGNED NOT NULL, judul VARCHAR(200) NOT NULL,
      kategori VARCHAR(40) NOT NULL, waktu VARCHAR(120) DEFAULT NULL, deskripsi TEXT DEFAULT NULL,
      tayang TINYINT(1) NOT NULL DEFAULT 0, publikasi ENUM('Menunggu','Disetujui','Ditolak') NOT NULL DEFAULT 'Menunggu', dibuat DATE DEFAULT NULL,
      PRIMARY KEY (id), KEY idx_as_sektor (sektor_id), KEY idx_as_publik (tayang, publikasi)",
    'panitia_pembangunan' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, jabatan VARCHAR(100) NOT NULL, nama VARCHAR(150) NOT NULL,
      urut SMALLINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (id)",
    'jadwal_ibadah' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, tanggal DATE NOT NULL, jam TIME DEFAULT NULL, tempat VARCHAR(150) NOT NULL,
      jenis VARCHAR(60) NOT NULL, unit VARCHAR(100) DEFAULT NULL, tema VARCHAR(255) DEFAULT NULL, pelayan VARCHAR(150) DEFAULT NULL,
      PRIMARY KEY (id), KEY idx_ibadah_tanggal (tanggal), KEY idx_ibadah_jenis (jenis)",
    'notulen' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, jenis VARCHAR(60) NOT NULL, judul VARCHAR(200) NOT NULL, tanggal DATE NOT NULL,
      tempat VARCHAR(150) DEFAULT NULL, pimpinan VARCHAR(120) DEFAULT NULL, notulis VARCHAR(120) DEFAULT NULL,
      peserta TEXT DEFAULT NULL, agenda TEXT DEFAULT NULL, isi LONGTEXT DEFAULT NULL,
      dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id), KEY idx_notulen_tanggal (tanggal)",
    'notulen_keputusan' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, notulen_id INT UNSIGNED NOT NULL, teks TEXT NOT NULL,
      pj VARCHAR(120) DEFAULT NULL, target DATE DEFAULT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Belum',
      PRIMARY KEY (id), KEY idx_nk_notulen (notulen_id)",
    'program_pelkes' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, tahun YEAR NOT NULL, unit VARCHAR(100) NOT NULL, nama VARCHAR(200) NOT NULL,
      target VARCHAR(255) DEFAULT NULL, capaian TINYINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(30) DEFAULT NULL,
      anggaran DECIMAL(15,2) NOT NULL DEFAULT 0.00, hasil TEXT DEFAULT NULL,
      PRIMARY KEY (id), KEY idx_pelkes_tahun (tahun)",
    'buletin' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT, uid VARCHAR(40) NOT NULL, tanggal DATE NOT NULL,
      edisi VARCHAR(120) NOT NULL, judul VARCHAR(200) NOT NULL, deskripsi TEXT DEFAULT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'Terbit', berkas VARCHAR(255) DEFAULT NULL, pdf LONGTEXT DEFAULT NULL,
      dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      diubah_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id), UNIQUE KEY uq_buletin_uid (uid), KEY idx_buletin_tanggal (tanggal), KEY idx_buletin_status (status, tanggal)",
  );

  foreach ( $ddl as $nama => $kolom ) {
    $wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . $t( $nama ) . " ( $kolom ) ENGINE=InnoDB $cs" );
  }

  // Enam sektor jemaat
  foreach ( array( 'Sektor Sion', 'Sektor Galelia', 'Sektor Ebenhaezer', 'Sektor Getsemany', 'Sektor Tiberias', 'Sektor Betlehem' ) as $s ) {
    $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . $t( 'sektor' ) . ' (nama) VALUES (%s)', $s ) );
  }

  // Tampilan statistik (opsional; dilewati bila hosting tidak mengizinkan VIEW)
  $w = $t( 'warga' ); $s = $t( 'sektor' );
  $wpdb->suppress_errors( true );
  $wpdb->query( 'CREATE OR REPLACE VIEW ' . $t( 'v_statistik_publik' ) . " AS SELECT COUNT(*) AS total_penduduk,
    COALESCE(SUM(kategori = 'Kepala Keluarga'),0) AS jumlah_kk, COALESCE(SUM(jenis_kelamin = 'Laki-laki'),0) AS laki_laki,
    COALESCE(SUM(jenis_kelamin = 'Perempuan'),0) AS perempuan, (SELECT COUNT(*) FROM $s) AS total_sektor
    FROM $w WHERE status = 'Aktif' AND persetujuan = 'Disetujui'" );
  $wpdb->query( 'CREATE OR REPLACE VIEW ' . $t( 'v_warga_per_sektor' ) . " AS SELECT s.nama AS sektor, COUNT(w.id) AS jumlah_warga
    FROM $s s LEFT JOIN $w w ON w.sektor_id = s.id AND w.status = 'Aktif' AND w.persetujuan = 'Disetujui' GROUP BY s.id, s.nama" );
  $wpdb->query( 'CREATE OR REPLACE VIEW ' . $t( 'v_buletin_terbit' ) . " AS SELECT id, uid, tanggal, edisi, judul, deskripsi, berkas
    FROM " . $t( 'buletin' ) . " WHERE status = 'Terbit' ORDER BY tanggal DESC, id DESC" );
  $wpdb->suppress_errors( false );

  update_option( 'gpm_db_ver', GPM_DB_VER );
}
