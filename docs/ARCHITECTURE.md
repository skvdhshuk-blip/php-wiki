# PHP Wiki 架构

## 权威与边界

| 能力 | 唯一权威 |
|---|---|
| 原始资料 | 外部工作区 `raw/` |
| 已批准知识 | 外部工作区 `wiki/**/*.md` 与 `AGENTS.md` |
| 变更历史 | 工作区 Git + `wiki/log.md` |
| 待审批内容 | SQLite `wiki_proposals` / `wiki_page_changes` |
| 运行状态 | SQLite `agent_runs` / `agent_events` |
| 搜索 | 可从 Markdown 重建的 SQLite FTS5 |
| 单次问答证据 | 从本次成功工具调用构建并写入 `agent_events` 的 `EvidenceBundle` |

Livewire 组件只接收输入、调用 Application Service、展示状态。业务编排位于 `app/Services`，Eloquent 查询集中在 `app/Repositories`，跨层结构使用 `app/Entities`，状态值位于 `app/Constants`。

## 视觉链路

Hao Code 的图片输入来自 `RunOptions::images`，因此 `VisionAnalystAgent` 由应用直接调用，图片附在初始用户消息中。ToolResult 是字符串，不能把它伪装成视觉输入。视觉结果转换成带路径、哈希和页码/区域的文字证据，再交给 Mapper、Auditor 和 Orchestrator。

PDF 每页分别执行文字提取和渲染；每批默认八页。图片缓存位于 Laravel storage，不进入 Wiki Git 仓库，也不会覆盖 `raw/`。

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
- `EvidenceBundleBuilder` 只消费本次运行内完成且成功的工具调用，重新核对 Wiki SHA-256、raw 路径、raw SHA-256 和 locator；`EvidenceIdRegistry` 保证早期证据失效后 ID 也不会被另一条证据复用。
- `RetrievalEvidencePublisher` 在每次工具完成后发布新增 EvidenceItem 和完整 coverage，下一次工具调用前 UI 已能看到进展。
- 答案 Agent 没有工具，只接收 QueryPlan 和 EvidenceBundle。模型生成的未知引用、无证据 section、未披露缺口或冲突、没有同时引用冲突双方、过高置信度都会被拒绝。
- Verifier 只允许一次基于错误清单的修正；第二次仍失败时不创建 assistant message。
- SQLite 先持久化语义事件，Reverb 仍只广播 `{run_id, sequence, type}` 失效通知。

固定的 50 题验收集位于 `resources/core-agent/acceptance-corpus.json`，对应知识夹具位于 `resources/core-agent/workspace/`。`php-wiki:benchmark-core-agent --live` 默认在临时工作区和可回滚数据库事务中运行夹具，从持久化事件和正式消息重建观察结果；夹具运行不会读取或修改用户的 Wiki。报告保存夹具 SHA-256，并验证引用数量、视觉/PDF 证据类型、冲突双方引用、终态、覆盖、预算和事件顺序。`--workspace=configured` 只提供当前知识库诊断，不作为固定验收结果。整个过程不读取模型思维链。

## 提案事务

批准提案时：

1. 获取 `.git/php-wiki-apply.lock` 独占锁。
2. 重新验证路径、符号链接、base SHA-256、frontmatter、引用和 Wiki 链接。
3. 保存所有涉及文件的内存快照。
4. 使用同目录临时文件和 rename 原子替换页面。
5. 更新 index、追加 log，仅提交本提案路径。
6. 在 SQLite 事务中记录 applied 状态和 commit hash。

发生异常时恢复文件快照；若 Git commit 已生成且 HEAD 未被其他进程改变，则回到提交前 HEAD。工作区的无关已暂存或未暂存修改不会进入提案提交。

## Octane 生命周期

Agent、SdkTool、AbortController 和回调在每个 Queue Job 内重新创建。服务容器中不保存可变 Provider/Agent 运行态。模型调用不发生在 HTTP 请求内，页面通过持久化事件轮询运行进度。

Queue Worker 固定并发 1；应用层还会复用同一来源已有的 queued/running run，审批路径另有工作区文件锁。
