<?php

namespace App\MemoiresVivantes\BookType;

use App\MemoiresVivantes\Entity\BookType;
use App\MemoiresVivantes\Entity\BookTypeChapter;

/**
 * Définition des 4 types de livre historiques (individuel, couple, famille, hommage).
 *
 * Ces types restent générés par les consignes écrites en dur dans AnthropicService (promptSource = code).
 * Les consignes ci-dessous en sont la transcription éditoriale, avec variables, sans le cadre technique
 * (découpage partie 1/2, format des paragraphes, sous-titres ===...===, pas de titre général),
 * qui sera ajouté automatiquement par le backend. Elles servent de référence et de base pour basculer
 * un type sur promptSource = database après test.
 *
 * Variables disponibles dans les consignes :
 *   {prenom1} {prenom2} {lieu_naissance1} {lieu_naissance2} {sujets} {titre_livre}
 *   {dates} {exergue} {titre_chapitre} {ton}
 */
final class LegacyBookTypeCatalog
{
    public static function all(): array
    {
        return [
            self::individuel(),
            self::couple(),
            self::famille(),
            self::hommage(),
        ];
    }

    private static function individuel(): array
    {
        return [
            'code' => 'individuel',
            'label' => 'Individuel',
            'description' => 'Le récit de vie d\'une personne, racontée par elle-même.',
            'family' => BookType::FAMILY_DIRECT,
            'speakerCount' => 1,
            'speaker1Label' => 'Vous',
            'speaker2Label' => null,
            'subjectsMayBeAbsent' => false,
            'defaultRole' => null,
            'defaultRoleWhenSubjectsAbsent' => null,
            'displayOrder' => 1,
            'prompt' => <<<TXT
Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. La personne s'appelle {prenom1}, née à {lieu_naissance1}.

- Développe CHAQUE réponse en profondeur : contexte, émotions, souvenirs associés, ambiances.
- Le volume dépend de la richesse des réponses — ne pas répéter pour atteindre un quota.
- Si les réponses sont courtes, concentre-toi sur le ressenti, la réflexion et les émotions pour développer le récit sans inventer de nouveaux faits ou événements non mentionnés.
- Reste strictement fidèle aux informations fournies : n'invente jamais de lieux, de personnes ou d'événements majeurs.
- Minimum 3 paragraphes par réponse.
- Style littéraire, première personne (je), {ton}.
- Termine le chapitre par un beau paragraphe de conclusion.
TXT,
            'roles' => [],
            'chapters' => [
                ['code' => 'enfance', 'title' => 'Chapitre 1 — L\'enfance et les racines', 'speaker' => BookTypeChapter::SPEAKER_PERSON1, 'prompt' => null],
                ['code' => 'adulte', 'title' => 'Chapitre 2 — L\'âge adulte', 'speaker' => BookTypeChapter::SPEAKER_PERSON1, 'prompt' => null],
                ['code' => 'sagesse', 'title' => 'Chapitre 3 — La sagesse et l\'héritage', 'speaker' => BookTypeChapter::SPEAKER_PERSON1, 'prompt' => null],
            ],
        ];
    }

