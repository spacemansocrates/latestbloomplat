<?php
// integrations/s3_client.php
// Minimal S3-compatible client (AWS Signature V4) for recording storage.
// Works with Cloudflare R2, Backblaze B2, AWS S3, Wasabi — anything S3-compatible.
// No composer dependency: signing is ~60 lines of hashing.
//
// .env keys: S3_ENDPOINT (e.g. https://<account>.r2.cloudflarestorage.com),
//            S3_REGION (R2: auto), S3_BUCKET, S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY

declare(strict_types=1);

if (!function_exists('load_env')) {
    $envLoader = __DIR__ . '/../utils/env_loader.php';
    if (is_file($envLoader)) require_once $envLoader;
}
if (function_exists('load_env')) load_env(__DIR__ . '/../.env');

function s3_config(): ?array {
    $cfg = [
        'endpoint' => rtrim((string)getenv('S3_ENDPOINT'), '/'),
        'region'   => (string)(getenv('S3_REGION') ?: 'auto'),
        'bucket'   => (string)getenv('S3_BUCKET'),
        'key'      => (string)getenv('S3_ACCESS_KEY_ID'),
        'secret'   => (string)getenv('S3_SECRET_ACCESS_KEY'),
    ];
    if ($cfg['endpoint'] === '' || $cfg['bucket'] === '' || $cfg['key'] === '' || $cfg['secret'] === '') {
        return null;
    }
    return $cfg;
}

function s3_configured(): bool {
    return s3_config() !== null;
}

// --- Signature V4 internals ---

function s3_uri_encode(string $key): string {
    return implode('/', array_map('rawurlencode', explode('/', $key)));
}

function s3_signing_key(string $secret, string $date, string $region): string {
    $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

function s3_sign(array $cfg, string $method, string $canonicalUri, string $canonicalQuery,
                 array $headers, string $payloadHash, string $amzDate): string {
    ksort($headers);
    $canonicalHeaders = '';
    $signedHeaders = [];
    foreach ($headers as $k => $v) {
        $lk = strtolower($k);
        $canonicalHeaders .= $lk . ':' . trim((string)$v) . "\n";
        $signedHeaders[] = $lk;
    }
    $signedHeadersStr = implode(';', $signedHeaders);

    $canonicalRequest = implode("\n", [
        $method, $canonicalUri, $canonicalQuery, $canonicalHeaders, $signedHeadersStr, $payloadHash,
    ]);

    $date  = substr($amzDate, 0, 8);
    $scope = "$date/{$cfg['region']}/s3/aws4_request";
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest),
    ]);

    return hash_hmac('sha256', $stringToSign, s3_signing_key($cfg['secret'], $date, $cfg['region']));
}

// --- Public API ---

/**
 * Upload a local file. Streams from disk (no full read into memory).
 */
function s3_put_object(string $key, string $filePath, string $contentType = 'application/octet-stream'): bool {
    $cfg = s3_config();
    if (!$cfg || !is_file($filePath)) return false;

    $amzDate = gmdate('Ymd\THis\Z');
    $host    = parse_url($cfg['endpoint'], PHP_URL_HOST);
    $uri     = '/' . $cfg['bucket'] . '/' . s3_uri_encode($key);
    $payloadHash = hash_file('sha256', $filePath);

    $headers = [
        'content-type'         => $contentType,
        'host'                 => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date'           => $amzDate,
    ];
    $sig = s3_sign($cfg, 'PUT', $uri, '', $headers, $payloadHash, $amzDate);
    $auth = sprintf(
        'AWS4-HMAC-SHA256 Credential=%s/%s/%s/s3/aws4_request, SignedHeaders=%s, Signature=%s',
        $cfg['key'], substr($amzDate, 0, 8), $cfg['region'],
        implode(';', array_keys($headers)), $sig
    );

    $fh = fopen($filePath, 'rb');
    $ch = curl_init($cfg['endpoint'] . $uri);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => filesize($filePath),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 280,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $auth,
            'Content-Type: ' . $contentType,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
        ],
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($code < 200 || $code >= 300) {
        error_log("s3_put_object failed: HTTP $code $err " . substr((string)$res, 0, 300));
        return false;
    }
    return true;
}

/**
 * Presigned GET URL (time-limited download/playback link).
 */
function s3_presign_get(string $key, int $expires = 3600, array $extraQuery = []): ?string {
    $cfg = s3_config();
    if (!$cfg) return null;

    $amzDate = gmdate('Ymd\THis\Z');
    $date    = substr($amzDate, 0, 8);
    $host    = parse_url($cfg['endpoint'], PHP_URL_HOST);
    $uri     = '/' . $cfg['bucket'] . '/' . s3_uri_encode($key);
    $scope   = "$date/{$cfg['region']}/s3/aws4_request";

    $query = $extraQuery + [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => $cfg['key'] . '/' . $scope,
        'X-Amz-Date'          => $amzDate,
        'X-Amz-Expires'       => (string)$expires,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($query);
    $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $sig = s3_sign($cfg, 'GET', $uri, $canonicalQuery, ['host' => $host], 'UNSIGNED-PAYLOAD', $amzDate);
    return $cfg['endpoint'] . $uri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $sig;
}

/** Signed HEAD request: does the object exist? */
function s3_head_object(string $key): bool {
    $cfg = s3_config();
    if (!$cfg) return false;

    $amzDate = gmdate('Ymd\THis\Z');
    $host    = parse_url($cfg['endpoint'], PHP_URL_HOST);
    $uri     = '/' . $cfg['bucket'] . '/' . s3_uri_encode($key);
    $payloadHash = hash('sha256', '');

    $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
    $sig = s3_sign($cfg, 'HEAD', $uri, '', $headers, $payloadHash, $amzDate);
    $auth = sprintf(
        'AWS4-HMAC-SHA256 Credential=%s/%s/%s/s3/aws4_request, SignedHeaders=%s, Signature=%s',
        $cfg['key'], substr($amzDate, 0, 8), $cfg['region'], implode(';', array_keys($headers)), $sig
    );

    $ch = curl_init($cfg['endpoint'] . $uri);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $auth,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

function s3_delete_object(string $key): bool {
    $cfg = s3_config();
    if (!$cfg) return false;

    $amzDate = gmdate('Ymd\THis\Z');
    $host    = parse_url($cfg['endpoint'], PHP_URL_HOST);
    $uri     = '/' . $cfg['bucket'] . '/' . s3_uri_encode($key);
    $payloadHash = hash('sha256', '');

    $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
    $sig = s3_sign($cfg, 'DELETE', $uri, '', $headers, $payloadHash, $amzDate);
    $auth = sprintf(
        'AWS4-HMAC-SHA256 Credential=%s/%s/%s/s3/aws4_request, SignedHeaders=%s, Signature=%s',
        $cfg['key'], substr($amzDate, 0, 8), $cfg['region'], implode(';', array_keys($headers)), $sig
    );

    $ch = curl_init($cfg['endpoint'] . $uri);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $auth,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
