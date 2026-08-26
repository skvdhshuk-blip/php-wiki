# PHP Wiki

PHP Wiki 是一套开箱即用的个人视觉智能知识库：Laravel + Livewire 提供中文工作台，Octane + FrankenPHP 提供常驻运行时，Hao Code 负责多 Agent 编排，`deepseek-v4-flash-vision-exp` 统一负责文字和图片/PDF理解。

它遵循 Karpathy 的 [LLM Wiki](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f) 思路：原始资料保持不变，Agent 持续把它们编译成可读的 Markdown Wiki，用户审阅每一个变更。Source Catalog 是原始证据权威，获批 Wiki 是知识组织权威；SQLite FTS5 只是可重建的检索缓存，不使用向量数据库代替知识结构。

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

首次访问创建唯一管理员；之后注册会被关闭。默认把资料放入 `wiki-data/raw/`，也可以通过 `PHP_WIKI_SOURCE_ROOTS` 接管工作区中已有的多个只读来源目录。进入“本地来源”扫描并摄取后，Agent 只生成提案，只有你在“变更提案”批准后才会写入 Wiki 并创建 Git commit。

Docker Compose 固定包含三个服务：

- `app`：FrankenPHP 1.12.7 + Laravel Octane
- `queue`：单并发 Agent 队列，避免个人工作区并行写入
- `scheduler`：每小时扫描、每天执行确定性 Lint
- `reverb`：只推送 Agent 事件序号，正文仍由 Livewire 从 SQLite 读取

## 支持的资料

- Markdown、TXT、HTML
- PDF：逐页提取文字并以 144 DPI 渲染视觉页面
- PNG、JPEG、GIF、WebP
- Markdown/HTML 中指向同一 Source Catalog 根目录内的本地图片

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

Agent 只能使用四类作用域工具：搜索 Wiki、读取 Wiki 页面、读取文字来源摘录、记录页面提案。没有 Bash、通用文件写入、网页抓取或直接修改 Source Catalog 原始资料的能力。

知识问答使用独立的证据优先链路：

```text
问题范围与 lookup/research 计划
  → 有预算的 Wiki 检索和读取
  → 运行内 EvidenceBundle
  → 无工具的结构化答案编排
  → 确定性 Evidence ID / 来源哈希 / locator 核验
  → 正式答案、澄清问题或证据不足
```

模型不能直接把 `[[source:...]]` 写进正式回答。应用只接受本次成功工具调用生成、运行内稳定且不复用的 Evidence ID，并统一渲染 `[^E1]` 引用和来源原文。每次知识工具完成后都会先更新证据与覆盖状态；冲突结论必须同时引用至少两条冲突证据，视觉题必须实际引用 `region:` 或 `page:` 证据。查询阶段最多执行 2 次搜索和 4 次读取；研究阶段最多执行 4 次搜索和 12 次读取。含未绑定指代的独立问题会被 QueryPlan 标记为需要澄清，不能落成确定性知识答案。

## Workspace

Docker 默认把 `./wiki-data` 挂载到容器 `/wiki`。也可以设置绝对路径：

```dotenv
PHP_WIKI_HOST_ROOT=/Users/you/Documents/my-wiki
PHP_WIKI_SOURCE_ROOTS=raw,GetNote导入,工作,AI,个人
```

首次初始化后结构如下：

```text
raw/                    # 默认来源根，用户所有、应用只读
GetNote导入/            # 可选既有来源根
工作/ AI/ 个人/         # 可选既有来源根
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

Source Catalog 使用相对工作区根目录的稳定路径，不复制原文件。引用绑定路径、SHA-256 和定位，例如：

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
php artisan php-wiki:adopt-legacy
php artisan php-wiki:ingest raw/example.pdf
php artisan php-wiki:ingest 'GetNote导入/example.md'
php artisan php-wiki:ingest --all
php artisan php-wiki:lint
php artisan php-wiki:lint --semantic
php artisan php-wiki:doctor --live
php artisan php-wiki:rebuild-search
php artisan php-wiki:benchmark-core-agent
php artisan php-wiki:benchmark-core-agent --live --limit=1
php artisan php-wiki:benchmark-core-agent --live --ids=lookup-01,conflict-01,unknown-01
php artisan php-wiki:benchmark-core-agent --live --workspace=configured --limit=1
```

`doctor --live` 会发送一张临时生成的测试图片，要求视觉模型调用无副作用工具，并验证正常终止和非空最终文本。

`adopt-legacy` 只创建可审阅提案，用于把既有 Obsidian Wiki 的 frontmatter、页面短链接和 `AGENTS.md` 执行契约收敛到应用规则；命令本身不写 Wiki，也不修改 Source Catalog。

`benchmark-core-agent` 会验证固定的 50 题中英文验收集及其仓库内知识夹具。加 `--live` 才会真实调用模型并把机器可读报告写入 `storage/app/core-agent-benchmarks/`；默认把夹具临时挂载为 Wiki，并在数据库事务中运行，结束后回滚来源、FTS、聊天和运行记录，不读取或污染 `PHP_WIKI_ROOT`。完整验收不传 `--limit`；smoke 可以显式限制题数，`--ids` 可按固定题号组成跨分类子集，且不能与 `--limit` 同时使用。报告只保存安全诊断、语义事件类型和精确来源定位，不保存提示词、答案正文、工具原始 payload 或证据原文。`--workspace=configured` 仅用于诊断当前知识库，不作为可重复验收证据。真实运行仍受 `PHP_WIKI_ALLOW_REMOTE_MODEL` 和环境 API Key 门禁保护。

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
- Hao Code 1.21.1
- PHP 8.4 / Node 24.14.1
- FrankenPHP 1.12.7

Hao Code 通过公开 Git repository 锁定 `v1.21.1`，避免包索引延迟。详细边界见 [架构说明](docs/ARCHITECTURE.md) 和[隐私说明](docs/PRIVACY.md)。
