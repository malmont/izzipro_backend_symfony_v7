<?php

namespace App\Factory;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

class HtmlSanitizerFactory
{
    public static function createCms(): HtmlSanitizerInterface
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements(); // base sûre : p, b, i, etc. (selon version)

        // ✅ Autoriser explicitement les éléments dont tu as besoin
        foreach ([
            'p','br','strong','b','em','i','u',
            'ul','ol','li','blockquote',
            'a','span',
        ] as $el) {
            $config->allowElement($el);
        }

        // ✅ Autoriser quelques attributs utiles
        foreach ([
            ['href',  'a'],
            ['title', 'a'],
            ['target','a'],
            ['rel',   'a'],
            ['class', 'span'],
        ] as [$attr, $on]) {
            $config->allowAttribute($attr, $on);
        }

        // (Optionnel) Si ta version supporte cette méthode, tu peux la remettre :
        // $config->allowLinkSchemes(['http','https','mailto','tel']);

        return new HtmlSanitizer($config);
    }
}
