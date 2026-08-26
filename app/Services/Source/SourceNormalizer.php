<?php

namespace App\Services\Source;

use App\Entities\NormalizedSource;
use App\Models\WikiSource;
use App\Repositories\Source\SourceRepository;
use App\Services\Wiki\WikiPathGuard;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class SourceNormalizer
{
    public function __construct(
        private readonly WikiPathGuard $paths,
        private readonly VisualPreprocessor $visuals,
        private readonly SourceRepository $sources,
    ) {}

    public function normalize(WikiSource $source): NormalizedSource
    {
        $sourcePath = $this->paths->assertRawPath($source->path);
        $absolute = $this->paths->absolute($sourcePath);
        $cacheRelative = 'visual-cache/'.$source->sha256;
        $cache = storage_path('app/private/'.$cacheRelative);
        File::deleteDirectory($cache);
        File::ensureDirectoryExists($cache);
        $this->sources->resetArtifacts($source);

        return match ($source->type) {
            'markdown' => $this->normalizeMarkdown($source, $absolute, $cacheRelative, $cache),
            'text' => new NormalizedSource($this->readText($absolute)),
            'html' => $this->normalizeHtml($source, $absolute, $cacheRelative, $cache),
            'pdf' => $this->normalizePdf($source, $absolute, $cacheRelative, $cache),
            'image' => $this->normalizeImage($source, $absolute, $cacheRelative, $cache),
            default => throw new RuntimeException("未知来源类型：{$source->type}"),
        };
    }

    private function normalizeMarkdown(WikiSource $source, string $absolute, string $cacheRelative, string $cache): NormalizedSource
    {
        $text = $this->readText($absolute);
        preg_match_all('/!\[[^\]]*\]\(([^)]+)\)/', $text, $matches);

        return $this->withLinkedImages($source, $text, $matches[1], $cacheRelative, $cache);
    }

    private function normalizeHtml(WikiSource $source, string $absolute, string $cacheRelative, string $cache): NormalizedSource
    {
        $html = $this->readText($absolute);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $links = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $links[] = $image->getAttribute('src');
        }
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        return $this->withLinkedImages($source, $text, $links, $cacheRelative, $cache);
    }

    /** @param list<string> $links */
    private function withLinkedImages(
        WikiSource $source,
        string $text,
        array $links,
        string $cacheRelative,
        string $cache,
    ): NormalizedSource {
        $images = [];
        $warnings = [];
        $sequence = 1;
        foreach ($links as $link) {
            $link = trim(html_entity_decode($link), '<> ');
            if ($link === '' || preg_match('#^(https?:|data:)#i', $link)) {
                $warnings[] = "已忽略远程或内联图片：{$link}";

                continue;
            }

            $relative = $this->resolveLinkedPath($source->path, rawurldecode(explode('#', $link, 2)[0]));
            try {
                $relative = $this->paths->assertRawPath($relative);
                $outputName = sprintf('linked-%04d.jpg', $sequence);
                $output = $this->visuals->prepare($this->paths->absolute($relative), $cache.'/'.$outputName);
                $images[] = $output;
                $this->artifact($source, 'linked_image', $cacheRelative.'/'.$outputName, $sequence, null, ['source_path' => $relative]);
                $sequence++;
            } catch (\Throwable $exception) {
                $warnings[] = "本地图片 {$link} 无法处理：{$exception->getMessage()}";
            }
        }

        return new NormalizedSource($text, $images, $warnings);
    }

    private function normalizeImage(WikiSource $source, string $absolute, string $cacheRelative, string $cache): NormalizedSource
    {
        if (strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'gif') {
            $frames = $this->gifFrames($absolute, $cache);
            if ($frames !== []) {
                $images = [];
                foreach ($frames as $index => $frame) {
                    $name = sprintf('frame-%02d.jpg', $index + 1);
                    $output = $this->visuals->prepare($frame, $cache.'/'.$name);
                    $images[] = $output;
                    $this->artifact($source, 'gif_frame', $cacheRelative.'/'.$name, $index + 1);
                    @unlink($frame);
                }

                return new NormalizedSource('', $images);
            }
        }

        $output = $this->visuals->prepare($absolute, $cache.'/image-0001.jpg');
        $this->artifact($source, 'image', $cacheRelative.'/image-0001.jpg', 1);

        return new NormalizedSource('', [$output]);
    }

    private function normalizePdf(WikiSource $source, string $absolute, string $cacheRelative, string $cache): NormalizedSource
    {
        $info = $this->run(['pdfinfo', $absolute]);
        if (! preg_match('/^Pages:\s+(\d+)$/mi', $info, $match)) {
            throw new RuntimeException('无法读取 PDF 页数。');
        }

        $pages = (int) $match[1];
        $text = '';
        $images = [];
        $warnings = [];
        for ($page = 1; $page <= $pages; $page++) {
            try {
                $pageText = $this->run(['pdftotext', '-f', (string) $page, '-l', (string) $page, '-layout', $absolute, '-']);
                $text .= "\n\n## PDF Page {$page}\n\n".trim($pageText);

                $prefix = $cache.'/render-'.sprintf('%04d', $page);
                $this->run(['pdftoppm', '-f', (string) $page, '-l', (string) $page, '-singlefile', '-jpeg', '-r', '144', $absolute, $prefix], 120);
                $rendered = $prefix.'.jpg';
                $name = 'page-'.sprintf('%04d', $page).'.jpg';
                $output = $this->visuals->prepare($rendered, $cache.'/'.$name);
                @unlink($rendered);
                $images[] = $output;
                $this->artifact($source, 'pdf_page', $cacheRelative.'/'.$name, $page, $page);
            } catch (\Throwable $exception) {
                $warnings[] = "PDF 第 {$page} 页处理失败：{$exception->getMessage()}";
            }
        }

        return new NormalizedSource(trim($text), $images, $warnings);
    }

    /** @return list<string> */
    private function gifFrames(string $absolute, string $cache): array
    {
        $pattern = $cache.'/raw-frame-%02d.jpg';
        $process = new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $absolute,
            '-vf', 'fps=1', '-frames:v', '4', $pattern,
        ]);
        $process->setTimeout(60);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        $frames = glob($cache.'/raw-frame-*.jpg') ?: [];
        sort($frames);

        return $frames;
    }

    private function readText(string $absolute): string
    {
        $content = file_get_contents($absolute);
        if ($content === false) {
            throw new RuntimeException("无法读取来源：{$absolute}");
        }

        $converted = mb_convert_encoding($content, 'UTF-8', 'UTF-8,GB18030,GBK,BIG-5,ISO-8859-1');
        if ($converted === false) {
            throw new RuntimeException("无法把来源转换为 UTF-8：{$absolute}");
        }

        return $converted;
    }

    private function resolveLinkedPath(string $sourcePath, string $link): string
    {
        $segments = explode('/', dirname($sourcePath).'/'.str_replace('\\', '/', $link));
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($resolved) <= 1) {
                    throw new \InvalidArgumentException('本地图片路径逃逸出 raw/。');
                }
                array_pop($resolved);

                continue;
            }
            $resolved[] = $segment;
        }

        return implode('/', $resolved);
    }

    /** @param array<string, mixed>|null $metadata */
    private function artifact(
        WikiSource $source,
        string $kind,
        string $path,
        ?int $sequence = null,
        ?int $page = null,
        ?array $metadata = null,
    ): void {
        $this->sources->recordArtifact($source, $kind, $path, $sequence, $page, $metadata);
    }

    /** @param list<string> $command */
    private function run(array $command, int $timeout = 60): string
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process->getOutput();
    }
}
