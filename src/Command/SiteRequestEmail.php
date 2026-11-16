<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * L'adresse de l'administrateur d'une demande de site, donnée à une commande du serveur (lot de la campagne, 16/11/2026) : en option, ou
 * lue sur l'entrée standard avec `--admin-email=-` (elle n'apparaît alors ni dans la ligne de commande ni dans l'historique du terminal).
 * Elle n'est jamais affichée en clair dans la sortie : seulement masquée.
 */
final class SiteRequestEmail
{
    public static function read(InputInterface $input): string
    {
        $email = (string) $input->getOption('admin-email');
        if ($email === '-') {
            $stream = $input instanceof \Symfony\Component\Console\Input\StreamableInputInterface && $input->getStream() !== null ? $input->getStream() : \STDIN;
            $email = (string) fgets($stream);
        }

        return trim($email);
    }

    /** « j***@e***.com » : assez pour reconnaître, jamais l'adresse entière */
    public static function mask(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $dot = strrpos($domain, '.');

        return mb_substr($local, 0, 1) . '***@' . mb_substr($domain, 0, 1) . '***' . ($dot !== false ? substr($domain, $dot) : '');
    }
}
