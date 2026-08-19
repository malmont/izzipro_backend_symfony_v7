<?php

namespace App\ESG\Enum;

enum ReportStatusEnum: string
{
    case PENDING = 'pending';
    case GENERATING = 'generating';
    case READY = 'ready';
    case FAILED = 'failed';
}
