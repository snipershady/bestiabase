# BestiaBase – Setup

*English version: [SETUP.md](SETUP.md).*

Guida per un server **Debian 13** pulito, con PHP 8.5-FPM e **nginx** oppure **Apache2**.
Il web server gira come `www-data`; il progetto sta nella home di un utente dedicato:
`/home/bestiabase/public_html`. Esegui i comandi come `root`, salvo diversa indicazione.

## Requisiti

Installa quanto segue prima di iniziare (la loro installazione non è trattata qui):

- Debian 13 con **nginx** o **Apache2**
- PHP 8.5 con FPM, CLI e le estensioni `mysql`, `xml`, `mbstring`, `intl`, `curl`, `redis`
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
CREATE USER 'bestiabase'@'localhost' IDENTIFIED BY 'SCEGLI-UNA-PASSWORD';
GRANT ALL ON bestiabase.* TO 'bestiabase'@'localhost';"
```

## 3. Utente e codice

```bash
adduser --disabled-password --gecos "" bestiabase
chgrp www-data /home/bestiabase && chmod 750 /home/bestiabase

sudo -u bestiabase -i
git clone git@github.com:snipershady/bestiabase.git public_html
cd public_html
composer install --no-dev --optimize-autoloader
exit
```

## 4. Configurazione

Crea `/home/bestiabase/public_html/.env.local`:

```dotenv
APP_ENV=prod
APP_SECRET=<32 caratteri esadecimali casuali: openssl rand -hex 16>
ALTCHA_KEY=<un'altra stringa casuale: openssl rand -hex 32>
DATABASE_URL="mysql://bestiabase:SCEGLI-UNA-PASSWORD@127.0.0.1:3306/bestiabase?serverVersion=9.7.4&charset=utf8mb4"
DEFAULT_URI=https://blast.example.org
BLAST_BIN_DIR=/opt/ncbi-blast-2.17.0+/bin
MAILER_DSN=smtp://utente:password@smtp.example.org:587
MAILER_FROM_ADDRESS=no-reply@example.org
```

Permessi: `www-data` legge il codice e scrive in `var/` e `blastdb/`.

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

## 6. Inizializzazione dell'applicazione

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

Per HTTPS usa [Certbot](https://certbot.eff.org/) (`apt install certbot python3-certbot-nginx`
oppure `python3-certbot-apache`, poi `certbot --nginx` o `certbot --apache`).

## 8. Worker in background e pulizia

Le ricerche girano in un worker in background. `/etc/systemd/system/bestiabase-worker.service`:

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

## 9. Caricare un database BLAST

```bash
cd /home/bestiabase/public_html
sudo -u www-data php bin/console app:blast:import-db /percorso/human.fa human_db nucl "Homo sapiens"
```

Aggiungi `--restricted` per riservare il database agli utenti autorizzati. Apri
`https://blast.example.org` e accedi con l'amministratore creato al punto 6.

## Aggiornamento

```bash
sudo -u bestiabase git -C /home/bestiabase/public_html pull
cd /home/bestiabase/public_html
sudo -u bestiabase composer install --no-dev --optimize-autoloader
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data php bin/console asset-map:compile
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console messenger:stop-workers
```
