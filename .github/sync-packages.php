<?php

if ('cli' !== PHP_SAPI) {
    echo "This script can only be run from the command line.\n";
    exit(1);
}

$mainRepo = 'https://github.com/symfony/symfony';
exec('find src -name composer.json', $packages);

foreach ($packages as $package) {
    $package = dirname($package);
    $c = file_get_contents($package.'/.gitattributes');
    $c = preg_replace('{^/\.git.*+\n}m', '', $c);
    $c .= "/.git* export-ignore\n";
    file_put_contents($package.'/.gitattributes', $c);
}
