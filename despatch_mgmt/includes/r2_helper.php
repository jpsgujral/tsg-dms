<?php
/* ═══════════════════════════════════════════════════════════════
   Cloudflare R2 Helper — via Cloudflare Worker relay
   Worker URL: https://dms-r2-upload.jpsgujral.workers.dev
   Public URL: https://pub-5721570094064d529f1527519424c77b.r2.dev
   ═══════════════════════════════════════════════════════════════ */

define('R2_WORKER_URL', 'https://dms-r2-upload.jpsgujral.workers.dev');
define('R2_WORKER_TOKEN', 'dms_worker_s3cur3_t0k3n_2024');
define('R2_PUBLIC_URL',  'https://pub-5721570094064d529f1527519424c77b.r2.dev/dms_uploads');

/* ── Upload a file from tmp path to R2 via Worker ── */
function r2_upload(string $tmpPath, string $r2Key, string $mimeType = 'application/octet-stream'): bool {
    if (!file_exists($tmpPath)) return false;
    $body = file_get_contents($tmpPath);
    if ($body === false) return false;

    $ch = curl_init(R2_WORKER_URL . '/' . ltrim($r2Key, '/'));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'X-DMS-Token: ' . R2_WORKER_TOKEN,
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/* ── Delete a file from R2 via Worker ── */
function r2_delete(string $r2Key): bool {
    if (empty($r2Key)) return true;

    $ch = curl_init(R2_WORKER_URL . '/' . ltrim($r2Key, '/'));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'X-DMS-Token: ' . R2_WORKER_TOKEN,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return in_array($httpCode, [200, 204, 404]);
}

/* ── Get public URL for a stored file ── */
function r2_url(string $r2Key): string {
    if (empty($r2Key)) return '';
    return R2_PUBLIC_URL . '/' . ltrim($r2Key, '/');
}

/* ── Detect MIME type from extension ── */
function r2_mime(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match($ext) {
        'pdf'        => 'application/pdf',
        'jpg','jpeg' => 'image/jpeg',
        'png'        => 'image/png',
        'gif'        => 'image/gif',
        'webp'       => 'image/webp',
        default      => 'application/octet-stream',
    };
}

/* ── Handle a standard $_FILES upload to R2 ──
   Returns the r2Key on success, '' on failure.
   Pass $oldKey to delete the previous file automatically. ── */
function r2_handle_upload(string $fieldName, string $prefix, string $oldKey = ''): string {
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $allowed  = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'];
    $maxBytes = 10 * 1024 * 1024; // 10MB
    $ext      = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    $size     = $_FILES[$fieldName]['size'];

    if (!in_array($ext, $allowed) || $size > $maxBytes) return '';

    $newKey  = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $mime    = r2_mime($newKey);
    $tmpPath = $_FILES[$fieldName]['tmp_name'];

    if (r2_upload($tmpPath, $newKey, $mime)) {
        if (!empty($oldKey)) r2_delete($oldKey);
        return $newKey;
    }
    return '';
}
