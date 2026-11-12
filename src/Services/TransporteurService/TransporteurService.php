<?php
namespace App\Services\TransporteurService;

use App\Entity\Transporteur;
use App\Services\TenantEntityManagerProvider; 

class TransporteurService
{
    private TenantEntityManagerProvider $emProvider;

    /** Transporteur créé par la lecture des factures fournisseurs, retrouvé par son nom : il ne se renomme pas */
    public const RESERVED_NAME = \App\Gestion\UseCase\ConfirmGestionDocumentUseCase::SHIPPING_CARRIER;

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * Modification partielle d'un transporteur (lot EDIT, 12/11/2026) : seules les clés envoyées changent ; `contact` et `logo` vides ou
     * null sont effacés. Écrit une entrée au journal des contenus.
     *
     * @param array<string, mixed> $data
     */
    public function update(Transporteur $transporteur, array $data): Transporteur
    {
        $input = new \App\Gestion\Services\GestionInput($data);
        $before = ['name' => $transporteur->getName(), 'logo' => $transporteur->getLogo(), 'contact' => $transporteur->getContact()];
        $name = array_key_exists('name', $data) ? $input->text('name') : null;
        $input->check();
        if ($name !== null && $name !== $transporteur->getName() && $transporteur->getName() === self::RESERVED_NAME) {
            throw new \App\Gestion\Services\GestionConflictException('TRANSPORTEUR_RESERVED', 'Ce transporteur sert aux frais de port lus sur les factures : il ne peut pas être renommé.');
        }
        if ($name !== null && self::isReserved($name) && !self::isReserved((string) $transporteur->getName())) {
            throw new \App\Gestion\Services\GestionConflictException('TRANSPORTEUR_RESERVED', 'Ce nom est réservé aux frais de port lus sur les factures.');
        }
        if ($name !== null) {
            $transporteur->setName($name);
        }
        if (array_key_exists('contact', $data)) {
            $transporteur->setContact($input->text('contact', false));
        }
        if (array_key_exists('logo', $data)) {
            $transporteur->setLogo($input->text('logo', false));
        }
        $this->emProvider->getEntityManager()->flush();
        $after = ['name' => $transporteur->getName(), 'logo' => $transporteur->getLogo(), 'contact' => $transporteur->getContact()];
        if ($after !== $before) {
            $this->audit?->record('transporteurs', $transporteur->getId(), 'update', $before, $after, array_keys(array_diff_assoc($after, $before)));
        }

        return $transporteur;
    }

    /**
     * Ordre stable (lot EDIT-JOURNAL, 12/11/2026) : par nom sans tenir compte de la casse, puis par identifiant. Avant : aucun ordre
     * (`findAll`), et un transporteur modifié passait en fin de liste.
     *
     * @return list<Transporteur>
     */
    public function getAllTransporteurs(): array
    {
        return $this->emProvider->getEntityManager()->getRepository(Transporteur::class)->createQueryBuilder('t')
            ->addSelect('LOWER(t.name) AS HIDDEN sortName')->orderBy('sortName', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();
    }

    /** Vrai pour le nom réservé, sans tenir compte de la casse ni des espaces autour */
    public static function isReserved(string $name): bool
    {
        return mb_strtolower(trim($name)) === mb_strtolower(self::RESERVED_NAME);
    }

    public function createTransporteur(string $name, ?string $logo, ?string $contact): Transporteur
    {
        $em = $this->emProvider->getEntityManager();
        // lot EDIT-JOURNAL (12/11/2026) : nom obligatoire (avant : un nom vide était enregistré), nom réservé refusé (la lecture de
        // factures retrouverait le mauvais transporteur), logo et contact vides enregistrés null
        $name = trim($name);
        if ($name === '') {
            throw new \App\Gestion\Services\GestionValidationException(['name' => 'REQUIRED']);
        }
        if (self::isReserved($name)) {
            throw new \App\Gestion\Services\GestionConflictException('TRANSPORTEUR_RESERVED', 'Ce nom est réservé aux frais de port lus sur les factures.');
        }

        $transporteur = new Transporteur();
        $transporteur->setName(mb_substr($name, 0, 255));
        $transporteur->setLogo(\App\Gestion\Services\GestionInput::blank($logo));
        $transporteur->setContact(\App\Gestion\Services\GestionInput::blank($contact));

        $em->persist($transporteur);
        $em->flush();

        return $transporteur;
    }

    public function deleteTransporteur(Transporteur $transporteur): void
    {
        $em = $this->emProvider->getEntityManager();
        // lot EDIT-JOURNAL (12/11/2026) : le transporteur des factures lues par IA ne se supprime pas ; suppression inscrite au journal
        if (self::isReserved((string) $transporteur->getName())) {
            throw new \App\Gestion\Services\GestionConflictException('TRANSPORTEUR_RESERVED', 'Ce transporteur sert aux frais de port lus sur les factures : il ne peut pas être supprimé.');
        }
        // lot G4 (09/11/2026) : refusé s'il est utilisé par un frais de port ou une statistique figée (avant : violation de clé étrangère)
        if (!$transporteur->getFraisDePorts()->isEmpty() || !$transporteur->getCommandeStatistiques()->isEmpty()) {
            throw new \App\Gestion\Services\GestionConflictException('TRANSPORTEUR_IN_USE', 'Ce transporteur est utilisé par des frais de port : il ne peut pas être supprimé.');
        }
        $id = $transporteur->getId();
        $before = ['name' => $transporteur->getName(), 'logo' => $transporteur->getLogo(), 'contact' => $transporteur->getContact()];
        $em->remove($transporteur);
        $em->flush();
        $this->audit?->record('transporteurs', $id, 'delete', $before, null);
    }
}