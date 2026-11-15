<?php

namespace App\Controller\Admin;

use App\Services\CaisseService\StoreClock;
use App\Services\Platform\PlatformAccessJournal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Accès de la plateforme » (lot AG-2, 15/11/2026, décision du propriétaire) : l'administrateur d'un site y lit chaque connexion
 * du compte de la plateforme sur SON site (journal du site, `PlatformAccessJournal`). Lecture seule. Heures à l'heure du magasin.
 */
#[IsGranted('ROLE_ADMIN')]
class PlatformAccessAdminController extends AbstractController
{
    public function __construct(private readonly PlatformAccessJournal $journal, private readonly StoreClock $clock)
    {
    }

    #[Route('/admin/acces-plateforme', name: 'admin_acces_plateforme', methods: ['GET'])]
    public function index(): Response
    {
        $timezone = $this->clock->timezone();
        $lines = array_map(static fn (array $line): array => ['at' => $line['at']->setTimezone($timezone)->format('Y-m-d H:i'), 'account' => $line['account'], 'channel' => $line['channelLabel']], $this->journal->recent(200));

        return $this->render('admin/acces_plateforme.html.twig', ['lines' => $lines, 'label' => PlatformAccessJournal::LABEL, 'timezone' => $timezone->getName()]);
    }
}
