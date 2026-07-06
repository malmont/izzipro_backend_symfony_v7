<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class FinancementSubmitDto
{
    #[Assert\NotBlank(message: "Le prénom est requis.")]
    public ?string $firstName = null;

    #[Assert\NotBlank(message: "Le nom est requis.")]
    public ?string $lastName = null;

    #[Assert\NotBlank(message: "Le courriel est requis.")]
    #[Assert\Email(message: "L'adresse courriel n'est pas valide.")]
    public ?string $email = null;

    #[Assert\NotBlank(message: "Le numéro de téléphone est requis.")]
    public ?string $phone = null;

    #[Assert\NotBlank(message: "La date de naissance est requise.")]
    public ?string $birthDate = null;

    #[Assert\NotBlank(message: "Le type de véhicule est requis.")]
    public ?string $vehicleType = null;

    #[Assert\NotBlank(message: "L'adresse complète est requise.")]
    public ?string $address = null;

    #[Assert\NotBlank(message: "La durée au domicile est requise.")]
    public ?string $timeAtResidence = null;

    #[Assert\NotBlank(message: "Le statut résidentiel est requis.")]
    #[Assert\Choice(
        choices: ["Locataire", "Propriétaire"],
        message: "Le statut résidentiel doit être Locataire ou Propriétaire."
    )]
    public ?string $housingStatus = null;

    #[Assert\NotBlank(message: "Le montant du paiement mensuel est requis.")]
    #[Assert\GreaterThanOrEqual(value: 0, message: "Le paiement mensuel doit être supérieur ou égal à 0.")]
    public ?float $monthlyPayment = null;

    #[Assert\NotBlank(message: "Le revenu mensuel brut est requis.")]
    #[Assert\GreaterThanOrEqual(value: 0, message: "Le revenu mensuel brut doit être supérieur ou égal à 0.")]
    public ?float $monthlyIncome = null;

    #[Assert\NotBlank(message: "La cote de crédit estimée est requise.")]
    #[Assert\Choice(
        choices: [
            "Bonne (650+)",
            "Normale (550–649)",
            "Mauvaise (400–549)",
            "Très mauvaise (399 et moins)",
            "Je ne suis pas certain",
            "Faillite en cours",
            "Pas de crédit"
        ],
        message: "La cote de crédit sélectionnée est invalide."
    )]
    public ?string $creditScore = null;
}
