<?php

namespace App\Services\Group\AllShops;

/** Lot G-2 : la lecture d'une boutique a dépassé son échéance (`ShopDeadline`) ; la boutique est classée `timeout` */
final class ShopDeadlineExceeded extends \RuntimeException
{
}
