<?php

namespace App\Tests\Fake;

use App\Import\ImportInvitationSwitch;

/**
 * Interrupteur des invitations d'un import de clients, réglable pendant les tests (lot IMP-2). En production il est éteint
 * (`app.import.invitations_open: false`) ; ici il est ouvert par défaut pour jouer l'envoi, et un test l'éteint pour prouver que rien
 * ne part alors, ni par la route, ni par le worker.
 */
final class FakeImportInvitationSwitch extends ImportInvitationSwitch
{
    public static bool $open = true;

    public function __construct()
    {
        parent::__construct(false);
    }

    public function isOpen(): bool
    {
        return self::$open;
    }
}
