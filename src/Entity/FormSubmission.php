<?php

namespace App\Entity;

use App\Repository\FormSubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande reçue par un formulaire à étapes d'une composition réglable (landing page ou boutique, 28/10/2026).
 * Les réponses gardent le libellé de chaque question au moment de l'envoi : la demande reste lisible si le formulaire
 * change ensuite. Aucune adresse IP n'est conservée. Supprimée après Entreprise::getFormSubmissionsRetentionMonths().
 */
#[ORM\Entity(repositoryClass: FormSubmissionRepository::class)]
#[ORM\Table(name: 'form_submission')]
class FormSubmission
{
    public const STATUSES = ['new', 'read', 'done', 'archived'];
    public const APPS = ['landingpage', 'boutique'];
    public const TARGETS = ['generic', 'reservation', 'contact'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $reference;

    #[ORM\Column(length: 255)]
    private string $formName = '';

    #[ORM\Column(length: 20)]
    private string $app;

    #[ORM\Column(length: 100)]
    private string $sectionUid;

    #[ORM\Column(length: 100)]
    private string $formId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $formHash = null;

    /** @var list<array{name: string, label: string, type: string, value: mixed, display: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    #[ORM\Column(length: 5)]
    private string $locale = 'fr';

    #[ORM\Column(length: 20)]
    private string $status = 'new';

    #[ORM\Column(length: 20)]
    private string $target = 'generic';

    #[ORM\Column(nullable: true)]
    private ?int $reservationId = null;

    #[ORM\Column(nullable: true)]
    private ?int $contactId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $reference, string $app, string $sectionUid, string $formId)
    {
        $this->reference = $reference;
        $this->app = $app;
        $this->sectionUid = $sectionUid;
        $this->formId = $formId;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getFormName(): string { return $this->formName; }
    public function setFormName(string $formName): static { $this->formName = mb_substr($formName, 0, 255); return $this; }
    public function getApp(): string { return $this->app; }
    public function getSectionUid(): string { return $this->sectionUid; }
    public function getFormId(): string { return $this->formId; }
    public function getFormHash(): ?string { return $this->formHash; }
    public function setFormHash(?string $formHash): static { $this->formHash = $formHash; return $this; }
    /** @return list<array{name: string, label: string, type: string, value: mixed, display: string}> */
    public function getAnswers(): array { return $this->answers; }
    public function setAnswers(array $answers): static { $this->answers = array_values($answers); return $this; }
    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }
    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Statut inconnu : $status");
        }
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getTarget(): string { return $this->target; }
    public function setTarget(string $target): static { $this->target = $target; return $this; }
    public function getReservationId(): ?int { return $this->reservationId; }
    public function setReservationId(?int $id): static { $this->reservationId = $id; return $this; }
    public function getContactId(): ?int { return $this->contactId; }
    public function setContactId(?int $id): static { $this->contactId = $id; return $this; }
    public function getNotifiedAt(): ?\DateTimeImmutable { return $this->notifiedAt; }
    public function setNotifiedAt(?\DateTimeImmutable $at): static { $this->notifiedAt = $at; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Réponses en texte (EasyAdmin, export) : « Libellé : réponse » par ligne */
    public function getAnswersText(): string
    {
        return implode("\n", array_map(fn (array $a) => sprintf('%s : %s', $a['label'] ?? $a['name'] ?? '', $a['display'] ?? ''), $this->answers));
    }
}
