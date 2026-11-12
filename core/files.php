<?php
/** Uploads: validated, stored outside web-guessable names, served via /file.php with access checks. */
const ALLOWED_UPLOADS = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/msword' => 'doc',
    'text/csv' => 'csv', 'text/plain' => 'txt', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
];
/** Save an uploaded file to uploads/<folder>/Y/m. Returns relative path or null if none. */
function save_upload(string $field, string $folder, array $allowMimes = []): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new ValidationError([$field => 'Upload failed (code ' . $f['error'] . ').']);
    if ($f['size'] > config('uploads.max_bytes')) throw new ValidationError([$field => 'File is larger than ' . round(config('uploads.max_bytes') / 1048576) . ' MB.']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed = $allowMimes ? array_intersect_key(ALLOWED_UPLOADS, array_flip($allowMimes)) : ALLOWED_UPLOADS;
    if (!isset($allowed[$mime])) throw new ValidationError([$field => 'File type not allowed.']);
    return store_file($f['tmp_name'], $folder, $allowed[$mime], $f['name'], true);
}
/** Base64 data-URL (camera capture from the meter reader page) */
function save_data_url(?string $dataUrl, string $folder): ?string {
    if (!$dataUrl || !preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#', $dataUrl, $m)) return null;
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > config('uploads.max_bytes')) throw new ValidationError(['photo' => 'Photo is too large.']);
    $tmp = tempnam(sys_get_temp_dir(), 'vk'); file_put_contents($tmp, $bin);
    if (!@getimagesize($tmp)) throw new ValidationError(['photo' => 'Invalid image.']);
    return store_file($tmp, $folder, $m[1] === 'jpeg' ? 'jpg' : $m[1], 'capture.' . $m[1], false);
}
function store_file(string $tmp, string $folder, string $ext, string $original, bool $uploaded): string {
    $rel = $folder . '/' . date('Y/m') . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
    $abs = rtrim(config('uploads.dir'), '/') . '/' . $rel;
    if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0775, true);
    $ok = $uploaded ? move_uploaded_file($tmp, $abs) : rename($tmp, $abs);
    if (!$ok && !copy($tmp, $abs)) throw new RuntimeException('Could not store file.');
    [$type, $id] = audit_actor();
    insert('sys_files', ['uuid' => uuid4(), 'disk_path' => $rel, 'original_name' => mb_substr($original, 0, 255), 'mime_type' => mime_content_type($abs) ?: 'application/octet-stream',
        'size_bytes' => filesize($abs), 'uploaded_by_type' => $type === 'api' ? 'system' : $type, 'uploaded_by_id' => $id]);
    return $rel;
}
function upload_abs(string $rel): string { return rtrim(config('uploads.dir'), '/') . '/' . ltrim(str_replace('..', '', $rel), '/'); }
