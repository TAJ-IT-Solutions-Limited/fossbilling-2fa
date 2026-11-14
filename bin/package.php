<?php

declare(strict_types=1);

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
    if (($files['manifest.json'] ?? '') === '' || !str_contains($files['manifest.json'], '"icon_url": "icon.svg"')) {
        fwrite(STDERR, "manifest does not point at icon.svg\n");
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
}

function directory_zip_store(string $path, array $files): void
{
    ksort($files);
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    $dosDate = ((2026 - 1980) << 9) | (11 << 5) | 14;
    $dosTime = 12 << 11;
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $size = strlen($data);
        $nameLen = strlen($name);
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
            20,
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
            0,
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
