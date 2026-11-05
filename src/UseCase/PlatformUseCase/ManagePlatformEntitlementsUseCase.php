<?php

namespace App\UseCase\PlatformUseCase;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeaturePolicyRepository;
use App\Services\Platform\FeatureRequestRepository;
use App\Message\EntitlementChangedMessage;
use App\Services\Platform\PlatformConsoleService;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Actions du super administrateur sur les droits d'usage (console de pilotage, lot 4, 04/11/2026), partagées par l'API `/api/platform/*`
 * et la page EasyAdmin « Plateforme » : accorder (manuel, essai, gratuit), suspendre, rétablir, annuler, prolonger, politiques,
 * décision sur une demande. Validation ici (PlatformValidationException : erreurs par champ), règles métier dans EntitlementService
 * (DomainException : politique, état incompatible ; OutOfBoundsException : introuvable).
 */
class ManagePlatformEntitlementsUseCase
{
    public const SOURCES = ['manual', 'trial', 'free'];
    public const ACTIONS = ['suspend', 'resume', 'cancel', 'extend'];

    public function __construct(private readonly EntitlementService $entitlements, private readonly FeaturePolicyRepository $policies, private readonly FeatureRequestRepository $requests, private readonly PlatformConsoleService $console, private readonly MessageBusInterface $bus)
    {
    }

    /**
     * @param array<string, mixed> $data { tenant, feature, source, days?, endsAt?, note? }
     * @return array<string, mixed> le droit créé
     */
    public function grant(array $data, ?string $by): array
    {
        $errors = [];
        $tenant = is_string($data['tenant'] ?? null) ? trim($data['tenant']) : '';
        if ($tenant === '' || !$this->console->tenantExists($tenant)) {
            $errors[] = ['path' => 'tenant', 'message' => 'code de site existant attendu'];
        }
        $feature = is_string($data['feature'] ?? null) ? trim($data['feature']) : '';
        if (!isset(FeatureCatalog::GOVERNED[$feature])) {
            $errors[] = ['path' => 'feature', 'message' => 'fonctionnalité gouvernée attendue : ' . implode(', ', array_keys(FeatureCatalog::GOVERNED))];
        }
        $source = is_string($data['source'] ?? null) ? trim($data['source']) : 'manual';
        if (!in_array($source, self::SOURCES, true)) {
            $errors[] = ['path' => 'source', 'message' => 'manual, trial ou free attendu'];
        }
        $days = $data['days'] ?? null;
        if ($days !== null && $days !== '' && (!is_numeric($days) || (int) $days < 1 || (int) $days > 730)) {
            $errors[] = ['path' => 'days', 'message' => 'entier de 1 à 730 attendu'];
        }
        $endsAt = self::date($data['endsAt'] ?? null, $errors, 'endsAt');
        $note = self::note($data['note'] ?? null);
        if ($errors !== []) {
            throw new PlatformValidationException($errors);
        }

        return match ($source) {
            'trial' => $this->entitlements->trial($tenant, $feature, $days !== null && $days !== '' ? (int) $days : null, $by, $note),
            'free' => $this->entitlements->free($tenant, $feature, $by, $note),
            default => $this->entitlements->grant($tenant, $feature, 'manual', $endsAt ?? ($days !== null && $days !== '' ? (new \DateTimeImmutable())->modify('+' . (int) $days . ' days') : null), $by, $note),
        };
    }

    /** @param array<string, mixed> $data { action, endsAt?, note? } */
    public function act(int $id, array $data, ?string $by): array
    {
        $errors = [];
        $action = is_string($data['action'] ?? null) ? trim($data['action']) : '';
        if (!in_array($action, self::ACTIONS, true)) {
            $errors[] = ['path' => 'action', 'message' => implode(', ', self::ACTIONS) . ' attendu'];
        }
        $endsAt = self::date($data['endsAt'] ?? null, $errors, 'endsAt');
        if ($action === 'extend' && $endsAt === null && !array_key_exists('endsAt', $data)) {
            $errors[] = ['path' => 'endsAt', 'message' => 'date de fin attendue (null pour un droit sans fin)'];
        }
        if ($errors !== []) {
            throw new PlatformValidationException($errors);
        }
        $note = self::note($data['note'] ?? null);

        return match ($action) {
            'suspend' => $this->entitlements->suspend($id, $by, $note),
            'resume' => $this->entitlements->resume($id, $by, $note),
            'cancel' => $this->entitlements->cancel($id, $by, $note),
            default => $this->entitlements->extend($id, $endsAt, $by, $note),
        };
    }

