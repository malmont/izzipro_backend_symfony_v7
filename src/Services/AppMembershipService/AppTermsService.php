<?php

namespace App\Services\AppMembershipService;

use App\Security\AppFamilies;
use App\Services\AppProfileService\AppProfileService;
use App\Services\TenantEntityManagerProvider;

/**
 * Conditions d'une famille d'applications (lot APPS-MEMBRES M1, `docs/apps-membres.md` § 3) : les textes EXISTANTS, sans nouveau
 * texte. Boutique : la fiche Entreprise du site. Mémoires Vivantes et caisse : le profil d'application de la famille, dont un champ
 * vide prend celui de la fiche Entreprise (`AppProfileService`).
 *
 * La version est calculée par le serveur : une empreinte des textes ENREGISTRÉS de la famille, toutes langues confondues (lus en
 * SQL, pas par la fiche traduite : le résultat ne dépend ni de la langue demandée, ni de l'ordre des lectures). Elle change dès
 * qu'un texte change, dans n'importe quelle langue. Une famille sans aucun texte n'a pas de version : il n'y a rien à accepter.
 */
final class AppTermsService
{
    public const LOCALES = ['fr', 'en'];

    /** Champ rendu => [champ du profil d'application et de la fiche Entreprise, colonne des tables] */
    private const TEXTS = ['privacyPolicy' => ['privacyPolicy', 'privacy_policy'], 'termsOfUse' => ['conditionOfUse', 'condition_of_use'], 'legalNotice' => ['legalNotice', 'legal_notice']];

    public function __construct(private readonly AppProfileService $profiles, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{app: string, version: ?string, locale: string, privacyPolicy: ?string, termsOfUse: ?string, legalNotice: ?string, privacyPolicyUrl: ?string, updatedAt: ?string} */
    public function terms(string $family, string $host, string $locale): array
    {
        $locale = self::locale($locale);
        $texts = $this->texts($family, $host, $locale);

        return ['app' => $family, 'version' => $this->version($family), 'locale' => $locale,
            'privacyPolicy' => $texts['privacyPolicy'], 'termsOfUse' => $texts['termsOfUse'], 'legalNotice' => $texts['legalNotice'],
            'privacyPolicyUrl' => $texts['privacyPolicyUrl'], 'updatedAt' => $texts['updatedAt']];
    }

    /**
     * Version en vigueur des conditions de la famille ; null : la famille n'a aucun texte. Par champ : les textes du profil de la
     * famille (le sien et ses traductions) ; ceux de la fiche Entreprise comptent aussi tant que le profil n'a pas son propre texte de
     * base, puisqu'ils s'affichent alors à sa place. La boutique n'a que ceux de la fiche Entreprise.
     */
    public function version(string $family): ?string
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $columns = implode(', ', array_column(self::TEXTS, 1));
        $site = $connection->fetchAllAssociative("SELECT '' AS language, $columns FROM entreprise WHERE id = (SELECT MIN(id) FROM entreprise)
            UNION ALL SELECT language, $columns FROM entreprise_translation WHERE entreprise_id = (SELECT MIN(id) FROM entreprise)");
        $profile = AppFamilies::TERMS_PROFILE[$family];
        $own = $profile === null ? [] : $connection->fetchAllAssociative("SELECT '' AS language, $columns FROM app_profile WHERE app = ?
            UNION ALL SELECT t.language, t.privacy_policy, t.condition_of_use, t.legal_notice FROM app_profile_translation t JOIN app_profile p ON p.id = t.app_profile_id WHERE p.app = ?", [$profile, $profile]);
        $all = [];
        $any = false;
        foreach (self::TEXTS as $field => [, $column]) {
            $app = self::byLanguage($own, $column);
            $all[$field] = ['app' => $app, 'site' => isset($app['']) ? [] : self::byLanguage($site, $column)];
            $any = $any || $all[$field]['app'] !== [] || $all[$field]['site'] !== [];
        }

        return $any ? substr(hash('sha256', (string) json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16) : null;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, string> langue ('' : le texte de base) => texte, sans les vides, par langue */
    private static function byLanguage(array $rows, string $column): array
    {
        $texts = [];
        foreach ($rows as $row) {
            if (trim((string) $row[$column]) !== '') {
                $texts[(string) $row['language']] = trim((string) $row[$column]);
            }
        }
        ksort($texts);

        return $texts;
    }

    public static function locale(mixed $locale): string
    {
        return is_string($locale) && in_array(strtolower($locale), self::LOCALES, true) ? strtolower($locale) : self::LOCALES[0];
    }

    /** @return array{privacyPolicy: ?string, termsOfUse: ?string, legalNotice: ?string, privacyPolicyUrl: ?string, updatedAt: ?string} */
    private function texts(string $family, string $host, string $locale): array
    {
        $profile = AppFamilies::TERMS_PROFILE[$family];
        if ($profile !== null) {
            $resolved = $this->profiles->resolve($profile, $host, $locale);
            $texts = ['privacyPolicyUrl' => $resolved['privacyPolicyUrl'] ?? null, 'updatedAt' => $resolved['updatedAt'] ?? null];
            foreach (self::TEXTS as $field => [$source]) {
                $texts[$field] = self::text($resolved[$source] ?? null);
            }

            return $texts;
        }
        $company = $this->profiles->company($host, $locale);

        return ['privacyPolicy' => self::text($company?->privacyPolicy), 'termsOfUse' => self::text($company?->conditionOfUse), 'legalNotice' => self::text($company?->LegalNotice),
            'privacyPolicyUrl' => null, 'updatedAt' => null];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
