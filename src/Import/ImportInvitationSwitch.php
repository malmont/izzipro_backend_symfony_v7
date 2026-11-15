<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lot IMP-2 : l'interrupteur de l'envoi des invitations aux clients repris d'une ancienne boutique (`app.import.invitations_open`,
 * `config/services.yaml`). Livré éteint le 15/11/2026, OUVERT le même jour quand la page du lien a été mise en ligne sur la boutique
 * publique et que le propriétaire a validé le texte du courriel. Éteint, AUCUNE invitation ne peut partir, ni par la route, ni par le
 * worker ; tout le reste de l'import des clients marche. Le refermer est une ligne de configuration.
 */
class ImportInvitationSwitch
{
    public function __construct(#[Autowire('%app.import.invitations_open%')] private readonly bool $open)
    {
    }

    public function isOpen(): bool
    {
        return $this->open;
    }
}
