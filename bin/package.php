<?php

declare(strict_types=1);

require __DIR__ . '/directory_manifest.php';

$root = dirname(__DIR__);
$manifestPath = $root . '/src/manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$version = (string) ($manifest['version'] ?? '');
if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fwrite(STDERR, "manifest version is not a release number\n");
    exit(1);
}

$src = $root . '/src';
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $name = substr($file->getPathname(), strlen($src) + 1);
    $name = str_replace('\\', '/', $name);
    if ($name === 'assets/icon.svg') {
        continue;
    }
    $files[$name] = directory_lf((string) file_get_contents($file->getPathname()));
}
foreach (['LICENSE' => $root . '/LICENSE', 'README.md' => $root . '/README.md'] as $name => $source) {
    $files[$name] = directory_lf((string) file_get_contents($source));
}
$files['manifest.json'] = directory_lf(directory_manifest_encode(directory_manifest_from_repo($manifest)));

$outDir = $root . '/dist';
if (!is_dir($outDir) && !mkdir($outDir) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create dist\n");
    exit(1);
}
$zipPath = $outDir . '/tajhost2fa-' . $version . '.zip';
if (is_file($zipPath)) {
    unlink($zipPath);
}
directory_zip_store($zipPath, $files);
directory_assert_package($zipPath, $files);
if (directory_zip_root_manifest($zipPath) !== $files['manifest.json']) {
    fwrite(STDERR, "zip root manifest does not match the directory schema copy\n");
    exit(1);
}

echo $zipPath . PHP_EOL;
echo 'sha256 ' . hash_file('sha256', $zipPath) . PHP_EOL;
foreach (array_keys($files) as $name) {
    echo $name . PHP_EOL;
}

function directory_lf(string $bytes): string
{
    $text = str_replace("\r\n", "\n", $bytes);
    $text = str_replace("\r", "\n", $text);
    if (str_contains($text, "\r")) {
        throw new RuntimeException('a carriage return survived normalisation');
    }

    return $text;
}

function directory_assert_package(string $zipPath, array $files): void
{
    $size = filesize($zipPath);
    if ($size === false || $size >= 5 * 1024 * 1024) {
        fwrite(STDERR, "package is missing or is 5 MiB or larger\n");
        exit(1);
    }
    $required = [
        'manifest.json',
        'Service.php',
        'icon.svg',
        'Api/Admin.php',
        'Api/Client.php',
        'Controller/Admin.php',
        'Controller/Client.php',
    ];
    foreach ($required as $name) {
        if (!isset($files[$name])) {
            fwrite(STDERR, "package is missing $name\n");
            exit(1);
        }
    }
    if (!str_contains($files['icon.svg'], '#B8902C')) {
        fwrite(STDERR, "icon is missing the TAJHOST gold\n");
        exit(1);
    }
    if (($files['manifest.json'] ?? '') === '' || !str_contains($files['manifest.json'], '"type": "module"')) {
        fwrite(STDERR, "manifest is not a directory module manifest\n");
        exit(1);
    }
    if (str_contains($files['manifest.json'], 'icon_url') || str_contains($files['manifest.json'], 'minimum_fossbilling_version')) {
        fwrite(STDERR, "manifest still has a field the directory schema rejects\n");
        exit(1);
    }
    $top = [];
    foreach ($files as $name => $data) {
        if (str_contains($name, '\\') || str_starts_with($name, 'src/') || str_contains($data, "\r")) {
            fwrite(STDERR, "bad entry $name\n");
            exit(1);
        }
        $top[explode('/', $name, 2)[0]] = true;
    }
    if (!isset($top['manifest.json'], $top['icon.svg']) || isset($top['src'])) {
        fwrite(STDERR, "package is wrapped or still uses src/\n");
        exit(1);
    }
    directory_check_zip($zipPath);
    directory_extract_check($zipPath, $files, 'zip');
}

