<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Enforces that security-sensitive boundary classes ship their hardening
// regression test, so the protection is not silently dropped later:
//
//   A. Every concrete RequestParser (extends AbstractRequestParser) must have a
//      test that expects a RejectWebhookException, proving the reject path is
//      exercised (missing/forged/empty secret, unknown IP, malformed payload).
//   B. Every class with __unserialize() and a string-typed property must have a
//      test that feeds a __toString gadget through unserialize(), proving the
//      property does not become a __toString trampoline.
//
// Companion to the PHPStan rules HardenedComparisonRule (timing-safe compare)
// and UnserializeToStringTrampolineRule (the missing guard itself). Those flag
// missing hardening in the implementation; this flags missing hardening in the
// test suite. Runs on the tokenizer only, no autoloading of the analysed code.

$root = \dirname(__DIR__, 2).'/src/Symfony';

// Pre-existing gaps accepted on this branch. Each entry is a TODO, not a waiver:
// add a reason, remove the entry once the test is written. New violations are
// not allowed here and fail the build.
const ALLOWLIST = [
    // 'Symfony\Component\...\FooRequestParser' => 'reason',
];

/**
 * @return array{fqcn:string, abstract:bool, extends:?string, methods:list<string>, hasStringProperty:bool}|null
 */
function analyze(string $path): ?array
{
    $tokens = \PhpToken::tokenize(file_get_contents($path));

    $namespace = '';
    $class = null;
    $abstract = false;
    $extends = null;
    $methods = [];
    $sawAbstract = false;
    $expectClassName = false;
    $expectExtends = false;

    foreach ($tokens as $i => $token) {
        if ($token->is(T_NAMESPACE)) {
            $namespace = trim(collectName($tokens, $i + 1));
            continue;
        }
        if ($token->is(T_ABSTRACT)) {
            $sawAbstract = true;
            continue;
        }
        if ($token->is(T_CLASS) && null === $class) {
            $abstract = $sawAbstract;
            $expectClassName = true;
            continue;
        }
        if ($expectClassName && $token->is(T_STRING)) {
            $class = $token->text;
            $expectClassName = false;
            continue;
        }
        if (null !== $class && null === $extends && $token->is(T_EXTENDS)) {
            $expectExtends = true;
            continue;
        }
        if ($expectExtends && ($token->is(T_STRING) || $token->is(T_NAME_QUALIFIED) || $token->is(T_NAME_FULLY_QUALIFIED))) {
            // keep only the short name of the parent
            $parts = explode('\\', $token->text);
            $extends = end($parts);
            $expectExtends = false;
            continue;
        }
        if ($token->is(T_FUNCTION)) {
            if (null !== $name = nextString($tokens, $i + 1)) {
                $methods[] = $name;
            }
        }
    }

    if (null === $class) {
        return null;
    }

    return [
        'fqcn' => '' !== $namespace ? $namespace.'\\'.$class : $class,
        'abstract' => $abstract,
        'extends' => $extends,
        'methods' => $methods,
        'hasStringProperty' => hasStringProperty(file_get_contents($path)),
        'unserializeRestoresState' => unserializeRestoresState($tokens),
    ];
}

// True when __unserialize() actually restores object state, i.e. its first
// statement is not an unconditional throw. A class that refuses to unserialize
// ("throw new \BadMethodCallException(...)") has no trampoline surface and so
// needs no trampoline test.
function unserializeRestoresState(array $tokens): bool
{
    $n = \count($tokens);
    for ($i = 0; $i < $n; ++$i) {
        if (!$tokens[$i]->is(T_FUNCTION) || '__unserialize' !== nextString($tokens, $i + 1)) {
            continue;
        }
        // advance to the body opening brace
        for ($j = $i + 1; $j < $n && !$tokens[$j]->is('{'); ++$j);
        // first significant token inside the body
        for ($k = $j + 1; $k < $n; ++$k) {
            if ($tokens[$k]->isIgnorable()) {
                continue;
            }

            return !$tokens[$k]->is(T_THROW);
        }
    }

    return false;
}

