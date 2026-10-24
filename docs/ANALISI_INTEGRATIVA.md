# Analisi integrativa del legacy ViroBLAST

Integra e corregge `/home/shady/public_html/ViroblastStandalone/DEEPSEEK.md` (che non
viene modificato). Dove i due documenti divergono **vale questo**.

Metodo: rilettura completa di `blast.pl`, `blastresult.php`, `sequence.php`,
`javascripts/viroblast.js`, `viroblast.ini`, README/ChangeLog e dei branch
`full_refactoring` / `feature_refactoring_php8`; verifica sperimentale con i binari
BLAST+ **2.17.0** e i database demo (2026-10-24).

---

## 1. Correzioni a DEEPSEEK.md (verificate sui binari)

| # | DEEPSEEK.md dice | Realtà verificata | Impatto |
|---|---|---|---|
| C1 | §10.1: basic = `-outfmt 5 -num_descriptions 50 -num_alignments 50` | Con `outfmt > 4` BLAST+ **ignora** `-num_descriptions`/`-num_alignments` (warning) e usa il default di 500 target. Serve `-max_target_seqs 50`. | Il builder deve usare sempre `-max_target_seqs` perché la pipeline gira sempre in XML. |
| C2 | §10.4: `-outfmt 5` è "XML2" | `-outfmt 5` è l'XML **legacy** (`NCBI_BlastOutput.dtd`, encoding `US-ASCII`). XML2 è `-outfmt 16`. | Il parser va scritto per lo schema `BlastOutput` (quello descritto in §10.4, solo il nome è sbagliato). |
| C3 | §4.10: `test_na_db` contiene 1 sequenza da circa 10.596 bp | **6 sequenze**, 69.370 basi (sezioni 1–6 di *E. coli*). `test_aa_db` ha **214 proteine**. | Fixture e test di integrazione. |
| C4 | §4.10/§8.3: i DB demo hanno accessioni e `blastdbcmd -entry ACC` funziona | I volumi demo **non** sono costruiti con `-parse_seqids`: `Hit_id` = `gnl\|BL_ORD_ID\|N`, `Hit_accession` = numero ordinale (OID), `blastdbcmd -entry` → *"DB contains no accession info"*. Il nome reale è solo in `Hit_def`. | Vedi §3.1: i DB demo vanno **ricostruiti** dai FASTA sorgente con `-parse_seqids`. Verificato: dopo la ricostruzione `Hit_id`=`gi\|1786181\|gb\|AE000111\|ECAE000111`, `Hit_accession`=`AE000111`, `blastdbcmd -entry AE000111` funziona. |
| C5 | §4.5/§6.1.5: se blast+ fallisce `.err` resta vuoto → attesa infinita | `blast.pl` L132 ridirige STDERR su `.err` **prima** di `system(@cmd)`: il figlio eredita lo stream, quindi gli errori di blast+ (es. DB inesistente, exit code 2) finiscono in `.err` e il poller li mostra. L'attesa infinita si verifica solo con un'uscita non zero **senza** output su stderr (segnale, OOM, kill). | Il bug resta da correggere (lo stato si decide dall'exit code, non dal contenuto di stderr), ma è meno frequente di quanto descritto. |
| C6 | §3.2/§10.3: la query viene normalizzata (CRLF→LF, troncamento prima di `>`) | Solo la query **caricata come file** viene normalizzata. La textarea viene salvata **così com'è**, senza `>` obbligatorio: una sequenza nuda è accettata (blast+ la legge). Il controllo CR sostituisce CR con LF **solo se nel file non c'è nessun LF** (file Mac classici); i file CRLF restano invariati. | `FastaNormalizer` deve accettare la sequenza nuda della textarea (aggiungendo un header sintetico), oltre al FASTA. |
| C7 | §5.2: la colonna "Identities (Query length)" mostra X e la Y è la lunghezza query | Il valore è `X/Y (Lq)`: `X/Y` = identità/lunghezza dell'**allineamento** (testo della riga `Identities =`), `Lq` = lunghezza della query. `Percentage` = intero arrotondato da BLAST (`(91%)`), non un decimale. | Ricostruire `X/Y` da `Hsp_identity`/`Hsp_align-len`; la percentuale va mostrata arrotondata all'intero per coerenza, ma memorizzata come decimale per i filtri. |
| C8 | §8.3: link NCBI sempre `nuccore` | Il legacy linka `nuccore` anche per gli hit **proteici** (blastp/blastx): è un bug. | Link `…/protein/ACC` per i DB `prot`, `…/nuccore/ACC` per i DB `nucl`. |
| C9 | §4.6 download *entire* | `sequence.php` usa `each()`, **rimossa in PHP 8**: il download *entire* va in fatal error su qualunque PHP moderno. | Solo storico: conferma che il comportamento di riferimento va preso dal codice, non dal runtime. |
| C10 | (non menzionato) | BLAST+ ≥ 2.10 invia a NCBI un *usage report* a ogni esecuzione (`BLAST_PRIVACY`). In un ambiente senza uscita verso NCBI ogni invocazione attende circa **5,2 s** di timeout (misurato: 5,23 s contro 0,01 s con `BLAST_USAGE_REPORT=false`). | Ogni processo BLAST+ gira con `BLAST_USAGE_REPORT=false`, per privacy e prestazioni. |

## 2. Comportamenti non documentati in DEEPSEEK.md

### 2.1 Tabella risultati (`blastresult.php`)

- **Massimo 10 righe per query** nella vista di default, anche se BLAST restituisce fino a 50
  target. Le righe oltre la decima non vengono mostrate né scritte in `download.txt`.
- **"Download all"** scarica le righe **mostrate** (`download.txt` viene riscritto a ogni
  render), cioè al massimo 10 per query o il sottoinsieme filtrato, non tutti gli hit.
- **Top N** (`filt_val` 1/5/10) prende i **primi N nell'ordine dell'output BLAST**, che è
  già ordinato per e-value e bit score, quindi non serve un riordino. Il filtro agisce su
  `out.par`, cioè **dopo** l'eventuale cutoff "Parse again".
- **Cutoff**: `pct` confronta la percentuale intera (`>=`), `blst` il bit score (`>=`).
  Default UI 95% e 1000.
- Il filtro top N e il cutoff si escludono nella stessa richiesta: "Parse again" riscrive
  `out.par`, mentre "Filter" legge `out.par` così com'è.
- La pagina di attesa legge `dot` da POST mentre il refresh lo passa in GET, quindi i
  pallini non avanzano. È solo cosmetico.

### 2.2 Riconoscimento accessioni (`blast.pl` L229, L255)

Due set di regex **diversi** nei due rami (accessione nuda: `\.\d+` obbligatorio in L229,
`\.?\d+` in L255). Regola unificata da adottare, applicata al **primo token** del nome:

1. `^gi\|\d+\|\w+\|([A-Z]{1,5}\d+(?:\.\d+)?)` e `^gi\|\d+\|\w+\|([A-Z]{2}_\d+(?:\.\d+)?)`
2. `^gnl\|\w+\|([A-Z]{1,5}\d+(?:\.\d+)?)`
3. `^([A-Z]{1,5}\d+(?:\.\d+)?)` e `^([A-Z]{2}_\d+(?:\.\d+)?)`

Se `Hit_accession` è valorizzato (DB con `-parse_seqids`) ha la precedenza sulle regex.

### 2.3 Nome query e nome soggetto

- Nome query: primo token del defline, tagliato al primo carattere tra `, ; spazio +`
  (la classe `[,;\s+]` include il `+`). Defline vuoto → `Query<N>`. In XML corrisponde a
  `Iteration_query-def` (defline completo): il troncamento si applica in PHP.
- Nome soggetto: stessa regola sul primo token. Per DB senza `-parse_seqids` va preso da
  `Hit_def`, non da `Hit_id` (vedi C4).

### 2.4 Download *mapping* (`sequence.php`)

- Calcola le coordinate di fine solo per `Strand=Plus/Plus` e `Plus/Minus`: per
  blastp, blastx, tblastn e tblastx (che hanno `Frame=` e non `Strand=`) `$send` resta
  indefinito e `qend` è sbagliato con le query tradotte. Nella riscrittura le coordinate
  si prendono direttamente da `Hsp_query-from/to` e `Hsp_hit-from/to` (con i frame), senza
  ricalcolarle.
- Un record per ogni HSP (chiave query, soggetto, qstart, sstart), header
  `>defline (Subject: acc, sstart..send; Query: query, qstart..qend)`, sequenza senza gap,
  a capo ogni 80 caratteri.

### 2.5 Download *entire*

Il path del DB **è** il FASTA sorgente (basename senza estensione accanto ai volumi); per
l'upload è `blastagainst.txt`. Le sequenze sono rese maiuscole e ripulite da `-` e spazi,
e l'header è il defline originale.

### 2.6 Parametri avanzati: preset dei gap cost (`viroblast.js`)

Il primo elemento della lista è il default selezionato.

| Scoring | Gap cost (Existence, Extension) |
|---|---|
| blastn iniziale / `2,-3` | 5,2 · 4,4 · 2,4 · 0,4 · 3,3 · 6,2 · 4,2 · 2,2 |
| `1,-2` | 5,2 · 2,2 · 1,2 · 0,2 · 3,1 · 2,1 · 1,1 |
| `1,-3` | 5,2 · 2,2 · 1,2 · 0,2 · 2,1 · 1,1 |
| `1,-4` | 5,2 · 1,2 · 0,2 · 2,1 · 1,1 |
| `1,-1` | 5,2 · 3,2 · 2,2 · 1,2 · 0,2 · 4,1 · 3,1 · 2,1 |
| `4,-5` | 12,8 · 6,5 · 5,5 · 4,5 · 3,5 |
| proteine iniziale | 11,1 · 8,2 · 9,2 · 10,1 · 7,2 · 12,1 |
| `BLOSUM62` | 11,1 · 9,2 · 8,2 · 7,2 · 12,1 · 10,1 |
| `PAM30` | 9,1 · 7,2 · 6,2 · 5,2 · 10,1 · 8,1 |
| `PAM70`, `BLOSUM80` | 10,1 · 8,2 · 7,2 · 6,2 · 11,1 · 9,1 |
| `BLOSUM45` | 15,2 · 13,3 · 12,3 · 11,3 · 10,3 · 14,2 · 13,2 · 12,2 · 19,1 · 18,1 · 17,1 · 16,1 |

Altri default UI: word size blastn 7/**11**/15, altri 2/**3**; max target
10/**50**/100/250/500/1000/5000/10000; expect **10**, validato come
`^\d*\.?\d+$` o `^\d*[eE][+-]\d+$`. Il gap cost non è disponibile per tblastx.

### 2.7 Varie

- **Retention**: il branch `feature_refactoring_php8` aveva `cleandata.sh`, che cancellava
  tutto `data/` (`*.html *.txt *.fas *.err *.log *.par *.out`) senza criteri d'età.
  `app:blast:cleanup` lo sostituisce con una retention per data.
- **Sorting** della tabella: lato client (`sorttable.js`) su tutte le colonne, con
  ordinamento numerico per identities ed e-value (ChangeLog 2.2+).
- **Multi-DB**: `-db "path1 path2"` (un solo argomento con i path separati da spazio)
  funziona con 2.17.0 (verificato). Conseguenza: i path dei DB **non possono contenere
  spazi**. Va validato all'import.
- **Encoding**: i sorgenti legacy sono in ISO-8859-1. L'XML di BLAST è `US-ASCII`. Il nuovo
  progetto è UTF-8 end-to-end.

### 2.8 Formattazione dei numeri di BLAST+ (verificata con 2.17.0)

L'XML ha i valori a piena precisione; pairwise e tabulare li arrotondano con regole proprie,
riprodotte da `BlastNumberFormat` e verificate dai test di parità:

| Valore | Pairwise (`-outfmt 0`) | Tabulare (`-outfmt 6/7`) |
|---|---|---|
| e-value < 1e-180 | `0.0` | `0.0` |
| e-value < 0.0009 | `3e-06`, `2e-165` | `3.08e-06`, `2.32e-165` |
| e-value < 0.1 / < 1 / < 10 / oltre | `0.052` / `0.54` / `2.9` / `25` | uguale |
| bit score > 99.9 | intero troncato (`632`, `19109`) | uguale |
| bit score ≤ 99.9 | un decimale (`43.7`) | uguale |
| percentuale | `GetPercentMatch`: 100 solo se completa, 0 solo se nulla, altrimenti arrotondata tra 1 e 99 (232/233 → **99%**) | `pident` con 3 decimali |

Non ricostruibili dall'XML: `Expect(N)` per gli e-value da sum statistics (tblastx), la riga
`Method: Compositional matrix adjust.`, le regioni mascherate (in minuscolo nel testo; nell'XML in
maiuscolo con DUST e come `X` con SEG). Sul lato tradotto l'XML riporta from/to crescenti anche per i
frame negativi, mentre pairwise e tabulare li stampano decrescenti.

## 3. Decisioni che ne derivano

### 3.1 Database demo

`app:blast:import-test-data` **non** riusa i volumi legacy: ricostruisce i DB con
`makeblastdb -parse_seqids -title …` a partire dai FASTA sorgente (`test_na_db`,
`test_aa_db`) in `BLAST_DB_*_DIR`. Lo stesso `-parse_seqids` è obbligatorio per ogni DB
importato dall'admin. Per DB esterni già formattati senza seqid, il download *entire* ripiega
sul FASTA sorgente registrato (`source_fasta`), come fa il legacy.

### 3.2 Parità con il legacy: limite noto

Il legacy limita i risultati con `-num_descriptions/-num_alignments` (filtro sull'output),
mentre la nuova pipeline in XML usa `-max_target_seqs`, che in BLAST+ agisce **durante** la
ricerca. Con DB grandi e query con molti hit simili i primi 50 possono differire
leggermente. Il golden test di parità (Appendice E) va fatto sui DB demo e documenta questa
differenza.

---

## 4. `blast.pl`: Go o PHP?

### Cosa fa davvero `blast.pl`

1. **Costruisce l'argv** di blast+ dai parametri (L40–130): logica condizionale pura, circa
   90 righe.
2. **Esegue** `makeblastdb` (per un eventuale upload) e il programma BLAST (L133–154).
3. **Post-processa l'HTML** (L166–400): ancore, link NCBI, paginazione, estrazione del
   riepilogo. Nella riscrittura **scompare**, sostituito dal parsing XML.

Il calcolo pesante è tutto nei binari C++ di NCBI. L'orchestratore costruisce argomenti,
avvia processi e legge un file XML.

### Misura (2.17.0, su questa macchina)

| Operazione | Tempo | Memoria |
|---|---|---|
| `blastp` 214 query × `test_aa_db`, 4 thread | 7,8 s | (processo C++) |
| Output XML prodotto | 48,7 MB | — |
| Parsing PHP 8.5 `XMLReader` streaming (214 iterazioni, 35.521 hit, 44.198 HSP) | **0,29 s** | **2 MB** di picco |

Il parsing in PHP costa meno del 4% del tempo di BLAST, con memoria costante. Il collo di
bottiglia reale sarà l'inserimento delle righe in MySQL, che è uguale in Go e in PHP
(entrambi parlano con lo stesso DB).

### Confronto

| Criterio | PHP (Symfony) | Go |
|---|---|---|
| Performance utile | Sufficiente (vedi misura); il tempo è dominato da BLAST e dagli INSERT | Parsing più veloce, ma su una quota di tempo già trascurabile |
| Esecuzione processi | `symfony/process` (argv array, timeout, idle timeout, stop) | `os/exec`, equivalente |
| Asincronia e worker | Messenger + `messenger:consume` (retry, failure transport, supervisione) | Da costruire: coda, retry, stato, oppure un bridge verso Messenger |
| Modello dati | Un solo modello Doctrine (entity, enum, validazione) | Duplicazione di enum, validazione e schema, oppure un contratto JSON/RPC da versionare |
| Qualità e test | Una toolchain (PHPStan max, Rector, cs-fixer, PHPUnit, coverage 100%) | Seconda toolchain (golangci-lint, go test), seconda CI, secondo gate di coverage |
| Deploy | Già presente (PHP-FPM e worker) | Binario aggiuntivo da compilare, distribuire e aggiornare con le sue dipendenze |
| Manutenibilità richiesta | Un linguaggio e un repository | Due linguaggi, competenze doppie |
| Sicurezza | Validazione e argv in un unico punto | Due punti di validazione da tenere allineati |

### Raccomandazione: **PHP**

Go non porta un vantaggio misurabile. L'unico punto in cui sarebbe più veloce (il parsing)
pesa meno del 4% del job, e il costo dominante (gli INSERT) non cambia. In cambio
introdurrebbe un secondo runtime, una seconda toolchain di qualità, un contratto IPC e la
duplicazione del dominio, contro l'obiettivo esplicito di un software "facilmente
manutenibile".

Va rivalutato solo se emerge un requisito oggi assente: output di molti GB per job,
throughput di molti job al secondo, oppure un esecutore BLAST da distribuire su nodi
separati senza PHP. In quel caso Go andrebbe introdotto come **worker isolato** dietro
un'interfaccia (`BlastExecutorInterface`), senza toccare il resto. Questo design lo rende
possibile senza riscritture: è il motivo per cui runner, builder e parser stanno dietro
interfacce.

Mappatura in PHP di `blast.pl`:

| `blast.pl` | Nuovo componente |
|---|---|
| L13–38 parsing dei parametri e log | DTO `BlastSearchParameters` validato + snapshot JSON in `blast_job.parameters` |
| L40–130 costruzione argv | `BlastCommandBuilder` (puro, testato con una tabella di verità) |
| L132 stderr su file | `ProcessRunnerInterface` cattura stderr; errore = exit code ≠ 0 |
| L133–148 makeblastdb | `BlastRunner::prepareUploadedSubject()` |
| L149–154 esecuzione | `BlastRunner::run()` (timeout, idle timeout) |
| L156–400 post-processing HTML | `BlastXmlParser` (streaming) + `PairwiseRenderer` (Twig) |
| L394–405 conteggio e marker | `BlastJob::finish(numQueries, numHits)`, stato su DB |
