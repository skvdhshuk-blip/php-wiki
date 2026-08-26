<?php

namespace App\Constants;

enum SourceStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Missing = 'missing';
    case Failed = 'failed';
    case BlockedModelCapability = 'blocked_model_capability';
}
