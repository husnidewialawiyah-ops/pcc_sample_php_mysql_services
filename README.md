# Services Product API

Konfigurasi ini menjalankan API PHP untuk data produk DummyJSON menggunakan Docker Compose.

## Arsitektur

Service yang tersedia:

- **PHP-FPM**: menjalankan API PHP dan koneksi ke MySQL.
- **Caddy**: web server dan reverse proxy ke PHP-FPM.
- **MySQL**: menyimpan data produk pada folder `databases`.
- **phpMyAdmin**: antarmuka web untuk mengelola MySQL.
- **RabbitMQ**: message broker dengan dashboard management.

Source code PHP berada di folder `htdocs` dan document root Caddy mengarah ke folder tersebut.

## Konfigurasi

File `.env` berisi kredensial MySQL. Konfigurasi Google Authenticator bersifat
opsional karena secret dan account dapat dibuat otomatis:

```dotenv
MYSQL_USER=app
MYSQL_PASSWORD=app_secret
MYSQL_ROOT_PASSWORD=root_secret
# GOOGLE2FA_SECRET=isi_secret_base32_persisten_jika_diperlukan
# GOOGLE2FA_ACCOUNT=admin@localhost
GOOGLE2FA_ISSUER='Products API'
```

Ubahlah nilai tersebut sebelum digunakan di lingkungan production.

## Instalasi Composer dan Library

Library API autentikasi didefinisikan di `htdocs/composer.json`:

- `pragmarx/google2fa`: membuat dan memverifikasi kode TOTP Google Authenticator.
- `bacon/bacon-qr-code`: membuat QR code SVG untuk setup authenticator.

Pada penggunaan Docker, dependency di-install otomatis saat image PHP dibuat:

```bash
docker compose build --no-cache php
docker compose up -d
```

Untuk meng-install atau memperbarui dependency pada container yang sudah
berjalan:

```bash
docker compose run --rm php composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

Dependency disimpan pada volume `php-vendor-v2`, sedangkan secret otomatis
disimpan pada volume `php-data`.

## Menjalankan Service

Dari folder ini, jalankan:

```bash
docker compose up -d --build
```

Melihat status container:

```bash
docker compose ps
```

Melihat log API PHP:

```bash
docker compose logs -f php
```

Menghentikan service:

```bash
docker compose down
```

Data MySQL tetap tersimpan di folder `databases` saat container dihentikan.

## Import Data DummyJSON

Script seed mengambil seluruh data produk menggunakan endpoint:

```text
https://dummyjson.com/products?limit=0
```

Jalankan seed secara manual:

```bash
docker compose exec php php /var/www/html/seed.php
```

Script aman dijalankan berulang kali karena menggunakan `ON DUPLICATE KEY UPDATE` berdasarkan `id` produk.

API hanya membuat tabel jika belum tersedia. Pengisian data DummyJSON dilakukan melalui script seed secara manual.

## Akses Service

| Service | URL atau host |
| --- | --- |
| API PHP melalui Caddy | http://localhost:8086 |
| phpMyAdmin | http://localhost:8085 |
| MySQL dari host | `localhost:3308` |
| RabbitMQ AMQP dari host | `localhost:5674` |
| RabbitMQ Management | http://localhost:15674 |

Untuk koneksi antar-container, gunakan host berikut:

- MySQL: `mysql:3306`
- RabbitMQ: `rabbitmq:5672`

## API Produk

Semua endpoint API menggunakan prefix `/products`.

| Method | Endpoint | Keterangan |
| --- | --- | --- |
| `GET` | `/products` | Mengambil daftar produk |
| `GET` | `/products/{id}` | Mengambil satu produk |
| `GET` | `/products/search?q=phone` | Mencari produk |
| `GET` | `/products/category/{category}` | Filter berdasarkan kategori |
| `GET` | `/products/categories` | Mengambil daftar kategori beserta URL |
| `GET` | `/products/category-list` | Mengambil daftar slug kategori |
| `POST` | `/products/add` | Menambahkan produk |
| `PUT` | `/products/{id}` | Mengubah produk |
| `PATCH` | `/products/{id}` | Mengubah sebagian atau seluruh produk |
| `DELETE` | `/products/{id}` | Menghapus produk |

### Query Parameter List

Endpoint `GET /products` mendukung:

- `q`: kata pencarian.
- `limit`: jumlah data, default `30`. Gunakan `0` untuk mengambil semua data.
- `skip`: jumlah data yang dilewati.
- `sortBy`: `id`, `title`, `price`, `rating`, atau `stock`.
- `order`: `asc` atau `desc`.

Contoh:

```text
GET http://localhost:8086/products?limit=10&skip=0&sortBy=price&order=desc
GET http://localhost:8086/products?q=phone
GET http://localhost:8086/products/category/smartphones
```

### Contoh Tambah Produk

```bash
curl -X POST http://localhost:8080/products/add \
  -H 'Content-Type: application/json' \
  -d '{
    "title": "Produk Baru",
    "description": "Deskripsi produk",
    "category": "laptops",
    "price": 899.99,
    "stock": 20,
    "brand": "Contoh"
  }'
