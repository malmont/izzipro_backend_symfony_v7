<?php

namespace App\ESG\Enum;

enum SessionStatusEnum: string
{
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case ARCHIVED = 'archived';
}
