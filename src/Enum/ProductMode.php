<?php
// src/Enum/ProductMode.php
namespace App\Enum;

enum ProductMode: string
{
    case RETAIL = 'retail';
    case BOOKING = 'booking';
}