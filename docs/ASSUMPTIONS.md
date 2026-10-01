# Asumsi

- Pengadaan perusahaan swasta Indonesia; bukan SPSE pemerintah.
- Railway: layanan web, worker, scheduler, PostgreSQL, Redis dan private S3 storage terpisah.
- Tidak memindahkan dana. Payment reference adalah rekaman finance/ERP.
- ERP, IdP, SMTP produksi, e-signature dan WORM memerlukan akses asli.
- Single currency budget ledger menyimpan currency/kurs snapshot; tarif pajak input policy, bukan klaim tarif legal.
- Produksi tidak boleh seed demo.
