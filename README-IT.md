# BestiaBase

*English version: [README.md](README.md).*

**BESTIA** è l'acronimo di **B**LAST **E**nvironment for **S**earch, **T**racking, **I**nspection and
**A**nalysis: un ambiente BLAST per la ricerca, il monitoraggio, la consultazione e l'analisi dei
risultati. «Base» richiama i database di sequenze e la piattaforma che li rende accessibili.

BestiaBase è un web server per ricerche di similarità con **NCBI BLAST+** (blastn, blastp, blastx,
tblastn, tblastx) su database di sequenze. Le ricerche girano in background, i risultati vengono
salvati e si possono consultare, scaricare e condividere, e i database possono essere pubblici o
riservati a utenti scelti.

## Origine

BestiaBase è **open source**. Nasce dall'*idea* di
[ViroblastStandalone](https://github.com/MullinsLab/ViroblastStandalone); **non è un fork né un
refactoring** di quel progetto, che è deprecato, non manutenuto e soggetto a criticità di sicurezza.

BestiaBase non ha dipendenze da Perl e usa uno stack aggiornato e facilmente manutenibile: PHP 8.5,
Symfony, Doctrine, MySQL 9.7, Redis, Twig e Stimulus.

## Licenza

Rilasciato con licenza [GNU General Public License v2.0](LICENSE) (`GPL-2.0-only`).

## Installazione

Vedi [SETUP-IT.md](SETUP-IT.md).

## Contribuire

BestiaBase segue lo standard imposto dal suo creatore, **Stefano Perrini**. I contributi sono
benvenuti purché lo rispettino:

- **Modifiche piccole e mirate.** Una pull request, uno scopo, con una descrizione chiara.
- **PHP rigoroso e tipizzato.** `declare(strict_types=1)` ovunque, tipi espliciti, classi `final`,
  enum nativi, convenzioni Symfony (attributi, autowiring, controller sottili).
- **Sicurezza prima di tutto.** Nessun path, nome di binario o opzione CLI dal client; processi solo
  con array di argomenti; nessun segreto nel repository.
- **Test con ogni modifica.** Ogni comportamento nuovo è esercitato come lo userebbe un chiamante
  (richiesta HTTP o chiamata di servizio). La suite resta verde e la coverage al 100%.
- **I controlli di qualità devono passare** prima di aprire una pull request:

  ```bash
  composer fix    # Rector + php-cs-fixer
  composer qa     # Rector, php-cs-fixer, PHPStan (level max), PHPUnit, linter, test del browser
  ```

  Non si abbassa il livello di PHPStan, non si aggiungono ignore generici e non si disattiva un
  controllo per farlo passare.
- **Documentazione aggiornata.** Ogni modifica visibile va in `CHANGELOG.md` (Keep a Changelog) e
  `TODO.md` resta allineato.
- **Rispetto e chiarezza.** Sii costruttivo in review e issue; i problemi di sicurezza si segnalano
  in privato al maintainer, non con una issue pubblica.
