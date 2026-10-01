# Status LayananBisnis eProcurement — 1 Oktober 2026

Status keseluruhan: **IMPLEMENTASI PARSIAL, TERVERIFIKASI LOKAL; BELUM PRODUCTION APPROVED**.
Source sebelumnya ditemukan di workspace lama dan dilanjutkan tanpa mengganti stack.

## Pemeriksaan aktual

- React TypeScript typecheck + Vite production build: PASS.
- PHPUnit PostgreSQL: 47 tests, 252 assertions, PASS. Bukti: qa/phpunit.xml.
- Browser: requester login, pembuatan draft PR, submit PR, desktop/mobile dashboard: PASS. Tidak ada page error atau mobile overflow pada jalur ini. Axe tidak menemukan pelanggaran pada dua halaman dashboard yang diuji; belum audit WCAG manual menyeluruh.
- Local backup restore: PostgreSQL 16 custom dump ke database terpisah, seluruh 45 tabel public mempunyai jumlah baris sama. Bukti: qa/restore-drill.json. Bukan bukti PITR, backup cloud, object storage restore, RPO/RTO, atau DR produksi.
- Docker image build, deploy Railway, beban 50 rps/30 menit, UAT dan integrasi ERP produksi: NOT VERIFIED/BLOCKED.

## Peta modul

| Modul | Implementasi aktual | Verifikasi | Kekurangan utama |
|---|---|---|---|
| Login, tenancy, RBAC, MFA | Session, scope, MFA TOTP/recovery, OIDC adapter | API tests; requester browser | Seluruh peran browser, revocation/recovery operasional, IdP nyata |
| Dashboard | KPI database dan scope akses | API + browser | Semua KPI/SLA/saved filters/escalation |
| Master data dan import | Struktur organisasi, katalog, workflow policy, CSV/XLSX preview/commit | CSV test, formula rejection | Konversi UOM, penomoran lanjutan, seluruh format/resources |
| Vendor | Review dan status, portal akses vendor, maker-checker bank | API tests | Registrasi publik/email verification, dokumen expiry, performance score |
| Budget | Reservation/commitment/actual, row locks, ledger | Overspend race, return/release, PR→PO | Workflow transfer/revisi/override, seluruh multicurrency edge cases |
| PR | Draft multiline, scope, revisions/versioning, workflow | API + requester browser | Autosave, penuh status split/merge sourcing |
| Tender | Publish, invite, encrypted bids, deadline, quorum, technical/commercial, evaluation/award | Sealed bid/deadline/revision tests | Semua metode/lot/multivendor/clarification/addenda/negotiation |
| Kontrak | Dokumen, approval, activation, sumber PO/consumption guard | Belum suite lifecycle lengkap | Renewal, termination, reminders, templates, e-signature |
| PO | Source PR/award, approval, issue, vendor acknowledgement, PDF | Direct flow, reservation consumption | Change order, email pengiriman nyata, scheduling lengkap |
| Receipt/return | Partial receipt, over-receipt guard, kompensasi return | Partial/over-receipt tests | Inspection, tolerance exception approvals, service acceptance lengkap |
| Invoice/credit/payment | Matching line, duplicate guard, allocation, credit, pencatatan referensi | Direct flow dan partial allocation | Aging/dispute/tolerances/ERP posting nyata; tidak mentransfer dana |
| Laporan/export/audit | Scoped CSV/XLSX/PDF async, append-only audit trigger/hash | Export revocation, audit update denied | Seluruh laporan wajib, storage retention/WORM/legal hold |
| Integrasi | REST boundary, SOAP safe transport/fixture, outbox retry/replay, webhook sequence | Adapter/security fixtures | Field mapping ERP nyata; webhook hanya mencatat event, tidak mengubah payment record |
| Lampiran | Private upload, quarantine, scanner job, akses berizin | Missing scanner fail-closed | ClamAV/S3 nyata, malicious parser/format corpus |
| Operasional | Docker/Compose, CI, Railway service configs, startup validation, health DB/Redis | 6 runtime regression tests; restore lokal | Deploy smoke, Docker build, monitor/alerts, SBOM/SAST/container scan |
| Bahasa/aksesibilitas | UI Indonesia dengan sebagian istilah Inggris; responsive | Dua dashboard axe scans | i18n lengkap dan manual WCAG seluruh peran/PDF/email |

## Perubahan sesi ini

Health check kini memeriksa PostgreSQL serta setiap Redis connection yang digunakan cache/session/queue, mengembalikan 503 tanpa detail rahasia saat gagal. Endpoint health tidak memulai session. Startup menolak APP_KEY invalid, kunci bid yang sama dengan APP_KEY, serta Redis retry_after ≤130 detik.

## Urutan pekerjaan berikutnya

1. Lengkapi workflow kontrak, vendor onboarding, tender addendum/clarification/multi-vendor award dan change order.
2. Lengkapi notifikasi email, i18n, laporan, authorization seluruh lifecycle dan traceability granular.
3. Tambah race tests award/receipt/invoice, browser seluruh peran, dependency/security scans dan load benchmark.
4. Bangun container dan deploy staging Railway dengan PostgreSQL/Redis/S3/scanner/SMTP nyata. Jalankan smoke dan restore drill di staging.
5. Integrasi ERP/IdP/e-signature hanya setelah kontrak dan kredensial tersedia, lalu UAT stakeholder.

Jangan mengubah semua requirement menjadi VERIFIED hanya karena suite ini lulus. Matrix REQUIREMENTS.md mempertahankan batas bukti tiap requirement.
