# BestiaBase

Interfaccia web per ricerche di similarità con **NCBI BLAST+** (blastn, blastp, blastx, tblastn,
tblastx) su database di sequenze di specie diverse, con risultati salvati e consultabili, accesso
per utente ai database riservati e area di amministrazione. È la riscrittura di ViroBLAST
Standalone in Symfony 8.1.

- Le ricerche si inviano dal web incollando la query; BLAST+ viene eseguito da un worker in
  background e la pagina del job si aggiorna da sola.
- Risultati: tabella con filtri (top N per query, cutoff su identità o score), allineamenti nel
  formato pairwise di BLAST, link alle schede NCBI, download tabulare, XML e delle sequenze soggetto.
- I database BLAST si importano da riga di comando a partire da file FASTA; ognuno può essere
  pubblico o riservato a utenti scelti. Gli amministratori vedono sempre tutti i database.

## Requisiti

| Componente | Versione |
|---|---|
| PHP | 8.4 o successivo, con `pdo_mysql`, `xmlreader`, `dom`, `ctype`, `iconv` |
| Database | MySQL 8.0 o successivo (usa window function e `REGEXP_REPLACE`); sviluppato e testato solo su 9.7. Charset `utf8mb4`, collation `utf8mb4_0900_ai_ci` |
| NCBI BLAST+ | 2.17.0 o compatibile, Linux x86_64 (glibc ≥ 2.28) |
| Web server | Nginx + **PHP-FPM** (mod_php non è supportato) |
| Composer | 2.x |
| Facoltativi | Node.js 24 e Chrome/Chromium per i test del browser |

## Installazione

```bash
git clone <repository> bestiabase && cd bestiabase
composer install --no-dev --optimize-autoloader     # in sviluppo: composer install
```

Crea `.env.local` (non versionato) con i valori della macchina. `.env` contiene solo i default.

```dotenv
APP_ENV=prod
APP_SECRET=<stringa casuale di 32 caratteri esadecimali>
DATABASE_URL="mysql://<utente>:<password>@127.0.0.1:3306/<database>?serverVersion=9.7.4&charset=utf8mb4"
DEFAULT_URI=https://blast.example.org
BLAST_BIN_DIR=/opt/ncbi-blast-2.17.0+/bin
BLAST_DB_DIR=/srv/bestiabase/blastdb
```

In alternativa ai file `.env`, i segreti possono stare nel vault di Symfony
(`bin/console secrets:set DATABASE_URL --env=prod`).

```bash
bin/console doctrine:migrations:migrate --no-interaction
bin/console asset-map:compile          # solo in produzione: pubblica JS e CSS in public/assets
bin/console cache:clear
bin/console app:blast:smoke-test       # verifica l'installazione di BLAST+
bin/console app:user:create admin@example.org admin --admin   # chiede la password (min. 12 caratteri)
```

### Variabili d'ambiente

| Variabile | Default | Significato |
|---|---|---|
| `BLAST_BIN_DIR` | `/usr/local/ncbi/blast/bin` | Directory dei binari BLAST+ (`blastn`, `makeblastdb`, `blastdbcmd`…) |
| `BLAST_DB_DIR` | `blastdb/` nel progetto | Volumi dei database registrati. In produzione un volume dedicato, fuori dal progetto |
| `BLAST_VAR_DIR` | `var/blast` | Directory di lavoro dei job (query, output XML), fuori dalla document root |
| `BLAST_TIMEOUT` | `3600` | Durata massima di un processo BLAST+, in secondi |
| `BLAST_QUERY_MAX_BYTES` | `10485760` (10 MiB) | Dimensione massima della query incollata nel form |
| `BLAST_FASTA_MAX_BYTES` | `15728640` (15 MiB) | Dimensione massima di un FASTA da CLI (`--subject`). Non aumentarla: il FASTA normalizzato va in colonne `MEDIUMTEXT` |
| `BLAST_JOB_RETENTION_DAYS` | `30` | Giorni dopo cui `app:blast:cleanup` elimina i job terminati |
| `BLAST_SEARCH_RATE_LIMIT` / `BLAST_SEARCH_RATE_INTERVAL` | `20` / `10 minutes` | Ricerche dal web ammesse per indirizzo IP |
| `MESSENGER_TRANSPORT_DSN` | `redis://127.0.0.1:6379/messages` | Coda dei job (Redis Streams; i messaggi falliti restano nella coda Doctrine `failed`) |
| `LOCK_DSN` | `redis://127.0.0.1:6379` | Lock per job, su Redis (condiviso tra worker); `flock` solo per sviluppo con un unico worker |

## Parametri PHP consigliati

Il progetto non modifica i parametri PHP: vanno impostati nel `php.ini` (o nel pool) del server.

**PHP-FPM** (richieste web):

```ini
post_max_size = 12M        ; la query web arriva a 10 MiB (BLAST_QUERY_MAX_BYTES); il default 8M non basta
memory_limit = 128M        ; una query di 10 MiB occupa circa 30 MB durante la normalizzazione
file_uploads = Off         ; dal web non si caricano file
max_execution_time = 30    ; le richieste salvano e accodano il job: BLAST gira nel worker
expose_php = Off
```

**PHP CLI** (worker e comandi):

```ini
memory_limit = 512M        ; l'import dei FASTA da CLI usa circa 3 volte la dimensione del file
max_execution_time = 0     ; default del CLI: i processi BLAST+ hanno il proprio timeout (BLAST_TIMEOUT)
```

## BLAST+

I binari non sono nel repository. Si scaricano dal sito NCBI
(`https://ftp.ncbi.nlm.nih.gov/blast/executables/blast+/`) e si estraggono, per esempio, in
`/opt/ncbi-blast-<versione>+/`. Poi si imposta `BLAST_BIN_DIR` sulla sua `bin/`.

