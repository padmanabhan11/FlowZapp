#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Static rule from 03-Technical-Architecture §4.1: raw and unscoped queries are
 * forbidden in application code because they bypass TenantScope.
 *
 * Fails (exit 1) when any file under app/ or routes/ contains one of the
 * patterns below on a line that is not marked "// allowlisted: <reason>".
 * Migrations, seeders and factories are exempt. Run in CI before PHPUnit.
 *
 *   php scripts/check-raw-queries.php
 */
$root = dirname(__DIR__);
$dirs = ["$root/app", "$root/routes"];

$patterns = [
    '/\bDB::(raw|select|insert|update|delete|statement|unprepared|table|scalar)\s*\(/' => 'DB facade query — use an Eloquent model that extends TenantModel',
    '/->(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw|joinSub|fromSub)\s*\(/' => 'raw query-builder call — express it through the model layer or allowlist with a reason',
    '/::withoutGlobalScopes?\s*\(/' => 'global scope removed — every use must be allowlisted with a reason',
    '/->withoutGlobalScopes?\s*\(/' => 'global scope removed — every use must be allowlisted with a reason',
];

$violations = [];
foreach ($dirs as $dir) {
    if (! is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $line) {
            if (str_contains($line, '// allowlisted:')) {
                continue;
            }
            foreach ($patterns as $re => $why) {
                if (preg_match($re, $line)) {
                    $rel = substr($file->getPathname(), strlen($root) + 1);
                    $violations[] = sprintf('%s:%d  %s%s    %s', $rel, $i + 1, trim($line), PHP_EOL, $why);
                }
            }
        }
    }
}

if ($violations) {
    fwrite(STDERR, 'Raw / unscoped query check FAILED ('.count($violations).' finding(s)):'.PHP_EOL.PHP_EOL);
    foreach ($violations as $v) {
        fwrite(STDERR, "  $v".PHP_EOL.PHP_EOL);
    }
    fwrite(STDERR, 'Mark a deliberate exception on the same line with  // allowlisted: <reason>  and have it reviewed.'.PHP_EOL);
    exit(1);
}

echo 'Raw / unscoped query check passed.'.PHP_EOL;
