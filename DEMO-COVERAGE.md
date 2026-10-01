# Demo lengkap dan login tanpa MFA

Paket ini berisi file pengganti relatif terhadap root repository. Salin ke repository, commit/push main, lalu deploy web dan worker dari commit yang sama. Hapus aset frontend lama yang tidak lagi dirujuk jika diperlukan. Ini belum diterapkan ke Railway.

Sesudah deployment, jalankan SEKALI melalui console/SSH Railway di service web:

    cd /app
    php artisan procurement:demo --force

Masukkan password minimal 12 karakter pada prompt; Enter menghasilkan password acak. Console menampilkan email dan password akun baru. Jangan memasang perintah ini di start command atau preDeployCommand. Predeploy tetap `php artisan migrate --force`.

Akun utama: demo.admin@example.com. Akun lain memakai pola demo.<role>@example.com dengan role requester, approver, procurement, manager, evaluator, legal, receiver, finance, auditor, vendor_admin, vendor_staff. Ada manager2, evaluator2, approver2 untuk panel dan delegasi. Semua akun baru memakai password yang dipilih saat instalasi. Login cukup email/password; MFA routes dan layar enrollment/verification sudah dihapus. Kolom database lama tetap kompatibel, tetapi tidak menghalangi login. Hak akses, status akun, tenant isolation, dan session timeout tetap berjalan.

Seeder membuat workspace terpisah `demo-feature-workspace`. Tidak menghapus tenant lain atau menimpa akun. Pengulangan tidak menggandakan data atau mereset password. Jika email demo sudah digunakan tenant lain, instalasi dibatalkan. Data historis sintetis dilabeli sebagai demo dalam audit.

| Fitur | Data demo |
|---|---|
| Dashboard dan daftar dokumen | Lebih dari 20 dokumen, seluruh 8 jenis |
| Master data | Legal entity, department, cost center, warehouse, category, item, currency, tax code, payment term, procurement plan |
| Vendor | Approved, under review, draft; portal dua vendor dan perubahan vendor pending |
| PR dan anggaran | Draft, pending, returned, rejected, approved; reservation, commitment, actual |
| Tender | Draft, published, technical open, commercial open, pending approval, awarded; 3 penawar, encrypted bids, dua evaluator, panel opening |
| Kontrak dan PO | Draft/active contract; draft, approved, issued, acknowledged, partially received, received, cancelled PO |
| Penerimaan dan retur | Draft dan posted, partial receipt, posted return |
| Invoice dan kredit | Draft, mismatch, matched, pending approval, approved, paid; draft/posted credit |
| Workflow | Policy lima jenis dokumen, dua tahap, pending approval dan delegasi |
| Riwayat dan audit | Document revision, snapshot approval sintetis, audit hash chain |
| Notifikasi | Read/unread untuk tiap akun |
| Impor/ekspor | Preview impor item; job CSV/XLSX dan file contoh di demo-imports |
| Lampiran | File spesifikasi privat; status mengikuti hasil scanner sungguhan |
| Integrasi | REST/SOAP nonaktif, integration event, outbox delivered/dead sintetis |

Prasyarat: migration terbaru, APP_KEY dan BID_ENCRYPTION_KEY valid, database PostgreSQL, object storage, queue worker dan scanner. Lampiran tidak dipalsukan sebagai clean. Integrasi tidak mengirim ke ERP. OIDC, koneksi ERP, malware scanner dan konfigurasi infrastruktur memakai layanan nyata; tidak dapat digantikan oleh data demo.

Jika storage/job gagal sesudah transaksi database selesai, console tetap menampilkan kredensial akun yang telah dibuat. Perbaiki storage lalu lakukan upload/ekspor dari aplikasi; rerun tidak memodifikasi workspace.

Validasi: frontend TypeScript/Vite build berhasil; 48 tes backend, 314 assertion lulus pada database PostgreSQL terpisah. Termasuk login, session security, seeder idempotency, attachment dispatch dan CSV/XLSX export.
