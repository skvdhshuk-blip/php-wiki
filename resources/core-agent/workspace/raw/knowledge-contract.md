# Knowledge authority contract
A persistent source citation uses [[source:raw/path|sha256:<64-hex>|lines:start-end]], with page:N for PDF and region:description for images.
Raw material is immutable evidence, approved Wiki Markdown is the knowledge fact layer, and SQLite search is only a rebuildable candidate index.
Source ownership establishes who may correct raw material; a changed source hash makes old citations stale and lowers answer confidence until revalidated.
Answers cite run-local Evidence IDs, and every EvidenceItem must trace to one successful knowledge-tool call.