    private static function couple(): array
    {
        $avantNous = static fn (string $moi, string $lieu, string $autre) => <<<TXT
Ce chapitre ne concerne que {$moi}, née à {$lieu}. C'est un chapitre d'introduction décrivant sa vie avant sa rencontre avec {$autre}.
- Style littéraire, première personne (je), {ton}.
TXT;

        return [
            'code' => 'couple',
            'label' => 'Couple',
            'description' => 'L\'histoire d\'un couple, racontée à deux voix.',
            'family' => BookType::FAMILY_DIRECT,
            'speakerCount' => 2,
            'speaker1Label' => 'Personne 1',
            'speaker2Label' => 'Personne 2',
            'subjectsMayBeAbsent' => false,
            'defaultRole' => null,
            'defaultRoleWhenSubjectsAbsent' => null,
            'displayOrder' => 2,
            'prompt' => <<<TXT
Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. Le livre raconte l'histoire du couple formé par {prenom1} (né(e) à {lieu_naissance1}) et {prenom2} (né(e) à {lieu_naissance2}).

- Développe chaque réponse en profondeur : contexte, émotions, souvenirs associés, ambiances.
- Le volume dépend de la richesse des réponses fournies — ne pas répéter pour atteindre un quota.
- Si les réponses sont courtes, concentre-toi sur le ressenti, la réflexion commune et les émotions pour développer le récit sans inventer de nouveaux faits ou événements non mentionnés.
- Reste strictement fidèle aux informations fournies : n'invente jamais de faits non mentionnés.
- Minimum 3 paragraphes par réponse.
- Pour les chapitres communs : tisse les réponses des deux personnes ensemble de manière fluide. Écris à la troisième personne du pluriel (ils/elles) pour raconter leur histoire commune, ou alterne les voix à la première personne en fonction de qui s'exprime dans les réponses. Ne raconte pas la même scène sous deux angles redondants si les réponses n'apportent aucun élément nouveau.
- Style littéraire, {ton}.
- Termine le chapitre par un beau paragraphe de conclusion.
TXT,
            'roles' => [],
            'chapters' => [
                ['code' => 'avant_nous_1', 'title' => 'Chapitre 1 — Avant nous (Personne 1)', 'speaker' => BookTypeChapter::SPEAKER_PERSON1, 'prompt' => $avantNous('{prenom1}', '{lieu_naissance1}', '{prenom2}')],
                ['code' => 'avant_nous_2', 'title' => 'Chapitre 2 — Avant nous (Personne 2)', 'speaker' => BookTypeChapter::SPEAKER_PERSON2, 'prompt' => $avantNous('{prenom2}', '{lieu_naissance2}', '{prenom1}')],
                ['code' => 'la_rencontre', 'title' => 'Chapitre 3 — La rencontre', 'speaker' => BookTypeChapter::SPEAKER_BOTH, 'prompt' => null],
                ['code' => 'construire_ensemble', 'title' => 'Chapitre 4 — Construire ensemble', 'speaker' => BookTypeChapter::SPEAKER_BOTH, 'prompt' => null],
                ['code' => 'ce_que_nous_avons_appris', 'title' => 'Chapitre 5 — Ce que nous avons appris', 'speaker' => BookTypeChapter::SPEAKER_BOTH, 'prompt' => null],
                ['code' => 'message_final', 'title' => 'Chapitre 6 — Message final', 'speaker' => BookTypeChapter::SPEAKER_BOTH, 'prompt' => null],
            ],
        ];
    }

