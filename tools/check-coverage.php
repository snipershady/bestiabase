<?php

declare(strict_types=1);

/*
 * Gate di coverage: fallisce se metodi, statement o condizioni del progetto
 * non sono coperti al 100% secondo il report Clover di PHPUnit.
 */

$file = $argv[1] ?? 'var/coverage/clover.xml';

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Report di coverage non trovato: {$file}\n");

    exit(1);
}

$document = new DOMDocument();

if (!$document->load($file)) {
    fwrite(STDERR, "Report di coverage illeggibile: {$file}\n");

    exit(1);
}

$xpath = new DOMXPath($document);
$metricsNodes = $xpath->query('/coverage/project/metrics');
$metrics = $metricsNodes === false ? null : $metricsNodes->item(0);

if (!$metrics instanceof DOMElement) {
    fwrite(STDERR, "Metriche di progetto assenti in {$file}\n");

    exit(1);
}

$failed = false;

foreach (['methods', 'statements', 'conditionals', 'elements'] as $kind) {
    $total = (int) $metrics->getAttribute($kind);
    $covered = (int) $metrics->getAttribute('covered' . $kind);
    printf("%-13s %d/%d\n", $kind, $covered, $total);

    if ($covered !== $total) {
        $failed = true;
    }
}

if (!$failed) {
    echo "Coverage 100%\n";

    exit(0);
}

// Metodi e statement scoperti: un metodo eseguito solo in parte non compare tra i metodi.
$uncovered = $xpath->query('//file/line[@count="0"]');

foreach ($uncovered === false ? [] : $uncovered as $line) {
    if (!$line instanceof DOMElement || !$line->parentNode instanceof DOMElement) {
        continue;
    }

    printf("  scoperto: %s:%s (%s%s)\n", $line->parentNode->getAttribute('name'), $line->getAttribute('num'), $line->getAttribute('type'), $line->hasAttribute('name') ? ' ' . $line->getAttribute('name') : '');
}

exit(1);
