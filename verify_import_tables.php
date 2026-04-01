<?php
// verify_import_tables.php
// Jalankan: php verify_import_tables.php
// Opsional: php verify_import_tables.php --only=reg_0

date_default_timezone_set('UTC');

$container = 'mysql8-api';
$user = 'liquid';
$pass = 'liqu304';

$imports = [
    'reg_1_1.sql'       => 'reg_1',
    'reg_2_490_492.sql' => 'reg_2',
    'reg_3_856.sql'     => 'reg_3',
    'reg_0.sql'         => 'reg_0',
];

function parseOnlyOption($argv)
{
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = $argv[$i];
        if (strpos($arg, '--only=') === 0) {
            return trim(substr($arg, 7));
        }
        if ($arg === '--only' && isset($argv[$i + 1])) {
            return trim($argv[$i + 1]);
        }
    }
    return '';
}

function filterImportsByOnly($imports, $onlyValue)
{
    if ($onlyValue === '') {
        return $imports;
    }

    $filtered = [];
    foreach ($imports as $file => $db) {
        $fileNoExt = preg_replace('/\.sql$/i', '', $file);
        if (
            $onlyValue === $db ||
            $onlyValue === $file ||
            $onlyValue === $fileNoExt
        ) {
            $filtered[$file] = $db;
        }
    }

    if (empty($filtered)) {
        throw new RuntimeException(
            "Target --only '{$onlyValue}' tidak ditemukan. Gunakan nama DB (contoh: reg_0) atau nama file."
        );
    }

    return $filtered;
}

function getExpectedTablesFromSql($file)
{
    if (!is_file($file)) {
        throw new RuntimeException("File tidak ditemukan: {$file}");
    }

    $expected = [];
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Gagal buka file: {$file}");
    }

    while (($line = fgets($fh)) !== false) {
        if (
            preg_match(
                '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i',
                $line,
                $m
            )
        ) {
            $expected[$m[1]] = true;
        }
    }

    fclose($fh);

    return array_keys($expected);
}

function getActualTablesFromDb($container, $user, $pass, $db)
{
    $sql = sprintf(
        "SELECT table_name FROM information_schema.tables WHERE table_schema='%s' ORDER BY table_name;",
        addslashes($db)
    );

    $cmd = sprintf(
        'docker exec -i %s mysql -N -B -u%s -p%s -e %s',
        escapeshellarg($container),
        escapeshellarg($user),
        escapeshellarg($pass),
        escapeshellarg($sql)
    );

    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException("Gagal baca tabel database {$db}\n" . implode("\n", $output));
    }

    $tables = [];
    foreach ($output as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'Using a password on the command line interface') !== false) {
            continue;
        }
        $tables[] = $line;
    }

    sort($tables);
    return $tables;
}

function diffTables($expected, $actual)
{
    $expectedSet = array_fill_keys($expected, true);
    $actualSet = array_fill_keys($actual, true);

    $missing = [];
    foreach ($expectedSet as $name => $v) {
        if (!isset($actualSet[$name])) {
            $missing[] = $name;
        }
    }

    $extra = [];
    foreach ($actualSet as $name => $v) {
        if (!isset($expectedSet[$name])) {
            $extra[] = $name;
        }
    }

    sort($missing);
    sort($extra);

    return [$missing, $extra];
}

try {
    $only = parseOnlyOption($argv);
    $selectedImports = filterImportsByOnly($imports, $only);
    $hasError = false;

    if ($only !== '') {
        echo "Mode --only aktif: {$only}\n";
    }

    foreach ($selectedImports as $file => $db) {
        echo "Verifying {$file} -> {$db} ...\n";
        $expected = getExpectedTablesFromSql($file);
        $actual = getActualTablesFromDb($container, $user, $pass, $db);
        list($missing, $extra) = diffTables($expected, $actual);

        echo "  Expected tables: " . count($expected) . "\n";
        echo "  Actual tables  : " . count($actual) . "\n";

        if (empty($missing) && empty($extra)) {
            echo "  Status         : OK (match)\n";
            continue;
        }

        $hasError = true;
        echo "  Status         : MISMATCH\n";
        if (!empty($missing)) {
            echo "  Missing (" . count($missing) . "): " . implode(', ', $missing) . "\n";
        }
        if (!empty($extra)) {
            echo "  Extra (" . count($extra) . "): " . implode(', ', $extra) . "\n";
        }
    }

    if ($hasError) {
        exit(1);
    }

    echo "Semua tabel sudah ter-create sesuai dump SQL.\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
