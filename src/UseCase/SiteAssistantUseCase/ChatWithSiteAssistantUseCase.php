<?php

namespace App\UseCase\SiteAssistantUseCase;

use App\Services\SiteAssistantService\SiteAssistantService;
use App\Services\TenantConnectionManager;

/** POST /api/site/assistant : validation de la conversation puis échange avec l'IA (crédits, outils, propositions) */
class ChatWithSiteAssistantUseCase
{
    public function __construct(private readonly SiteAssistantService $assistant, private readonly TenantConnectionManager $tenants)
    {
    }

    /**
     * @param array<string, mixed> $body { messages: [{ role, content }], locale? }
     * @throws SiteAssistantValidationException
     */
    public function execute(array $body, ?string $user): array
    {
        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || $messages === [] || count($messages) > SiteAssistantService::MAX_MESSAGES) {
            throw new SiteAssistantValidationException([['path' => 'messages', 'message' => sprintf('liste de 1 à %d messages attendue', SiteAssistantService::MAX_MESSAGES)]]);
        }
        $clean = [];
        foreach (array_values($messages) as $i => $m) {
            $role = $m['role'] ?? null;
            $content = $m['content'] ?? null;
            if (!in_array($role, ['user', 'assistant'], true) || !is_string($content) || trim($content) === '' || mb_strlen($content) > SiteAssistantService::MAX_MESSAGE_LENGTH) {
                throw new SiteAssistantValidationException([['path' => "messages[$i]", 'message' => sprintf('role user | assistant et content de 1 à %d caractères attendus', SiteAssistantService::MAX_MESSAGE_LENGTH)]]);
            }
            $clean[] = ['role' => $role, 'content' => trim($content)];
        }
        if ($clean[array_key_last($clean)]['role'] !== 'user') {
            throw new SiteAssistantValidationException([['path' => 'messages', 'message' => 'le dernier message doit être celui de l\'utilisateur']]);
        }
        $language = ($body['locale'] ?? 'fr') === 'en' ? 'en' : 'fr';

        return $this->assistant->chat($clean, $user, (string) $this->tenants->getCurrentTenantCode(), $language);
    }
}
