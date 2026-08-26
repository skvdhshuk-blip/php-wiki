# PHP Wiki

PHP Wiki 是一套开箱即用的个人视觉智能知识库：Laravel + Livewire 提供中文工作台，Octane + FrankenPHP 提供常驻运行时，Hao Code 负责多 Agent 编排，`deepseek-v4-flash-vision-exp` 统一负责文字和图片/PDF理解。

它遵循 Karpathy 的 [LLM Wiki](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f) 思路：原始资料保持不变，Agent 持续维护可读的 Markdown Wiki，用户审阅每一个变更。Wiki 本身是事实权威；SQLite FTS5 只是可重建的检索缓存，不使用向量数据库代替知识结构。

## 5 分钟启动

要求：Docker Desktop 或兼容的 Docker Compose。

```bash
cp .env.example .env
mkdir -p wiki-data/raw
cp examples/wiki-data/raw/getting-started.md wiki-data/raw/
```

在 `.env` 中设置：

```dotenv
PHP_WIKI_ALLOW_REMOTE_MODEL=true
PHP_WIKI_API_KEY=your-key-here
```

这表示你明确同意把被处理的文字、PDF 页面和图片发送到 DeepSeek。不要把 `.env` 提交到 Git。

然后启动：

```bash
make up
open http://localhost:8000
```

首次访问创建唯一管理员；之后注册会被关闭。把资料放入 `wiki-data/raw/`，进入“本地来源”扫描并摄取。Agent 只生成提案，只有你在“变更提案”批准后才会写入 Wiki 并创建 Git commit。

Docker Compose 固定包含三个服务：

- `app`：FrankenPHP 1.12.7 + Laravel Octane
- `queue`：单并发 Agent 队列，避免个人工作区并行写入
- `scheduler`：每小时扫描、每天执行确定性 Lint
- `reverb`：只推送 Agent 事件序号，正文仍由 Livewire 从 SQLite 读取

## 支持的资料

- Markdown、TXT、HTML
- PDF：逐页提取文字并以 144 DPI 渲染视觉页面
- PNG、JPEG、GIF、WebP
- Markdown/HTML 中指向 `raw/` 内的本地图片

远程图片和网页不会下载。GIF 提取最多四个代表帧；图片会生成不超过配置阈值的 JPEG 缓存，原文件永不修改。

## Agent 工作流

```text
来源扫描与规范化
  → VisionAnalystAgent（图片直接作为初始消息输入）
  → SourceAnalystAgent
  → MapKnowledge + AuditKnowledge 专家
  → Wiki Orchestrator
  → ProposeWikiPage
  → 确定性引用/Schema/哈希验证
  → 用户审批
  → 原子写入 + Git commit
```

所有 Agent 默认使用 `deepseek-v4-flash-vision-exp`。视觉任务失败时直接阻断；只有不含图片的问答和语义 Lint 能回退一次 `deepseek-v4-flash`，界面会显示 `fallback_used`。

模型接入使用 DeepSeek 的 [Anthropic 兼容入口说明](https://api-docs.deepseek.com/news/news260821/) 和[视觉输入指南](https://api-docs.deepseek.com/guides/vision/)。图片由 Hao Code 作为消息图片块发送，不会先转成文字 ToolResult 冒充视觉输入。

Agent 只能使用四类作用域工具：搜索 Wiki、读取 Wiki 页面、读取文字来源摘录、记录页面提案。没有 Bash、通用文件写入、网页抓取或直接修改 `raw/` 的能力。

## Workspace

Docker 默认把 `./wiki-data` 挂载到容器 `/wiki`。也可以设置绝对路径：

```dotenv
PHP_WIKI_HOST_ROOT=/Users/you/Documents/my-wiki
```

首次初始化后结构如下：

```text
raw/                    # 用户所有，应用只读
wiki/
  index.md              # 内容索引
  log.md                # 只追加审批日志
  sources/
  concepts/
  entities/
  syntheses/
  questions/
  archive/
AGENTS.md               # Wiki Schema 与 Agent 契约
.git/
```

引用绑定来源路径和 SHA-256，例如：

```text
[[source:raw/note.md|sha256:<64 hex>|lines:10-20]]
[[source:raw/book.pdf|sha256:<64 hex>|page:12]]
[[source:raw/chart.png|sha256:<64 hex>|region:左上角图表]]
```

来源或页面在审批前发生变化会让提案进入 `stale`，不会覆盖用户改动。

## 命令

```bash
make up
make down
make logs
make test
make doctor
make doctor-live

php artisan php-wiki:init
php artisan php-wiki:scan
php artisan php-wiki:ingest raw/example.pdf
php artisan php-wiki:ingest --all
php artisan php-wiki:lint
php artisan php-wiki:lint --semantic
php artisan php-wiki:doctor --live
php artisan php-wiki:rebuild-search
```

`doctor --live` 会发送一张临时生成的测试图片，要求视觉模型调用无副作用工具，并验证正常终止和非空最终文本。

## 原生 macOS 开发

需要 PHP 8.4、Composer 2.8、Node 24、Git、Poppler、FFmpeg 和 GD：

```bash
cp .env.example .env
# 把 PHP_WIKI_ROOT 改成本机绝对目录
composer install
npm install
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan php-wiki:init
npm run build
php artisan serve
php artisan queue:work --tries=1 --timeout=1200
php artisan schedule:work
php artisan reverb:start --host=127.0.0.1 --port=8080
```

## 固定版本

- Laravel Framework 13.27.0
- Livewire 4.4.2 / Flux 2.x
- Laravel Octane 2.19.1
- Hao Code 1.21.0
- PHP 8.4 / Node 24.14.1
- FrankenPHP 1.12.7

Hao Code 通过公开 Git repository 锁定 `v1.21.0`，避免包索引延迟。详细边界见 [架构说明](docs/ARCHITECTURE.md) 和[隐私说明](docs/PRIVACY.md)。
