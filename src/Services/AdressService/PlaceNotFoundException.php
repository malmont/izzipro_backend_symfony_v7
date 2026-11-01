<?php

namespace App\Services\AdressService;

/** Identifiant Google Places inconnu ou invalide : 404, jamais 500 (31/10/2026) */
class PlaceNotFoundException extends \RuntimeException
{
}
