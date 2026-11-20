# Stateless Goods

A small PHP shop for practising an Application Load Balancer and an Auto Scaling group.

The application keeps nothing important on the instance. The catalogue, the product photos, the cart, and the admin login are all rows in MySQL. Any healthy instance can serve the next request. The footer of every page, and the body of `/health.php`, name the instance that answered.

That is the behaviour to prove. Target-group stickiness will also make a demo look fine, and it hides a shop that only works on the instance that happened to create the session file. Leave stickiness off.

## What students are showing

- Two or more instances behind one load balancer.
- A shared MySQL database (RDS is the usual choice).
- Health checks against `GET /health.php`, success code 200.
- Stickiness turned off.
- Refreshing the shop changes the hostname in the footer, while the cart and the login stay.
- A product photo added on one instance still loads when another instance answers.

## Layout

| Path | Role |
| --- | --- |
| `public/` | Web root. Point nginx here, not at the project root. |
| `public/index.php` | Shop. Lists every product. A signed-in admin gets **Add a product**. |
| `public/admin/product.php` | Add or edit a product, including the photo. |
| `public/health.php` | Load balancer health check. Does not create a session. |
| `public/image.php` | Serves a photo from `products.image`. |
| `src/session.php` | Writes the PHP session into the `sessions` table. |
| `config.php` | Database host, name, user, and password. |
| `deploy/nginx.conf` | Ubuntu nginx site. Root is `public/`. |
| `sql/schema.sql` | Tables, the admin user, and four sample products. Import once. |

## Requirements

- Ubuntu 24.04 or 26.04
- nginx and PHP-FPM, PHP 8.1 or newer, with `php-mysql`
- MySQL 8 or MariaDB 10.6+
- The nginx root must be the `public` directory

## Configure the database

Edit `config.php` on every instance so the four values point at the same database:

```php
'db_host' => 'your-db.xxxxxx.region.rds.amazonaws.com',
'db_port' => '3306',
'db_name' => 'shop',
'db_user' => 'shop',
'db_pass' => 'the-password',
```

Environment variables override the file when they are non-empty: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`. Editing `config.php` is the reliable option. PHP-FPM on Ubuntu does not pass the shell environment into the pool unless you also set `fastcgi_param` in the nginx site.

Create the database once, then import the schema once. Do not import it from each instance's user data on every boot, or a new instance will wipe the shop.

```sql
CREATE DATABASE shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
mysql -h your-db.example.rds.amazonaws.com -u shop -p shop < sql/schema.sql
php bin/check.php
```

`php bin/check.php` prints the server version and the table list. It does not print the password.

Lab login, also written on the login page: `admin` / `admin123`.
