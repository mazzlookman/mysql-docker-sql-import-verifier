<?php
// import_sql_to_docker.php
// Jalankan: php import_sql_to_docker.php

date_default_timezone_set('UTC');

$container = 'mysql8-api';
$user = 'liquid';
$pass = 'liqu304';
$resetDatabaseBeforeImport = true;

// Mapping file => database
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

function importFileToDb($container, $user, $pass, $db, $file)
{
    if (!is_file($file)) {
        throw new RuntimeException("File tidak ditemukan: {$file}");
    }

    $totalSize = filesize($file);
    if ($totalSize === false) {
        throw new RuntimeException("Gagal baca ukuran file: {$file}");
    }

    $cmd = sprintf(
        'docker exec -i %s mysql -u%s -p%s %s',
        escapeshellarg($container),
        escapeshellarg($user),
        escapeshellarg($pass),
        escapeshellarg($db)
    );

    $descriptors = [
        // Descriptor mode dari sisi process child:
        // stdin child harus "r" agar parent bisa menulis ke $pipes[0].
        0 => ['pipe', 'r'], // stdin process
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("Gagal menjalankan command docker.");
    }

    $fh = fopen($file, 'rb');
    if ($fh === false) {
        proc_close($process);
        throw new RuntimeException("Gagal buka file: {$file}");
    }

    fwrite($pipes[0], "SET FOREIGN_KEY_CHECKS=0;\n");

    // Stream per chunk agar hemat memory
    $written = 0;
    $lastPercent = -1;
    while (!feof($fh)) {
        $chunk = fread($fh, 1024 * 1024); // 1MB
        if ($chunk === false) {
            fclose($fh);
            fclose($pipes[0]);
            proc_close($process);
            throw new RuntimeException("Gagal baca file: {$file}");
        }

        if ($chunk === '') {
            continue;
        }

        fwrite($pipes[0], $chunk);
        $written += strlen($chunk);

        if ($totalSize > 0) {
            $percent = (int) floor(($written / $totalSize) * 100);
            if ($percent > 100) {
                $percent = 100;
            }
            if ($percent !== $lastPercent) {
                echo "\rProgress {$file}: {$percent}%";
                $lastPercent = $percent;
            }
        }
    }

    fclose($fh);
    fwrite($pipes[0], "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($pipes[0]);
    echo "\rProgress {$file}: 100%\n";

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Import gagal {$file} -> {$db}\n{$stderr}\n{$stdout}");
    }
}

function resetDatabase($container, $user, $pass, $db)
{
    $cmd = sprintf(
        'docker exec -i %s mysql -u%s -p%s -e %s',
        escapeshellarg($container),
        escapeshellarg($user),
        escapeshellarg($pass),
        escapeshellarg("DROP DATABASE IF EXISTS `{$db}`; CREATE DATABASE `{$db}`;")
    );

    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Gagal reset database {$db}\n" . implode("\n", $output)
        );
    }
}

try {
    $only = parseOnlyOption($argv);
    $selectedImports = filterImportsByOnly($imports, $only);

    if ($only !== '') {
        echo "Mode --only aktif: {$only}\n";
    }

    foreach ($selectedImports as $file => $db) {
        echo "Importing {$file} -> {$db} ...\n";
        if ($resetDatabaseBeforeImport) {
            echo "Reset database {$db} ...\n";
            resetDatabase($container, $user, $pass, $db);
        }
        importFileToDb($container, $user, $pass, $db, $file);
        echo "OK: {$file}\n";
    }
    echo "Semua import selesai.\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}