<?php

namespace App\Message;

/*
 * Lot DIR (11/11/2026) : le message vit désormais dans App\Gestion\Message\GenerateGestionAdviceMessage.
 * Ce nom d'emprunt garde lisibles les messages déjà sérialisés sous l'ancien nom (file gestion_ai, file des échecs)
 * pendant une version ; à retirer au lot suivant.
 */
class_alias(\App\Gestion\Message\GenerateGestionAdviceMessage::class, __NAMESPACE__ . '\\GenerateGestionAdviceMessage');
