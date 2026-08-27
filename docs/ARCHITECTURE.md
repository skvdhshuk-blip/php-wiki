# PHP Wiki 架构

## 权威与边界

| 能力 | 唯一权威 |
|---|---|
| 原始资料 | `SourceCatalog` 配置允许的工作区只读目录 |
| 来源扫描与处理进度 | SQLite `wiki_sources`；`processed` 只表示已完成处理，不表示提案获批 |
| 已批准知识 | 外部工作区 `wiki/**/*.md` 与 `AGENTS.md` |
| 变更历史 | 工作区 Git + `wiki/log.md` |
| 提案审批 | SQLite `wiki_proposals` 状态 + 对应 Wiki Git commit |
| 运行状态 | SQLite `agent_runs` / `agent_events` |
| 搜索 | 可从 Markdown 重建的 SQLite FTS5 |
| 单次问答证据 | 从本次成功工具调用构建并写入 `agent_events` 的 `EvidenceBundle` |

Livewire 组件只接收输入、调用 Application Service、展示状态。业务编排位于 `app/Services`，Eloquent 查询集中在 `app/Repositories`，跨层结构使用 `app/Entities`，状态值位于 `app/Constants`。

## Karpathy LLM Wiki 对照

| 原始模式 | PHP Wiki 可执行契约 |
|---|---|
| Raw sources 不可变且是 source of truth | `SourceCatalog` 只接收显式允许的本地目录；扫描、Agent、审批和 Git 路径均不能写来源文件 |
| Wiki 是持续积累的 Markdown 编译层 | Ingest 把新来源合并进既有页面，以原生 Obsidian 链接维护关系，不把每份资料变成孤立摘要 |
| Schema 约束 ingest、query、maintenance | `AGENTS.md` 保存领域规则；`CitationValidator`、`ChangeSetValidator` 和工具白名单把关键规则变成失败关闭的代码契约 |
| Ingest 更新页面、index 和 append-only log | Agent 生成 `WikiChangeSet`，用户批准后原子落盘，并以单独 Git commit 更新受管页面、索引和日志 |
| Query 先读 index，再深入页面并带引用回答 | `QueryPlan → EvidenceBundle → AnswerVerifier`；旧链接和无来源 Wiki 文本不能直接支撑事实 |
| Lint 维护矛盾、过期、孤儿、缺口和交叉链接 | 确定性 Lint 检查结构与引用，语义 Lint 只读审计矛盾与知识缺口 |

应用增加的人工审批、哈希引用和确定性验证属于安全收紧，不改变原始三层模式。

## 视觉链路

Hao Code 的图片输入来自 `RunOptions::images`，因此 `VisionAnalystAgent` 由应用直接调用，图片附在初始用户消息中。ToolResult 是字符串，不能把它伪装成视觉输入。视觉结果转换成带路径、哈希和页码/区域的文字证据，再交给 Mapper、Auditor 和 Orchestrator。

PDF 每页分别执行文字提取和渲染；每批默认八页。图片缓存位于 Laravel storage，不进入 Wiki Git 仓库，也不会覆盖 Source Catalog 原始资料。

## 证据优先问答

查询链的规范顺序是：

```text
QueryPlan
  → QueryToolBudget
  → AgentToolInvocation
  → EvidenceBundle
  → AnswerDraft
  → AnswerVerifier
  → ChatMessage
```

