# PHP Wiki Core Agent Product Goal

Status: Ready for implementation
Updated: 2026-08-26
Scope owner: Core Agent only

## Goal

在不改变本地文件、Wiki 审批和现有产品外壳的前提下，把 php-wiki 从“会调用知识工具的聊天 Agent”收敛为“证据优先的知识研究 Agent”：先判断问题深度和知识范围，再建立可追溯证据集，最后生成逐段可核验的答案；证据不足时澄清或拒答，不用模型常识填空。

用户仍从同一个对话入口提问。检索模式选择、证据组织、答案核验和运行可观测性属于 Agent 内部职责，本 Goal 不增加新的产品入口或设置页面。

## Product Reference

成熟知识库产品的共同点不是开放更多工具，而是让“来源范围 → 研究过程 → 证据 → 答案”成为可控且可核验的链路。

| 产品 | 已验证的核心形态 | PHP Wiki 取舍 |
|---|---|---|
| Gemini Notebook / NotebookLM | 回答基于选定来源；引用可预览原文并跳到准确位置 | 来源范围明确；引用必须绑定原文位置 |
| Notion Research Mode | 区分普通搜索和深度研究；可限定来源并展示报告使用的来源 | 内部区分快速查找和研究，不增加模式选择 UI |
| Glean | 普通问答与 Deep Research 分离；研究执行多源检索、综合和报告；研究 Agent 保持只读 | 查询链只读；复杂问题走有预算的多步研究 |
| Perplexity Projects | 项目文件、指令、会话和持续记忆共同构成 Agent 上下文 | 保留 `AGENTS.md + Wiki + 会话` 的稳定上下文，不引入连接器 |

Primary references:

- Google, Use chat in Gemini Notebook: <https://support.google.com/gemininotebook/answer/16179559>
- Google, Add or discover sources: <https://support.google.com/notebooklm/answer/16215270>
- Notion, Research Mode: <https://www.notion.com/en-gb/help/research-mode>
- Glean, Deep Research: <https://docs.glean.com/user-guide/assistant/deep-research>
- Glean, Citations: <https://docs.glean.com/user-guide/assistant/glean-chat/glean-chat-citations/glean-citations>
- Perplexity, Projects: <https://www.perplexity.ai/help-center/en/articles/10352961-what-are-spaces>

## Current Baseline

已经成立的能力：

- 查询 Agent 只能使用 `SearchWiki`、`ReadWikiPage` 和 `ReadSourceExcerpt`。
- Wiki 工具只读、同进程顺序执行，不开放 Bash、任意文件写入或网络抓取。
- 运行只有在生命周期完整、终止原因为 `normal`、最终文本非空且至少一个知识工具成功时才能完成。
- 轮次、分析状态、工具调用、增量文本、终止原因和工具耗时已经持久化并实时展示。
- Wiki 页面写入链已经有严格的来源路径、SHA-256 和定位验证。

当前核心缺口：

1. `QueryWikiWorkflow` 只验证“至少一次知识工具成功”，不能证明答案的全部事实有充分依据。
2. 查询答案仅用正则收集 `[[source:...]]`，没有调用严格的 `CitationValidator`。
3. 模型可以生成 `[[source:wiki/75]]` 或 `[[source:wiki/36、49]]` 之类不能解析到原文的缩写引用，现有完成门禁仍会接受。
4. `SearchWiki` 只有 FTS 词项检索，没有查询改写、覆盖检查、矛盾检查或二次检索决策。
5. 工具输出是搜索结果或整页文本，不存在统一、不可变、可复核的证据对象。
6. 运行界面展示了工具细节，却没有说明正在进行规划、检索、阅读、核验还是生成，也没有展示结论与来源的对应关系。

真实基线运行 `agent_runs.id=4`：

- 模型 `deepseek-v4-flash-vision-exp`
- `normal` 终止，无文本回退
- 5 次 `ReadWikiPage`、2 次 `SearchWiki`，全部成功
- 最终回答非空，但保存了 9 个包含缩写和组合路径的不可严格验证引用

这证明工具链和实时链路已经可用，下一阶段的瓶颈是证据质量，而不是增加工具数量。

## Product Contract

### One Entry, Two Internal Depths

用户只看到一个问答入口。Agent 根据问题自动选择内部深度：

- `lookup`：明确事实、定义、位置或单一主题的快速查找。
- `research`：跨页面综合、比较、冲突分析、时间演化或开放问题。

模式选择必须写入运行事件并说明可展示的简短理由，例如“需要比较三个专题页”。不得保存或展示原始思维链。

如果问题存在会实质改变答案的歧义，Agent 返回澄清问题；如果知识库没有足够证据，Agent 返回证据不足结论。二者都不得伪装成正常知识答案。

