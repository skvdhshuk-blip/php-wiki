<?php

namespace App\Constants;

enum SourceStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Missing = 'missing';
    case Failed = 'failed';
    case BlockedModelCapability = 'blocked_model_capability';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待处理',
            self::Processing => '处理中',
            self::Processed => '已处理',
            self::Missing => '来源缺失',
            self::Failed => '处理失败',
            self::BlockedModelCapability => '模型能力不足',
        };
    }
}
