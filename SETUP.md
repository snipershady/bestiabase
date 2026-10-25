# BestiaBase – Setup

*Versione italiana: [SETUP-IT.md](SETUP-IT.md).*

Guide for a fresh **Debian 13** server, with PHP 8.5-FPM and either **nginx** or **Apache2**.
The web server runs as `www-data`; the project lives in the home of a dedicated user:
`/home/bestiabase/public_html`. Run the commands as `root` unless stated otherwise.

## Requirements

Install the following before starting (their installation is not covered here):

- Debian 13 with **nginx** or **Apache2**
- PHP 8.5 with FPM, CLI and the extensions `mysql`, `xml`, `mbstring`, `intl`, `curl`, `redis`
- MySQL 9.7
- Redis
- Git, Composer, `sudo`, `curl`

## 1. NCBI BLAST+

```bash
cd /opt
curl -LO https://ftp.ncbi.nlm.nih.gov/blast/executables/blast+/2.17.0/ncbi-blast-2.17.0+-x64-linux.tar.gz
tar xzf ncbi-blast-2.17.0+-x64-linux.tar.gz && rm ncbi-blast-2.17.0+-x64-linux.tar.gz
```

## 2. Database

```bash
mysql -e "CREATE DATABASE bestiabase CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'bestiabase'@'localhost' IDENTIFIED BY 'CHOOSE-A-PASSWORD';
GRANT ALL ON bestiabase.* TO 'bestiabase'@'localhost';"
```

## 3. User and code

```bash
adduser --disabled-password --gecos "" bestiabase
chgrp www-data /home/bestiabase && chmod 750 /home/bestiabase

sudo -u bestiabase -i
git clone git@github.com:snipershady/bestiabase.git public_html
cd public_html
composer install --no-dev --optimize-autoloader
exit
```

## 4. Configuration

Create `/home/bestiabase/public_html/.env.local`:

```dotenv
APP_ENV=prod
APP_SECRET=<32 random hex characters: openssl rand -hex 16>
ALTCHA_KEY=<another random string: openssl rand -hex 32>
DATABASE_URL="mysql://bestiabase:CHOOSE-A-PASSWORD@127.0.0.1:3306/bestiabase?serverVersion=9.7.4&charset=utf8mb4"
DEFAULT_URI=https://blast.example.org
BLAST_BIN_DIR=/opt/ncbi-blast-2.17.0+/bin
MAILER_DSN=smtp://user:password@smtp.example.org:587
MAILER_FROM_ADDRESS=no-reply@example.org
```

Permissions: `www-data` reads the code and writes `var/` and `blastdb/`.

```bash
cd /home/bestiabase/public_html
chown bestiabase:www-data .env.local && chmod 640 .env.local
mkdir -p var blastdb
chown -R www-data:www-data var blastdb
```

## 5. PHP

```bash
cat > /etc/php/8.5/fpm/conf.d/99-bestiabase.ini <<'EOF'
post_max_size = 12M
memory_limit = 128M
file_uploads = Off
expose_php = Off
EOF
cat > /etc/php/8.5/cli/conf.d/99-bestiabase.ini <<'EOF'
memory_limit = 512M
EOF
systemctl restart php8.5-fpm
```

## 6. Initialize the application

```bash
cd /home/bestiabase/public_html
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data php bin/console asset-map:compile
sudo -u www-data php bin/console app:blast:smoke-test
sudo -u www-data php bin/console app:user:create admin@example.org admin --admin
```

## 7. Web server

### nginx

`/etc/nginx/sites-available/bestiabase`:

```nginx
server {
    listen 80;
    server_name blast.example.org;
    root /home/bestiabase/public_html/public;
    client_max_body_size 12m;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/bestiabase /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### Apache2

`/etc/apache2/sites-available/bestiabase.conf`:

```apache
<VirtualHost *:80>
    ServerName blast.example.org
    DocumentRoot /home/bestiabase/public_html/public
    LimitRequestBody 12582912

    <Directory /home/bestiabase/public_html/public>
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.5-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
```

```bash
a2enmod proxy proxy_fcgi
a2ensite bestiabase && a2dissite 000-default
apache2ctl configtest && systemctl reload apache2
```

For HTTPS, use [Certbot](https://certbot.eff.org/) (`apt install certbot python3-certbot-nginx`
or `python3-certbot-apache`, then `certbot --nginx` or `certbot --apache`).

## 8. Background worker and cleanup

Searches run in a background worker. `/etc/systemd/system/bestiabase-worker.service`:

```ini
[Unit]
Description=BestiaBase worker
After=network.target mysql.service redis-server.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/home/bestiabase/public_html
ExecStart=/usr/bin/php bin/console messenger:consume async --time-limit=3600 --memory-limit=512M
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload && systemctl enable --now bestiabase-worker
echo '15 3 * * * www-data cd /home/bestiabase/public_html && php bin/console app:blast:cleanup --no-interaction' > /etc/cron.d/bestiabase
```

## 9. Load a BLAST database

```bash
cd /home/bestiabase/public_html
sudo -u www-data php bin/console app:blast:import-db /path/to/human.fa human_db nucl "Homo sapiens"
```

Add `--restricted` to make a database available only to authorized users. Open
`https://blast.example.org` and sign in with the administrator created in step 6.

## Updating

```bash
sudo -u bestiabase git -C /home/bestiabase/public_html pull
cd /home/bestiabase/public_html
sudo -u bestiabase composer install --no-dev --optimize-autoloader
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data php bin/console asset-map:compile
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console messenger:stop-workers
```
