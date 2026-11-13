<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Gestion\Services\GestionValidationException;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Outils communs aux actions du fil : forme d'une question, textes, refus d'une réponse */
abstract class AbstractGestionAction implements GestionAction
{
    public function __construct(
        protected readonly TenantEntityManagerProvider $emProvider,
        protected readonly TranslatorInterface $translator,
        protected readonly TenantCurrencyProvider $currency,
    ) {
    }

    /**
     * Question d'un paramètre. `id` est le paramètre (`amount`, `planned:shipping`) ; `kind` = `<action>.<paramètre>`.
     *
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $options
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    protected function question(string $id, bool $required, array $input, array $options, mixed $answer, string $locale, array $params = [], bool $proposed = false): array
    {
        $name = explode(':', $id)[0];
        $base = 'conversation.actions.' . str_replace('.', '_', static::key()) . '.' . $name;

        return ['id' => $id, 'kind' => static::key() . '.' . $name, 'path' => $id, 'title' => $this->tr("$base.title", $locale, $params), 'text' => $this->tr("$base.text", $locale, $params),
            'required' => $required, 'input' => $input, 'options' => $options, 'answer' => $answer, 'proposed' => $proposed];
    }

    /** @param array<string, string> $params */
    protected function tr(string $id, string $locale, array $params = []): string
    {
        return $this->translator->trans($id, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
    }

    protected function fail(int $index, string $code): never
    {
        throw new GestionValidationException(["answers[$index].value" => $code], 'Réponse invalide');
    }

    protected static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && \DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value ? $value : null;
    }

    /** Texte court non vide, sinon refus */
    protected function text(mixed $value, int $index, int $max = 255): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            $this->fail($index, 'REQUIRED');
        }

        return mb_substr($text, 0, $max);
    }

    /** Montant en cents, entier, strictement positif ou nul selon l'action */
    protected function cents(mixed $value, int $index, bool $zeroAllowed = false): int
    {
        if (!is_int($value) || $value < 0) {
            $this->fail($index, 'INVALID_AMOUNT');
        }
        if ($value === 0 && !$zeroAllowed) {
            $this->fail($index, 'MUST_BE_POSITIVE');
        }

        return $value;
    }

    protected function em(): EntityManagerInterface
    {
        return $this->emProvider->getEntityManager();
    }

    protected function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }

    protected function site(): string
    {
        return strtoupper($this->currency->code());
    }
}
