# Menjalankan dan menyiapkan Railway

Paket ini konfigurasi deployment; belum pernah dideploy atau dibangun dengan Docker di sesi ini. Ikuti STATUS.md untuk batas verifikasi.

## Lokal melalui Docker Compose

1. Install Docker dan Compose. Salin backend/.env.example ke backend/.env.
2. Buat APP_KEY: `php artisan key:generate --show` di backend setelah `composer install` (PHP 8.3 + ekstensi composer diperlukan). Buat BID_ENCRYPTION_KEY terpisah: `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`.
3. Isi dua key di backend/.env. Tetapkan LOCAL_DB_PASSWORD melalui environment terminal atau berkas .env root yang tidak masuk version control.
4. `docker compose build`
5. `docker compose up -d postgres redis scanner mail`
6. `docker compose run --rm app php artisan migrate --force`
7. Hanya sandbox lokal: `docker compose run --rm app php artisan procurement:sandbox`
8. `docker compose up -d app worker scheduler`
9. Buka http://localhost:8080. Mail sandbox ada di http://localhost:8025.

Demo hanya sandbox: requester@sandbox.test / SandboxOnly!2026. Email peran lain mengikuti ROLE@sandbox.test sesuai Access::ROLES. Jangan seed sandbox pada production. Akun ini bukan kredensial produksi.

## Railway staging/production

Hubungkan source root berisi Dockerfile ke repository. Buat layanan web/API, worker, scheduler, PostgreSQL dan Redis. Untuk web gunakan railway.json. Worker dan scheduler menggunakan railway/worker.json dan railway/scheduler.json sebagai config path masing-masing; jangan pasang health HTTP pada layanan tanpa HTTP.

Salin railway/variables.env.example sebagai daftar konfigurasi, bukan sebagai secret siap pakai. Nama referensi `${{Postgres.DATABASE_URL}}` dan `${{Redis.REDIS_URL}}` harus disesuaikan nama layanan target. Gunakan APP_URL domain HTTPS sebenarnya. Buat APP_KEY dan BID_ENCRYPTION_KEY berbeda dan acak. Isi private S3 bucket/endpoint/key/secret, ClamAV internal host dan SMTP nyata. Production startup menolak storage lokal dan scanner yang belum dikonfigurasi.

Web migration dilakukan pre-deploy oleh railway.json. Pastikan hanya satu service migrator dijalankan untuk satu release. Semua service memakai database, Redis dan key yang sama; role masing-masing web/worker/scheduler. Ubah konfigurasi hanya melalui environment service. Jangan upload backend/.env atau storage lokal.

Bootstrap satu kali setelah migrate: set BOOTSTRAP_PASSWORD acak ≥14 karakter lalu jalankan `php artisan procurement:bootstrap "Nama Organisasi" "email-admin-sebenarnya"`. Hapus variable password setelah sukses, login dan enroll MFA sebelum penggunaan. Bootstrap menolak bila user telah ada. Lengkapi legal entity, cost center, budget, vendor dan approval policy sebelum transaksi.

## Smoke dan acceptance

- `/up`: liveness Laravel; `/health/ready`: koneksi PostgreSQL dan Redis yang diperlukan.
- Login/CSRF/MFA, membuat PR, self-approval ditolak, vendor scope, worker dan scheduler heartbeat.
- Upload harus tetap quarantine sampai scanner clean; lampiran private tidak boleh public.
- Outbox gagal tidak boleh dilabel berhasil. Tidak ada ERP produksi tanpa adapter/mapping terverifikasi.
- Backup/restore database serta object storage; catat hasil staging, RPO/RTO terukur.
- Jalankan seluruh release gates di RELEASE_CHECKLIST.md.

## Backup dan rollback

railway/backup.sh memerlukan DATABASE_URL dan BACKUP_FILE pada penyimpanan tahan lama yang dilindungi. railway/restore.sh memerlukan RESTORE_DATABASE_URL yang terpisah dan BACKUP_FILE. Jangan restore drill ke database produksi. Full dump ini bukan PITR; atur backup/PITR managed secara terpisah sesuai target dan verifikasi pemulihannya.

Rollback container hanya untuk kode yang kompatibel dengan schema. Jangan otomatis migrate:rollback atau menghapus data transaksi. Restore hanya setelah target, downtime, integritas dan keputusan recovery disepakati pemilik sistem. Catat versi release dan migration sebelumnya.
