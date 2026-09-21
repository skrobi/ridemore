<?php
/**
 * /api/photos/thumbnail.php
 * 
 * Dynamic image thumbnail generator with disk cache
 * 
 * Usage:
 *   /api/photos/thumbnail.php?path=uploads/photos/2026/01/photo_xxx.jpg&size=400
 * 
 * Sizes: 200, 400, 800, 1200, 1600 (or original)
 */

require_once __DIR__ . '/../config.php';

// Validate inputs
$path = $_GET['path'] ?? null;
$size = isset($_GET['size']) ? intval($_GET['size']) : 800;

if (!$path) {
    http_response_code(400);
    die('Missing path parameter');
}

// Security: prevent directory traversal
if (strpos($path, '..') !== false || strpos($path, './') !== false) {
    http_response_code(403);
    die('Invalid path');
}

// Validate size
$allowedSizes = [200, 400, 800, 1200, 1600];
if (!in_array($size, $allowedSizes)) {
    http_response_code(400);
    die('Invalid size. Allowed: 200, 400, 800, 1200, 1600');
}

// Build paths
$originalPath = base_path($path);
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

// Check if original exists
if (!file_exists($originalPath)) {
    http_response_code(404);
    die('Original image not found');
}

// Build cache path: uploads/photos/2026/01/thumb_400_photo_xxx.jpg
$dirname = dirname($path);
$basename = basename($path);
$cachePath = base_path($dirname . '/thumb_' . $size . '_' . $basename);

// Check if cached thumbnail exists and is newer than original
if (file_exists($cachePath) && filemtime($cachePath) >= filemtime($originalPath)) {
    // Serve cached thumbnail
    serveCachedImage($cachePath, $extension);
    exit;
}

// Generate thumbnail
try {
    $thumbnail = generateThumbnail($originalPath, $size, $extension);
    
    // Save to cache
    $cacheDir = dirname($cachePath);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    file_put_contents($cachePath, $thumbnail);
    
    // Serve generated thumbnail
    serveImage($thumbnail, $extension);
    
} catch (Exception $e) {
    http_response_code(500);
    die('Failed to generate thumbnail: ' . $e->getMessage());
}

/**
 * Generate thumbnail using GD
 */
function generateThumbnail($sourcePath, $maxSize, $extension) {
    // Create image resource
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            $source = @imagecreatefromjpeg($sourcePath);
            break;
        case 'png':
            $source = @imagecreatefrompng($sourcePath);
            break;
        case 'webp':
            $source = @imagecreatefromwebp($sourcePath);
            break;
        default:
            throw new Exception('Unsupported image format: ' . $extension);
    }
    
    if (!$source) {
        throw new Exception('Failed to create image from source');
    }
    
    // ✅ Fix orientation from EXIF
    if (in_array($extension, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        if ($exif && isset($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $source = imagerotate($source, 180, 0);
                    break;
                case 6:
                    $source = imagerotate($source, -90, 0);
                    break;
                case 8:
                    $source = imagerotate($source, 90, 0);
                    break;
            }
        }
    }
    
    // Get dimensions (after rotation)
    $width = imagesx($source);
    $height = imagesy($source);
    
    // Calculate new dimensions (maintain aspect ratio)
    if ($width > $height) {
        $newWidth = $maxSize;
        $newHeight = intval(($height / $width) * $maxSize);
    } else {
        $newHeight = $maxSize;
        $newWidth = intval(($width / $height) * $maxSize);
    }
    
    // Don't upscale
    if ($newWidth > $width && $newHeight > $height) {
        $newWidth = $width;
        $newHeight = $height;
    }
    
    // Create thumbnail
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG
    if ($extension === 'png') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
        imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // Resize
    imagecopyresampled(
        $thumb, $source,
        0, 0, 0, 0,
        $newWidth, $newHeight,
        $width, $height
    );
    
    // Output to buffer
    ob_start();
    
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            imagejpeg($thumb, null, 85); // 85% quality
            break;
        case 'png':
            imagepng($thumb, null, 6); // Compression level 6
            break;
        case 'webp':
            imagewebp($thumb, null, 85);
            break;
    }
    
    $output = ob_get_clean();
    
    // Free memory
    imagedestroy($source);
    imagedestroy($thumb);
    
    return $output;
}

/**
 * Serve cached image file
 */
function serveCachedImage($path, $extension) {
    $mimeType = getMimeType($extension);
    
    // Cache headers (1 year)
    header('Content-Type: ' . $mimeType);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');
    header('Content-Length: ' . filesize($path));
    
    readfile($path);
}

/**
 * Serve generated image from buffer
 */
function serveImage($data, $extension) {
    $mimeType = getMimeType($extension);
    
    header('Content-Type: ' . $mimeType);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
    header('Content-Length: ' . strlen($data));
    
    echo $data;
}

/**
 * Get MIME type from extension
 */
function getMimeType($extension) {
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp'
    ];
    
    return $mimeTypes[$extension] ?? 'application/octet-stream';
}