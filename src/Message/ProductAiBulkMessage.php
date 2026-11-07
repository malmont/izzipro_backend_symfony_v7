<?php

namespace App\Message;

/** Traitement IA en lot des produits (fiches, traductions, images), voir App\Services\ProductAiService\ProductAiBulkService */
final class ProductAiBulkMessage
{
    public function __construct(public readonly string $jobId, public readonly string $tenantCode)
    {
    }
}
