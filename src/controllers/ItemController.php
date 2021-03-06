<?php

namespace mywishlist\controllers;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use mywishlist\models\Cagnotte;
use mywishlist\models\Item;
use mywishlist\models\Liste;
use mywishlist\models\Participe;
use mywishlist\models\Reservation;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\UploadedFile;

/**
 * Class ItemController
 * @author Jules Sayer <jules.sayer@protonmail.com>
 * @author Anthony Pernot <anthony.pernot9@etu.univ-lorraine.fr>
 * @author Nathan Chevalier <nathan.chevalier2@etu.univ-lorraine.fr>
 * @package mywishlist\controllers
 */
class ItemController extends CookiesController {

    /**
     * Appel item.phtml, permet d'afficher les informations
     * d'un item, l'état de sa réservation, et le nom stocké
     * en cookies de l'utilisateur
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function getItem(Request $request, Response $response, array $args): Response {
        try {
            $liste = Liste::where('token', '=', $args['token'])->firstOrFail();
            $item = Item::where(['id' => $args['id'], 'liste_id' => $liste->no])->firstOrFail();
            $this->loadCookiesFromRequest($request);

            $can = [
                "canSee" => $liste->haveExpired() || !in_array($liste->creationToken, $this->getCreationTokens()),
                "haveExpired" => $liste->haveExpired(),
                "haveCreated" => in_array($liste->tokenCreation, $this->getCreationTokens())
            ];

            $this->view->render($response, 'item.phtml', [
                "liste" => $liste,
                "item" => $item,
                "reservation" => $item->reservation()->get(),
                "nom" => $this->getName(),
                "infos" => $can
            ]);
        } catch (ModelNotFoundException $e) {
            $this->flash->addMessage('error', "Cet objet n'existe pas...");
            $response = $response->withRedirect($this->router->pathFor('home'));
        } catch (Exception $e) {
            $this->flash->addMessage('error', "Une erreur est survenue, veuillez réessayer ultérieurement.");
            $response = $response->withRedirect($this->router->pathFor('home'));
        }
        return $response;
    }

    /**
     * Cette fonction permet de réserver un item
     * Elle vérifie que l'objet n'est pas déjà reservé
     * Que ce n'est pas le créateur qui réserve
     * et que la date d'expiration n'est pas dépassée
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function bookItem(Request $request, Response $response, array $args): Response {
        try {
            $name = filter_var($request->getParsedBodyParam('name'), FILTER_SANITIZE_STRING);
            $message = filter_var($request->getParsedBodyParam('message'), FILTER_SANITIZE_STRING);
            $item_id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);
            $token = filter_var($args['token'], FILTER_SANITIZE_STRING);

            if (mb_strlen($name, 'utf8') < 2) throw new Exception("Votre nom doit comporter au minimum 2 caractères");

            $liste = Liste::where('token', '=', $token)->firstOrFail();
            $item = Item::where(['id' => $item_id, 'liste_id' => $liste->no])->firstOrFail();

            if (in_array($liste->token, $this->getCreationTokens())) throw new Exception("Le créateur de la liste ne peut pas réserver d'objet.");
            if ($liste->haveExpired()) throw new Exception("Cette liste a déjà expiré, il n'est plus possible de réserver des objets.");
            if (Reservation::where('item_id', '=', $item_id)->exists()) throw new Exception("Cet objet est déjà reservé.");

            $r = new Reservation();
            $r->item_id = $item_id;
            $r->message = $message;
            $r->nom = $name;
            $r->save();

            $this->changeName($name);
            $response = $this->createResponseCookie($response);
            $this->flash->addMessage('success', "$name, votre réservation a été enregistrée !");
            $response = $response->withRedirect($this->router->pathFor('home'));
        } catch (ModelNotFoundException $e) {
            $this->flash->addMessage('error', 'Nous n\'avons pas pu trouver cet objet.');
            $response = $response->withRedirect($this->router->pathFor('home'));
        } catch (Exception $e) {
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('home'));
        }
        return $response;
    }

    public function addParticipation(Request $request, Response $response, array $args): Response {
        try {
            $name = filter_var($request->getParsedBodyParam('name'), FILTER_SANITIZE_STRING);
            $message = filter_var($request->getParsedBodyParam('message'), FILTER_SANITIZE_STRING);
            $item_id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);
            $token = filter_var($args['token'], FILTER_SANITIZE_STRING);
            $montant = filter_var($request->getParsedBodyParam('montant'), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

            if (mb_strlen($name, 'utf8') < 2) throw new Exception("Votre nom doit comporter au minimum 2 caractères");

            $liste = Liste::where('token', '=', $token)->firstOrFail();
            $item = Item::where(['id' => $item_id, 'liste_id' => $liste->no])->firstOrFail();

            if (in_array($liste->token, $this->getCreationTokens())) throw new Exception("Le créateur de la liste ne peut pas participer à la cagnotte.");
            if ($liste->haveExpired()) throw new Exception("Cette liste a déjà expiré, il n'est plus possible de participer à la cagnotte.");
            if (is_null($item->id_cagnotte))throw new Exception("Cet item n'a pas de cagnotte.");

            $c = Cagnotte::where('id','=',$item->id_cagnotte)->firstOrFail();
            $c->recolte += $montant;
            $c->save();

            $p = new Participe();
            $p->id_cagnotte = $item->id_cagnotte;
            $p->message = $message;
            $p->nom = $name;
            $p->montant = $montant;
            $p->save();

            $this->changeName($name);
            $response = $this->createResponseCookie($response);
            $this->flash->addMessage('success', "$name, Votre participation a été ajoutée !");
            $response = $response->withRedirect($this->router->pathFor('home'));
        } catch (ModelNotFoundException $e) {
            $this->flash->addMessage('error', 'Nous n\'avons pas pu trouver cet objet.');
            $response = $response->withRedirect($this->router->pathFor('home'));
        } catch (Exception $e) {
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('home'));
        }
        return $response;
    }

    /**
     * Cette fonction permet de
     * créer un item en vérifiant le prix,
     * et l'image
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function createItem(Request $request, Response $response, array $args): Response {
        try {
            $token = filter_var($args['token'], FILTER_SANITIZE_STRING);
            $creationToken = filter_var($args['creationToken'], FILTER_SANITIZE_STRING);
            $nom = filter_var($request->getParsedBodyParam('nom'), FILTER_SANITIZE_STRING);
            $description = filter_var($request->getParsedBodyParam('descr'), FILTER_SANITIZE_STRING);
            $url = filter_var($request->getParsedBodyParam('url'), FILTER_SANITIZE_URL);
            $img = filter_var($request->getParsedBodyParam('picture'), FILTER_SANITIZE_STRING);
            $prix = filter_var($request->getParsedBodyParam('prix'), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $cagnotte = !is_null($request->getParsedBodyParam('cagnotte')) ? 1 : 0;

            if (mb_strlen($nom, 'utf8') < 2) throw new Exception("Le nom doit comporter au minimum 2 caractères");
            if ($prix < 0) throw new Exception("Le prix ne peut pas être négatif");
            if (mb_strlen($img, 'utf8') == 0) $img = "default.jpg";
            if (!filter_var($img, FILTER_VALIDATE_URL)) {
                if (!file_exists($_SERVER['DOCUMENT_ROOT'] . $request->getUri()->getBasePath() . "/public/images/" . $img)) throw new Exception("Le lien n'est pas bon et/ou l'image voulue n'existe pas dans le dossier /public/images/");
            }


            $liste = Liste::where(['token' => $token, 'creationToken' => $creationToken])->firstOrFail();

            $item = new Item();
            $item->liste_id = $liste->no;
            $item->nom = $nom;
            $item->descr = $description;
            $item->img = $img;
            $item->url = $url;
            $item->tarif = $prix;

            if ($cagnotte){
                $c = new Cagnotte();
                $c->montant=$prix;
                $c->save();
                $item->id_cagnotte = $c->id;
            }

            $item->save();

            $this->flash->addMessage('success', "Votre item a été enregistrée !");
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        } catch (ModelNotFoundException $e) {
            $this->flash->addMessage('error', 'Nous n\'avons pas pu créer cet item.');
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        } catch (Exception $e) {
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        }
        return $response;
    }

    /**
     * Cette fonction permet
     * de supprimer un item
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function deleteItem(Request $request, Response $response, array $args): Response {
        try {
            $token = filter_var($args['token'], FILTER_SANITIZE_STRING);
            $creationToken = filter_var($args['creationToken'], FILTER_SANITIZE_STRING);
            $item_id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);

            $liste = Liste::where(['token' => $token, 'creationToken' => $creationToken])->firstOrFail();
            $item = Item::where(['id' => $item_id, 'liste_id' => $liste->no])->firstOrFail();
            if (Reservation::where('item_id', '=', $item_id)->exists()) throw new Exception("Cet objet est déjà reservé, il est donc impossible de le supprimer.");

            $item->destroy($item_id);

            $this->flash->addMessage('success', "Votre objet a été supprimé !");
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        } catch (ModelNotFoundException $e) {
            $this->flash->addMessage('error', 'Nous n\'avons pas pu supprimer cet item.');
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        } catch (Exception $e) {
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        }
        return $response;
    }

    /**
     * Cette fonction permet
     * de mettre à jour un item
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function updateItem(Request $request, Response $response, array $args): Response {
        try {
            $token = filter_var($args['token'], FILTER_SANITIZE_STRING);
            $creationToken = filter_var($args['creationToken'], FILTER_SANITIZE_STRING);
            $item_id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);
            $nom = filter_var($request->getParsedBodyParam('name'), FILTER_SANITIZE_STRING);
            $description = filter_var($request->getParsedBodyParam('desc'), FILTER_SANITIZE_STRING);
            $prix = filter_var($request->getParsedBodyParam('prix'), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $img = filter_var($request->getParsedBodyParam('picture'), FILTER_SANITIZE_STRING);
            $url = filter_var($request->getParsedBodyParam('url'), FILTER_SANITIZE_URL);
            $cagnotte = !is_null($request->getParsedBodyParam('cagnotte')) ? 1 : 0;

            if (mb_strlen($prix, 'utf8') < 2) throw new Exception("Le nom doit comporter au moins 1 caractère");
            if ($prix < 0) throw new Exception("Le prix ne peut pas être négatif");
            if (mb_strlen($img, 'utf8') == 0) $img = "default.jpg";
            if (!filter_var($img, FILTER_VALIDATE_URL)) {
                if (!file_exists($_SERVER['DOCUMENT_ROOT'] . $request->getUri()->getBasePath() . "/public/images/" . $img)) throw new Exception("Le lien n'est pas bon et/ou l'image voulue n'existe pas dans le dossier /public/images/");
            }

            $liste = Liste::where(['token' => $token, 'creationToken' => $creationToken])->firstOrFail();
            $item = Item::where(['id' => $item_id, 'liste_id' => $liste->no])->firstOrFail();
            if (Reservation::where('item_id', '=', $item_id)->exists()) throw new Exception("Cet objet est déjà reservé, il ne peut donc pas être modifié.");
            if ($cagnotte && is_null($item->id_cagnotte)){
                $c = new Cagnotte();
                $c->montant=$prix;
                $c->save();
                $item->id_cagnotte = $c->id;
            }
            $item->nom = $nom;
            $item->descr = $description;
            $item->tarif = $prix;
            $item->img = $img;
            $item->url = $url;
            $item->save();

            $this->flash->addMessage('success', "Votre item a été modifié !");
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        } catch (ModelNotFoundException $e) {
            $this->flash->addMessage('error', 'Nous n\'avons pas pu modifier cet item.');
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        } catch (Exception $e) {
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        }
        return $response;
    }

    /**
     * Cette fonction permet
     * de supprimer l'image
     * d'un item
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function deleteImgItem(Request $request, Response $response, array $args): Response {
        try {
            $token = filter_var($args['token'], FILTER_SANITIZE_STRING);
            $creationToken = filter_var($args['creationToken'], FILTER_SANITIZE_STRING);
            $item_id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);

            $liste = Liste::where(['token' => $token, 'creationToken' => $creationToken])->firstOrFail();
            $item = Item::where(['liste_id' => $liste->no, 'id' => $item_id])->firstOrFail();
            if (Reservation::where('item_id', '=', $item_id)->exists()) throw new Exception("Cet objet est déjà reservé, il ne peut donc pas être modifié.");

            $item->img = "default.jpg";
            $item->save();

            $this->flash->addMessage('success', "L'image de votre objet à été supprimée!");
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        } catch (ModelNotFoundException $e) {
            $this->flash->addMessage('error', 'Nous n\'avons pas pu supprimer l\'image de cet objet.');
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        } catch (Exception $e) {
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        }
        return $response;
    }

    public function uploadImgItem(Request $request, Response $response, array $args): Response {
        try{
            $token = filter_var($args['token'], FILTER_SANITIZE_STRING);
            $creationToken = filter_var($args['creationToken'], FILTER_SANITIZE_STRING);
            $item_id = filter_var($args['id'], FILTER_SANITIZE_NUMBER_INT);
            $file = $request->getUploadedFiles();

            /**
             * Apparemment, c'est une erreur de paramètre pour la méthode moveUploadedFile
             * D'après la doc de slim 3 pour la gestion des uploads de fichier, la méthode
             * gère son extension, un nom de fichier hashé et insère dans un répertoire
             * 
             * Vu le message d'erreur et le code source, ça soulève un exception
             * InvalidArgumentException. Problème de chemin ? mauvaise gestion du If ?
             * 
             */


