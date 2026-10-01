# LayananBisnis eProcurement

Aplikasi React TypeScript + Laravel PHP 8.3, PostgreSQL, Redis, private object storage.

Baca docs/STATUS.md untuk hasil aktual dan cakupan yang masih parsial. docs/DEPLOYMENT.md berisi cara menjalankan lokal dan konfigurasi Railway. docs/RELEASE_CHECKLIST.md berisi gate sebelum produksi.

Source backend/app berisi domain/API, backend/database berisi migrations dan sandbox seeder. frontend/src berisi UI. railway berisi konfigurasi runtime. Dependency lockfiles disertakan; vendor dan node_modules tidak dikemas. Build frontend yang diperiksa disertakan di backend/public/app.

Verifikasi biasa: `composer install` di backend, `npm ci && npm run build` di frontend, lalu `php vendor/bin/phpunit` di backend dengan PostgreSQL database terpisah eprocurement_test. Jangan arahkan phpunit atau sandbox seeder ke produksi.
