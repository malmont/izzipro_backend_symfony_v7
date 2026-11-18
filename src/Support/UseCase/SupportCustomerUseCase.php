<?php

namespace App\Support\UseCase;

use App\Entity\User;
use App\Support\Services\SupportCustomerService;

/** « Aide et support » des applications du client (lot AIDE-APP, 18/11/2026) : passage du contrôleur au service, sans règle propre */
class SupportCustomerUseCase
{
    public function __construct(private readonly SupportCustomerService $customers)
    {
    }

    public function availability(): array { return $this->customers->availability(); }
    public function create(User $customer, array $body, array $files, ?string $app): array { return $this->customers->create($customer, $body, $files, $app); }
    public function mine(User $customer): array { return $this->customers->mine($customer); }
    public function detail(User $customer, string $reference): array { return $this->customers->detail($customer, $reference); }
    public function reply(User $customer, string $reference, array $body, array $files): array { return $this->customers->reply($customer, $reference, $body, $files); }
    public function attachment(User $customer, string $reference, int $attachmentId): array { return $this->customers->attachment($customer, $reference, $attachmentId); }
}
