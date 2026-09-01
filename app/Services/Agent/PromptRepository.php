<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * 模型提示词的唯一来源。
 *
 * 提示词写死在代码里时，改一句话就是一次代码改动，两次运行之间也无从
 * 判断「行为变了是因为改了提示词还是改了逻辑」。外置之后，version()
 * 给出当前这套提示词的指纹，验收报告带上它，两次结果才可比。
 */
class PromptRepository
{
    private const EXTENSION = '.md';

    /** @var array<string, string> */
    private array $cache = [];

    private ?string $version = null;

    public function __construct(private readonly string $directory) {}

    /**
     * 取一段提示词，并把 :name 形式的占位符替换成给定值。
     *
     * @param  array<string, string>  $replacements
     */
    public function render(string $name, array $replacements = []): string
    {
        $prompt = $this->get($name);
        if ($replacements === []) {
            return $prompt;
        }

        $keys = array_map(static fn (string $key): string => ':'.$key, array_keys($replacements));

        return str_replace($keys, array_values($replacements), $prompt);
    }

    public function get(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $path = $this->path($name);
        if (! is_file($path)) {
            throw new RuntimeException("提示词不存在：{$name}");
        }

        return $this->cache[$name] = trim((string) File::get($path));
    }

    /**
     * 当前提示词集合的指纹，用于把一次运行结果关联到具体的提示词版本。
     */
    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $files = glob(rtrim($this->directory, '/').'/*'.self::EXTENSION) ?: [];
        sort($files);

        $digest = '';
        foreach ($files as $file) {
            $digest .= basename($file).':'.hash_file('sha256', $file)."\n";
        }

        return $this->version = substr(hash('sha256', $digest), 0, 12);
    }

    private function path(string $name): string
    {
        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            throw new RuntimeException("非法提示词名称：{$name}");
        }

        return rtrim($this->directory, '/').'/'.$name.self::EXTENSION;
    }
}