    /** @param array<string, mixed> $data { trialAllowed?, trialDays?, freeAllowed?, graceDays? } */
    public function policy(string $feature, array $data, ?string $by): array
    {
        $errors = [];
        if (!isset(FeatureCatalog::GOVERNED[$feature])) {
            $errors[] = ['path' => 'feature', 'message' => 'fonctionnalité gouvernée attendue'];
        }
        $values = [];
        foreach (['trialAllowed' => 'trial_allowed', 'freeAllowed' => 'free_allowed'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $bool = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    $errors[] = ['path' => $key, 'message' => 'booléen attendu'];
                } else {
                    $values[$column] = $bool;
                }
            }
        }
        foreach (['trialDays' => ['trial_days', 1, 730], 'graceDays' => ['grace_days', 0, 90]] as $key => [$column, $min, $max]) {
            if (array_key_exists($key, $data)) {
                if (!is_numeric($data[$key]) || (int) $data[$key] < $min || (int) $data[$key] > $max) {
                    $errors[] = ['path' => $key, 'message' => sprintf('entier de %d à %d attendu', $min, $max)];
                } else {
                    $values[$column] = (int) $data[$key];
                }
            }
        }
        if ($errors !== []) {
            throw new PlatformValidationException($errors);
        }

        return $this->policies->upsert($feature, $values, $by);
    }

    /**
     * Décision sur une demande d'un site : `grant` (source manual | trial | free, days?) accorde puis clôt ; `refuse` clôt.
     * @param array<string, mixed> $data { action, source?, days?, note? }
     * @return array{request: array<string, mixed>, entitlement: ?array<string, mixed>}
     */
    public function decide(int $requestId, array $data, ?string $by): array
    {
        $request = $this->requests->find($requestId);
        if ($request === null) {
            throw new \OutOfBoundsException(sprintf('demande n° %d introuvable', $requestId));
        }
        if ($request['status'] !== 'pending') {
            throw new \DomainException('Demande déjà traitée');
        }
        $action = is_string($data['action'] ?? null) ? trim($data['action']) : '';
        if (!in_array($action, ['grant', 'refuse'], true)) {
            throw new PlatformValidationException([['path' => 'action', 'message' => 'grant ou refuse attendu']]);
        }
        $entitlement = null;
        if ($action === 'grant') {
            $entitlement = $this->grant(['tenant' => $request['tenant_code'], 'feature' => $request['feature_key'], 'source' => $data['source'] ?? 'manual', 'days' => $data['days'] ?? null, 'note' => $data['note'] ?? ('demande n° ' . $requestId)], $by);
        }
        $this->requests->decide($requestId, $action === 'grant' ? 'granted' : 'refused', $by);
        if ($action === 'refuse') {
            try {
                $this->bus->dispatch(new EntitlementChangedMessage($request['tenant_code'], $request['feature_key'], 'request_refused', $requestId, ['by' => $by, 'note' => $data['note'] ?? null]));
            } catch (\Throwable) {
            }
        }

        return ['request' => $this->requests->find($requestId), 'entitlement' => $entitlement];
    }

    private static function date(mixed $raw, array &$errors, string $path): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            $errors[] = ['path' => $path, 'message' => 'date ISO 8601 attendue'];

            return null;
        }
        try {
            $date = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            $errors[] = ['path' => $path, 'message' => 'date ISO 8601 attendue'];

            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $date = $date->setTime(23, 59, 59); // une date seule : fin de journée
        }

        return $date;
    }

    private static function note(mixed $raw): ?string
    {
        return is_string($raw) && trim($raw) !== '' ? mb_substr(trim($raw), 0, 500) : null;
    }
}