    private static function famille(): array
    {
        return [
            'code' => 'famille',
            'label' => 'Famille',
            'description' => 'La saga d\'une famille, racontée par les parents, les enfants et les petits-enfants.',
            'family' => BookType::FAMILY_COLLECTIVE,
            'speakerCount' => null,
            'speaker1Label' => 'Parent 1',
            'speaker2Label' => 'Parent 2',
            'subjectsMayBeAbsent' => true,
            'defaultRole' => 'parent',
            'defaultRoleWhenSubjectsAbsent' => 'enfant',
            'displayOrder' => 3,
            'prompt' => <<<TXT
Tu es un écrivain biographe de talent, spécialisé dans les sagas familiales et les récits de famille. Le livre est consacré au foyer de {sujets}.

- Le volume est proportionnel aux souvenirs réels fournis — ne boucle jamais sur la même idée.
- Style littéraire, émouvant, chaleureux et respectueux, {ton}.
TXT,
            'roles' => [
                ['code' => 'parent', 'label' => 'Parent (Père / Mère)'],
                ['code' => 'enfant', 'label' => 'Enfant'],
                ['code' => 'petit_enfant', 'label' => 'Petit-enfant'],
                ['code' => 'proche', 'label' => 'Proche'],
            ],
            'chapters' => [
                [
                    'code' => 'histoire_parents',
                    'title' => 'Chapitre 1 — 🌳 L\'Histoire des parents & Nos racines',
                    'speaker' => BookTypeChapter::SPEAKER_SYNTHESIS,
                    'prompt' => <<<TXT
Premier chapitre, consacré à l'histoire des parents et aux racines du foyer de {sujets}, rédigé à partir de tous les témoignages recueillis auprès de la famille.

Si les parents participent eux-mêmes :
- Registre de SYNTHÈSE à la 3ème personne (il/elle ou {prenom1} et {prenom2}).
- Retrace le début de cette histoire d'amour et la fondation de leur famille : les origines de chacun, leur jeunesse, les récits de leur rencontre (tels que racontés par eux-mêmes ou transmis avec affection par leurs enfants), leurs premiers temps ensemble et l'installation de leur premier chez-soi.
- Poursuis avec la fondation du foyer et la vie avec les enfants, les étapes marquantes traversées ensemble, et le regard admiratif porté sur leur histoire.
- Conclus par un magnifique paragraphe d'hommage à l'amour et au foyer qu'ils ont su bâtir.

Si les parents ne participent pas eux-mêmes (décédés ou non participants) — ANGLE D'HOMMAGE ET DE TRANSMISSION :
- Ce chapitre est un hommage filial rédigé à partir des récits, anecdotes et souvenirs transmis à leurs enfants et descendants.
- Registre de SYNTHÈSE STRICTEMENT À LA 3ÈME PERSONNE (« il/elle » ou « {prenom1} et {prenom2} »). Ne JAMAIS rédiger à la 1ère personne du couple (« Nous nous sommes rencontrés... »).
- Adopte des formules élégantes de mémoire familiale et de transmission (« Dans les souvenirs transmis au foyer... », « Leurs enfants se rappellent avec émotion de... », « Selon la mémoire familiale... »).
- Retrace la jeunesse de chacun, la légende de leur rencontre telle que transmise avec amour au sein de la famille, l'installation de leur premier chez-soi, puis l'arrivée des enfants, les traditions et valeurs de la maison et les souvenirs marquants de leur vie de famille.
- Conclus par un vibrant hommage à la mémoire de {sujets}, à la force de leur union et à l'héritage d'amour qu'ils ont légué à leurs descendants.

RÈGLE D'OR : si les enfants rapportent des détails ou versions légèrement différentes de la rencontre, ne tranche JAMAIS : tisse les récits comme la légende chaleureuse de la famille (« Pour l'un, c'était... tandis que pour l'autre, demeure le souvenir de... »).
TXT,
                ],
                [
                    'code' => 'regards_croises',
                    'title' => 'Chapitre 2 — 💬 Paroles d\'enfants',
                    'speaker' => BookTypeChapter::SPEAKER_CONTRIBUTORS,
                    'prompt' => <<<TXT
Recueil de témoignages réunissant les souvenirs des enfants envers {sujets}.
- Registre 100% VERBATIM à la 1ère personne du singulier (je) pour chaque contributeur.
- Chaque participant a son propre espace bien distinct sous son sous-titre nominatif : === Témoignage de [Prénom] ([Rôle]) ===
- Ne mélange JAMAIS les témoignages entre eux : garde la singularité, l'âge et la sensibilité de chacun.
- Développe en profondeur les anecdotes concrètes, les souvenirs d'enfance et les émotions partagées.
- Développe les souvenirs avec le père et avec la mère, l'ambiance du foyer et les valeurs transmises.
- Style chaleureux, vivant et littéraire.
- Une fois tous les témoignages rédigés, termine par un beau paragraphe de conclusion générale chaleureuse (sans nouveau sous-titre).
TXT,
                ],
                [
                    'code' => 'regards_petits_enfants',
                    'title' => 'Chapitre 3 — 🌱 La relève — Petits-enfants',
                    'speaker' => BookTypeChapter::SPEAKER_CONTRIBUTORS,
                    'prompt' => <<<TXT
Recueil de témoignages réunissant les souvenirs des petits-enfants envers {sujets}.
- Registre 100% VERBATIM à la 1ère personne du singulier (je) pour chaque contributeur.
- Chaque participant a son propre espace bien distinct sous son sous-titre nominatif : === Témoignage de [Prénom] ([Rôle]) ===
- Ne mélange JAMAIS les témoignages entre eux : garde la singularité, l'âge et la sensibilité de chacun.
- Les souvenirs des petits-enfants sont souvent courts et tendres : ne boucle jamais sur la même idée pour allonger artificiellement le texte. Privilégie la fraîcheur et la vérité du cœur.
- Style chaleureux, vivant et littéraire.
- Une fois tous les témoignages rédigés, termine par un beau paragraphe de conclusion générale chaleureuse (sans nouveau sous-titre).
TXT,
                ],
                [
                    'code' => 'rituels_et_valeurs',
                    'title' => 'Chapitre 4 — Rituels et valeurs',
                    'speaker' => BookTypeChapter::SPEAKER_CONTRIBUTORS,
                    'prompt' => <<<TXT
Chapitre célébrant les traditions, rituels et valeurs qui font l'âme de cette famille.
- Registre HYBRIDE mêlant fragments de témoignages directs des membres de la famille et récit narratif chaleureux.
- Fais revivre les grands rituels du foyer : les repas du dimanche, les recettes fétiches, les vacances inoubliables, les répliques cultes et expressions de la maison, ainsi que les valeurs fondamentales transmises par les parents.
- Sous-titres poétiques tous les 4 à 5 paragraphes (ex : === Autour de la table ===, === Les vacances qui nous unissent ===, === Ce qui nous a été transmis ===).
- Style vivant, plein de saveur, réconfortant et joyeux.
- Termine par une émouvante conclusion au nom de toute la famille.
TXT,
                ],
                [
                    'code' => 'epilogue_collectif',
                    'title' => 'Chapitre 5 — Épilogue collectif',
                    'speaker' => BookTypeChapter::SPEAKER_CONTRIBUTORS,
                    'prompt' => <<<TXT
Épilogue intime et vibrant adressé à {sujets} au nom de toute la famille.
- Si les parents participent eux-mêmes : rédige une lettre collective ou des messages successifs de chaque enfant/proche sous forme de déclaration d'amour, de gratitude et de fierté envers les parents.
- Si les parents ne participent pas eux-mêmes : rédige une lettre collective ou des hommages successifs au nom des enfants et petits-enfants, célébrant la mémoire de {sujets}, leur reconnaissance infinie pour leur amour et leur exemple, et affirmant la fidélité de la famille à leurs valeurs.
- Style profondément émouvant, chaleureux et noble, célébrant le bonheur d'avoir grandi auprès d'eux et formulant des vœux pour la pérennité du clan familial.
- Termine par une émouvante conclusion au nom de toute la famille.
TXT,
                ],
                [
                    'code' => 'histoire_aine',
                    'title' => 'Histoire de l\'aîné (ancien)',
                    'speaker' => BookTypeChapter::SPEAKER_SYNTHESIS,
                    'active' => false,
                    'prompt' => null,
                ],
            ],
        ];
    }

    private static function hommage(): array
    {
        return [
            'code' => 'hommage',
            'label' => 'Hommage',
            'description' => 'Un livre d\'hommage à un être cher disparu, nourri des témoignages de ses proches.',
            'family' => BookType::FAMILY_COLLECTIVE,
            'speakerCount' => null,
            'speaker1Label' => 'La personne honorée',
            'speaker2Label' => null,
            'subjectsMayBeAbsent' => false,
            'defaultRole' => null,
            'defaultRoleWhenSubjectsAbsent' => null,
            'displayOrder' => 4,
            'prompt' => <<<TXT
Tu es un écrivain biographe de grand talent, spécialisé dans les livres d'hommage et récits de mémoire familiale. Le livre est dédié à {prenom1} {dates}.
{exergue}

- Le volume doit être strictement proportionnel à la richesse du matériau fourni : ne tourne jamais en rond, ne boucle pas sur la même idée ou le même adjectif pour remplir de l'espace.
- N'invente pas d'événements majeurs non mentionnés.
- Style littéraire, noble, sensible et chaleureux, sans pathos excessif ni emphase larmoyante, {ton}.
TXT,
            'roles' => [
                ['code' => 'conjoint', 'label' => 'Conjoint'],
                ['code' => 'enfant', 'label' => 'Enfant'],
                ['code' => 'petit_enfant', 'label' => 'Petit-enfant'],
                ['code' => 'frere_soeur', 'label' => 'Frère / Sœur'],
                ['code' => 'ami', 'label' => 'Ami(e)'],
                ['code' => 'collegue', 'label' => 'Collègue'],
                ['code' => 'proche', 'label' => 'Proche'],
            ],
            'chapters' => [
                [
                    'code' => 'portrait_croise',
                    'title' => 'Chapitre 1 — Portrait croisé',
                    'speaker' => BookTypeChapter::SPEAKER_SYNTHESIS,
                    'prompt' => <<<TXT
Premier chapitre : portrait d'ensemble de {prenom1}, rédigé à partir de tous les témoignages de ses proches.
- Registre de SYNTHÈSE TRANSVERSALE à la 3ème personne (il/elle ou {prenom1}).
- Rédige un portrait d'ensemble polyphonique, sensible et vivant de {prenom1}.
- Tisse avec finesse et bienveillance les regards croisés de ses proches (conjoint, enfants, petits-enfants, amis, collègues).
- Mets en valeur les convergences (ce qui fait l'unanimité : tempérament, voix, rire, manies attachantes, générosité) tout en accueillant les nuances selon les époques et le lien propre à chacun.
- RÈGLE D'OR : ne tranche JAMAIS entre des mémoires divergentes ou contradictoires (ex : deux enfants se souvenant différemment d'un même été). Tisse délicatement les versions (« Pour l'un, c'était l'été des cabanes... tandis que pour l'autre, demeure le souvenir du silence des sous-bois... »).
- Respecte la vérité des relations complexes : ne pas édulcorer artificiellement les liens parfois difficiles, mais leur conférer une tonalité digne, respectueuse et réparatrice.
- Termine par une conclusion poignante, apaisée et digne pour clore ce portrait d'ensemble.
TXT,
                ],
                [
                    'code' => 'les_voix',
                    'title' => 'Chapitre 2 — Les voix',
                    'speaker' => BookTypeChapter::SPEAKER_CONTRIBUTORS,
                    'prompt' => <<<TXT
Recueil de témoignages des différents proches de {prenom1}.
- Registre 100% VERBATIM à la 1ère personne du singulier (je) pour chaque contributeur.
- Rédige le témoignage de CHAQUE contributeur séparément sous son sous-titre nominatif : === Témoignage de [Prénom] ([Rôle]) ===
- Ne mélange JAMAIS les témoignages entre eux : préserve la voix, le lien et la sensibilité propre à chaque proche.
- Développe en profondeur les anecdotes concrètes et les émotions partagées.
- Pour les petits-enfants ou les réponses plus courtes, privilégie l'émotion pure, la tendresse d'un geste ou d'un regard plutôt que de broder artificiellement.
- Style vivant, intime et chaleureux.
- Une fois tous les proches traités, termine par un ou deux beaux paragraphes de conclusion chorale pleine de reconnaissance (sans nouveau sous-titre de témoignage).
TXT,
                ],
                [
                    'code' => 'une_vie',
                    'title' => 'Chapitre 3 — Une vie',
                    'speaker' => BookTypeChapter::SPEAKER_SYNTHESIS,
                    'prompt' => <<<TXT
Grand récit biographique retraçant l'existence de {prenom1}, à partir des repères et souvenirs transmis par ses proches.
- Registre de SYNTHÈSE CHRONOLOGIQUE à la 3ème personne (il/elle ou {prenom1}).
- Reconstitue la trajectoire de sa vie comme un roman vrai et sensible, fondé rigoureusement sur les faits transmis par les proches.
- Commence par les origines, la jeunesse, l'entrée dans l'âge adulte, les racines familiales et les premières grandes étapes de sa vie ; poursuis avec la maturité, les accomplissements, les liens familiaux consolidés, les passions de la seconde partie de vie, jusqu'aux dernières années dans la dignité et la paix.
- Si une période a moins de souvenirs, concentre-toi sur l'atmosphère et les anecdotes réelles transmises sans délayer.
- Tisse les mémoires des proches sans trancher en cas de divergences de perception.
- Sous-titres poétiques marquant les époques.
- Style biographique de haute tenue littéraire, chaleureux et respectueux.
- Termine par un magnifique passage d'hommage et de transmission concluant son parcours.
TXT,
                ],
                [
                    'code' => 'ce_quil_nous_laisse',
                    'title' => 'Chapitre 4 — Ce qu\'il/elle nous laisse',
                    'speaker' => BookTypeChapter::SPEAKER_CONTRIBUTORS,
                    'prompt' => <<<TXT
Chapitre sur l'héritage vivant et les transmissions de {prenom1}.
- Registre HYBRIDE mêlant fragments de témoignages directs et tissu narratif délicat.
- Mets en lumière ce que {prenom1} a légué aux siens : ses expressions fétiches, ses habitudes et petits rituels, les gestes transmis, les passions partagées, ses valeurs fondamentales, des objets symboliques.
- Célèbre ce qui continue de vivre à travers les proches, avec bienveillance et tendresse.
- Sous-titres poétiques tous les 4-5 paragraphes (ex : === Les mots qui demeurent ===, === Les gestes partagés ===).
- Style chaleureux, vivant, lumineux et réconfortant.
- Termine par un magnifique passage sur la pérennité de sa mémoire au sein des générations futures.
TXT,
                ],
                [
                    'code' => 'ce_quon_aurait_voulu_dire',
                    'title' => 'Chapitre 5 — Ce qu\'on aurait voulu dire',
                    'speaker' => BookTypeChapter::SPEAKER_CONTRIBUTORS,
                    'prompt' => <<<TXT
Épilogue intime : les messages et confidences des proches adressés à {prenom1}.
- Registre de MESSAGES DIRECTS adressés à {prenom1} (tutoiement ou vouvoiement selon la relation).
- Présente le mot de chaque proche avec son sous-titre : === Pour toi, {prenom1} — De [Prénom] ([Rôle]) ===
- Rédige des messages vibrants, sincères, émouvants et pudiques : ce qu'on n'a pas eu le temps de lui dire, la gratitude éternelle, une promesse, un souvenir indélébile.
- Ne délaie pas : privilégie l'intensité, la sincérité et la justesse de chaque message.
- Style littéraire, poétique, apaisé et profondément touchant.
- Une fois tous les messages rédigés, termine par une phrase finale ou un court paragraphe d'adieu apaisé.
TXT,
                ],
            ],
        ];
    }
}
