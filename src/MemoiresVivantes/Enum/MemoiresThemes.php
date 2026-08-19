<?php

namespace App\MemoiresVivantes\Enum;

class MemoiresThemes
{
    public const THEMES_COUPLE = [
        'avant_nous_1', 'avant_nous_2', 'la_rencontre',
        'construire_ensemble', 'ce_que_nous_avons_appris', 'message_final'
    ];

    public const THEMES_FAMILLE = [
        'histoire_aine', 'regards_croises', 
        'regards_petits_enfants', 'epilogue_collectif'
    ];

    public const QUESTIONS_AVANT_NOUS = [
        "Où et quand êtes-vous né(e) ?",
        "Décrivez votre famille d'origine",
        "Quel enfant étiez-vous ?",
        "Quel métier rêviez-vous de faire, enfant ?",
        "Qui étiez-vous avant de le/la rencontrer ?",
        "Quel était votre rêve de jeunesse ?"
    ];

    public const QUESTIONS_LA_RENCONTRE = [
        "Où et comment vous êtes-vous rencontrés ?",
        "Quelle a été votre première impression l'un de l'autre ?",
        "Qu'est-ce qui vous a fait dire « c'est la bonne personne » ?",
        "Racontez votre première sortie ensemble",
        "Comment s'est passée la demande ou la décision de vous engager ?",
        "Que pensaient vos familles de votre union ?"
    ];

    public const QUESTIONS_CONSTRUIRE_ENSEMBLE = [
        "Décrivez votre mariage ou le début de votre vie commune",
        "Quel a été votre premier logement ?",
        "Comment avez-vous accueilli chaque enfant ?",
        "Quel a été le moment le plus difficile traversé ensemble ?",
        "Comment l'avez-vous surmonté ?",
        "Quel a été votre plus beau voyage ou souvenir commun ?",
        "Comment décririez-vous votre vie de famille ?",
        "Qu'est-ce qui vous a fait rire le plus souvent ensemble ?"
    ];

    public const QUESTIONS_CONSTRUIRE_ENSEMBLE_REDUCED = [
        "Décrivez votre mariage ou le début de votre vie commune",
        "Quel a été votre premier logement ?",
        "Comment avez-vous accueilli chaque enfant ?",
        "Quel a été le moment le plus difficile traversé ensemble ?",
        "Comment l'avez-vous surmonté ?",
        "Quel a été votre plus beau voyage ou souvenir commun ?",
        "Comment décririez-vous votre vie de famille ?",
        "Qu'est-ce qui vous a fait rire le plus souvent ensemble ?"
    ];

    public const QUESTIONS_CE_QUE_NOUS_AVONS_APPRIS = [
        "Qu'est-ce que votre conjoint(e) vous a appris ?",
        "Y a-t-il quelque chose que vous feriez différemment ?",
        "Quel a été votre plus grand sacrifice l'un pour l'autre ?",
        "Qu'est-ce qui vous rend le plus fiers de votre vie ensemble ?",
        "Quel conseil donneriez-vous à un jeune couple qui commence ?",
        "Comment décririez-vous l'amour après toutes ces années ?"
    ];

    public const QUESTIONS_MESSAGE_FINAL = [
        "Quel message voulez-vous laisser l'un à l'autre ?",
        "Quel message voulez-vous laisser à vos enfants et petits-enfants ?"
    ];

    public const QUESTIONS_REGARDS_ENFANT = [
        "Quel est votre souvenir le plus marquant avec lui/elle ?",
        "Ce que votre parent vous a transmitted sans le savoir ?",
        "Décrivez un moment où vous avez été fier/fière de lui/elle",
        "Quelle qualité admirez-vous le plus chez lui/elle ?",
        "Quel conseil vous a-t-il/elle donné qui vous suit encore ?",
        "Que voulez-vous qu'il/elle sache aujourd'hui ?"
    ];

    public const QUESTIONS_REGARDS_PETIT_ENFANT = [
        "Ton souvenir préféré avec mamie/papi ?",
        "Ce que tu aimes faire avec lui/elle ?",
        "Qu'est-ce qu'il/elle t'a appris ?",
        "Que veux-tu lui dire ?"
    ];

    public const QUESTIONS_EPILOGUE_COLLECTIF = [
        "Message collectif de la famille à la personne célébrée",
        "Ce que cette famille souhaite transmettre aux générations futures"
    ];
}
