<?php
namespace mywishlist\controllers;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use mywishlist\models\Item;
use mywishlist\models\Liste;
use mywishlist\models\Reservation;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * Class ItemController
 * @author Jules Sayer <jules.sayer@protonmail.com>
 * @author Anthony Pernot <anthony.pernot9@etu.univ-lorraine.fr>
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
    public function getItem(Request $request, Response $response, array $args) : Response {
        try {
            $liste = Liste::where('token', '=', $args['token'])->firstOrFail();
            $item = Item::where(['id' => $args['id'], 'liste_id' => $liste->no])->firstOrFail();
            $this->loadCookiesFromRequest($request);

            $can = [
                "canSee" => $liste->haveExpired() || !in_array($liste->tokenCreation, $this->getCreationTokens()),
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
        } catch(ModelNotFoundException $e) {
            $this->flash->addMessage('error', "Cet objet n'existe pas...");
            $response = $response->withRedirect($this->router->pathFor('home'));
        } catch(Exception $e) {
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
    public function bookItem(Request $request, Response $response, array $args) : Response {
        try {
            $name = filter_var($request->getParsedBodyParam('name'), FILTER_SANITIZE_STRING);
            $message = filter_var($request->getParsedBodyParam('message'), FILTER_SANITIZE_STRING);
            $item_id = filter_var($request->getParsedBodyParam('item_id'), FILTER_SANITIZE_NUMBER_INT);
            $token = filter_var($request->getParsedBodyParam('token'), FILTER_SANITIZE_STRING);

            if(!isset($name, $message, $item_id, $token)) throw new Exception("Un des paramètres est manquant.");

            $liste = Liste::where('token', '=', $token)->firstOrFail();
            $item = Item::where(['id' => $item_id, 'liste_id' => $liste->no])->firstOrFail();

            if(in_array($liste->token, $this->getCreationTokens())) throw new Exception("Le créateur de la liste ne peut pas réserver d'objet.");
            if($liste->haveExpired()) throw new Exception("Cette liste a déjà expiré, il n'est plus possible de réserver des objets.");
            if(Reservation::where('item_id', '=', $item_id)->exists()) throw new Exception("Cet objet est déjà reservé.");


            $r = new Reservation();
            $r->item_id = $item_id;
            $r->message = $message;
            $r->nom = $name;
            $r->save();

            $this->changeName($name);
            $response = $this->createResponseCookie($response);
            $this->flash->addMessage('success', "$name, votre réservation a été enregistrée !");
            $response = $response->withRedirect($this->router->pathFor('home'));
        } catch(ModelNotFoundException $e) {
            $this->flash->addMessage('error', 'Nous n\'avons pas pu trouver cet objet.');
            $response = $response->withRedirect($this->router->pathFor('home'));
        } catch (Exception $e) {
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('home'));
        }
        return $response;
    }

    public function createItem(Request $request, Response $response, array $args) : Response {
        try {
            $nom = filter_var($request->getParsedBodyParam('nom'), FILTER_SANITIZE_STRING);
            $description = filter_var($request->getParsedBodyParam('descr'), FILTER_SANITIZE_STRING);
            $file = $request->getUploadedFiles('file');
            $url = filter_var($request->getParsedBodyParam('url'), FILTER_SANITIZE_URL);
            $prix = filter_var($request->getParsedBodyParam('prix'), FILTER_SANITIZE_NUMBER_INT);
            $token = filter_var($request->getParsedBodyParam('token'), FILTER_SANITIZE_STRING);
            $createToken = filter_var($request->getParsedBodyParam('creationToken'), FILTER_SANITIZE_STRING);

            if(!isset($nom, $description, $file, $prix, $token, $createToken)) throw new Exception("Un des paramètres est manquant.");

            /**
             * WTF Anthony, si coté client la valeur est modifiée, il peut upload un fichier dont la taille peut être choisie...
             * @todo !!!
             */
            if($file['size'] > $request->getParsedBodyParam('MAX_FILE_SIZE')) throw new Exception("La taille de l'image est trop grande");

            $i = Liste::where(['token' => $token, 'creationToken' => $createToken])->firstOrFail();

            $item = new Item();
            $item->liste_id = $i->no;
            $item->nom = $nom;
            $item->descr=$description;
            $item->img=$file['file'];
            $item->url=$url;
            $item->tarif=$prix;
            $item->save();

            move_uploaded_file($file['file']['tmp_name'], '/public/images/'.$file['file']['name']);

            $this->flash->addMessage('success', "Votre item a été enregistrée !");
            $response = $response->withRedirect($this->router->pathFor('home'));
        }catch(ModelNotFoundException $e){
            $this->flash->addMessage('error', 'Nous n\'avons pas pu créer cet item.');
            $response = $response->withRedirect($this->router->pathFor('home'));
        }catch(Exception $e){
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('home'));
        }
        return $response;
    }


    /**
     * @todo: Ici pas besoin de tester le cookie car il faut vérifier que le creationToken passé dans l'URL est bon...
     * @todo: passer par $args[] au lieu des BodyParams
     */
    public function deleteItem(Request $request, Response $response, array $args ) : Response {
        try{

            $token = filter_var($request->getParsedBodyParam('token'),FILTER_SANITIZE_STRING);
            $createToken = filter_var($request->getParsedBodyParam('creationToken'), FILTER_SANITIZE_STRING);
            $item  = $request->getParsedBodyParam('item_id');

            if(!isset($token, $createToken)){
                throw new Exception("Vous n'avez pas spécifié votre token de créateur ou le cookie du créateur n'existe pas tout simplement, cela veut dire que vous n'êtes pas le propriétaire de la liste");
            }
            
            $l = Liste::where(['token' =>  $token, 'creationToken' => $createToken])->firstOrFail();


            /*
                - le test du cookie est traditionnel avec le tableau superglobale $_COOKIE 
                    et supprime l'item dans la bdd, permet de tester l'existence du cookie du créateur 

                    ===> FONCTIONNE

                - la condition en commentaire en dessous ne fonctionne pas, 
                    le message flash de succès s'affiche sans supprimer l'item dans la bdd

                    ===> A REVOIR POUR ETABLIR UN TEST PLUS ESTHETIQUE ET PLUS SECURISER
            */

            //if(in_array($l->creationToken, $this->getCreationTokens())){

            if(isset($_COOKIE['nom']) && isset($_COOKIE['created'])){
                Item::where([ 'liste_id' => $l->no, 'id' => $item ])->delete();
            }
            

            $this->flash->addMessage('success', "Votre item a été supprimée !");
            $response = $response->withRedirect($this->router->pathFor('home'));

        }catch(ModelNotFoundException $e){
            $this->flash->addMessage('error', 'Nous n\'avons pas pu supprimer cet item.');
            $response = $response->withRedirect($this->router->pathFor('home'));
        }catch(Exception $e){
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('home'));
        }
        return $response;
    }

    /**
     * @todo: ajouter modification url et image!
     * @todo: Un item déjà reservé ne peut pas être modifié!
     */
    public function updateItem(Request $request, Response $response, array $args) : Response {
        try {
            $token = filter_var($args['token'], FILTER_SANITIZE_STRING);
            $creationToken = filter_var($args['creationToken'], FILTER_SANITIZE_STRING);
            $id = filter_var($args['id'], FILTER_SANITIZE_STRING);
            $nom = filter_var($request->getParsedBodyParam('name'), FILTER_SANITIZE_STRING);
            $description = filter_var($request->getParsedBodyParam('desc'), FILTER_SANITIZE_STRING);
            $prix = filter_var($request->getParsedBodyParam('prix'), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

            $liste = Liste::where(['token' => $token, 'creationToken' => $creationToken])->firstOrFail();
            $item = Item::where(['id' => $id, 'liste_id' => $liste->no])->firstOrFail();

            $item->nom = $nom;
            $item->descr = $description;
            $item->tarif = $prix;
            $item->save();

            $this->flash->addMessage('success', "Votre item a été modifié !");
            $response = $response->withRedirect($this->router->pathFor('home'));
        }catch(ModelNotFoundException $e){
            $this->flash->addMessage('error', 'Nous n\'avons pas pu modifier cet item.');
            $response = $response->withRedirect($this->router->pathFor('home'));
        }catch(Exception $e){
            $this->flash->addMessage('error', $e->getMessage());
            $response = $response->withRedirect($this->router->pathFor('home'));
        }
        return $response;
    }

}