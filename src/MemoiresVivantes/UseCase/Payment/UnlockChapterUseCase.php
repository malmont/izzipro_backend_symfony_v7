<?php

namespace App\MemoiresVivantes\UseCase\Payment;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Services\ChapterPaymentService;

/** POST /api/memoires/mobile/chapters/{id}/unlock : affecte une transaction de store au chapitre */
class UnlockChapterUseCase
{
    public function __construct(private readonly ChapterPaymentService $payments)
    {
    }

    /** @param array<string, mixed> $data {transactionId, store} */
    public function execute(Chapter $chapter, User $user, array $data): Chapter
    {
        return $this->payments->unlock($chapter, $user, (string) ($data['store'] ?? ''), (string) ($data['transactionId'] ?? $data['transaction_id'] ?? ''));
    }
}