### Authoritative Flow

```text
Question
  -> Query Scope
  -> Query Plan
  -> Bounded Retrieval
  -> Evidence Bundle
  -> Answer Composition
  -> Deterministic Verification
  -> Answer | Clarification | Insufficient Evidence
```

生成自然语言答案之前，必须先形成完整的 `EvidenceBundle`。答案生成器不得直接读取原始工具输出或自行创造来源引用。

## 1. Retrieval

新增内部 `QueryPlan`，至少包含：

- `mode`: `lookup | research`
- 需要回答的子问题
- 检索词与同义改写
- 首选 Wiki 范围
- 最大搜索轮数和读取数量
- 结束条件

检索继续以 `wiki/index.md + SQLite FTS5 + 只读工具` 为基础，不引入向量数据库。FTS 是候选发现器，不是事实权威。

默认预算：

| 模式 | SearchWiki | ReadWikiPage / ReadSourceExcerpt | 结束条件 |
|---|---:|---:|---|
| lookup | 最多 2 次 | 最多 4 次 | 一个直接证据集完整覆盖问题 |
| research | 最多 4 次 | 最多 12 次 | 子问题均有证据或被明确标为缺口 |

超过预算不能静默继续。Agent 必须基于已有证据回答、声明缺口或失败，不得通过增加 Hao Code `maxTurns` 掩盖检索失控。

## 2. Evidence Organization

`EvidenceBundle` 是查询链的单一证据权威。每个 `EvidenceItem` 至少包含：

```text
evidence_id
tool_call_id
wiki_path
wiki_revision_or_hash
raw_path
raw_sha256
locator: lines | page | region
quote
claim_hint
stale
```

规则：

- `evidence_id` 在单次运行内稳定、不可复用。
- 每个 EvidenceItem 必须来自本次成功的工具调用。
- Wiki 页面只是知识入口；事实证据继续追溯到页面保存的 raw 路径、SHA-256 和定位。
- 无 raw 谱系的 Wiki 内容可以作为“Wiki 陈述”展示，但必须降低置信度，不能伪装成原始事实。
- 过期、断裂或无法读取的来源不得进入可引用证据集。
- 图片使用 `region:`，PDF 使用 `page:`，文本使用 `lines:start-end`。
- 矛盾证据同时保留，不由检索器提前删除不符合预期的一方。

查询回答使用运行内 Evidence ID，例如 `[^E3]`。持久 Wiki 的 `[[source:path|sha256:...|locator]]` 格式保持不变，避免混淆“回答引用”和“Wiki 事实引用”。

## 3. Tool Orchestration

工具职责保持窄边界：

- `SearchWiki`：只返回候选页面和匹配片段。
- `ReadWikiPage`：返回页面内容、页面身份和页面中可解析的来源谱系。
- `ReadSourceExcerpt`：返回有路径、哈希、定位和原文的 EvidenceItem 候选。
- 不新增通用 Read、Bash、Web、Write 或 Edit。

编排器负责：

1. 先覆盖 `QueryPlan` 的子问题，再考虑补充材料。
2. 每轮工具调用后更新 EvidenceBundle 的覆盖和缺口。
3. 搜索连续两轮没有新增证据时停止，不用重复关键词消耗轮次。
4. 单个工具失败可以继续，但相关子问题必须标记缺口；所有相关工具失败时不得完成答案。
5. fallback 模型必须重新接受同一 QueryPlan 和已验证 EvidenceBundle，不得继承未经验证的文本草稿。

## 4. Answer Generation and Verification

答案生成器只接收：

- 用户问题
- QueryPlan
- EvidenceBundle
- 明确的缺口与矛盾

它不接收工具对象，不允许在答案生成阶段继续检索。

答案内部先形成结构化 section：

```text
heading
content
evidence_ids[]
inference: bool
confidence: high | medium | low
```

确定性 Verifier 在答案落库前检查：

- 所有 Evidence ID 存在于本次 EvidenceBundle。
- 所有 EvidenceItem 均来自成功工具调用且来源未过期。
- 每个事实性 section 至少绑定一个 Evidence ID。
- 推断必须显式标注，且至少绑定形成推断的证据。
- 矛盾来源必须在答案中披露，不能选择性隐藏。
- 没有证据的内容只能出现在“未知、缺口或澄清问题”中。
- 最终渲染的引用可以打开原文预览并定位到行、页或图片区域。

Verifier 失败时，允许一次只基于验证错误的答案修正；第二次仍失败则运行失败。不得把非法引用原样保存为正式 assistant 消息。

## 5. Observability

新增语义事件，但数据库仍是唯一事实源，Reverb 仍只发送失效通知：

```text
query_scoped
plan_completed
retrieval_started
evidence_added
coverage_updated
answer_contract_selected
verification_started
verification_failed
answer_completed
```

