# TODO

Roadmap operativa di BestiaBase. Fonte: §17 di
`/home/shady/public_html/ViroblastStandalone/DEEPSEEK.md`.
Aggiornare questo file a ogni step: spuntare ciò che è fatto (`[x]`), aggiungere ciò che
emerge, spostare in `CHANGELOG.md` le voci completate quando chiudono una fase.

Legenda: `[ ]` da fare · `[~]` in corso · `[x]` fatto · `[?]` decisione richiesta all'utente

## Fase 0 — Bootstrap

- [x] Skeleton Symfony 8.1 (Doctrine, Messenger, Security, Form, Validator, Twig, Turbo/Stimulus, AssetMapper)
- [x] `snipershady/typeidentifier` installato e alias dell'interfaccia registrato
- [x] Connessione MySQL 9.7 (`utf8mb4` / `utf8mb4_0900_ai_ci`), credenziali in `.env.local`
- [x] `BLAST_BIN_DIR` configurabile (binari NCBI fuori da git)
- [x] PHPStan max + strict/deprecation/phpunit/symfony/doctrine rules + bleedingEdge
- [x] Rector (PHP 8.4, prepared sets, attribute sets, composer-based sets)
- [x] php-cs-fixer (`@Symfony`, `@Symfony:risky`, PHP 8.4 / PHPUnit 11 migration, strict rules)
- [x] PHPUnit strict (suite unit/integration/functional, random order, coverage metadata) + gate coverage 100%
- [x] Script Composer `qa` / `fix`
- [x] Layout Twig + Bootstrap 5.3 (importmap), home `/` con test funzionale
- [x] Messenger transport: Doctrine, poi passato a Redis (`symfony/redis-messenger`)
- [x] Database di test `bestiabase_test` (utente dedicato, credenziali in `.env.test.local`)

## Da discutere

