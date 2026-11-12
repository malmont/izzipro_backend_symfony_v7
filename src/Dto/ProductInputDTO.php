<?php
namespace App\Dto;

use App\Gestion\Services\GestionInput;

/**
 * Corps de la création d'un article dans une commande fournisseur (POST /api/commandes/{id}/products, multipart).
 *
 * Le constructeur ne lève jamais (correctif du 12/11/2026 ; avant : un champ absent, vide ou mal formé sortait en 500) : chaque champ
 * est lu par `GestionInput`, les refus sont rangés dans `$errors` (champ → code) et le contrôleur en fait un 422 `VALIDATION_FAILED`
 * avant toute écriture. Obligatoires : `name`, `purchasePrice` et `coefficientMultiplier` (le prix de vente, colonne non nulle, est leur
 * produit : sans l'un des deux, l'enregistrement échouait en 500). `description` est facultative (colonne nullable) : absente → null.
 */
class ProductInputDTO
{
    public string $name;
    public ?string $description;
    public ?float $purchasePrice; // cents entiers
    public ?float $coefficientMultiplier;
    public ?int $quantity = null; // facultative (lot G4, 09/11/2026)
    public ?int $styleId;
    /** @var int[] */
    public array $categoryIds;
    public ?string $image; // champ texte, non lu : l'image est le fichier `image` de la requête

    /** @var array<string, string> champ → code du refus ; vide si le corps est valide */
    public array $errors;

    public function __construct(array $data)
    {
        $input = new GestionInput($data);
        $this->name = (string) $input->text('name');
        $description = $data['description'] ?? null;
        $this->description = is_scalar($description) ? (string) $description : null;
        $purchasePrice = $input->cents('purchasePrice');
        $this->purchasePrice = $purchasePrice !== null ? (float) $purchasePrice : null;
        $this->coefficientMultiplier = $input->decimal('coefficientMultiplier');
        $this->quantity = $input->integer('quantity', false);
        // 0 = « aucun style » : toléré, comme avant
        $this->styleId = in_array($data['style_id'] ?? null, [0, '0'], true) ? null : $input->id('style_id', false);
        $this->categoryIds = $input->ids('category_ids');
        $this->image = is_string($data['image'] ?? null) ? $data['image'] : null;
        $this->errors = $input->errors();
    }
}
