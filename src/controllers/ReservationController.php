<?php
namespace mywishlist\controllers;

use Dflydev\FigCookies\FigResponseCookies;
use Dflydev\FigCookies\SetCookie;
use Exception;
use mywishlist\models\Item;
use mywishlist\models\Liste;
use mywishlist\models\Reservation;

class ReservationController extends Controller {

    /**
     * @todo Flash Message
     * @todo Sécurité filter_var
     */
    public function bookItem($request, $response, $args) {
       try {
            $name = $request->getParsedBody()['name'];
            $message = $request->getParsedBody()['message'];
            $item_id = $request->getParsedBody()['item_id'];
            $token = $request->getParsedBody()['token'];
            $liste = Liste::where('token', '=', $token)->first();
            if(is_null($liste)) {
                throw new Exception();
            }
            $item = Item::where(['id' => $item_id, 'liste_id' => $liste->no])->first();
            if(is_null($item)) {
                throw new Exception();
            }
            $reservation = Reservation::where('item_id', '=', $item_id)->first();
            if(!is_null($reservation)) {
                throw new Exception();
            }
            $r = new Reservation();
            $r->item_id = $item_id;
            $r->message = $message;
            $r->nom = $name;
            $r->save();
            $response = FigResponseCookies::set($response, SetCookie::create("nom")->withValue($name)->rememberForever());
            $response = $response->withRedirect($request->getUri()->getBaseUrl() . "/error/200" , 301);
        } catch(Exception $e) {
            $response = $response->withRedirect($request->getUri()->getBaseUrl() . "/error/406" , 301);
        }
        return $response;
    }
}
