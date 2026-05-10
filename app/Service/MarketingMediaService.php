<?php

namespace App\Service;

class MarketingMediaService
{
    private const MAX_VIDEO_BYTES = 100 * 1024 * 1024;
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public static function storeUpload(array $user, int $campaignId, array $file, string $assetType): array
    {
        $assetType = strtolower(trim($assetType));
        if (!in_array($assetType, ['video', 'thumbnail'], true)) {
            throw new \InvalidArgumentException('Tipo de arquivo de marketing inválido.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Falha no upload do arquivo.');
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Arquivo enviado é inválido.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new \InvalidArgumentException('Arquivo enviado está vazio.');
        }

        $detectedMime = self::detectMime($tmp);

        $allowed = $assetType === 'video'
            ? [
                'video/mp4' => 'mp4',
                'video/quicktime' => 'mov',
                'video/webm' => 'webm',
            ]
            : [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

        $maxSize = $assetType === 'video' ? self::MAX_VIDEO_BYTES : self::MAX_IMAGE_BYTES;

        if ($size > $maxSize) {
            throw new \InvalidArgumentException(
                $assetType === 'video'
                    ? 'O vídeo deve ter no máximo 100 MB.'
                    : 'A thumbnail deve ter no máximo 5 MB.'
            );
        }

        if (!isset($allowed[$detectedMime])) {
            throw new \InvalidArgumentException(
                $assetType === 'video'
                    ? 'Formato de vídeo inválido. Use MP4, MOV ou WEBM.'
                    : 'Formato de thumbnail inválido. Use JPG, PNG ou WEBP.'
            );
        }

        $extension = $allowed[$detectedMime];
        $tenancyId = (string)($user['tenancy_id'] ?? 'global');
        $storageBase = __DIR__ . '/../../resources/assets/upload/marketing/' . $tenancyId . '/' . $campaignId;
        if (!is_dir($storageBase) && !mkdir($storageBase, 0775, true) && !is_dir($storageBase)) {
            throw new \RuntimeException('Não foi possível preparar o diretório de upload.');
        }

        $prefix = $assetType === 'video' ? 'video_' : 'thumb_';
        $fileName = uniqid($prefix, true) . '.' . $extension;
        $absolutePath = $storageBase . '/' . $fileName;

        if (!move_uploaded_file($tmp, $absolutePath)) {
            throw new \RuntimeException('Não foi possível armazenar o arquivo enviado.');
        }

        $publicRelative = 'upload/marketing/' . $tenancyId . '/' . $campaignId . '/' . $fileName;
        $result = [
            'asset_type' => $assetType,
            'original_name' => (string)($file['name'] ?? $fileName),
            'storage_path' => $absolutePath,
            'public_path' => $publicRelative,
            'mime_type' => $detectedMime,
            'extension' => $extension,
            'size_bytes' => $size,
            'width' => null,
            'height' => null,
            'duration_seconds' => null,
            'generated_thumbnail_path' => null,
            'metadata_json' => null,
        ];

        if ($assetType === 'thumbnail') {
            $dimensions = @getimagesize($absolutePath);
            if (is_array($dimensions)) {
                $result['width'] = (int)($dimensions[0] ?? 0) ?: null;
                $result['height'] = (int)($dimensions[1] ?? 0) ?: null;
            }
        }

        if ($assetType === 'video') {
            $thumb = self::tryGenerateVideoThumbnail($absolutePath, $storageBase, $tenancyId, $campaignId);
            if ($thumb !== null) {
                $result['generated_thumbnail_path'] = $thumb;
            }
        }

        $result['metadata_json'] = json_encode([
            'original_name' => $result['original_name'],
            'mime_type' => $result['mime_type'],
            'size_bytes' => $result['size_bytes'],
            'generated_thumbnail_path' => $result['generated_thumbnail_path'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $result;
    }

    public static function publicUrl(string $relativePath): string
    {
        return rtrim(URL, '/') . '/resources/assets/' . ltrim($relativePath, '/');
    }

    private static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        $fallback = (string)mime_content_type($path);
        return $fallback !== '' ? $fallback : 'application/octet-stream';
    }

    private static function tryGenerateVideoThumbnail(string $videoPath, string $storageBase, string $tenancyId, int $campaignId): ?string
    {
        $ffmpeg = self::resolveBinary('ffmpeg');
        if ($ffmpeg === null) {
            return null;
        }

        $thumbName = uniqid('auto_thumb_', true) . '.jpg';
        $thumbAbsolute = $storageBase . '/' . $thumbName;

        $command = escapeshellarg($ffmpeg)
            . ' -y -i ' . escapeshellarg($videoPath)
            . ' -ss 00:00:01 -vframes 1 ' . escapeshellarg($thumbAbsolute) . ' 2>&1';

        @exec($command, $output, $status);
        if ($status !== 0 || !is_file($thumbAbsolute)) {
            return null;
        }

        return 'upload/marketing/' . $tenancyId . '/' . $campaignId . '/' . $thumbName;
    }

    private static function resolveBinary(string $name): ?string
    {
        $output = [];
        $status = 1;
        @exec('where ' . escapeshellarg($name) . ' 2>NUL', $output, $status);
        if ($status === 0 && !empty($output[0])) {
            return trim((string)$output[0]);
        }

        @exec('which ' . escapeshellarg($name) . ' 2>/dev/null', $output, $status);
        if ($status === 0 && !empty($output[0])) {
            return trim((string)$output[0]);
        }

        return null;
    }
}
