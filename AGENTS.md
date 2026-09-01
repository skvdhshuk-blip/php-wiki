# PHP Wiki 工程约定

本文件面向在本仓库工作的编码 Agent，定义**行为边界**与**契约到实现的对应关系**。
产品目标见 `docs/CORE_AGENT_GOAL.md`，系统组成见 `docs/ARCHITECTURE.md`，数据边界见 `docs/PRIVACY.md`。

`wiki/AGENTS.md` 是另一份文件：它由 `WikiWorkspace::schemaTemplate()` 生成，写入用户的知识库工作区，
约束的是知识库内容的组织方式，与本文件无关。

## 权威边界

- 原始资料的权威是 Source Catalog，应用只读、不复制、不修改。
- 已批准知识的权威是工作区 Markdown 加 Git 提交。
- SQLite FTS5 只是可重建的检索缓存，索引失败不得回滚已经成功的 Wiki、Git 与 Proposal 提交。
- 单次问答的事实权威是该次运行内的 EvidenceBundle，不是模型文本。

## 检索

- 检索是候选发现器，不是事实权威。任何结论都必须回到证据。
- 中文必须经 `TextTokenizer` 切成 bigram 后再进 FTS5：默认的 unicode61 分词器把连续汉字视为一个词，
  不切分则中文查询几乎必然零召回。索引侧与检索侧共用同一套切分，不得各写一套。
- 索引以小节为单位，结果按页面聚合，每个页面只占一个结果位；`limit` 按页面计数，
  不能让单个多小节页面在候选截断前挤占其他页面的名额。
- 排序在 bm25 之上叠加锚点覆盖度。覆盖度由字符锚点在**当前候选集**内的文档频率计算，
  不维护主题词表或意图词表——词表会随知识库增长退化成特例集合，且无法泛化到新写入的知识。
- 检索得分只用于应用侧排序与预算决策，不进入模型输入：它不是证据强度。
- 纠错只在原查询完全无召回时执行一次，变体只能取自知识库里真实存在的标题。
  可自动纠正的只有：相邻颠倒、叠字增删、末尾缺字、单处同音替换。
  读音不同的替换不是打错字而是在问另一件事，一律拒绝；多处同音替换与中间漏字
  只能作为待确认候选，有多个同样说得通的纠正时不替用户选。
- 数字不参与纠错。
- 读音字典约 20 MB，只在真正走到纠错路径时加载，不得放进启动预热。

## 证据与答案

- 答案生成阶段不得再检索，只接收问题、QueryPlan 和 EvidenceBundle。
- 模型不得自行书写引用标记，引用由应用根据 `evidence_ids` 统一渲染。
- 结构校验只保证「这句话挂了一条存在的证据」；事实层由 `GroundingDiagnoser` 校验：
  答案写出的数量、单位、比例、限定词和边界方向必须能在同一命题的绑定证据里找到，
  链接无论证据语言如何都必须精确匹配，
  肯否也不得与证据相反。
- 接地校验是准入校验，误判会直接毙掉正确答案，因此每条规则都取保守一侧：
  只检查能被确定性提取的事实，提取不到就不判失败。
- 原始资料与答案经常不是同一种语言。所有事实比对都必须跨语言成立；
  凡是依赖词法重叠的判断，在跨语系时必须放宽或跳过，不得据此判失败。
- 单位表与限定词表是封闭的、领域中性的语法性集合，可以维护；主题词表不可以。
- 校验失败只允许一次定向修正，第二次仍失败则整个运行失败，不得把未通过的答案写入正式消息。

## 提示词

- 所有模型提示词位于 `resources/prompts/*.md`，由 `PromptRepository` 读取，不得内联回代码。
- 占位符使用 `:name` 形式。
- 改动提示词会改变 `PromptRepository::version()`，验收报告据此关联到具体提示词版本。

## 验收

- 验收集是产品对「正确」的定义。校验器不得比验收集更严：
  当一条规则会把验收集认定为正确的答案判为失败时，要改的是规则。
- 验收集的充分性门槛与质量门槛并列。删减用例与回答变差同样是失败。
- 需要真实模型与密钥的验收单独成阶段，PR 上不跑，主干与定时任务必跑；
  缺少密钥时门禁必须失败，不得静默跳过。
- 退出码区分「跑不起来」（2，用法错误）和「跑完但没过」（1，门槛未通过）。

## 修改代码时

- 数据访问收敛到 Repository：`app/Livewire`、`app/Jobs`、`app/Services/Application`、
  `app/Services/Agent` 内不得出现 `::query(`、`DB::` 或模型刷新调用。
- 不得在 `AppServiceProvider` 注册可变的 Agent 单例（Octane 常驻进程会跨请求复用）。
- 新增确定性判断时优先扩展 `PropositionAnalyzer`，不要在调用方另写一套读法。
- 提交前跑 `composer ci:check`（pint、phpstan level 7、全部测试）。

## 契约与实现的对应

| 实现位置 | 承担的约定 |
| --- | --- |
| `app/Services/Wiki/TextTokenizer.php` | 中文 bigram 切分，索引与检索共用 |
| `app/Services/Wiki/MarkdownSectionSplitter.php` | 小节级索引的切分规则 |
| `app/Services/Wiki/AnchorInformationScorer.php` | 锚点覆盖度与候选集内的区分度 |
| `app/Services/Wiki/QueryVariantGenerator.php` | 纠错的准入、判定与拒绝 |
| `app/Services/Wiki/PinyinReadings.php` | 同音判定所用的读音集合与惰性加载 |
| `app/Services/Wiki/WikiSearchService.php` | 检索编排、结果聚合、得分不外泄 |
| `app/Services/Agent/PropositionAnalyzer.php` | 数量、边界、限定词、命题子句的统一读法 |
| `app/Services/Agent/GroundingDiagnoser.php` | 事实层接地校验的失败判定 |
| `app/Services/Agent/AnswerVerifier.php` | 结构校验与接地校验的汇总 |
| `app/Services/Agent/EvidenceBundleBuilder.php` | 证据构建、覆盖与冲突判定 |
| `app/Services/Agent/PromptRepository.php` | 提示词读取与版本指纹 |
| `app/Services/Agent/CoreAgentBenchmarkEvaluator.php` | 质量门槛与验收集充分性门槛 |
| `app/Services/Wiki/CitationValidator.php` | 引用的路径、哈希与 locator 校验 |
| `app/Services/Wiki/ProposalApplyService.php` | 提案的原子落盘与失败恢复 |

修改上述任一行为时，必须同步更新对应实现、测试和本文件。
