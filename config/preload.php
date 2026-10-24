<?php

declare(strict_types=1);

$preloadFiles = glob(__DIR__ . '/../var/cache/prod/*.preload.php');

foreach ($preloadFiles === false ? [] : $preloadFiles as $file) {
    require $file;
}
