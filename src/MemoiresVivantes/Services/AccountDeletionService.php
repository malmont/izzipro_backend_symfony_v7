<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\UseCase\DeleteBookUseCase;
use App\Services\AccountService\PersonalDataEraser;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Suppression d'un compte à la demande de l'utilisateur (17/10/2026, exigence des stores). Ce qui est effacé : tous
 * ses livres (chapitres, réponses, enregistrements audio, photos, proches, PDF), ses jetons de rafraîchissement et
 * codes à usage unique, et toute donnée personnelle du compte (e-mail, nom, mot de passe, adresses de livraison).
 * Ce qui est conservé, anonymisé, pour la comptabilité : la ligne du compte (identifiant, dates, `deleted_at`), les
 * achats de chapitre (RevenueCat) et les commandes d'impression (montants, statut Lulu). Le compte ne peut plus se
 * connecter ; un jeton encore en cours cesse de fonctionner, l'adresse e-mail qu'il désigne n'existant plus.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly DeleteBookUseCase $deleteBook,
        private readonly LoggerInterface $logger,
        private readonly PersonalDataEraser $eraser, // données personnelles du compte : service partagé avec la boutique (01/11/2026)
    ) {}

    /** @return array{booksDeleted: int, printOrdersAnonymized: int} */
    public function delete(User $user): array
    {
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $originalEmail = (string) $user->getEmail();
        $userId = (int) $user->getId();

        $orders = $em->getRepository(BookPrintOrder::class)->findBy(['user' => $user]);
        foreach ($orders as $order) {
            $order->setRecipientName('Compte supprimé')->setStreet1('-')->setStreet2(null)->setCity('-')->setState(null)->setPostalCode('-')->setPhoneNumber(null)->setEmail(null);
        }
        $em->flush();

        // Livres : contenu effacé dans tous les cas ; la ligne du livre n'est gardée (titre neutre) que si des commandes
        // d'impression ou des achats de chapitre y sont rattachés, pour que la comptabilité reste lisible
        $books = $em->getRepository(Book::class)->findBy(['user' => $user]);
        $withAccounting = array_flip(array_map('strval', array_merge(
            $connection->fetchFirstColumn('SELECT DISTINCT book_id FROM mv_book_print_order WHERE user_id = ?', [$userId]),
            $connection->fetchFirstColumn('SELECT DISTINCT book_id FROM mv_chapter_payment WHERE user_id = ? AND book_id IS NOT NULL', [$userId])
        )));
        foreach ($books as $book) {
            if (isset($withAccounting[(string) $book->getId()])) {
                $this->deleteBook->purgeContent($book);
                $book->setTitle('Livre supprimé')->setSubtitle(null)->setBirthplace(null)->setPerson1FirstName(null)->setPerson1Birthplace(null)
                    ->setPerson2FirstName(null)->setPerson2Birthplace(null)->setEpigraph(null)->setClientAddress(null)->setAuthorName(null)->setPaymentLinkUrl(null);
            } else {
                $this->deleteBook->execute($book);
            }
        }

        // Compte, adresses (anonymisées sur place et non plus seulement détachées, 01/11/2026), avis, infolettre, client Stripe,
        // jetons de rafraîchissement, codes à usage unique, demandes de réinitialisation : PersonalDataEraser
        $this->eraser->erase($user);
        $this->logger->info(sprintf('Compte %d supprimé à sa demande : %d livre(s) effacé(s), %d commande(s) d\'impression anonymisée(s)', $userId, count($books), count($orders)));

        return ['booksDeleted' => count($books), 'printOrdersAnonymized' => count($orders)];
    }
}
