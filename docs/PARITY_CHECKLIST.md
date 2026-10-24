# Checklist di parità con ViroBLAST

Verifica delle voci dell'Appendice E di `DEEPSEEK.md`: per ognuna, come è soddisfatta, quale test
la dimostra (in `composer qa`) e le differenze volute rispetto al legacy.

Legenda: ✅ parità verificata da test · ↔️ comportamento diverso per scelta (motivazione indicata)

| # | Voce | Esito | Test | Note |
|---|---|---|---|---|
| 1 | Basic search sui 5 programmi con i DB demo produce gli stessi hit del legacy | ✅ | `BlastPipelineTest::testEveryProgramAcceptsTheGeneratedCommand` (BLAST+ reale, basic e advanced), `testBlastnFindsTheSourceSectionOfTheQuery`, `testBlastpFindsTheQueryProteinItself` | Il limite di 50 target usa `-max_target_seqs` invece di `-num_descriptions`: con database grandi e molti hit simili l'insieme dei primi 50 può differire di poco (BLAST+ applica il limite durante la ricerca). Vedi `ANALISI_INTEGRATIVA.md` §3.2. |
| 2 | Advanced search: ogni parametro dell'Appendice A produce la stessa command line | ✅ | `BlastCommandBuilderTest` (tabella di verità, inclusa la logica "inversa" di filtro e masking), `SearchFormDataTest`, `SearchControllerTest`, test browser `search.test.mjs` | ↔️ `OTHER_ADVANCED` (opzioni CLI libere) eliminato per sicurezza. ↔️ Le combinazioni che BLAST+ rifiuterebbe (es. `-ungapped` con tblastx) sono respinte all'invio invece di far fallire il job. |
| 3 | Upload query multi-FASTA con CRLF e prefisso spurio → normalizzazione identica | ✅ | `FastaNormalizerTest`, `SearchControllerTest::testBasicSearchIsQueuedAndRedirectsToTheJob` | ↔️ Nessun upload di file dal web (decisione del committente: Cloudflare free limita le richieste a 100 s / 100 MB); la query si incolla nella textarea (fino a 10 MiB), i FASTA grandi si caricano da CLI. La normalizzazione è più completa del legacy (CRLF sempre convertiti, residui validati, sequenza nuda accettata). |
| 4 | Upload soggetto FASTA → makeblastdb automatico e ricerca (nucl e prot) | ✅ | `BlastPipelineTest::testUploadedSubjectIsSearchedWithoutRegisteredDatabases`, `BlastRunnerTest::testUploadedSubjectIsFormattedWithMakeblastdbFirst`, `BlastCommandsTest::testAdvancedSearchAgainstAnUploadedSubjectWithoutHits` | ↔️ Disponibile da CLI (`app:blast:search --subject`), non dal web (vedi voce 3). |
| 5 | Più database selezionati → ricerca congiunta | ✅ | `BlastRunnerTest::testBasicSearchOnRegisteredDatabases` (`-db "a b"`), `BlastCommandBuilderTest` | I path con spazi sono rifiutati (BLAST+ separa i database con spazi). |
| 6 | Tabella riepilogo: stesse colonne e stessi valori | ✅ | `TabularParityTest` (riga per riga contro `-outfmt 6`, 5 programmi), `BlastNumberFormatTest`, `JobControllerTest::testFinishedJobShowsTheResultsTableWithNcbiLinks` | Colonne Query, Subject, Score, Identities (Query length), Percentage, Expect come nel legacy; percentuale con la regola di BLAST (232/233 → 99%). |
| 7 | Filtro top N per query e cutoff %/score → stessi sottoinsiemi del legacy | ✅ | `BlastHitRepositoryTest::testRowsHonourTopCutoffSortAndQueryRange`, `JobControllerTest::testFiltersNoMatchMessageAndInvalidFilters` | Cutoff applicato prima del top N, top N nell'ordine di BLAST, come il legacy; ora sono query SQL e non riscrivono file. |
| 8 | Vista pairwise: stessa informazione di `blastN.html` | ✅ | `PairwiseParityTest` (intestazioni, percentuali, strand/frame, righe Query/Sbjct e coordinate contro `-outfmt 0`, 5 programmi), `PairwiseRendererTest` | ↔️ Non ricostruibili dall'XML: `Expect(N)` delle sum statistics, riga `Method:`, regioni mascherate in minuscolo (qui maiuscole o `X`). Paginazione per hit invece che per dimensione del file. Link NCBI `protein` per i database proteici (il legacy usava sempre `nuccore`). |
| 9 | 0 hit → messaggio corretto | ✅ | `JobControllerTest::testFiltersNoMatchMessageAndInvalidFilters`, `BlastCommandsTest::testAdvancedSearchAgainstAnUploadedSubjectWithoutHits` | "No comparison meets the cutoff criterion…", come il legacy. |
| 10 | Download "entire": sequenze complete dei soggetti (DB demo) | ✅ | `BlastPipelineTest::testEntireSubjectSequenceIsExtractedWithBlastdbcmd` (10 596 bp), `SequenceExtractorTest`, `JobControllerTest::testEntireSequencesOfAnUploadedSubject` | Fonte: `blastdbcmd`, poi FASTA caricato, poi FASTA sorgente (come il legacy). Nel legacy questa funzione andava in errore fatale su PHP 8 (`each()` rimossa). |
| 11 | Download "mapping": regioni mappate con coordinate e verso corretti | ✅ | `SequenceExtractorTest::testMappedRegionsUseBlastCoordinatesWithoutGaps`, `JobControllerTest::testMappedRegionsAreDownloadedAndTheDownloadIsRecorded` | Corretto anche per i programmi tradotti, dove il legacy lasciava la fine indefinita. Per tblastn/tblastx la sequenza è la traduzione allineata. |
| 12 | Errore BLAST+ (es. DB inesistente) → job `failed` con stderr mostrato | ✅ | `BlastJobProcessorTest::testBlastFailureMarksTheJobFailedWithTheBlastMessage`, `testPartialResultsAreRemovedWhenTheOutputBreaksHalfway`, `testUnexpectedErrorsAreNotShownToTheUser`, `BlastCommandsTest::testFailedSearchReportsTheBlastError` | L'esito dipende dall'exit code, non da file marker; nessun risultato parziale; i messaggi interni (path, SQL) non sono mostrati all'utente. |
| 13 | Job contemporanei senza collisioni | ✅ | `BlastJobFactoryTest` (UUID v7), `RunBlastJobHandlerTest::testJobLockedByAnotherWorkerIsLeftAlone` | UUID v7 al posto di `time().rand(10,99)`; lock per job contro le doppie consegne del messaggio. |
| 14 | Pulizia periodica di job e file | ✅ | `JobCleanerTest`, `BlastCommandsTest::testCleanupHonoursTheRetention` | `app:blast:cleanup` da cron, solo job terminati, con risultati e workspace. |

## Oltre il legacy

Funzioni che ViroBLAST non aveva, coperte dai test:

- utenti, ruoli e database riservati per utente (`AccessRulesTest`, `AccessControlTest`, `AdministrationTest`);
- storico delle ricerche per utente (`AccessControlTest::testMySearchesListsOnlyTheJobsOfTheUser`);
- export tabulare e XML scaricabili per ogni job (`JobControllerTest`);
- rate limit, CSRF, Content-Security-Policy (`SearchControllerTest`, `SecurityHeadersTest`, test browser);
- smoke test dell'installazione BLAST+ (`BlastSmokeTestTest`).
