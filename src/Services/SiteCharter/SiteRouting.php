<?php

namespace App\Services\SiteCharter;

/**
 * Où se fait quoi (08/11/2026) : la table d'aiguillage entre le back-office (réglages, données, argent) et l'éditeur du site Next (ce qu'on
 * voit pendant qu'on le touche). Lue par le questionnaire « Mon site pas à pas », par les consignes de l'assistant IA et par l'aide intégrée.
 */
class SiteRouting
{
    /** @var list<array{need: string, where: 'backend'|'editor', screen: ?string, label: string}> */
    public const TABLE = [
        ['need' => 'Dire ce que fait le site, pour qui, avec quelles pages', 'where' => 'backend', 'screen' => 'site_setup', 'label' => 'Mon site pas à pas'],
        ['need' => 'Déposer la charte, le PDF, les couleurs et les polices', 'where' => 'backend', 'screen' => 'site_setup', 'label' => 'Mon site pas à pas, questions « couleurs » et « charte »'],
        ['need' => 'Composer le site depuis zéro, avec de vrais textes', 'where' => 'backend', 'screen' => 'site_setup', 'label' => 'Mon site pas à pas, bouton « Composer mon site »'],
        ['need' => 'Retoucher une section, déplacer, changer une image, voir en direct', 'where' => 'editor', 'screen' => null, 'label' => 'Éditeur du site (bouton « Éditeur » en bas à droite de votre site, administrateur connecté)'],
        ['need' => 'Changer le contenu d\'un composant (bannières, blocs, logos, prestations, vidéos)', 'where' => 'backend', 'screen' => null, 'label' => 'Rubriques « Site vitrine » et « Boutique : accueil et pages » du menu'],
        ['need' => 'Produits, prix, photos, catégories', 'where' => 'backend', 'screen' => 'products', 'label' => 'Produits'],
        ['need' => 'Livraison, retrait, transporteurs', 'where' => 'backend', 'screen' => 'shipping', 'label' => 'Livraison'],
        ['need' => 'Taxes, paiements Stripe, courriels, avis', 'where' => 'backend', 'screen' => 'configuration', 'label' => 'Configuration'],
        ['need' => 'Fiche de l\'entreprise, adresse, textes légaux', 'where' => 'backend', 'screen' => 'entreprise', 'label' => 'Entreprise'],
        ['need' => 'Modules, applications, crédits IA, domaine propre', 'where' => 'backend', 'screen' => 'mon_site', 'label' => 'Mon site : pilotage'],
    ];

    /** Texte des consignes de l'assistant IA */
    public static function promptText(): string
    {
        $lines = ['OÙ SE FAIT QUOI (aiguille toujours l\'administrateur vers le bon endroit) :'];
        foreach (self::TABLE as $row) {
            $lines[] = sprintf('- %s → %s : %s', $row['need'], $row['where'] === 'editor' ? 'éditeur du site (front)' : 'back-office', $row['label']);
        }

        return implode("\n", $lines);
    }
}
