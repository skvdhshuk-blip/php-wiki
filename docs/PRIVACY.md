# 隐私与安全

PHP Wiki 读取本地工作区，但 DeepSeek 是远程云模型。启用前必须设置：

```dotenv
PHP_WIKI_ALLOW_REMOTE_MODEL=true
```

启用后，以下内容可能发送到 `PHP_WIKI_BASE_URL`：

- 被摄取来源的文字片段
- PDF 页面渲染图
- 本地图片或 GIF 代表帧
- Wiki 搜索结果和相关页面
- 用户在 Agent 对话中输入的问题

不会主动发送：

- `.env` 或 API Key
- `raw/` 之外的宿主文件
- 没有进入某次 Agent 上下文的其他资料
- 远程网页或远程图片内容

应用不提供文件上传、WebFetch、Bash 或通用写工具。所有 Markdown 渲染禁用原始 HTML 和不安全链接。API Key 只从环境变量读取，状态页仅显示“已配置”，运行事件会隐藏提案完整内容。

若不能接受资料离开本机，请保持 `PHP_WIKI_ALLOW_REMOTE_MODEL=false`；扫描、确定性 Lint、Wiki 阅读和提案审批仍可本地使用，模型任务会明确失败。
