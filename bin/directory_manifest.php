<?php

declare(strict_types=1);

/**
 * Directory manifest for extensions.fossbilling.org.
 * Schema: FOSSBilling/extensions openapi/extensions-v2.json Manifest, commit f55035d.
 * additionalProperties is false. icon_url, download_url, and minimum_fossbilling_version are rejected.
 * The icon stays a file in the package. The FOSSBilling requirement is requires.fossbilling.
 */

function directory_manifest_from_repo(array $repo): array
{
    $type = (string) ($repo['type'] ?? '');
    if ($type === 'mod') {
        $type = 'module';
    }
    $minimum = (string) ($repo['minimum_fossbilling_version'] ?? '');
    if (preg_match('/^\d+\.\d+\.\d+$/', $minimum) !== 1) {
        fwrite(STDERR, "repo manifest has no x.y.z minimum_fossbilling_version\n");
        exit(1);
    }
    $authorName = $repo['author'] ?? '';
    if (is_array($authorName)) {
        $authorName = $authorName['name'] ?? '';
    }
    $author = ['name' => (string) $authorName];
    $authorUrl = (string) ($repo['author_url'] ?? '');
    if ($authorUrl !== '') {
        $author['url'] = $authorUrl;
    }
    $license = (string) ($repo['license'] ?? '');
    if (preg_match('/Apache/i', $license) === 1) {
        $license = 'Apache-2.0';
    }
    $manifest = [
        'manifest_version' => 1,
        'type' => $type,
        'id' => (string) ($repo['id'] ?? ''),
        'name' => (string) ($repo['name'] ?? ''),
        'version' => (string) ($repo['version'] ?? ''),
        'description' => (string) ($repo['description'] ?? ''),
        'author' => $author,
        'license' => $license,
        'requires' => [
            'fossbilling' => '>=' . $minimum,
            'php' => '>=8.2',
        ],
    ];
    $homepage = (string) ($repo['homepage_url'] ?? '');
    if ($homepage !== '') {
        $manifest['homepage_url'] = $homepage;
    }

    return $manifest;
}

function directory_manifest_encode(array $manifest): string
{
    directory_manifest_assert($manifest);

    return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

function directory_manifest_assert(array $manifest): void
{
    $allowed = ['manifest_version', 'type', 'id', 'name', 'version', 'description', 'author', 'license', 'homepage_url', 'requires'];
    foreach (array_keys($manifest) as $key) {
        if (!in_array($key, $allowed, true)) {
            fwrite(STDERR, "directory manifest has $key, which the schema rejects\n");
            exit(1);
        }
    }
    foreach (['manifest_version', 'type', 'id', 'name', 'version', 'description', 'author', 'license', 'requires'] as $key) {
        if (!array_key_exists($key, $manifest)) {
            fwrite(STDERR, "directory manifest is missing $key\n");
            exit(1);
        }
    }
    if ($manifest['manifest_version'] !== 1) {
        fwrite(STDERR, "manifest_version must be the number 1\n");
        exit(1);
    }
    $types = ['module', 'theme', 'payment-gateway', 'server-manager', 'domain-registrar', 'translation'];
    if (!in_array($manifest['type'], $types, true)) {
        fwrite(STDERR, "directory manifest type is not in the schema\n");
        exit(1);
    }
    directory_manifest_string($manifest['id'], 1, 64, '/^[a-z0-9_-]+$/', 'id');
    directory_manifest_string($manifest['name'], 1, 100, '/\S/', 'name');
    directory_manifest_string(
        $manifest['version'],
        1,
        100,
        '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/',
        'version'
    );
    directory_manifest_string($manifest['description'], 1, 500, '/\S/', 'description');
    if (!is_array($manifest['author']) || array_diff(array_keys($manifest['author']), ['name', 'url']) !== []) {
        fwrite(STDERR, "author must be an object with name and an optional url\n");
        exit(1);
    }
    directory_manifest_string($manifest['author']['name'] ?? '', 1, 200, '/\S/', 'author.name');
    if (isset($manifest['author']['url'])) {
        directory_manifest_uri((string) $manifest['author']['url'], 'author.url');
    }
    directory_manifest_string($manifest['license'], 1, 1000, '/^[A-Za-z0-9.\-+_:() |]+$/', 'license');
    if (isset($manifest['homepage_url'])) {
        directory_manifest_uri((string) $manifest['homepage_url'], 'homepage_url');
    }
    if (!is_array($manifest['requires']) || array_diff(array_keys($manifest['requires']), ['fossbilling', 'php', 'modules']) !== []) {
        fwrite(STDERR, "requires has a field the schema rejects\n");
        exit(1);
    }
    directory_manifest_string($manifest['requires']['fossbilling'] ?? '', 1, 1000, '/\S/', 'requires.fossbilling');
    if (isset($manifest['requires']['php'])) {
        directory_manifest_string($manifest['requires']['php'], 1, 1000, '/\S/', 'requires.php');
    }
}

function directory_manifest_string(mixed $value, int $min, int $max, string $pattern, string $label): void
{
    if (!is_string($value) || strlen($value) < $min || strlen($value) > $max || preg_match($pattern, $value) !== 1) {
        fwrite(STDERR, "directory manifest $label is invalid\n");
        exit(1);
    }
}

function directory_manifest_uri(string $value, string $label): void
{
    if (strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        fwrite(STDERR, "directory manifest $label is not an http(s) URL\n");
        exit(1);
    }
}

function directory_zip_root_manifest(string $zipPath): string
{
    $data = (string) file_get_contents($zipPath);
    if (substr($data, -22, 4) !== "PK\x05\x06") {
        fwrite(STDERR, "zip end record is missing\n");
        exit(1);
    }
    $count = unpack('v', substr($data, -12, 2))[1];
    $offset = unpack('V', substr($data, -6, 4))[1];
    $found = null;
    for ($i = 0; $i < $count; $i++) {
        if (substr($data, $offset, 4) !== "PK\x01\x02") {
            fwrite(STDERR, "zip central directory is damaged\n");
            exit(1);
        }
        $nameLen = unpack('v', substr($data, $offset + 28, 2))[1];
        $extraLen = unpack('v', substr($data, $offset + 30, 2))[1];
        $commentLen = unpack('v', substr($data, $offset + 32, 2))[1];
        $local = unpack('V', substr($data, $offset + 42, 4))[1];
        $name = substr($data, $offset + 46, $nameLen);
        if ($name === 'manifest.json') {
            $localNameLen = unpack('v', substr($data, $local + 26, 2))[1];
            $localExtraLen = unpack('v', substr($data, $local + 28, 2))[1];
            $size = unpack('V', substr($data, $local + 18, 4))[1];
            $found = substr($data, $local + 30 + $localNameLen + $localExtraLen, $size);
        }
        $offset += 46 + $nameLen + $extraLen + $commentLen;
    }
    if (!is_string($found)) {
        fwrite(STDERR, "zip has no manifest.json at its root\n");
        exit(1);
    }
    $decoded = json_decode($found, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "zip root manifest is not JSON\n");
        exit(1);
    }
    directory_manifest_assert($decoded);

    return $found;
}
