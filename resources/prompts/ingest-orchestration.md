请把以下待处理来源修订收敛进 Wiki。来源=:path，sha256=:sha256。

本次运行只会处理尚未被当前 Wiki 修订正式吸收的来源。旧式 `[[:path]]` 链接和 ReadWikiPage 返回的 source_candidates 只是导航线索，不是已验证引用，也不能证明本次修订已经摄取。
你必须至少调用一次 ProposeWikiPage：若知识已存在，就更新最小的现有页面，为相关陈述补上 `[[source::path|sha256::sha256|lines:<start>-<end>]]` 形式的规范引用；确有独立知识时才新建页面。不得以“已有旧链接”或“无需改动”为由跳过提案。
