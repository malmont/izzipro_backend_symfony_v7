<?php

namespace App\Controller\Admin;

use App\Services\CaisseService\StoreClock;
use App\Services\Platform\PlatformAccessJournal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Accès extérieurs » (lot AG-2, 15/11/2026, décision du propriétaire ; nommée « Accès de la plateforme » jusqu'au lot AG-3b) :
 * l'administrateur d'un site y lit chaque connexion du compte de la plateforme sur SON site, et chaque entrée d'une agence ou de la
 * plateforme par un jeton d'entrée (« Agence X, prénom ») (journal du site, `PlatformAccessJournal`). Lecture seule. Heures à l'heure du magasin.
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
        // lot AG-3b : « Accès extérieurs » : les connexions du compte de la plateforme ET les entrées d'une agence ou de la plateforme
        $all = [...$this->journal->recent(200), ...$this->journal->entries(200), ...$this->journal->assistance(60), ...$this->journal->groupWrites(100), ...$this->journal->groupLinks(50)]; // lot AG-ASSIST : chaque écran consulté pendant une assistance (borné : les connexions et les entrées gardent leur place)
        usort($all, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $lines = array_map(static fn (array $line): array => ['at' => $line['at']->setTimezone($timezone)->format('Y-m-d H:i'), 'account' => $line['account'], 'channel' => $line['channelLabel']], array_slice($all, 0, 200));

        return $this->render('admin/acces_plateforme.html.twig', ['lines' => $lines, 'label' => PlatformAccessJournal::LABEL, 'timezone' => $timezone->getName()]);
    }
}
