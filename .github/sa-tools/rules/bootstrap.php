<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Autoloads the global-namespace *Rule.php classes in this folder so PHPStan can
// instantiate the rules listed in ../rules.neon. New rules are picked up with no
// extra wiring: drop a "<Name>Rule.php" here and add it to rules.neon's "rules:".
spl_autoload_register(static function (string $class): void {
    if (!str_contains($class, '\\') && is_file($file = __DIR__.'/'.$class.'.php')) {
        require_once $file;
    }
});