- `QueryPlan` 确定 `lookup` 或 `research`、子问题、查询改写、固定工具预算和独立问题中的实质歧义。
- 三个知识工具只返回有身份的 JSON envelope；工具输出本身不是正式引用。
- `SourceCitation` + `SourceCitationCodec` 是持久 Wiki 引用的唯一解析与格式化契约；`CitationValidator`、Wiki 读取工具和 `EvidenceBundleBuilder` 不再各自解释引用文本。
- `EvidenceBundleBuilder` 只消费本次运行内完成且成功的工具调用，重新核对 Wiki SHA-256、Source Catalog 路径、来源 SHA-256 和 locator；同一路径、修订哈希和 locator 只形成一条证据，`EvidenceIdRegistry` 保证早期证据失效后 ID 也不会被另一条证据复用。跨语言内容必须有明确词项重合或双语 claim hint，不能仅凭语言不同自动判定覆盖。
- `RetrievalEvidencePublisher` 在每次工具完成后发布新增 EvidenceItem 和完整 coverage，下一次工具调用前 UI 已能看到进展。
- 答案 Agent 没有工具，只接收 QueryPlan、EvidenceBundle 和应用选择的单一答案类型。JSON Schema 进入提示词，但 Parser + Verifier 才是结构权威，不能依赖兼容网关执行 enum 或 minItems。
- 模型生成的未知引用、无证据 section、未披露冲突、没有同时引用冲突双方、过高置信度都会被拒绝；证据缺口由 Renderer 根据 EvidenceBundle 固定追加，避免把确定性披露交给模型措辞。
- Verifier 只允许一次基于错误清单的修正；第二次仍失败时不创建 assistant message。
- SQLite 先持久化语义事件，Reverb 仍只广播 `{run_id, sequence, type}` 失效通知。

只有带有效结构化 citations 的正式回答才能通过 `ProposalDraftService` 保存。该服务把正文中的 `[^E*]` 转为规范 `[[source:...]]`，去掉展示脚注，并从实际使用的证据生成 `source_ids`；澄清、证据不足、未知 Evidence ID、陈旧来源和已存在目标页面都会失败关闭。`AgentRunDispatchService` 只负责运行入队和取消。

固定的 50 题验收集位于 `resources/core-agent/acceptance-corpus.json`，对应知识夹具位于 `resources/core-agent/workspace/`。`php-wiki:benchmark-core-agent --live` 默认在临时工作区和可回滚数据库事务中运行夹具，从持久化事件和正式消息重建观察结果；夹具运行不会读取或修改用户的 Wiki。报告保存夹具 SHA-256，并验证引用数量、视觉/PDF 证据类型、冲突双方引用、终态、覆盖、预算和事件顺序。`--ids` 可生成固定的跨分类 live 子集；每个 case 还保存答案合约、语义事件类型及不含原文的来源路径、哈希与 locator，失败时仅保存脱敏错误和 JSON 形状。`--workspace=configured` 只提供当前知识库诊断，不作为固定验收结果。整个过程不读取模型思维链。

## 提案事务

批准提案时：

1. 确认 Wiki Schema、index、log 已初始化并且 Git 存在 HEAD。
2. 获取 `.git/php-wiki-apply.lock` 独占锁。
3. 重新验证路径、符号链接、base SHA-256、frontmatter、引用和 Wiki 链接。
4. 保存所有涉及文件的内存快照。
5. 使用同目录临时文件和 rename 原子替换页面。
6. 更新 index、追加 log，仅提交本提案路径。
7. 在 SQLite 事务中把 `pending` 条件更新为 `applied`，并记录唯一 commit hash。

Proposal 状态机固定为 `draft → pending/invalid` 和 `pending → applied/rejected/stale/invalid`，仓储层拒绝非法或重复终态。若 Git 已存在标题精确包含同一 Proposal UUID 的孤儿提交，重试只补齐 SQLite 记录；无法精确识别时不猜测。

权威提交完成前发生异常会恢复文件快照；若 Git commit 已生成且 HEAD 未被其他进程改变，则回到提交前 HEAD。工作区的无关已暂存或未暂存修改不会进入提案提交。FTS5 重建使用独立数据库事务，失败会保留旧索引并记录告警，不回滚已成功的 Wiki/Git/Proposal；统一恢复命令是 `php artisan php-wiki:rebuild-search`。

## Octane 生命周期

Agent、SdkTool、AbortController 和回调在每个 Queue Job 内重新创建。服务容器中不保存可变 Provider/Agent 运行态。模型调用不发生在 HTTP 请求内，页面通过持久化事件轮询运行进度。

Queue Worker 固定并发 1；应用层还会复用同一来源已有的 queued/running run，审批路径另有工作区文件锁。
部署新代码后必须重启 Queue Worker 和 Octane Worker，确保长驻进程使用同一不可变运行时快照。
