<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Compares two PHPStan JSON reports and fails if the second one (the PR) holds
// errors that are not already present in the first one (the target branch).
// Errors are matched on (file, message), ignoring line numbers, so that they
// keep being recognized when surrounding code shifts. This mirrors how Psalm's
// baseline works and, unlike PHPStan's own baseline, also covers errors PHPStan
// marks as non-ignorable.

function load(string $file): array
{
    $report = json_decode(file_get_contents($file), true) ?: [];

    $bag = [];
    foreach ($report['files'] ?? [] as $path => $data) {
        foreach ($data['messages'] ?? [] as $message) {
            $key = $path."\0".$message['message'];
            $bag[$key] = ($bag[$key] ?? 0) + 1;
        }
    }
    foreach ($report['errors'] ?? [] as $error) {
        $bag["\0".$error] = ($bag["\0".$error] ?? 0) + 1;
    }

    return [$report, $bag];
}

function annotation(string $message): string
{
    return strtr($message, ["\r" => '', "\n" => ' ', '%' => '%25']);
}

[, $baseBag] = load($argv[1]);
[$pr] = load($argv[2]);

$new = [];

foreach ($pr['files'] ?? [] as $path => $data) {
    foreach ($data['messages'] ?? [] as $message) {
        $key = $path."\0".$message['message'];
        if (0 < ($baseBag[$key] ?? 0)) {
            --$baseBag[$key];
            continue;
        }
        $new[] = sprintf('::error file=%s,line=%d::%s', $path, $message['line'] ?? 0, annotation($message['message']));
    }
}

foreach ($pr['errors'] ?? [] as $error) {
    $key = "\0".$error;
    if (0 < ($baseBag[$key] ?? 0)) {
        --$baseBag[$key];
        continue;
    }
    $new[] = '::error::'.annotation($error);
}

if ($new) {
    echo implode("\n", $new), "\n";
    exit(1);
}

echo "No new PHPStan errors.\n";