function directory_zip_store(string $path, array $files): void
{
    ksort($files);
    $entries = [];
    $dirs = [];
    foreach (array_keys($files) as $name) {
        $parts = explode('/', $name);
        array_pop($parts);
        $prefix = '';
        foreach ($parts as $part) {
            $prefix .= $part . '/';
            $dirs[$prefix] = true;
        }
    }
    foreach (array_keys($dirs) as $dir) {
        $entries[$dir] = '';
    }
    foreach ($files as $name => $data) {
        $entries[$name] = $data;
    }
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    $dosDate = ((2026 - 1980) << 9) | (11 << 5) | 14;
    $dosTime = 12 << 11;
    $versionMade = (3 << 8) | 20;
    foreach ($entries as $name => $data) {
        $isDir = str_ends_with($name, '/');
        $crc = crc32($data);
        $size = strlen($data);
        $nameLen = strlen($name);
        $external = $isDir ? ((040755 << 16) | 0x10) : (0100644 << 16);
        $header = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0x0800,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLen,
            0
        );
        $local .= $header . $name . $data;
        $central .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            $versionMade,
            20,
            0x0800,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLen,
            0,
            0,
            0,
            0,
            $external,
            $offset
        ) . $name;
        $offset += strlen($header) + $nameLen + $size;
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
    if (file_put_contents($path, $local . $central . $end) === false) {
        fwrite(STDERR, "cannot write $path\n");
        exit(1);
    }
}

function directory_check_zip(string $path): void
{
    $data = (string) file_get_contents($path);
    if (substr($data, -22, 4) !== "PK\x05\x06") {
        fwrite(STDERR, "zip end record is missing\n");
        exit(1);
    }
    $count = unpack('v', substr($data, -12, 2))[1];
    $offset = unpack('V', substr($data, -6, 4))[1];
    for ($i = 0; $i < $count; $i++) {
        if (substr($data, $offset, 4) !== "PK\x01\x02") {
            fwrite(STDERR, "zip central directory is damaged\n");
            exit(1);
        }
        $nameLen = unpack('v', substr($data, $offset + 28, 2))[1];
        $extraLen = unpack('v', substr($data, $offset + 30, 2))[1];
        $commentLen = unpack('v', substr($data, $offset + 32, 2))[1];
        $external = unpack('V', substr($data, $offset + 38, 4))[1];
        $name = substr($data, $offset + 46, $nameLen);
        $isDir = str_ends_with($name, '/');
        $expect = $isDir ? ((040755 << 16) | 0x10) : (0100644 << 16);
        if ($external !== $expect) {
            fwrite(STDERR, "zip mode for $name is " . dechex($external) . "\n");
            exit(1);
        }
        $offset += 46 + $nameLen + $extraLen + $commentLen;
    }
}

function directory_extract_check(string $archive, array $files, string $kind): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        return;
    }
    $uid = trim((string) shell_exec('id -u'));
    if ($uid === '' || $uid === '0') {
        fwrite(STDERR, "extract check must run as a normal user\n");
        exit(1);
    }
    $tmp = sys_get_temp_dir() . '/pkg-' . bin2hex(random_bytes(4));
    if (!mkdir($tmp, 0700) && !is_dir($tmp)) {
        fwrite(STDERR, "cannot create extract dir\n");
        exit(1);
    }
    $command = $kind === 'tar'
        ? 'tar -xf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($tmp)
        : 'unzip -q ' . escapeshellarg($archive) . ' -d ' . escapeshellarg($tmp);
    exec($command, $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "extract failed\n");
        exit(1);
    }
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $rel = substr($file->getPathname(), strlen($tmp) + 1);
        $rel = str_replace('\\', '/', $rel);
        $mode = fileperms($file->getPathname()) & 0777;
        if ($file->isDir()) {
            if ($mode !== 0755) {
                fwrite(STDERR, "extracted directory $rel is " . decoct($mode) . "\n");
                exit(1);
            }
            continue;
        }
        if ($mode !== 0644) {
            fwrite(STDERR, "extracted file $rel is " . decoct($mode) . "\n");
            exit(1);
        }
        $body = file_get_contents($file->getPathname());
        if ($body === false) {
            fwrite(STDERR, "cannot read $rel\n");
            exit(1);
        }
        $found[$rel] = $body;
    }
    ksort($files);
    ksort($found);
    if (array_keys($found) !== array_keys($files)) {
        fwrite(STDERR, "extracted file list does not match\n");
        exit(1);
    }
    foreach ($files as $name => $data) {
        if ($found[$name] !== $data) {
            fwrite(STDERR, "extracted bytes differ for $name\n");
            exit(1);
        }
    }
}
