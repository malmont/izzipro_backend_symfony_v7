# Polices des livres Mémoires Vivantes

Polices proposées pour la mise en page des livres (PDF), en plus de DejaVu Serif fournie avec Dompdf. Catalogue et
enregistrement auprès de Dompdf : `src/MemoiresVivantes/Services/BookFontCatalog.php`.

| Famille | Fichiers | Licence |
|---|---|---|
| EB Garamond | `EBGaramond-*.ttf` | SIL Open Font License 1.1 |
| Lora | `Lora-*.ttf` | SIL Open Font License 1.1 |
| Merriweather | `Merriweather-*.ttf` | SIL Open Font License 1.1 |
| Playfair Display | `PlayfairDisplay-*.ttf` | SIL Open Font License 1.1 |
| Lato | `Lato-*.ttf` | SIL Open Font License 1.1 |
| Dancing Script (titres seulement) | `DancingScript-Regular.ttf`, `DancingScript-Bold.ttf` | SIL Open Font License 1.1 |

Source : Google Fonts (instances statiques : Regular, Italic, Bold, BoldItalic). La licence OFL autorise l'usage
commercial, l'incorporation dans un PDF imprimé et la redistribution des fichiers avec le logiciel.

Ajouter une police : déposer ses quatre fichiers ici (versions statiques, pas de police variable : Dompdf ne les gère
pas), vérifier qu'elle couvre les caractères français (é è à ç œ « » ’ — …), puis l'ajouter à `BookFontCatalog::FONTS`.
