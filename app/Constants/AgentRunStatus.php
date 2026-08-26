<?php

namespace App\Constants;

enum AgentRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
}
