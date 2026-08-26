<?php

namespace App\Services\Source;

use RuntimeException;

class VisualPreprocessor
{
    public function prepare(string $input, string $output): string
    {
        [$width, $height, $type] = getimagesize($input) ?: throw new RuntimeException("无法解码图片：{$input}");
        $source = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($input),
            IMAGETYPE_PNG => imagecreatefrompng($input),
            IMAGETYPE_GIF => imagecreatefromgif($input),
            IMAGETYPE_WEBP => imagecreatefromwebp($input),
            default => false,
        };
        if ($source === false) {
            throw new RuntimeException("不支持的图片格式：{$input}");
        }

        $maxEdge = max(320, (int) config('phpwiki.visual.image_max_edge'));
        $scale = min(1, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($target, 255, 255, 255);
        if ($white === false) {
            imagedestroy($source);
            imagedestroy($target);
            throw new RuntimeException('无法分配图片背景色。');
        }
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        $directory = dirname($output);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            imagedestroy($target);
            throw new RuntimeException("无法创建视觉缓存目录：{$directory}");
        }

        $maxBytes = max(65_536, (int) config('phpwiki.visual.image_max_bytes'));
        $quality = 88;
        for ($attempt = 0; $attempt < 16; $attempt++) {
            if (! imagejpeg($target, $output, $quality)) {
                imagedestroy($target);
                throw new RuntimeException("无法写入视觉缓存：{$output}");
            }
            clearstatcache(true, $output);
            if ((int) filesize($output) <= $maxBytes) {
                break;
            }

            if ($quality > 40) {
                $quality -= 8;

                continue;
            }

            $currentWidth = imagesx($target);
            $currentHeight = imagesy($target);
            if (max($currentWidth, $currentHeight) <= 480) {
                break;
            }
            $smaller = imagecreatetruecolor(
                max(1, (int) ($currentWidth * .8)),
                max(1, (int) ($currentHeight * .8)),
            );
            $smallerWhite = imagecolorallocate($smaller, 255, 255, 255);
            if ($smallerWhite === false) {
                imagedestroy($smaller);
                imagedestroy($target);
                throw new RuntimeException('无法分配压缩图片背景色。');
            }
            imagefill($smaller, 0, 0, $smallerWhite);
            imagecopyresampled(
                $smaller,
                $target,
                0,
                0,
                0,
                0,
                imagesx($smaller),
                imagesy($smaller),
                $currentWidth,
                $currentHeight,
            );
            imagedestroy($target);
            $target = $smaller;
            $quality = 72;
        }

        imagedestroy($target);
        if ((int) filesize($output) > $maxBytes) {
            throw new RuntimeException('图片压缩后仍超过视觉输入大小限制。');
        }

        return $output;
    }
}
