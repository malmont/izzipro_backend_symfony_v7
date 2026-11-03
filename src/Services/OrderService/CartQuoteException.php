<?php

namespace App\Services\OrderService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Devis refusé : statut (400 corps illisible, 404 article inconnu, 409 indisponible, 422 règle non respectée), erreurs par ligne.
 * Depuis le 02/11/2026, chaque erreur porte aussi `code` (MAJUSCULES_SOULIGNÉES) et, pour un détail variable, `params` ;
 * le code de premier niveau (`code()`) reste celui de la caisse : VARIANT_NOT_FOUND, CARRIER_NOT_FOUND, sinon QUOTE_INVALID.
 */
final class CartQuoteException extends HttpException
{
    /** @param list<array{path: string, message: string, code?: string, params?: array<string, mixed>}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }

    /** Code stable de premier niveau, le même pour la caisse (create-multi-payment, /api/pos/quote) et pour le web */
    public function code(): string
    {
        return match ($this->getStatusCode()) {
            404 => str_contains($this->getMessage(), 'carrierId') ? 'CARRIER_NOT_FOUND' : 'VARIANT_NOT_FOUND',
            default => 'QUOTE_INVALID',
        };
    }

    /**
     * Erreur d'une ligne du devis : {path, message, code, params?}.
     *
     * @param array<string, mixed> $params
     * @return array{path: string, message: string, code: string, params?: array<string, mixed>}
     */
    public static function error(string $path, string $code, string $message, array $params = []): array
    {
        return ['path' => $path, 'message' => $message, 'code' => $code] + ($params !== [] ? ['params' => $params] : []);
    }
}
