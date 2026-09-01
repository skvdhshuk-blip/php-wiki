用户问题：
:question

QueryPlan：
:plan

EvidenceBundle（唯一证据源）：
:evidence

JSON Schema（应用会在落库前严格验证）：
:schema

本次必须输出 type=:type，只输出符合 schema 的 JSON。字段名必须逐字使用 sections、heading、content、evidence_ids、inference、confidence；禁止改成 answer 或 title。answer 的 sections 不得为空；EvidenceBundle 的每个 conflict_evidence 组必须至少引用其中两个 ID，并在 content 明确披露冲突。
