<?php

namespace App\Services\AppKeyService;

use App\Dto\AppKeyInputDto;
use App\Entity\Entreprise;
use App\UseCase\AppKeyUseCase\GenerateAppKeyUseCase;
use Psr\Log\LoggerInterface;

/**
 * Activation des applications mobiles depuis la fiche Entreprise (EasyAdmin, 15/10/2026), comme les autres projets :
 * passer un indicateur à vrai génère la première clé de l'application et l'envoie (clé + code QR) au contact du
 * site ; le passer à faux révoque toutes les clés actives de l'application. Les clés restent gérables une à une dans
 * l'écran « Clés des applications ».
 */
class AppActivationService
{
    /** application → propriété de Entreprise (changeset Doctrine) et lecteur */
    public const FLAGS = [
        'pos' => 'isPosActive',
        'mobile' => 'isMobileShopActive',
        'admin' => 'isManagementAppActive',
        'livingmemory' => 'isLivingMemoryActive',
    ];

    public function __construct(private readonly GenerateAppKeyUseCase $generate, private readonly AppKeyService $keys, private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet changements de la fiche (UnitOfWork::getEntityChangeSet)
     * @return array<string, string> application → ce qui a été fait (« clé envoyée à … », « N clé(s) révoquée(s) »)
     */
    public function applyChanges(array $changeSet, string $tenantCode, string $createdBy, string $domain): array
    {
        $done = [];
        foreach (self::FLAGS as $app => $property) {
            if (!isset($changeSet[$property])) {
                continue;
            }
            [$before, $after] = $changeSet[$property];
            if (!$before && $after) {
                $done[$app] = $this->activate($app, $createdBy, $domain);
            } elseif ($before && !$after) {
                $count = $this->keys->revokeAllForApp($tenantCode, $app);
                $done[$app] = sprintf('%d clé(s) révoquée(s)', $count);
            }
        }

        return $done;
    }

    public function isActive(Entreprise $entreprise, string $app): bool
    {
        $property = self::FLAGS[$app] ?? null;

        return $property !== null && (bool) $entreprise->{$property}();
    }

    private function activate(string $app, string $createdBy, string $domain): string
    {
        try {
            $result = $this->generate->execute(AppKeyInputDto::fromArray(['app' => $app, 'label' => sprintf('Activation %s du %s', AppKeyService::LABELS[$app], date('d/m/Y')), 'sendEmail' => true]), $createdBy, $domain);

            return $result['emailSentTo'] ? sprintf('clé %s envoyée à %s', $result['hint'], $result['emailSentTo']) : sprintf('clé %s créée (pas d\'e-mail : fiche sans adresse) ; voir « Clés des applications »', $result['hint']);
        } catch (\Throwable $e) {
            $this->logger->error('Activation d\'application : clé non créée : ' . $e->getMessage(), ['app' => $app]);

            return 'clé non créée (' . $e->getMessage() . ')';
        }
    }
}
