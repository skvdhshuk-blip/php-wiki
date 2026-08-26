# PHP Wiki 示例来源

PHP Wiki 把原始资料视为不可变证据。Agent 可以读取和分析本文件，但不能修改它。

## 关键原则

1. 原始来源、已批准 Wiki、Schema 分层保存。
2. Agent 生成完整变更集，用户查看 diff 后批准。
3. 每个事实使用来源路径、内容哈希和具体位置引用。
4. 搜索索引可以重建，Markdown Wiki 才是知识权威。

把这个文件复制到工作区 `raw/`，然后运行 `php artisan php-wiki:scan` 即可体验文本摄取流程。
