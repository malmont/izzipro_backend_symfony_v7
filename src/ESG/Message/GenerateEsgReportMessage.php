<?php

namespace App\ESG\Message;

class GenerateEsgReportMessage
{
    public function __construct(
        public readonly int $reportId,
        public readonly string $tenantCode
    ) {
    }
}
