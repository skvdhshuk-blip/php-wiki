<?php

namespace App\Constants;

enum ProposalStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Invalid = 'invalid';
    case Stale = 'stale';
    case Rejected = 'rejected';
    case Applied = 'applied';
}
