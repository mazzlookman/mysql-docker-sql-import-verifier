# SQL Import & Verification Guide

Dokumen ini menjelaskan langkah import file SQL ke MySQL Docker container dan verifikasi bahwa tabel sudah terbuat.

## Prasyarat

- Docker berjalan
- Container MySQL aktif: `mysql8-api`
- File SQL ditaruh sejajar dengan script PHP (satu folder dengan `import_sql_to_docker.php` dan `verify_import_tables.php`), misalnya:
  - `reg_1_1.sql`
  - `reg_2_490_492.sql`
  - `reg_3_856.sql`
  - `reg_0.sql`
- PHP CLI terpasang (di mesin ini pakai PHP 5.6)

## Konfigurasi yang Dipakai

- Container: `mysql8-api`
- User MySQL: `liquid`
- Password: `liqu304`
- Mapping import:
  - `reg_1_1.sql` -> `reg_1`
  - `reg_2_490_492.sql` -> `reg_2`
  - `reg_3_856.sql` -> `reg_3`
  - `reg_0.sql` -> `reg_0`

## 1) Import Semua SQL

Script import: `import_sql_to_docker.php`

Jalankan:

```bash
php import_sql_to_docker.php
```

Perilaku script:

- Menampilkan progress per file (`Progress ... xx%`)
- Reset database tujuan sebelum import (`DROP DATABASE` + `CREATE DATABASE`)
- Menonaktifkan sementara `FOREIGN_KEY_CHECKS` saat import agar dump legacy lebih aman diproses

## 2) Import Satu Target Saja (`--only`)

Contoh hanya import `reg_0`:

```bash
php import_sql_to_docker.php --only=reg_0
```

Bisa juga:

```bash
php import_sql_to_docker.php --only reg_0
```

`--only` menerima:

- nama database (contoh: `reg_1`)
- nama file (contoh: `reg_1_1.sql`)
- nama file tanpa ekstensi (contoh: `reg_1_1`)

## 3) Verifikasi Tabel Hasil Import

Script verifikasi: `verify_import_tables.php`

Jalankan semua:

```bash
php verify_import_tables.php
```

Jalankan satu target:

```bash
php verify_import_tables.php --only=reg_0
```

Perilaku verifikasi:

- Baca daftar tabel expected dari statement `CREATE TABLE` di file SQL
- Baca daftar tabel actual dari `information_schema.tables`
- Bandingkan per database
  - `OK (match)` jika sama
  - `MISMATCH` jika ada `Missing` atau `Extra`

Jika semua cocok, output akhir:

`Semua tabel sudah ter-create sesuai dump SQL.`

## Troubleshooting Singkat

- Error duplicate key saat import:
  - Biasanya karena database sudah berisi data lama.
  - Jalankan script import normal (karena script sudah reset DB), atau pastikan reset tidak dimatikan.
- Error foreign key:
  - Script sudah set `FOREIGN_KEY_CHECKS=0` selama import.
- Cek sintaks script PHP:

```bash
php -l import_sql_to_docker.php
php -l verify_import_tables.php
```

