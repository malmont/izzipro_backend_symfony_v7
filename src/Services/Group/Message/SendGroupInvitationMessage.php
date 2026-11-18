<?php

namespace App\Services\Group\Message;

/** Lot G-1a : une invitation à rejoindre un groupe, traitée en file (la réponse au siège ne dit jamais si le site existe) */
final class SendGroupInvitationMessage
{
    public function __construct(public readonly int $groupId, public readonly string $siteCode, public readonly ?string $by, public readonly string $locale = 'fr')
    {
    }
}