**Aggiornamento**: estrai la nuova versione accanto alla vecchia, punta `BLAST_BIN_DIR` sulla nuova,
svuota la cache (`bin/console cache:clear`) e lancia:

```bash
bin/console app:blast:smoke-test
```

Il comando verifica binari e versioni, ricostruisce i database demo in una directory temporanea,
esegue una ricerca per ogni programma e un'estrazione con `blastdbcmd`. Se un controllo fallisce
esce con codice 1: torna alla versione precedente. Ogni job registra la versione di BLAST+ usata.

L'applicazione esegue BLAST+ con `BLAST_USAGE_REPORT=false`: senza, BLAST+ invierebbe a NCBI dati
d'uso a ogni ricerca (e attenderebbe la rete per circa 5 secondi).

## Database BLAST

I database si importano da riga di comando a partire da un FASTA già presente sul server:

```bash
# pubblico: tutti possono cercarci, anche gli utenti non autenticati
bin/console app:blast:import-db /data/fasta/human.fa human_db nucl "Homo sapiens"

# riservato: solo gli utenti autorizzati e gli amministratori
bin/console app:blast:import-db /data/fasta/mouse.fa mouse_db nucl "Mus musculus" --restricted
bin/console app:blast:access mouse_db utente@example.org            # --revoke per togliere l'accesso

# database demo di ViroBLAST
bin/console app:blast:import-test-data
```

Il comando copia il FASTA in `BLAST_DB_DIR`, lo formatta con `makeblastdb -parse_seqids` (necessario
per le accessioni, i link NCBI e il download delle sequenze; `--no-parse-seqids` per FASTA con
identificatori non standard), verifica il risultato e registra il database. Con `--program` si
limitano i programmi abilitati (default: tutti quelli compatibili con il tipo di molecola).

Visibilità, programmi, descrizione e utenti autorizzati si cambiano anche da **Administration →
Databases**; gli utenti si gestiscono da **Administration → Users**. Disattivare un database ferma le
nuove ricerche senza nascondere i risultati già ottenuti.

Prova da riga di comando: `bin/console app:blast:search blastn query.fa --database=human_db`.

## Worker

Le ricerche vengono eseguite dal worker Messenger. Esempio di unit systemd
(`/etc/systemd/system/bestiabase-worker@.service`), avviabile in più istanze
(`systemctl enable --now bestiabase-worker@1 bestiabase-worker@2`):

```ini
[Unit]
Description=BestiaBase BLAST worker %i
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/srv/bestiabase
ExecStart=/usr/bin/php bin/console messenger:consume async --time-limit=3600 --memory-limit=512M
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Dopo ogni deploy: `bin/console messenger:stop-workers` (systemd li riavvia con il codice nuovo). I
messaggi falliti finiscono nella coda `failed`: `bin/console messenger:failed:show`.

## Cron

```cron
# retention dei job terminati (risultati e file di lavoro)
15 3 * * * www-data cd /srv/bestiabase && php bin/console app:blast:cleanup --no-interaction
```

## Nginx

```nginx
server {
    server_name blast.example.org;
    root /srv/bestiabase/public;

    # HSTS va impostato qui (o sul proxy che termina TLS): l'applicazione imposta gli altri header.
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    client_max_body_size 12m;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
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

Le directory `var/`, `blastdb/` (o `BLAST_DB_DIR`) e `fixtures/` non devono essere servite: con
`root` su `public/` non lo sono.

**Dietro Cloudflare (o un altro proxy)**: configura i proxy fidati di Symfony (`trusted_proxies`),
altrimenti il rate limit vedrebbe un solo indirizzo IP. Il limite di Cloudflare free per una
richiesta (100 s, 100 MB) non riguarda le ricerche: la richiesta accoda il job e risponde subito.

## Sicurezza

- Content-Security-Policy con nonce per richiesta, senza `unsafe-inline`; `frame-ancestors 'none'`,
  `nosniff`, `Referrer-Policy`, `Permissions-Policy`. Le pagine non devono contenere script inline.
- CSRF su tutti i form, rate limit sulle ricerche, password con hash `auto` di Symfony.
- BLAST+ viene eseguito sempre con un elenco di argomenti, mai attraverso una shell; i path dei
  database non arrivano mai dal browser.
- Job e risultati sono visibili al proprietario e agli amministratori (i job anonimi a chi ne
  conosce l'indirizzo); chi perde l'accesso a un database riservato perde anche i relativi risultati.

## Sviluppo

```bash
symfony serve -d                                  # oppure php -S 127.0.0.1:8000 -t public
bin/console messenger:consume async -vv           # worker
composer fix                                      # Rector + php-cs-fixer
composer qa                                       # validate, audit, Rector, cs, PHPStan max, lint, test con coverage 100%, test browser
composer test:browser                             # solo i test del JavaScript (Node 24 + Chrome headless)
```

I test usano il database `<database>_test` (`.env.test.local`) e, per i test che eseguono BLAST+
reale, i binari di `BLAST_BIN_DIR`: se mancano, quei test vengono saltati.

Documentazione del progetto:

- `CHANGELOG.md`: modifiche per versione;
- `TODO.md`: roadmap, attività aperte e decisioni in sospeso;
- `docs/ANALISI_INTEGRATIVA.md`: analisi del legacy, comportamento verificato di BLAST+ 2.17.0,
  scelte tecniche (es. PHP invece di Go);
- `docs/PARITY_CHECKLIST.md`: parità con ViroBLAST, voce per voce, con i test che la verificano.

## Licenza

Software proprietario. NCBI BLAST+ è distribuito da NCBI come software di pubblico dominio (vedi la
licenza inclusa nel pacchetto BLAST+).
