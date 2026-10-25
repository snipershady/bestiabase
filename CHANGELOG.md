# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it-IT/1.1.0/)
e il progetto adotta il [Semantic Versioning](https://semver.org/lang/it/).

## [Unreleased]

### Added

- Skeleton Symfony 8.1 su PHP >= 8.4 con Doctrine ORM, Migrations, Messenger, Security,
  Form, Validator, Twig, Turbo/Stimulus e AssetMapper.
- Libreria `snipershady/typeidentifier`, con alias dell'interfaccia
  `EffectivePrimitiveTypeIdentifierServiceInterface` nel container.
- Variabile d'ambiente `BLAST_BIN_DIR` per il path dei binari NCBI BLAST+, che non sono
  più vendorizzati nel repository.
- Variabile d'ambiente `REDIS_URL`.
- Toolchain di qualità: PHPStan livello max (strict rules, deprecation rules, estensioni
  PHPUnit/Symfony/Doctrine, bleedingEdge), Rector (set PHP 8.4 e prepared sets di qualità),
  php-cs-fixer (`@Symfony`, `@Symfony:risky` e regole strict).
- Configurazione PHPUnit strict con suite `unit`, `integration`, `functional` e gate di
  coverage al 100% (`tools/check-coverage.php`).
- Script Composer `qa` (validate, audit, rector, cs, phpstan, lint, test con coverage) e `fix`.
- Layout Twig con Bootstrap 5.3 via importmap e home page `/` con test funzionale.
- `TODO.md` e `CHANGELOG.md`.
- `docs/ANALISI_INTEGRATIVA.md`: correzioni a DEEPSEEK.md verificate su BLAST+ 2.17.0,
  comportamenti legacy non documentati, preset dei parametri e decisione di riscrivere
  `blast.pl` in PHP.

- Enum di dominio: `BlastProgram`, `MolType`, `JobStatus`, `SearchType`, `OutputFormat`,
  `DownloadSequenceType`.
- Entity Doctrine `User`, `BlastDatabase`, `BlastDatabaseProgram`, `BlastJob`, `BlastQuery`,
  `BlastHit`, `BlastHsp`, `SubjectSequence`, `BlastDownload` e migration iniziale.
- Eccezioni di dominio `InvalidJobTransitionException`, `IncompatibleProgramException`
  (marker `BestiaBaseException`).
- `BlastDatabaseRepository::findSelectableForProgram()`.
- Database di test `bestiabase_test` e test di integrazione con rollback per test.
- Motore BLAST in PHP (sostituisce `blast.pl`): `BlastBinaryLocator`, `BlastCommandBuilder`,
  `BlastRunner`, `JobWorkspace`, `ProcessRunnerInterface` con adapter `symfony/process`,
  `FastaNormalizer`, `BlastXmlParser` (streaming), `SequenceNames`, `BlastResultImporter`.
- Parametri di ricerca come value object (`SearchParameters`, `GapCosts`, `GapCostPresets`,
  `NucleotideScoring`, `ScoringMatrix`, `GeneticCode`) con i default e i preset del form legacy.
- Variabili d'ambiente `BLAST_FASTA_MAX_BYTES`, `BLAST_TIMEOUT`, `BLAST_VAR_DIR`.
- Fixture di test: output XML reali di BLAST+ 2.17.0.
- Gestione dei database BLAST: `BlastDatabaseImporter`, `BlastDatabaseInspector`
  (`blastdbcmd -metadata`), statistiche `sequence_count` / `total_length` su `blast_database`.
- Ciclo di vita dei job: `BlastJobFactory` (validazione all'invio) e `BlastJobProcessor`
  (esecuzione, import, fallimento senza risultati parziali).
- Comandi `app:blast:import-db`, `app:blast:import-test-data`, `app:blast:search`.
- FASTA dei database demo in `fixtures/blast-demo/`.
- Variabile d'ambiente `BLAST_DB_DIR`; in ambiente di test workspace e volumi stanno in `var/test/`.
- Esecuzione asincrona: messaggio `RunBlastJob` con `RunBlastJobHandler`, lock per job
  (`symfony/lock`, `LOCK_DSN`), `BlastJobSubmitter`, opzione `--async` di `app:blast:search`.
- Endpoint `GET /job/{id}/status` (JSON) per seguire l'avanzamento di un job.
- Versione di BLAST+ salvata in ogni job (`blast_job.blast_version`).
- Comando `app:blast:cleanup` con retention configurabile (`BLAST_JOB_RETENTION_DAYS`).
- Variabile d'ambiente `BLAST_QUERY_MAX_BYTES` (10 MiB) per la query incollata nel form web.
- Form di ricerca web su `/` (basic e advanced, parità con il form legacy): `SearchFormType`,
  `SearchFormData`, `SearchFormClientConfig`, controller Stimulus `search-form`.
- Pagina del job `/job/{id}` con aggiornamento automatico (controller Stimulus `job-status`).
- Rate limit delle ricerche per indirizzo IP (`BLAST_SEARCH_RATE_LIMIT`, `BLAST_SEARCH_RATE_INTERVAL`).
- Tema Bootstrap 5 per i form.
- Pagina risultati del job: tabella con filtri top-N per query, cutoff su percentuale o bit score,
  ordinamento e paginazione; vista pairwise per hit e per query; link NCBI (`nuccore`/`protein`).
- Download dei risultati: tabulare (`hits.tsv`, outfmt 6 o 7) e XML originale di BLAST.
- `PairwiseRenderer`, `AlignmentCoordinates`, `BlastNumberFormat`, `TabularExporter`, estensione
  Twig `BlastExtension`; test di parità con l'output pairwise e tabulare di BLAST+.
- Colonna `blast_hsp.gap_opens`.
- Autenticazione: login/logout con email e password, utenti disattivabili, ultimo accesso.
- Database BLAST pubblici o riservati, con utenti autorizzati per database (`blast_database_access`);
  gli amministratori vedono sempre tutti i database.
- Controllo d'accesso a job, risultati e download (`BlastJobVoter`) e ai database (`BlastDatabaseVoter`).
- Amministrazione web di database, utenti e job; pagina "My searches".
- Comandi `app:user:create`, `app:blast:access`; opzioni `--restricted` di `app:blast:import-db` e
  `--user` di `app:blast:search`.
- Content-Security-Policy con nonce per richiesta e header di sicurezza su tutte le risposte.
- Pagine di aiuto (`/help`, `/help/parameters`).
- Comando `app:blast:smoke-test` per verificare BLAST+ dopo un aggiornamento.
- Test del JavaScript nel browser (`composer test:browser`), inclusi in `composer qa`.
- README di installazione e deploy; `docs/PARITY_CHECKLIST.md`.
- Download delle sequenze soggetto degli hit selezionati (`POST /job/{id}/download`): sequenza intera
  (`blastdbcmd`, FASTA caricato, FASTA sorgente) o regione mappata alla query, registrato in
  `blast_download`; `SequenceExtractor`, `DownloadRequest`, controller Stimulus `select-all`.
- Test end-to-end con i binari reali (gruppo `blast`, saltati se `BLAST_BIN_DIR` non è valido).

### Fixed

- Download "mapping": coordinate corrette anche per frame e filamento minus (in sequence.php la fine
  restava indefinita per i programmi tradotti).
- `BLAST_FASTA_MAX_BYTES` ridotto da 50 MiB a 15 MiB: un FASTA tra 16 e 50 MiB passava la
  validazione e poi veniva rifiutato da MySQL (colonne `MEDIUMTEXT`).
- Deprecazioni Doctrine ORM 3: `SortDirection` al posto delle stringhe `'ASC'`, join sulle
  associazioni invece di `WITH` arbitrario, `nullable` superfluo su join column di identificatori
  e ManyToMany.

### Security

- Ogni processo BLAST+ gira con `BLAST_USAGE_REPORT=false`: per default BLAST+ invia a NCBI
  dati d'uso a ogni ricerca. Disattivarlo elimina anche circa 5 s di attesa di rete per
  invocazione.
- Il form di ricerca mostra e accetta solo i database accessibili all'utente; i job con proprietario
  sono visibili solo a lui e agli amministratori; chi perde l'accesso a un database riservato perde
  anche i risultati che ne provengono.
- Le password si inseriscono solo in modo nascosto (mai come argomento da riga di comando).
- Gli hit si leggono solo all'interno del proprio job (gli id sono sequenziali); i link NCBI si
  costruiscono solo da accessioni riconosciute, mai da testo arbitrario dei FASTA.
- Un errore non di dominio durante una ricerca viene salvato nel job con un messaggio
  generico: path interni e dettagli SQL restano solo nei log.
- I processi esterni ricevono sempre un argv (mai una stringa di shell); il parser XML non
  scarica mai il DTD remoto (`LIBXML_NONET`).

### Removed

- `HomeController` e la pagina segnaposto: `/` è ora il form di ricerca.
- `HitSummary` (sostituito da `HitRow` e `BlastHitRepository::findRows()`).
- Entity `SubjectSequence` e tabella `subject_sequence` (mai usate: i soggetti caricati stanno nel job,
  quelli dei database nei FASTA sorgente).
- Opzione "Additional output" del form di ricerca e colonna `blast_job.output_format`: non aveva effetto,
  i download XML e tabulare sono sempre disponibili.
- Colonne `blast_hit.total_identity` e `total_align_length` (calcolate e mai lette) e metodi non più usati.
- JavaScript di Bootstrap e Popper dall'importmap (si usa solo il CSS).

### Changed

- La coda dei job (`MESSENGER_TRANSPORT_DSN`) e i lock (`LOCK_DSN`) usano Redis invece di
  Doctrine e `flock`; aggiunto `symfony/redis-messenger`. I test restano su `in-memory://` e `flock`.
- Connessione Doctrine su MySQL 9.7 con charset `utf8mb4` e collation `utf8mb4_0900_ai_ci`.
- File di bootstrap dello skeleton (`public/index.php`, `tests/bootstrap.php`,
  `config/preload.php`) resi conformi a PHPStan max; l'input del runtime viene tipizzato
  con `typeidentifier`.
- Rispetto al DDL di DEEPSEEK.md: id del job `BINARY(16)` (UUID v7), colonna JSON dei
  database rinominata `database_snapshot` (`databases` è riservata in MySQL), `blast_query`
  con `definition` al posto di `sequence` (la query completa è in `blast_job.query_fasta`),
  vincolo unico di `subject_sequence` diviso in `(blast_database_id, name)` e
  `(job_id, name)` (con una FK sempre NULL quello composto non era efficace).
- Gate di coverage: elenca anche gli statement scoperti, non solo i metodi.
- L'esecuzione dei processi BLAST+ passa da `BlastProcessExecutor` (prima era interna a `BlastRunner`).
- `BLAST_DB_DIR` di default è `blastdb/` nella root del progetto (fuori da `var/` e da git); i path
  dei database registrati sono salvati relativi a `BLAST_DB_DIR`.
- I test fanno emergere anche le deprecazioni di Doctrine e quelle indirette.
- I fogli di stile sono collegati con `<link>` invece che importati da `app.js`.
- L'accessione del soggetto include la versione quando il seqid la contiene (`AAF24986.1`).
- `app:blast:search` stampa i valori con la formattazione di BLAST e usa la stessa query della
  pagina web.
- Rector: set `naming` disattivato, regole `PreferPHPUnitThisCallRector` e
  `NoSetupWithParentCallOverrideRector` saltate (conflitti con php-cs-fixer e PHPStan,
  motivati in `rector.php`).