```

### Contoh Update Produk

```bash
curl -X PATCH http://localhost:8080/products/1 \
  -H 'Content-Type: application/json' \
  -d '{"price": 799.99, "stock": 25}'
```

### Contoh Hapus Produk

```bash
curl -X DELETE http://localhost:8080/products/1
```

## Login Google Authenticator

### 1. Membuat konfigurasi authenticator

Jalankan service, lalu panggil endpoint berikut:

```http
GET http://localhost:8086/login
```

Response berisi `secret`, `account`, `issuer`, `otp_auth_url`, dan `qr_code`.
Tambahkan akun tersebut ke aplikasi Google Authenticator dengan salah satu cara:

- Scan nilai `qr_code` sebagai QR code SVG.
- Pilih **Enter setup key** di Google Authenticator, lalu masukkan nilai
  `secret` dan gunakan tipe **Time-based**.

Jika `GOOGLE2FA_SECRET` tidak diisi, aplikasi membuat secret 32 karakter secara
otomatis pada request pertama dan menyimpannya secara persisten di volume
`php-data`. Secret yang sudah tersimpan dan panjangnya kurang dari 32 karakter
akan dibuat ulang.

### 2. Verifikasi kode login

Ambil kode 6 digit dari Google Authenticator, lalu kirim request berikut:

```bash
curl -X POST http://localhost:8080/login \
  -H 'Content-Type: application/json' \
  -d '{"code":"123456"}'
```

Response berhasil:

```json
{
  "authenticated": true,
  "message": "Authentication successful."
}
```

Di Postman, gunakan `POST /login`, pilih **Body > raw > JSON**, lalu kirim
`{"code":"123456"}`. Ganti kode contoh dengan kode aktif dari authenticator.

Endpoint login saat ini memverifikasi TOTP, tetapi belum membuat JWT atau
session token untuk membatasi endpoint produk.

## Login phpMyAdmin

Buka `http://localhost:8085`, lalu gunakan:

- **Server**: `mysql`
- **Username**: nilai `MYSQL_USER` atau `root`
- **Password**: nilai `MYSQL_PASSWORD` atau `MYSQL_ROOT_PASSWORD`

Nama database yang digunakan adalah `app`, dan tabel produk bernama `products`.

## Struktur Folder

```text
services/
├── .env
├── Caddyfile
├── Dockerfile
├── docker-compose.yaml
├── databases/
└── htdocs/
  ├── Config.php
  ├── Rest.php
  ├── composer.json
  ├── controller/
  │   ├── AuthController.php
  │   └── ProductController.php
    ├── index.php
  ├── models/
  ├── routes/
    └── seed.php
```