对话中的核心 Agent 活动应展示：

- 当前阶段：规划、检索、阅读、核验、生成。
- 已找到的来源数量和已覆盖的子问题数量。
- 证据缺口、冲突和工具警告。
- 最终答案中的来源卡片与原文预览。

原始工具输入输出继续保留在诊断折叠区。不得保存或展示原始思维链。

## Acceptance Corpus

建立固定的本地验收集，至少 50 个问题。问题必须绑定仓库内的只读知识夹具；验收命令在临时工作区和可回滚数据库事务中运行，不得把个人 `PHP_WIKI_ROOT` 当作合成题目的数据源：

| 类型 | 数量 | 预期 |
|---|---:|---|
| 单页直接查找 | 10 | lookup，少量工具，准确定位 |
| 跨页综合 | 10 | research，覆盖所有子问题 |
| 比较与矛盾 | 10 | research，同时披露不同证据 |
| 知识库无答案 | 10 | 明确证据不足，不用常识补全 |
| 有实质歧义 | 10 | 返回澄清问题，不生成武断答案 |

验收集必须覆盖中英文资料，并至少包含 5 个依赖 PDF 页码、图片区域或视觉分析证据的问题。

## Acceptance Gates

### Evidence correctness

- 100% 回答引用可以解析到本次运行的 EvidenceItem。
- 100% EvidenceItem 可以追溯到成功工具调用。
- 100% raw 引用通过路径、SHA-256、存在性和 locator 验证。
- 0 个组合路径、缩写路径或模型生成的未知引用进入正式消息。

### Answer behavior

- 100% 事实性 section 至少包含一个有效 Evidence ID。
- 无答案题的正确拒答率不低于 90%。
- 矛盾题的冲突披露率不低于 90%。
- 需要澄清的问题不得被记录成确定性知识答案。
- `normal + 非空文本` 仍是必要条件，但不再是充分条件。

### Retrieval behavior

- 所有运行遵守 lookup/research 工具预算。
- 相同搜索结果连续两轮不得继续重复检索。
- lookup 问题不得无理由升级成 research。
- research 的每个子问题最终必须是 `covered | gap | conflict` 之一。

### Observability

- 最终答案出现前能看到规划、检索、证据和核验阶段。
- WebSocket payload 继续只有 `run_id`、`sequence` 和 `type`。
- 事件和 UI 不包含原始思维链、完整敏感工具输出或 API Key。
- Reverb 失败时，5 秒轮询仍能恢复相同阶段与证据状态。

### Compatibility and regression

- 现有 Wiki ingest、proposal、approval、Git 原子落盘链不改变。
- 现有视觉模型输入链和视觉失败关闭规则不改变。
- 现有工具并发关闭策略不改变。
- Provider wire format、Hao Code runtime 和模型配置不改变。

## Delivery Slices

每个切片必须独立测试和回退，不保留永久双实现。

1. **Benchmark baseline**：固化 50 题验收集和当前失败证据。
2. **Evidence contract**：增加 QueryPlan、EvidenceItem、EvidenceBundle 和工具结构化输出。
3. **Retrieval loop**：增加模式判断、子问题覆盖、查询改写和预算结束条件。
4. **Answer verifier**：结构化 section、Evidence ID 渲染、引用和覆盖验证。
5. **Semantic activity**：增加阶段事件、来源卡片和证据预览，工具卡片退到诊断层。
6. **Live acceptance**：用 DeepSeek 控制模型跑完整验收子集，确认无伪引用、无越界工具和无 fallback 漂移。

## Non-goals

本 Goal 明确不做：

- 登录、注册、用户体系或权限重构。
- 来源上传、网页抓取、外部连接器或多工作区。
- 向量数据库、Embedding 服务或传统 RAG 基础设施。
- Proposal、审批、Git 提交和 Wiki 页面编辑体验改版。
- 导航、主题、布局、动画或其他视觉样式调整。
- 语音、附件输入、Slash Command、分享、协作或移动端。
- 自动写 Wiki、绕过审批或新增通用执行工具。
- 展示模型原始思维链。

## Definition of Done

只有同时具备以下当前证据，才能宣布本 Goal 完成：

1. 50 题验收集及期望结果已经进入仓库。
2. 五类核心能力均有契约测试：检索、证据、编排、答案、可观测性。
3. Acceptance Gates 全部通过，并保存机器可读报告。
4. 至少一次真实 DeepSeek 运行证明回答生成前出现证据阶段，最终引用可打开准确原文。
5. 全量 `composer test`、Composer validate/audit、NPM audit/build 和 Compose 静态验证通过。
6. 变更扫描证明没有修改 Non-goals 所列模块。