- [x] **FASTA in ingresso e memoria PHP** — deciso dal committente il 2026-10-24:
  - **nessun upload di file FASTA dal web**: con Cloudflare (piano free) una richiesta HTTP ha al
    massimo 100 s (e 100 MB di body), quindi i file FASTA grandi si caricano solo da CLI
    (`app:blast:import-db` per i database, `app:blast:search --subject` per un soggetto ad hoc);
  - dal web solo la query incollata nella textarea; la richiesta salva e accoda il job (pochi ms),
    l'esecuzione avviene nel worker e non è soggetta al limite di Cloudflare;
  - un database BLAST registrato, anche da 1 GB, non pesa su PHP: lo legge il processo BLAST+;
    l'XML è parsato in streaming (circa 4 MB costanti) e importato a blocchi;
  - `BLAST_FASTA_MAX_BYTES` ridotto a 15 MiB: sta in `MEDIUMTEXT` (16 MiB) anche dopo la
    normalizzazione (a capo ogni 80 caratteri, +1,25%) e il `FastaNormalizer` (picco circa 3 volte
    l'input) resta sotto i 128 MB di `memory_limit`. Risolto così il bug 16 MB contro 50 MB.
- [x] Limite della query incollata nel form web: **10 MiB** (`BLAST_QUERY_MAX_BYTES`), deciso dal
  committente. Nessun override dei parametri PHP dal progetto: i valori consigliati vanno nel
  README (Fase 10). Memoria del normalizzatore circa 30 MB.

## Analisi

- [x] Analisi integrativa del legacy e verifica sui binari 2.17.0 (`docs/ANALISI_INTEGRATIVA.md`)
- [x] Decisione: `blast.pl` riscritto in PHP, non in Go (misure in §4 del documento)

## Fase 1 — Dominio e schema

- [x] Enum: `BlastProgram`, `MolType`, `JobStatus`, `SearchType`, `OutputFormat`, `DownloadSequenceType`
- [x] Entity `User`
- [x] Entity `BlastDatabase` + `BlastDatabaseProgram` (programmi compatibili col tipo di molecola)
- [x] Entity `BlastJob` (UUID v7, transizioni di stato protette)
- [x] Entity `BlastQuery`, `BlastHit`, `BlastHsp` (aggregati "best" dal primo HSP)
- [x] Entity `SubjectSequence` (upload di un job oppure database registrato)
- [x] Entity `BlastDownload` (+ hit selezionati, tutti dello stesso job)
- [x] `BlastDatabaseRepository::findSelectableForProgram()`
- [x] Migration iniziale applicata su `bestiabase` e `bestiabase_test`, `doctrine:schema:validate` verde
- [x] Test unit per enum ed entity, test di integrazione su mapping, cascade e repository
- [ ] Query di filtro top-N per query e cutoff %/score → spostate in Fase 6, dove servono

## Fase 2 — Motore BLAST

- [x] `BlastBinaryLocator` (`#[Autowire('%env(BLAST_BIN_DIR)%')]`, verifica che i binari esistano e siano eseguibili)
- [x] `BlastCommandBuilder` (porting di `blast.pl`, Appendice A) con tabella di verità nei test
  - sempre `-outfmt 5` + `-max_target_seqs` (mai `-num_descriptions`/`-num_alignments`)
  - combinazioni programma/opzione non supportate rifiutate (es. `-ungapped` con tblastx)
- [x] Parametri come value object: `SearchParameters` (+ default del form legacy), `GapCosts`,
  `GapCostPresets` (§2.6), `NucleotideScoring`, `ScoringMatrix`, `GeneticCode`;
  snapshot JSON con `SearchParametersNormalizer` (via `typeidentifier`)
- [x] `ProcessRunnerInterface` + adapter `symfony/process` (argv, timeout, ambiente)
- [x] `BlastRunner` (workspace `var/blast/jobs/{uuid}`, makeblastdb per l'upload, errori da exit code)
- [x] `BLAST_USAGE_REPORT=false` su ogni processo BLAST+ (privacy + 5 s di attesa di rete evitati)
- [x] `FastaNormalizer` (fine riga, troncamento pre-`>`, sequenza nuda, cifre/spazi, residui per tipo di molecola, limite di dimensione)
- [x] `BlastXmlParser` streaming (`XMLReader`, memoria costante, `LIBXML_NONET`)
- [x] `SequenceNames`: nome query (`Query<N>`), nome soggetto da `Hit_def` per `gnl|BL_ORD_ID|N`, accessioni (§2.2)
- [x] `BlastResultImporter` (flush + detach a blocchi da 500 entity)
- [x] Test end-to-end con i binari reali su tutti i programmi, basic e advanced (`#[Group('blast')]`)
- [ ] Comando CLI di ricerca → spostato in Fase 3 (servono i database registrati)

## Fase 3 — Gestione DB BLAST

- [x] `BlastDatabaseImporter` (copia del FASTA, `makeblastdb -parse_seqids -blastdb_version 5`,
  verifica con `blastdbcmd -metadata`, registrazione; volumi parziali rimossi se fallisce)
- [x] `BlastDatabaseInspector` (statistiche dai metadati JSON) + colonne `sequence_count`, `total_length`
- [x] `BlastProcessExecutor`: unico punto di lancio dei processi BLAST+ (ambiente, timeout, errori)
- [x] `app:blast:import-db <fasta> <code> <nucl|prot> <title> [--program] [--description] [--no-parse-seqids]`
- [x] `app:blast:import-test-data`: ricostruisce `test_na_db`/`test_aa_db` da `fixtures/blast-demo/`
- [x] `app:blast:search <program> <query> [--database] [--subject] [--advanced] [--evalue] [--max-target-seqs]`
- [x] `BlastJobFactory` (validazione all'invio) e `BlastJobProcessor` (pending → running → finished|failed,
  senza risultati parziali, messaggi interni non esposti): riusabili dal worker della Fase 4
- [x] Config `BLAST_DB_DIR` (dev: `var/blastdb`; test: `var/test/blastdb`)
- [x] Codici database senza punti (la pulizia di `{code}.*` non deve toccare altri database)
- [x] `BLAST_DB_DIR` fuori da `var/` (default `blastdb/` nel progetto, ignorata da git); path dei database
  salvati **relativi** a `BLAST_DB_DIR` (`BlastDatabaseStorage`): spostare i volumi = cambiare l'env
- [ ] In produzione `BLAST_DB_DIR` su un volume dedicato (es. `/srv/bestiabase/blastdb`)

## Fase 4 — Pipeline job

- [x] Messaggio `RunBlastJob` (`#[AsMessage('async')]`) + `RunBlastJobHandler` che delega a `BlastJobProcessor`
  (id non valido o job cancellato → `UnrecoverableMessageHandlingException`, niente retry)
- [x] `symfony/lock`: un solo worker per job (`LOCK_DSN=flock`)
- [x] `BlastJobSubmitter`: crea, salva e accoda (il messaggio parte dopo il flush)
- [x] `GET /job/{id}/status` JSON (`JobStatusView`, `Cache-Control: no-store`, solo UUID validi)
- [x] Versione BLAST+ salvata nel job (`BlastVersionReader`, colonna `blast_version`)
- [x] `app:blast:cleanup [--days] [--dry-run]` (`JobCleaner`, `BLAST_JOB_RETENTION_DAYS`, solo job terminati)
- [x] `app:blast:search --async` (accoda invece di eseguire)
- [x] Messenger `in-memory://` in ambiente di test
- [x] `LOCK_DSN` su Redis
- [ ] Workspace dei job: oggi rimosso solo dalla retention; valutare la rimozione a fine job quando
  l'XML grezzo non serve più (dipende dal download dell'output in Fase 6)

## Fase 5 — UI ricerca

- [x] `SearchFormType` + `SearchFormData`: programma, query in textarea, database, parametri avanzati;
  pulsanti "Basic search" / "Advanced search" come il legacy; validazione per programma (word size,
  gap cost per matrice/mmScore), opzioni non applicabili scartate lato server
- [x] `SearchFormClientConfig`: default, campi per programma e preset dei gap cost generati da PHP
  (unica fonte di verità per il JavaScript)
- [x] Stimulus `search-form` (ex changeDBList / changeParameters / changeGapcostsOptions) e
  `job-status` (polling ogni 2 s al posto del META refresh, reload a fine job)
- [x] `SearchController` su `/`: valida, accoda con `BlastJobSubmitter`, redirect a `/job/{id}`;
  errori di dominio sul campo giusto; 422 su invio non valido (Turbo)
- [x] Nessun upload di file; limite `BLAST_QUERY_MAX_BYTES` in byte; messaggio dedicato se il POST
  supera `post_max_size`
- [x] CSRF stateless (anche cross-origin respinto) e rate limit per IP (`symfony/rate-limiter`,
  `BLAST_SEARCH_RATE_LIMIT` / `BLAST_SEARCH_RATE_INTERVAL`, 429)
- [x] Pagina minima del job (`/job/{id}`): stato, conteggi, errore
- [x] Verifica in Chrome headless: filtri per programma, default, preset gap cost, invio, polling
- [ ] Test automatici del JavaScript (oggi verificato a mano in Chrome headless) → Fase 10
- [ ] Pagine di aiuto dei parametri (ex `docs/parameters.html`) con link dal form → Fase 10

## Fase 6 — UI risultati

- [x] Tabella risultati su `/job/{id}` con le colonne legacy (Query | Subject | Score | Identities (Query length)
  | Percentage | Expect), filtri `?top=&cutoff=&value=&sort=` (`HitFilter` via `#[MapQueryString]`, 400 se non
  validi), paginazione per query (20 per pagina)
- [x] `BlastHitRepository::findRows()`: top-N per query con `ROW_NUMBER()` dopo il cutoff (come il legacy),
  cutoff % con la regola di percentuale di BLAST anche in SQL, cutoff score, ordinamenti
- [x] Vista pairwise per hit (`/job/{id}/hit/{hitId}`, solo hit del job) e per query (`/job/{id}/query/{n}`,
  10 hit per pagina) con `PairwiseRenderer` + `AlignmentCoordinates`
- [x] Parità con BLAST+ verificata dai test: pairwise (`PairwiseParityTest`) e tabulare (`TabularParityTest`)
  su tutti e 5 i programmi; differenze note documentate in `PairwiseRenderer`
- [x] Formattazione numerica di BLAST (`BlastNumberFormat`): pairwise, tabulare, `GetPercentMatch`
- [x] Link NCBI `nuccore`/`protein` solo per accessioni riconosciute (funzione Twig `ncbi_url`); accessioni
  salvate con la versione (`AAF24986.1`)
- [x] Download: `hits.tsv` (outfmt 6, `?comments=1` per outfmt 7, generato dal DB in streaming) e
  `output.xml` (XML originale di BLAST, finché il workspace esiste)
- [x] Colonna `blast_hsp.gap_opens`; CLI `app:blast:search` sulla stessa query della pagina web
  (`HitSummary` rimosso)
- [ ] Ordinamento cliccando le intestazioni di colonna (oggi: select "Sort" del form filtri)
- [ ] Selezione degli hit per il download delle sequenze (checkbox della tabella legacy) → Fase 7

## Fase 7 — Download sequenze

- [x] Selezione degli hit nella tabella risultati (checkbox, "seleziona tutti" della pagina con Stimulus
  `select-all`), tipo "Entire sequence" / "Region mapped to query" come il legacy
- [x] `POST /job/{id}/download` (`DownloadRequest` via `#[MapRequestPayload]`, max 500 hit, CSRF stateless
  `download`, solo hit del job, `data-turbo="false"`): FASTA in streaming senza file permanenti, audit `BlastDownload`
- [x] `SequenceExtractor` "mapping": un record per HSP, coordinate da `AlignmentCoordinates` (corrette anche per
  frame e filamento minus, sbagliate nel legacy), intestazione nel formato legacy
- [x] `SequenceExtractor` "entire": `blastdbcmd -entry_batch` sui database del job (exit code 1 = voci saltate,
  accettato), poi FASTA soggetto caricato con il job, poi `source_fasta` dei database (letto riga per riga);
  soggetti non trovati registrati nel log
- [x] `BlastProcessExecutor`: codici d'uscita accettati configurabili
- [x] `BlastHit::getSubjectDefinition()` (defline senza ripetere il nome)
- [ ] "mapping" per tblastn/tblastx: oggi è la traduzione allineata (Hsp_hseq); valutare la regione
  nucleotidica con `blastdbcmd -range -strand` per i database registrati
- [ ] "entire" tiene in memoria i record trovati prima di scriverli in ordine: con 500 soggetti molto lunghi
  (genomi) valutare un limite per tipo o la scrittura nell'ordine delle sorgenti

## Fase 8 — Auth / Admin

- [x] Login con email e password (`form_login`, CSRF stateless), logout, `UserChecker` (utenti disattivati),
  ultimo accesso registrato, rehash automatico delle password (`PasswordUpgraderInterface`)
- [x] Database **pubblici** (tutti, anche anonimi) e **riservati** (solo utenti autorizzati); gli amministratori
  vedono sempre tutto. `BlastDatabase::isAccessibleBy()` / `isSearchableBy()` (+ attivo) e la stessa regola in SQL
  (`BlastDatabaseRepository::searchableBy()`)
- [x] Applicata in: form di ricerca (solo database dell'utente), `BlastJobFactory` (rifiuto all'invio, messaggio
  che non rivela i riservati), `BlastJobVoter` su tutte le pagine/download dei job (proprietario o admin; job anonimi
  per UUID; accesso ancora valido ai database del job; un database disattivato non nasconde i risultati)
- [x] `app:user:create <email> <username> [--admin]` (password chiesta in modo nascosto, min. 12 caratteri),
  `app:blast:access <code> <email> [--revoke]`, `app:blast:import-db --restricted`, `app:blast:search --user`
- [x] Amministrazione `/admin`: database (titolo, descrizione, attivo, pubblico/riservato, programmi, utenti
  autorizzati), utenti (creazione, ruolo admin, attivo, password, database riservati), job di tutti gli utenti;
  un amministratore non può togliersi il ruolo né disattivarsi
- [x] "My searches" (`/my-jobs`): storico delle ricerche dell'utente
- [?] Registrazione libera degli utenti: non implementata (con database riservati gli utenti li crea
  l'amministratore); da decidere se serve, ed eventualmente con conferma via email
- [ ] Recupero password via email (richiede `MAILER_DSN` configurato)
- [ ] Gruppi di utenti (es. per laboratorio) se l'assegnazione database per singolo utente diventa onerosa

## Fase 9 — Pulizia

- [x] Nessun riferimento al legacy nel codice e nella configurazione versionata (restano solo i commenti
  che indicano da quale parte del legacy viene ogni porting, e la documentazione di analisi)
- [x] BLAST+ 2.17.0 in sviluppo spostato fuori dal legacy: `/home/shady/opt/ncbi-blast-2.17.0+/`
  (`BLAST_BIN_DIR` in `.env.local` e `.env.test.local`)
- [x] Rimosso codice morto: entity `SubjectSequence` e tabella, opzione "Additional output" del form
  (`OutputFormat`, colonna `blast_job.output_format`: non aveva effetto), colonne `blast_hit.total_identity` /
  `total_align_length` (mai lette), metodi non più usati (`findByJobInBlastOrder`, `findSelectableForProgram`,
  `relocate`, `getMismatches`, `isSuccessful`, `FastaDocument::count`); migration reversibile con default validi
- [x] Importmap: rimossi JavaScript di Bootstrap e Popper (si usa solo il CSS)
- [?] Pacchetti Composer non usati, lasciati in attesa di decisione: `symfony/notifier` (con `notifier.yaml` e il
  routing Messenger delle notifiche), `symfony/web-link`, `symfony/http-client`, `symfony/intl`,
  `symfony/expression-language`, `altcha-org/altcha` (ora in uso)

## Fase 10 — Hardening & docs

- [x] Acronimo BESTIA e significato del nome BestiaBase nei README in inglese e italiano
- [x] Header di sicurezza (`SecurityHeadersListener`): CSP con nonce per richiesta su script e stili, senza
  `unsafe-inline` (`csp_nonce()` per importmap e `<meta name="csp-nonce">` per Turbo), `frame-ancestors 'none'`,
  `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`; HSTS demandato al web server (README)
- [x] CSS collegati con `<link>` invece che importati da JS (AssetMapper li trasformava in moduli `data:`, vietati dalla CSP)
- [x] Checklist di parità con ViroBLAST voce per voce, con i test che la verificano (`docs/PARITY_CHECKLIST.md`)
- [x] Test automatici del JavaScript: `composer test:browser` (Node 24 `node --test` + Chrome headless via DevTools
  Protocol, server PHP in ambiente test, fixture `tests/Browser/fixtures.php`), inclusi in `composer qa`
- [x] Pagine di aiuto `/help` e `/help/parameters` (testi riscritti), collegate da menu e form
- [x] README di installazione e deploy: requisiti, variabili d'ambiente, parametri `php.ini`, BLAST+ e suo
  aggiornamento, import dei database e accessi, worker systemd, cron, Nginx, Cloudflare, sicurezza, sviluppo
- [x] `app:blast:smoke-test`: verifica di un'installazione di BLAST+ dopo l'aggiornamento

## Aperte dopo la roadmap

Raccolte dalle fasi precedenti (dettagli nelle rispettive sezioni):

- [?] Pacchetti Composer non usati (notifier, web-link, http-client, intl, expression-language) — Fase 9
- [x] Workspace dei job: XML compresso (`output.xml.gz`), input FASTA eliminati a job concluso, messaggio se l'XML non c'è più
- [x] Redis: `redis-messenger` e `LOCK_DSN` condiviso
- [x] Registrazione libera degli utenti: account `ROLE_DISABLED` finché un amministratore non lo abilita
- [ ] `trusted_proxies` di Symfony dietro Cloudflare/proxy (altrimenti il rate limit vede un solo IP) — README
- [x] Recupero password via e-mail, cambio password, e-mail di abilitazione
- [ ] Gruppi di utenti — Fase 8
- [x] Verifica dell'indirizzo e-mail alla registrazione, avviso agli amministratori, `snipershady/emailvalidator`
- [x] ALTCHA su login e registrazione, verifica di sicurezza prima della home per gli anonimi, rotta `/nopainnogain`
- [?] `snipershady/emailvalidator` è GPL-2.0-only: compatibile con il software proprietario del progetto?
- [ ] Tarare le difficoltà ALTCHA (`ALTCHA_*_DIFFICULTY`) sui dispositivi reali degli utenti (telefoni inclusi)
- [ ] Rimozione del workspace a fine job; regione nucleotidica per "mapping" di tblastn/tblastx; limite di memoria
  per "entire" con molti soggetti lunghi; ordinamento cliccando le colonne — Fasi 4, 6, 7
- [ ] Prima messa in produzione: `asset-map:compile`, `APP_ENV=prod`, `BLAST_DB_DIR` su volume dedicato
