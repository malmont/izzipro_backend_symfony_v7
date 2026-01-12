<?php

namespace App\Dto;

interface ICreateOrderDTO
{
    public function getUserId(): int;
    public function getOrderSource(): int;
    public function getAddressId(): int;
    public function getCarrierId(): ?int;
    public function getTypeOrder(): int;
    public function getItems(): array;
    public function getPriceShipping(): ?float;

    // Ajout de la méthode pour récupérer le moyen de paiement (ou null en cas de multiple)
    public function getPaymentMethod(): ?int;

    // Square
    public function getSquarePaymentId(): ?string;
    public function getSquareOrderId(): ?string;
    public function getSquareReceiptUrl(): ?string;
    public function getSquareStatus(): ?string;
    public function getSquareCardBrand(): ?string;
    public function getSquareLast4(): ?string;
    public function getSquareRiskLevel(): ?string;

    // Stripe
    public function getStripePaymentId(): ?string;
    public function getStripeReceiptUrl(): ?string;
    public function getStripeStatus(): ?string;
    public function getStripeCardBrand(): ?string;
    public function getStripeLast4(): ?string;
    public function getStripeRiskLevel(): ?string;
}
