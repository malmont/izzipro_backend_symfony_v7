<?php

namespace App\ESG\DataFixtures;

use App\ESG\Entity\CertificationReferential;
use App\ESG\Entity\DiagnosticQuestion;
use App\ESG\Entity\EsgCompany;
use App\ESG\Entity\EsgUser;
use App\ESG\Entity\SubsidyProgram;
use App\ESG\Enum\AnswerTypeEnum;
use App\ESG\Enum\DomainEnum;
use App\ESG\Enum\SectorEnum;
use App\ESG\Enum\SizeEnum;
use App\ESG\Enum\TerritoryEnum;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class EsgFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // 1. Create Subsidies
        $subsidyPrograms = [];

        $sub1 = new SubsidyProgram();
        $sub1->setCode('sub_hq_efficacite');
        $sub1->setName('Aide Financière Hydro-Québec Efficacité');
        $sub1->setOrganism('Hydro-Québec');
        $sub1->setTerritory(TerritoryEnum::QUEBEC);
        $sub1->setSubsidyRatePercent(50.0);
        $sub1->setMaxAmountCad(5000);
        $sub1->setConditions('Réservé aux entreprises du Québec réalisant des travaux d\'efficacité énergétique.');
        $manager->persist($sub1);
        $subsidyPrograms['sub_hq_efficacite'] = $sub1;

        $sub2 = new SubsidyProgram();
        $sub2->setCode('sub_transition_canada');
        $sub2->setName('Fonds pour la Transition Écologique du Canada');
        $sub2->setOrganism('Gouvernement du Canada');
        $sub2->setTerritory(TerritoryEnum::CANADA);
        $sub2->setSubsidyRatePercent(40.0);
        $sub2->setMaxAmountCad(10000);
        $sub2->setConditions('Ouvert à toutes les PME canadiennes engagées dans une démarche de certification ESG.');
        $manager->persist($sub2);
        $subsidyPrograms['sub_transition_canada'] = $sub2;

        $sub3 = new SubsidyProgram();
        $sub3->setCode('sub_ademe_mq');
        $sub3->setName('Aide ADEME Martinique Transition Écologique');
        $sub3->setOrganism('ADEME Martinique');
        $sub3->setTerritory(TerritoryEnum::MARTINIQUE);
        $sub3->setSubsidyRatePercent(60.0);
        $sub3->setMaxAmountCad(8000);
        $sub3->setConditions('Destiné aux projets de transition écologique et de labellisation RSE en Martinique.');
        $manager->persist($sub3);
        $subsidyPrograms['sub_ademe_mq'] = $sub3;

        $sub4 = new SubsidyProgram();
        $sub4->setCode('sub_fed_caraibes');
        $sub4->setName('Fonds de Développement Durable des Caraïbes');
        $sub4->setOrganism('Union Européenne / FEDER');
        $sub4->setTerritory(TerritoryEnum::CARIB);
        $sub4->setSubsidyRatePercent(75.0);
        $sub4->setMaxAmountCad(15000);
        $sub4->setConditions('Soutien aux projets d\'écotourisme et d\'économie circulaire dans l\'espace Caraïbes.');
        $manager->persist($sub4);
        $subsidyPrograms['sub_fed_caraibes'] = $sub4;

        // 2. Create Certifications
        $certifications = [
            [
                'code' => 'b_corp',
                'name' => 'Certification B Corp',
                'category' => 'globale',
                'version' => 'v1',
                'thresholdEnvironment' => 50.0,
                'thresholdGovernance' => 50.0,
                'thresholdSocial' => 50.0,
                'thresholdClimate' => 50.0,
                'thresholdGlobal' => 60.0,
                'certLevel' => 'gold',
                'durationMinMonths' => 6,
                'durationMaxMonths' => 12,
                'costMinCad' => 2000,
                'costMaxCad' => 10000,
                'description' => 'B Corp certifie les entreprises qui répondent à des normes élevées de performance sociale et environnementale, de transparence et de responsabilité.',
                'marketImpact' => 'Impact commercial mondial, valorisation de la marque employeur et accès à un réseau d\'entreprises engagées.',
                'territory' => ['QC', 'MQ', 'CA', 'CB', 'INT'],
                'subventions' => ['sub_hq_efficacite', 'sub_transition_canada', 'sub_fed_caraibes']
            ],
            [
                'code' => 'ecovadis',
                'name' => 'Évaluation EcoVadis',
                'category' => 'globale',
                'version' => 'v1',
                'thresholdEnvironment' => 40.0,
                'thresholdGovernance' => 40.0,
                'thresholdSocial' => 40.0,
                'thresholdClimate' => 40.0,
                'thresholdGlobal' => 45.0,
                'certLevel' => 'silver',
                'durationMinMonths' => 3,
                'durationMaxMonths' => 6,
                'costMinCad' => 1500,
                'costMaxCad' => 5000,
                'description' => 'EcoVadis évalue la qualité de l\'intégration de la RSE dans le système de management des entreprises à travers leur chaîne d\'approvisionnement.',
                'marketImpact' => 'Exigence clé pour de nombreux grands donneurs d\'ordres internationaux.',
                'territory' => ['QC', 'CA', 'MQ', 'CB', 'INT'],
                'subventions' => ['sub_hq_efficacite', 'sub_transition_canada', 'sub_fed_caraibes']
            ],
            [
                'code' => 'green_key',
                'name' => 'Green Key',
                'category' => 'tourisme',
                'version' => 'v1',
                'thresholdEnvironment' => 65.0,
                'thresholdGovernance' => 40.0,
                'thresholdSocial' => 40.0,
                'thresholdClimate' => 50.0,
                'thresholdGlobal' => 55.0,
                'certLevel' => 'standard',
                'durationMinMonths' => 4,
                'durationMaxMonths' => 8,
                'costMinCad' => 1000,
                'costMaxCad' => 3000,
                'description' => 'Premier label environnemental international pour les hébergements touristiques et les restaurants.',
                'marketImpact' => 'Attractivité touristique accrue auprès de la clientèle éco-sensible.',
                'territory' => ['MQ', 'CB', 'INT'],
                'subventions' => ['sub_ademe_mq', 'sub_fed_caraibes']
            ],
            [
                'code' => 'ici_recycle',
                'name' => 'ICI on Recycle + (Québec)',
                'category' => 'environnement',
                'version' => 'v1',
                'thresholdEnvironment' => 60.0,
                'thresholdGovernance' => 30.0,
                'thresholdSocial' => 30.0,
                'thresholdClimate' => 30.0,
                'thresholdGlobal' => 40.0,
                'certLevel' => 'standard',
                'durationMinMonths' => 2,
                'durationMaxMonths' => 5,
                'costMinCad' => 500,
                'costMaxCad' => 2000,
                'description' => 'Programme de reconnaissance québécois pour la saine gestion des matières résiduelles en entreprise.',
                'marketImpact' => 'Reconnaissance officielle locale et économies sur la gestion des déchets.',
                'territory' => ['QC', 'CA'],
                'subventions' => ['sub_hq_efficacite']
            ]
        ];

        foreach ($certifications as $cData) {
            $cert = new CertificationReferential();
            $cert->setCode($cData['code']);
            $cert->setName($cData['name']);
            $cert->setCategory($cData['category']);
            $cert->setVersion($cData['version']);
            $cert->setThresholdEnvironment($cData['thresholdEnvironment']);
            $cert->setThresholdGovernance($cData['thresholdGovernance']);
            $cert->setThresholdSocial($cData['thresholdSocial']);
            $cert->setThresholdClimate($cData['thresholdClimate']);
            $cert->setThresholdGlobal($cData['thresholdGlobal']);
            $cert->setCertLevel($cData['certLevel']);
            $cert->setDurationMinMonths($cData['durationMinMonths']);
            $cert->setDurationMaxMonths($cData['durationMaxMonths']);
            $cert->setCostMinCad($cData['costMinCad']);
            $cert->setCostMaxCad($cData['costMaxCad']);
            $cert->setDescription($cData['description']);
            $cert->setMarketImpact($cData['marketImpact']);
            $cert->setTerritory($cData['territory']);
            $cert->setIsActive(true);

            // Link subsidies
            foreach ($cData['subventions'] as $sCode) {
                if (isset($subsidyPrograms[$sCode])) {
                    $cert->addSubsidyProgram($subsidyPrograms[$sCode]);
                }
            }

            $manager->persist($cert);
        }

        // 3. Create 16 Diagnostic Questions
        $questions = [
            // ENVIRONNEMENT (1-4)
            [
                'domain' => DomainEnum::ENVIRONMENT,
                'text' => 'Avez-vous une politique formalisée de gestion et de réduction de vos déchets ?',
                'help' => 'Par exemple : recyclage systématique, compostage, réduction du papier ou réutilisation des matériaux.',
                'weight' => 2,
                'type' => AnswerTypeEnum::BINARY,
                'order' => 1
            ],
            [
                'domain' => DomainEnum::ENVIRONMENT,
                'text' => 'Mesurez-vous et suivez-vous annuellement votre consommation d\'eau et d\'énergie ?',
                'help' => 'Le suivi par factures ou par compteurs intelligents permet d\'identifier les fuites et gaspillages.',
                'weight' => 1,
                'type' => AnswerTypeEnum::TERNARY,
                'order' => 2
            ],
            [
                'domain' => DomainEnum::ENVIRONMENT,
                'text' => 'Avez-vous mis en place des actions concrètes pour réduire l\'usage du plastique à usage unique ?',
                'help' => 'Par exemple : suppression des gobelets, des bouteilles plastiques, ou usage de contenants réutilisables.',
                'weight' => 2,
                'type' => AnswerTypeEnum::BINARY,
                'order' => 3
            ],
            [
                'domain' => DomainEnum::ENVIRONMENT,
                'text' => 'Privilégiez-vous des fournisseurs locaux et éco-responsables dans vos achats ?',
                'help' => 'Intégration de critères écologiques ou de proximité dans vos critères de choix de fournisseurs.',
                'weight' => 3,
                'type' => AnswerTypeEnum::TERNARY,
                'order' => 4
            ],

            // GOUVERNANCE (5-8)
            [
                'domain' => DomainEnum::GOVERNANCE,
                'text' => 'Votre entreprise dispose-t-elle d\'un code d\'éthique ou de conduite formalisé et diffusé ?',
                'help' => 'Un document écrit qui résume les valeurs et règles de comportement de l\'entreprise.',
                'weight' => 2,
                'type' => AnswerTypeEnum::BINARY,
                'order' => 5
            ],
            [
                'domain' => DomainEnum::GOVERNANCE,
                'text' => 'Les enjeux ESG/RSE sont-ils régulièrement intégrés aux discussions de la direction ?',
                'help' => 'La direction aborde ces thèmes lors de ses réunions mensuelles ou annuelles.',
                'weight' => 3,
                'type' => AnswerTypeEnum::TERNARY,
                'order' => 6
            ],
            [
                'domain' => DomainEnum::GOVERNANCE,
                'text' => 'Publiez-vous un rapport ou un bilan annuel transparent sur vos performances ?',
                'help' => 'Communication transparente des résultats financiers et environnementaux/sociaux.',
                'weight' => 1,
                'type' => AnswerTypeEnum::BINARY,
                'order' => 7
            ],
            [
                'domain' => DomainEnum::GOVERNANCE,
                'text' => 'Avez-vous mis en place un processus d\'alerte éthique interne ?',
                'help' => 'Un canal sécurisé permettant aux salariés de signaler des comportements déviants ou illégaux.',
                'weight' => 2,
                'type' => AnswerTypeEnum::BINARY,
                'order' => 8
            ],

            // SOCIAL (9-12)
            [
                'domain' => DomainEnum::SOCIAL,
                'text' => 'Suivez-vous des indicateurs de parité hommes-femmes et d\'égalité salariale ?',
                'help' => 'Suivi régulier du ratio de rémunération et de la parité dans les postes de direction.',
                'weight' => 2,
                'type' => AnswerTypeEnum::TERNARY,
                'order' => 9
            ],
            [
                'domain' => DomainEnum::SOCIAL,
                'text' => 'Proposez-vous des programmes de formation continue pour vos salariés ?',
                'help' => 'Au moins une formation par an pour chaque collaborateur pour développer ses compétences.',
                'weight' => 2,
                'type' => AnswerTypeEnum::TERNARY,
                'order' => 10
            ],
            [
                'domain' => DomainEnum::SOCIAL,
                'text' => 'Avez-vous mis en place des mesures de flexibilité pour favoriser l\'équilibre vie pro / vie privée ?',
                'help' => 'Télétravail, horaires flexibles, congés parentaux bonifiés, etc.',
                'weight' => 1,
                'type' => AnswerTypeEnum::BINARY,
                'order' => 11
            ],
            [
                'domain' => DomainEnum::SOCIAL,
                'text' => 'Réalisez-vous régulièrement des enquêtes de satisfaction interne pour mesurer le climat social ?',
                'help' => 'Sondage anonyme régulier pour évaluer le bien-être au travail.',
                'weight' => 2,
                'type' => AnswerTypeEnum::BINARY,
                'order' => 12
            ],

            // CLIMATE ACTIVATOR (13-16)
            [
                'domain' => DomainEnum::CLIMATE,
                'text' => 'Avez-vous réalisé un bilan carbone (Scope 1, 2 ou 3) au cours des 3 dernières années ?',
                'help' => 'Estimation globale de l\'empreinte carbone directe et indirecte générée par l\'activité.',
                'weight' => 3,
                'type' => AnswerTypeEnum::TERNARY,
                'order' => 13
            ],
            [
                'domain' => DomainEnum::CLIMATE,
                'text' => 'Avez-vous défini des objectifs chiffrés de réduction de vos émissions de gaz à effet de serre (GES) ?',
                'help' => 'Par exemple : réduire les émissions de 20% d\'ici 2030.',
                'weight' => 2,
                'type' => AnswerTypeEnum::BINARY,
                'order' => 14
            ],
            [
                'domain' => DomainEnum::CLIMATE,
                'text' => 'Utilisez-vous des sources d\'énergie renouvelable pour couvrir vos besoins ?',
                'help' => 'Panneaux solaires sur site, contrat d\'énergie verte certifiée, etc.',
                'weight' => 2,
                'type' => AnswerTypeEnum::TERNARY,
                'order' => 15
            ],
            [
                'domain' => DomainEnum::CLIMATE,
                'text' => 'Sensibilisez-vous ou formez-vous vos collaborateurs aux éco-gestes et à la transition climatique ?',
                'help' => 'Atelier Fresque du Climat, guides d\'éco-gestes au bureau, etc.',
                'weight' => 1,
                'type' => AnswerTypeEnum::BINARY,
                'order' => 16
            ]
        ];

        foreach ($questions as $qData) {
            $question = new DiagnosticQuestion();
            $question->setDomain($qData['domain']);
            $question->setQuestionText($qData['text']);
            $question->setHelpText($qData['help']);
            $question->setWeight($qData['weight']);
            $question->setAnswerType($qData['type']);
            $question->setDisplayOrder($qData['order']);
            $question->setIsActive(true);

            $manager->persist($question);
        }

        // 4. Create Demo Company
        $company = new EsgCompany();
        $company->setName('ESG Boost Demo');
        $company->setSector(SectorEnum::OTHER);
        $company->setSizeCategory(SizeEnum::MD);
        $company->setTerritory(TerritoryEnum::QUEBEC);
        $company->setContactEmail('contact@boussoleesg.com');
        $company->setCity('Montréal');
        $company->setWebsite('https://boussoleesg.com');
        $company->setExistingCertifications([]);
        $company->setIsActive(true);

        $manager->persist($company);

        // 5. Create Demo User
        $user = new EsgUser();
        $user->setEmail('test@boussoleesg.com');
        $user->setFirstName('Jean');
        $user->setLastName('Dupont');
        $user->setRoles(['ROLE_COMPANY']);
        $user->setCompany($company);
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setLocale('fr_CA');

        $hashedPassword = $this->passwordHasher->hashPassword($user, 'Demo2026!');
        $user->setPassword($hashedPassword);

        $manager->persist($user);

        $manager->flush();
    }
}
