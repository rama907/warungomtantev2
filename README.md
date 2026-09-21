# warungomtantev2

Aplikasi manajemen karyawan dan operasional (PHP + MySQL, antarmuka Next.js/Tailwind).

## Pemasangan
1. Salin `config.example.php` menjadi `config.php` dan isi database, kunci API, dan webhook Anda (jangan di-commit).
2. Impor `database.schema.sql` ke MySQL/MariaDB.
3. Buat akun admin pertama dengan password kuat (lihat komentar di `database.schema.sql`).
4. Isi `CRON_SECRET_KEY` dengan string acak panjang bila cron dipanggil lewat web.

## Keamanan
Rahasia (config.php, .env, dump database) tidak disimpan di repo ini. Lihat `.gitignore`.