/**
 * @param list<\PhpToken> $tokens
 */
function collectName(array $tokens, int $from): string
{
    $name = '';
    for ($i = $from, $n = \count($tokens); $i < $n; ++$i) {
        if ($tokens[$i]->is(';') || $tokens[$i]->is('{')) {
            break;
        }
        if ($tokens[$i]->isIgnorable()) {
            continue;
        }
        $name .= $tokens[$i]->text;
    }

    return $name;
}

/**
 * @param list<\PhpToken> $tokens
 */
function nextString(array $tokens, int $from): ?string
{
    for ($i = $from, $n = \count($tokens); $i < $n; ++$i) {
        if ($tokens[$i]->isIgnorable()) {
            continue;
        }

        return $tokens[$i]->is(T_STRING) ? $tokens[$i]->text : null;
    }

    return null;
}

// A property (incl. constructor-promoted) typed exactly "string" or "?string".
function hasStringProperty(string $source): bool
{
    return 1 === preg_match('/(?:private|protected|public)(?:\s+readonly)?\s+\?\s*string\s+\$/', $source);
}

function nearestComposerDir(string $path): ?string
{
    $dir = \dirname($path);
    while (str_starts_with($dir, '/') && false !== strpos($dir, '/src/Symfony')) {
        if (is_file($dir.'/composer.json')) {
            return $dir;
        }
        $dir = \dirname($dir);
    }

    return null;
}

/**
 * @param list<string> $allNeedles every needle must be present in a single test file
 */
function testTreeContains(string $componentDir, array $allNeedles): bool
{
    $testsDir = $componentDir.'/Tests';
    if (!is_dir($testsDir)) {
        return false;
    }

    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || 'php' !== $file->getExtension()) {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        foreach ($allNeedles as $needle) {
            if (false === stripos($code, $needle)) {
                continue 2;
            }
        }

        return true;
    }

    return false;
}

$violations = [];

$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || 'php' !== $file->getExtension() || false !== strpos($file->getPathname(), '/Tests/') || false !== strpos($file->getPathname(), '/vendor/')) {
        continue;
    }

    $info = analyze($file->getPathname());
    if (null === $info) {
        continue;
    }

    $componentDir = nearestComposerDir($file->getPathname());
    if (null === $componentDir) {
        continue;
    }

    // Check A: concrete webhook RequestParser must have a reject-path test.
    if (!$info['abstract'] && 'AbstractRequestParser' === $info['extends']) {
        if (!testTreeContains($componentDir, ['RejectWebhookException', 'expectException'])) {
            $violations[$info['fqcn']] = \sprintf('%s extends AbstractRequestParser but no test expects RejectWebhookException.', $info['fqcn']);
        }
    }

    // Check B: serializable class with a string property must have a trampoline test.
    if (\in_array('__unserialize', $info['methods'], true) && $info['hasStringProperty'] && $info['unserializeRestoresState']) {
        if (!testTreeContains($componentDir, ['__toString', 'unserialize'])) {
            $violations[$info['fqcn']] = \sprintf('%s has __unserialize() with a string property but no __toString-trampoline test.', $info['fqcn']);
        }
    }
}

$unexpected = array_diff_key($violations, ALLOWLIST);
$stale = array_diff_key(ALLOWLIST, $violations);

foreach ($unexpected as $message) {
    echo '::error::'.$message."\n";
}

foreach ($stale as $fqcn => $reason) {
    echo \sprintf("::warning::Allow-listed class \"%s\" no longer violates the convention (%s); remove it from the allow-list.\n", $fqcn, $reason);
}

if ($unexpected) {
    echo \sprintf("\n%d hardening-test convention violation(s). Add the missing test, or, only for an accepted pre-existing gap, an entry to the allow-list in %s.\n", \count($unexpected), 'check-hardening-tests.php');
    exit(1);
}

echo \sprintf("Hardening-test convention satisfied (%d allow-listed gap(s)).\n", \count(ALLOWLIST));
