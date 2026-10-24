# Database BLAST demo

FASTA sorgenti dei due database di prova distribuiti con ViroBLAST, usati da
`bin/console app:blast:import-test-data` e dai test di integrazione.

| File | Tipo | Sequenze | Contenuto |
|---|---|---|---|
| `test_na_db.fa` | nucleotide | 6 | sezioni 1–6 del genoma di *E. coli* (AE000111–AE000116) |
| `test_aa_db.fa` | proteine | 214 | proteine HepA-related e correlate |

I volumi BLAST vengono ricostruiti con `makeblastdb -parse_seqids` al momento dell'import: i
volumi originali di ViroBLAST non hanno i seqid e non permettono `blastdbcmd -entry`.
