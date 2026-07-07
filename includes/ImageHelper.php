<?php
declare(strict_types=1);

/**
 * Image Helper
 * 
 * Handles resizing and compression of images using PHP GD library.
 */
class ImageHelper {
    /**
     * Resizes and compresses an image in place or to a new destination.
     * 
     * @param string $sourcePath Path to the source image file
     * @param string $destPath Path to save the optimized image file
     * @param int $maxWidth Maximum width of the output image
     * @param int $maxHeight Maximum height of the output image
     * @param int $quality Compression quality (1-100)
     * @return bool True on success, false on failure
     */
    public static function optimizeImage(
        string $sourcePath, 
        string $destPath, 
        int $maxWidth = 400, 
        int $maxHeight = 533, 
        int $quality = 80
    ): bool {
        if (!file_exists($sourcePath)) {
            return false;
        }

        $imageInfo = @getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $mime = $imageInfo['mime'];

        // Determine scaling dimensions while maintaining aspect ratio
        $ratio = $width / $height;
        $targetRatio = $maxWidth / $maxHeight;

        $newWidth = $width;
        $newHeight = $height;

        if ($width > $maxWidth || $height > $maxHeight) {
            if ($ratio > $targetRatio) {
                $newWidth = $maxWidth;
                $newHeight = (int)round($maxWidth / $ratio);
            } else {
                $newHeight = $maxHeight;
                $newWidth = (int)round($maxHeight * $ratio);
            }
        }

        // Load source image resource based on mime type
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $srcImage = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $srcImage = @imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $srcImage = @imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $srcImage = @imagecreatefromwebp($sourcePath);
                } else {
                    return false;
                }
                break;
            default:
                return false;
        }

        if (!$srcImage) {
            return false;
        }

        // Create new destination canvas
        $dstImage = imagecreatetruecolor($newWidth, $newHeight);
        if (!$dstImage) {
            imagedestroy($srcImage);
            return false;
        }

        // Handle transparency for PNG, GIF, and WebP
        if ($mime === 'image/png' || $mime === 'image/gif' || $mime === 'image/webp') {
            imagealphablending($dstImage, false);
            imagesavealpha($dstImage, true);
            $transparent = imagecolorallocatealpha($dstImage, 255, 255, 255, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);
            }
        }

        // Perform the resize
        $resampleSuccess = imagecopyresampled(
            $dstImage, 
            $srcImage, 
            0, 0, 0, 0, 
            $newWidth, $newHeight, 
            $width, $height
        );

        if (!$resampleSuccess) {
            imagedestroy($srcImage);
            imagedestroy($dstImage);
            return false;
        }

        // Save image to destination path
        $destExt = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
        $success = false;

        switch ($destExt) {
            case 'png':
                // Convert 1-100 quality scale to 0-9 PNG compression scale
                $pngQuality = (int)floor((100 - $quality) / 10);
                $success = @imagepng($dstImage, $destPath, $pngQuality);
                break;
            case 'gif':
                $success = @imagegif($dstImage, $destPath);
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    $success = @imagewebp($dstImage, $destPath, $quality);
                } else {
                    $success = @imagejpeg($dstImage, $destPath, $quality);
                }
                break;
            case 'jpg':
            case 'jpeg':
            default:
                $success = @imagejpeg($dstImage, $destPath, $quality);
                break;
        }

        imagedestroy($srcImage);
        imagedestroy($dstImage);

        return $success;
    }
}