            $chemin = __DIR__.'/public/images/';
            $nameFile = basename($file['image']->getClientFilename());
            $typeFile = $file['image']->getClientMediaType();
            $direction = $chemin . $nameFile ;

            //$extensionAutorize = array('jpg','jpeg','gif','png');

            if (mb_strlen($file['image']->getClientFilename(), 'utf8') == 0) $nameFile = "default.jpg";

            //if(in_array($file['image']->getClientMediaType(), $extensionAutorize)) throw new Exception('Votre fichier n\'est pas autorisé. Insérez une image valide.');

            /*
                if(mime_content_type($typeFile)!='image/jpg' || mime_content_type($typeFile)!='image/jpeg' || mime_content_type($typeFile)!='image/png' || mime_content_type($typeFile)!='image/gif')
                    throw new Exception('Votre fichier n\'est pas autorisé. Insérez une image valide.');
            */

            $liste = Liste::where(['token' => $token, 'creationToken' => $creationToken])->firstOrFail();
            $item = Item::where(['id' => $item_id, 'liste_id' => $liste->no])->firstOrFail();
            if (Reservation::where('item_id', '=', $item_id)->exists()) throw new Exception("Cet objet est déjà reservé, il ne peut donc pas être modifié.");


                $item->img = $nameFile;
                $item->save();

