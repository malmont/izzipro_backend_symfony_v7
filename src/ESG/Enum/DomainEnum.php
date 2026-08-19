<?php

namespace App\ESG\Enum;

enum DomainEnum: string
{
    case ENVIRONMENT = 'environment';
    case GOVERNANCE = 'governance';
    case SOCIAL = 'social';
    case CLIMATE = 'climate_activator';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
