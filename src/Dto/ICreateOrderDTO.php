<?php
namespace App\Dto;

interface ICreateOrderDTO
{
    public function getUserId(): int;
    public function getOrderSource(): int;
    public function getAddressId(): int;
    public function getCarrierId(): int;
    public function getTypeOrder(): int;
    public function getItems(): array;
}
