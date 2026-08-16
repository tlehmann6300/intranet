<?php
/**
 * SecureImageUpload - Secure Image Upload Utility
 * 
 * Provides secure image upload functionality with multiple validation layers:
 * - MIME type validation using finfo_file() (not fakeable $_FILES['type'])
 * - Image content validation using getimagesize()
 * - Secure random filename generation
 * - Optional WebP conversion to remove metadata/malicious code
 * 
 * Prevents PHP shell uploads disguised as images.
 */

class SecureImageUpload {
    
    /**
     * Allowed MIME types (whitelist)
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif'
    ];
    
    /**
     * Allowed file extensions (whitelist)
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif'
    ];
    
    /**
     * Allowed image types for getimagesize()
     */
    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_WEBP,
        IMAGETYPE_GIF
    ];
    
    /**
     * Maximum file size (5MB)
     */
    private const MAX_FILE_SIZE = 5242880;
    
    /**
     * Upload directory (relative to project root)
     */
    private const UPLOAD_DIR = '/assets/uploads/';
    
    /**
     * Validate and upload an image file securely
     * 
     * @param array $file The $_FILES array element
     * @param string $uploadDir Optional custom upload directory (absolute path)
     * @param bool $convertToWebP Whether to convert uploaded images to WebP
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public static function uploadImage($file, $uploadDir = null, $convertToWebP = false) {
        // Check if file was uploaded
        if (!isset($file) || !isset($file['error'])) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Keine Datei hochgeladen'
            ];
        }

        // UPLOAD_ERR_INI_SIZE / UPLOAD_ERR_FORM_SIZE must be caught before inspecting
        // $file['size'], because PHP reports size as 0 when the limit is exceeded.
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Datei ist zu groß. Maximum: ' . ($convertToWebP ? '20MB' : '5MB')
            ];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Upload-Fehler (Code: ' . (int)$file['error'] . ')'
            ];
        }
        
        // Validate file size (allow larger source files when converting to WebP, since compression reduces size significantly)
        $effectiveMaxSize = $convertToWebP ? (self::MAX_FILE_SIZE * 4) : self::MAX_FILE_SIZE;
        if ($file['size'] > $effectiveMaxSize) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Datei ist zu groß. Maximum: ' . ($convertToWebP ? '20MB' : '5MB')
            ];
        }
        
        // Validate file extension using strict whitelist (use basename() to prevent path traversal)
        $originalExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        if (!in_array($originalExtension, self::ALLOWED_EXTENSIONS)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Ungültige Dateiendung. Nur JPG, JPEG, PNG, WebP und GIF sind erlaubt.'
            ];
        }
        
        // Validate MIME type using finfo_file() - NOT $_FILES['type'] which can be faked
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Ungültiger Dateityp. Nur JPG, PNG, GIF und WebP sind erlaubt. Erkannt: ' . $mimeType
            ];
        }
        
        // Validate image content using getimagesize()
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Datei ist kein gültiges Bild'
            ];
        }
        
        // Check if image type is allowed
        if (!in_array($imageInfo[2], self::ALLOWED_IMAGE_TYPES)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Bildformat nicht erlaubt'
            ];
        }
        
        // Determine upload directory
        $customUploadDir = ($uploadDir !== null);
        if ($uploadDir === null) {
            $uploadDir = __DIR__ . '/../../' . self::UPLOAD_DIR;
        }
        
        // Ensure upload directory exists and is writable
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => 'Upload-Verzeichnis konnte nicht erstellt werden'
                ];
            }
        }
        
        if (!is_writable($uploadDir)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Upload-Verzeichnis ist nicht beschreibbar'
            ];
        }
        
        // Generate secure random filename
        // Use cryptographically secure random bytes for unpredictability
        $randomFilename = 'item_' . bin2hex(random_bytes(16));
        
        // If converting to WebP, always use .webp extension
        // Otherwise, determine extension from MIME type
        if ($convertToWebP) {
            $extension = 'webp';
        } else {
            $extension = self::getExtensionFromMimeType($mimeType);
        }
        
        $filename = $randomFilename . '.' . $extension;
        $uploadPath = $uploadDir . $filename;
        
        // Convert to WebP if requested
        if ($convertToWebP) {
            $result = self::convertToWebP($file['tmp_name'], $uploadPath, $imageInfo[2]);
            if (!$result['success']) {
                return $result;
            }
        } else {
            // Move uploaded file to destination
            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => 'Fehler beim Hochladen der Datei'
                ];
            }
        }
        
        // Set proper permissions
        chmod($uploadPath, 0644);
        
        // Return relative path for database storage
        if ($customUploadDir) {
            // For custom upload directories, calculate relative path from project root
            $projectRoot = realpath(__DIR__ . '/../../');
            $realUploadPath = realpath($uploadPath);
            if ($realUploadPath && $projectRoot && strpos($realUploadPath, $projectRoot) === 0) {
                $relativePath = substr($realUploadPath, strlen($projectRoot) + 1);
                // Normalize path separators for consistency
                $relativePath = str_replace('\\', '/', $relativePath);
            } else {
                // Fallback: try to extract relative path from uploadDir
                $realUploadDir = realpath(dirname($uploadPath));
                if ($realUploadDir && $projectRoot && strpos($realUploadDir, $projectRoot) === 0) {
                    $relativeDir = substr($realUploadDir, strlen($projectRoot) + 1);
                    $relativePath = str_replace('\\', '/', $relativeDir) . '/' . $filename;
                } else {
                    // Last resort: return just the filename (not ideal but better than failing)
                    error_log("SecureImageUpload: Could not determine relative path for uploaded file");
                    $relativePath = $filename;
                }
            }
        } else {
            // Use the UPLOAD_DIR constant to maintain single source of truth
            $relativePath = trim(self::UPLOAD_DIR, '/') . '/' . $filename;
        }
        
        return [
            'success' => true,
            'path' => $relativePath,
            'error' => null
        ];
    }
    
    /**
     * Convert image to WebP format
     * This helps remove potentially malicious metadata
     * 
     * @param string $sourcePath Source image path
     * @param string $destPath Destination WebP path
     * @param int $imageType Image type constant from getimagesize()
     * @return array ['success' => bool, 'error' => string|null]
     */
    private static function convertToWebP($sourcePath, $destPath, $imageType) {
        // Check if WebP support is available
        if (!function_exists('imagewebp')) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'WebP-Unterstützung ist nicht verfügbar'
            ];
        }
        
        // Load image based on type
        $image = null;
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $image = @imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $image = @imagecreatefrompng($sourcePath);
                // Preserve transparency for PNG
                if ($image) {
                    imagepalettetotruecolor($image);
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                }
                break;
            case IMAGETYPE_GIF:
                $image = @imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                $image = @imagecreatefromwebp($sourcePath);
                break;
        }
        
        if ($image === false) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Bild konnte nicht geladen werden'
            ];
        }
        
        // Convert to WebP with quality 85
        $result = imagewebp($image, $destPath, 85);
        imagedestroy($image);
        
        if (!$result) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Fehler bei der WebP-Konvertierung'
            ];
        }
        
        return [
            'success' => true,
            'error' => null
        ];
    }
    
    /**
     * Get file extension from MIME type
     * 
     * @param string $mimeType MIME type
     * @return string File extension
     */
    private static function getExtensionFromMimeType($mimeType) {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif'
        ];
        
        return $mimeMap[$mimeType] ?? 'jpg';
    }
    
    /**
     * Delete an uploaded image file
     * 
     * @param string $relativePath Relative path (e.g., 'assets/uploads/item_xxx.jpg')
     * @return bool Success status
     */
    public static function deleteImage($relativePath) {
        if (empty($relativePath)) {
            return false;
        }

        $projectRoot = realpath(__DIR__ . '/../../');
        if ($projectRoot === false) {
            return false;
        }

        $fullPath = $projectRoot . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);

        if (!file_exists($fullPath)) {
            return true; // File already missing – nothing to delete
        }

        $realPath = realpath($fullPath);

        if ($realPath === false) {
            return false;
        }

        // Prevent path traversal: file must reside inside an allowed upload directory
        $allowedDirs = [
            $projectRoot . DIRECTORY_SEPARATOR . 'uploads',
            $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads',
        ];

        $allowed = false;
        foreach ($allowedDirs as $dir) {
            if (strpos($realPath, $dir . DIRECTORY_SEPARATOR) === 0) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return false;
        }

        return unlink($realPath);
    }
    
    /**
     * Get the default upload directory path (absolute)
     * Useful for testing and external access
     * 
     * @return string Absolute path to upload directory
     */
    public static function getUploadDirectory() {
        return __DIR__ . '/../../' . self::UPLOAD_DIR;
    }
}
