<?php

namespace Tests\Feature;

use App\Models\WikiSource;
use App\Services\Source\SourceNormalizer;
use App\Services\Source\SourceScanner;
use App\Services\Wiki\WikiWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class SourceNormalizerVisualTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        config(['phpwiki.visual.image_max_bytes' => 120_000]);
        app(WikiWorkspace::class)->initialize();
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_markdown_local_image_is_prepared_and_remote_image_is_isolated(): void
    {
        mkdir($this->wikiRoot.'/raw/docs', 0755, true);
        mkdir($this->wikiRoot.'/raw/images', 0755, true);
        $this->makePng($this->wikiRoot.'/raw/images/chart.png', 2200, 1200);
        file_put_contents(
            $this->wikiRoot.'/raw/docs/note.md',
            "# Note\n![local](../images/chart.png)\n![remote](https://example.com/tracker.png)\n",
        );
        app(SourceScanner::class)->scan();

        $source = WikiSource::query()->where('path', 'raw/docs/note.md')->firstOrFail();
        $normalized = app(SourceNormalizer::class)->normalize($source);

        $this->assertCount(1, $normalized->images);
        $this->assertFileExists($normalized->images[0]);
        $this->assertLessThanOrEqual(120_000, filesize($normalized->images[0]));
        $this->assertStringContainsString('已忽略远程', implode(' ', $normalized->warnings));
    }

    public function test_pdf_produces_page_text_image_and_page_artifact(): void
    {
        $pdf = $this->wikiRoot.'/raw/sample.pdf';
        $this->makePdf($pdf, 'Hello PHP Wiki');
        app(SourceScanner::class)->scan();
        $source = WikiSource::query()->where('path', 'raw/sample.pdf')->firstOrFail();

        $normalized = app(SourceNormalizer::class)->normalize($source);

        $this->assertStringContainsString('PDF Page 1', $normalized->text);
        $this->assertStringContainsString('Hello PHP Wiki', $normalized->text);
        $this->assertCount(1, $normalized->images);
        $this->assertSame(1, $source->artifacts()->sole()->page);
    }

    public function test_jpeg_gif_and_webp_are_normalized_to_safe_jpeg_cache(): void
    {
        foreach (['jpg', 'gif', 'webp'] as $extension) {
            $path = $this->wikiRoot.'/raw/format.'.$extension;
            $this->makeImage($path, $extension);
        }
        app(SourceScanner::class)->scan();

        foreach (['jpg', 'gif', 'webp'] as $extension) {
            $source = WikiSource::query()->where('path', 'raw/format.'.$extension)->firstOrFail();
            $normalized = app(SourceNormalizer::class)->normalize($source);

            $this->assertNotEmpty($normalized->images);
            foreach ($normalized->images as $image) {
                $this->assertSame(IMAGETYPE_JPEG, getimagesize($image)[2] ?? null);
                $this->assertLessThanOrEqual(120_000, filesize($image));
            }
        }
    }

    public function test_html_only_reads_local_raw_images_and_reports_remote_images(): void
    {
        mkdir($this->wikiRoot.'/raw/html', 0755, true);
        $this->makeImage($this->wikiRoot.'/raw/html/local.jpg', 'jpg');
        file_put_contents(
            $this->wikiRoot.'/raw/html/page.html',
            '<h1>图表</h1><img src="local.jpg"><img src="https://example.com/remote.png">',
        );
        app(SourceScanner::class)->scan();

        $source = WikiSource::query()->where('path', 'raw/html/page.html')->firstOrFail();
        $normalized = app(SourceNormalizer::class)->normalize($source);

        $this->assertCount(1, $normalized->images);
        $this->assertStringContainsString('图表', $normalized->text);
        $this->assertStringContainsString('已忽略远程', implode(' ', $normalized->warnings));
    }

    public function test_corrupt_image_fails_closed(): void
    {
        file_put_contents($this->wikiRoot.'/raw/broken.png', 'not-an-image');
        app(SourceScanner::class)->scan();
        $source = WikiSource::query()->where('path', 'raw/broken.png')->firstOrFail();

        $this->expectException(\RuntimeException::class);
        app(SourceNormalizer::class)->normalize($source);
    }

    public function test_multi_page_pdf_preserves_page_text_images_and_locators(): void
    {
        $pdf = $this->wikiRoot.'/raw/multi.pdf';
        $this->makeMultiPagePdf($pdf, ['First visual page', 'Second visual page']);
        app(SourceScanner::class)->scan();
        $source = WikiSource::query()->where('path', 'raw/multi.pdf')->firstOrFail();

        $normalized = app(SourceNormalizer::class)->normalize($source);

        $this->assertCount(2, $normalized->images);
        $this->assertStringContainsString('PDF Page 1', $normalized->text);
        $this->assertStringContainsString('PDF Page 2', $normalized->text);
        $this->assertSame([1, 2], $source->artifacts()->orderBy('page')->pluck('page')->all());
    }

    private function makePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 245, 245, 245);
        imagefill($image, 0, 0, $background);
        for ($i = 0; $i < 80; $i++) {
            $color = imagecolorallocate($image, ($i * 37) % 255, ($i * 73) % 255, ($i * 19) % 255);
            imageline($image, 0, $i * 17, $width, ($i * 41) % $height, $color);
        }
        imagepng($image, $path);
        imagedestroy($image);
    }

    private function makeImage(string $path, string $format): void
    {
        $image = imagecreatetruecolor(640, 360);
        $background = imagecolorallocate($image, 242, 230, 255);
        $ink = imagecolorallocate($image, 30, 30, 30);
        imagefill($image, 0, 0, $background);
        imageline($image, 20, 20, 620, 340, $ink);

        match ($format) {
            'jpg' => imagejpeg($image, $path, 90),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path, 90),
        };
        imagedestroy($image);
    }

    private function makePdf(string $path, string $text): void
    {
        $stream = "BT /F1 24 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        file_put_contents($path, $pdf);
    }

    /** @param list<string> $pages */
    private function makeMultiPagePdf(string $path, array $pages): void
    {
        $pageObjects = [];
        $contentObjects = [];
        $pageCount = count($pages);
        $fontObject = 3 + $pageCount;
        $firstContentObject = $fontObject + 1;

        foreach ($pages as $index => $text) {
            $contentObject = $firstContentObject + $index;
            $pageObjects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 '
                .$fontObject.' 0 R >> >> /Contents '.$contentObject.' 0 R >>';
            $stream = "BT /F1 24 Tf 72 720 Td ({$text}) Tj ET";
            $contentObjects[] = '<< /Length '.strlen($stream).">>\nstream\n{$stream}\nendstream";
        }

        $kids = [];
        for ($object = 3; $object < 3 + $pageCount; $object++) {
            $kids[] = $object.' 0 R';
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>',
            ...$pageObjects,
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            ...$contentObjects,
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        file_put_contents($path, $pdf);
    }
}