                $this->moveUploadedFile($chemin, $file['image']);
                chmod( $direction , 0600);

                // move_uploaded_file($_FILES['image']['tmp_name'], $direction);
                

                $this->flash->addMessage('success', "L'image de votre objet a été enregistrée sur le serveur !");
                $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));  

            /**if ($file['image']->getError() === UPLOAD_ERR_OK) {  
                $item->img = $nameFile;
                $item->save();

                $this->moveUploadedFile($chemin, $file['image']);
                chmod( $direction , 0600);

                // move_uploaded_file($_FILES['image']['tmp_name'], $direction);
                

                $this->flash->addMessage('success', "L'image de votre objet a été enregistrée sur le serveur !");
                $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));       
            } else {
                $this->flash->addMessage('error', "L'image de votre objet n'a pas été enregistrée sur le serveur !");
                $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));         
            }*/

        }catch (ModelNotFoundException $e) {
            $this->flash->addMessage('error', 'Nous n\'avons pas pu ajouter d\'image de cet objet.');
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        } catch (Exception $e) {
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('showAdminListe', ['token' => $token, 'creationToken' => $creationToken]));
        }
        return $response;
    }

    function moveUploadedFile($directory, UploadedFile $uploadedFile){
        $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
        $basename = bin2hex(random_bytes(8));
        $filename = sprintf('%s.%0.8s', $basename, $extension);

        $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);

        return $filename;
    }


}