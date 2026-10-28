<?php

namespace App\Services\FormSubmissionService;

use App\Entity\Entreprise;
use App\Entity\FormSubmission;
use App\Services\TenantEntityManagerProvider;

/**
 * Référence lisible au téléphone d'une demande reçue : <initiales du site>-<AAAAMMJJ>-<4 caractères>, ex. IP-20261104-K4T9
 * (« L'Intendant Privé »). Alphabet sans caractères ambigus (ni 0, O, 1, I, L) ; date dans le fuseau du site ;
 * unicité vérifiée en base. Initiales : premières lettres des deux premiers mots significatifs du nom de l'entreprise.
 */
final class FormSubmissionReferenceGenerator
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    public const DEFAULT_PREFIX = 'DM';
    private const STOPWORDS = ['le', 'la', 'les', 'de', 'du', 'des', 'et', 'en', 'au', 'aux', 'un', 'une', 'the', 'of', 'and', 'a'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** Nouvelle référence, absente de la base */
    public function generate(\DateTimeZone $zone): string
    {
        $repository = $this->emProvider->getEntityManager()->getRepository(FormSubmission::class);
        for ($try = 0; $try < 20; $try++) {
            $reference = $this->candidate($zone, $try < 10 ? 4 : 6);
            if (!$repository->referenceExists($reference)) {
                return $reference;
            }
        }
        throw new \RuntimeException('Aucune référence libre trouvée.');
    }

    /** Référence de même forme, jamais enregistrée (réponse aux envois écartés par l'anti-robot) */
    public function decoy(\DateTimeZone $zone): string
    {
        return $this->candidate($zone, 4);
    }

    public function prefix(): string
    {
        $name = (string) $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->getName();

        return self::initials($name);
    }

    public static function initials(string $name): string
    {
        $ascii = class_exists(\Transliterator::class) ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $name) : $name;
        $ascii = preg_replace("/\\b[ldjmnst]'/i", ' ', $ascii) ?? '';
        $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $ascii) ?: [], fn ($w) => $w !== '' && !in_array(strtolower($w), self::STOPWORDS, true)));
        $initials = match (true) {
            count($words) >= 2 => $words[0][0] . $words[1][0],
            count($words) === 1 => substr($words[0], 0, 2),
            default => '',
        };
        $initials = strtoupper($initials);

        return strlen($initials) === 2 ? $initials : self::DEFAULT_PREFIX;
    }

    private function candidate(\DateTimeZone $zone, int $length): string
    {
        $suffix = '';
        for ($i = 0; $i < $length; $i++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return sprintf('%s-%s-%s', $this->prefix(), (new \DateTimeImmutable('now', $zone))->format('Ymd'), $suffix);
    }
}
