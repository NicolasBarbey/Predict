<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Predict;

use Predict\Model\PricesQuery;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Install\Database;
use Thelia\Model\Country;
use Thelia\Model\ModuleImageQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\State;
use Thelia\Module\AbstractDeliveryModule;
use Thelia\Module\AbstractDeliveryModuleWithState;
use Thelia\Module\Exception\DeliveryException;

/**
 * Class Predict
 * @package Predict
 * @author Benjamin Perche <bperche@openstudio.fr>
 */
class Predict extends AbstractDeliveryModuleWithState
{

    const MESSAGE_DOMAIN = 'predict';

    const JSON_PRICE_RESOURCE = "/Config/prices.json";

    /**
     * This method is called by the Delivery loop, to check if the current module has to be displayed to the customer.
     * Override it to implements your delivery rules/
     *
     * If you return true, the delivery method will de displayed to the customer
     * If you return false, the delivery method will not be displayed
     *
     * @param Country $country the country to deliver to.
     *
     * @param State|null $state
     * @return boolean
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function isValidDelivery(Country $country, State $state = null)
    {
        $area = $this->getAreaForCountry($country, $state);
        if (null === $area){
            return false;
        }

        $areaId = $area->getId();
        $prices = PricesQuery::getPrices();
        $cartWeight = $this->getRequest()->getSession()->getSessionCart($this->getDispatcher())->getWeight();


        /* check if Predict delivers the asked area */
        if (isset($prices[$areaId]) && isset($prices[$areaId]["slices"])) {
            $areaPrices = $prices[$areaId]["slices"];
            ksort($areaPrices);

            /* check this weight is not too much */
            end($areaPrices);

            $maxWeight = key($areaPrices);
            if ($cartWeight <= $maxWeight) {
                return true;
            }
        }

        return false;
    }

    /**
     *
     * calculate and return delivery price
     *
     * @param Country $country
     * @param State|null $state
     * @return mixed
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getPostage(Country $country, State $state = null)
    {
        $cart = $this->getRequest()->getSession()->getSessionCart($this->getDispatcher());
        $cartWeight = $cart->getWeight();
        $cartAmount = $cart->getTaxedAmount($country, true, $state);

        $area = $this->getAreaForCountry($country, $state);
        if (null === $area){
            return 0;
        }

        $areaId = $area->getId();

        return PricesQuery::getPostageAmount(
            $areaId,
            $cartWeight,
            $cartAmount
        );
    }

    public function getCode()
    {
        return 'Predict';
    }

    public function postActivation(ConnectionInterface $con = null): void
    {
        $database = new Database($con);

        $database->insertSql(null, [__DIR__ . '/Config/insert.sql']);

        /* insert the images from image folder if first module activation */
        $module = $this->getModuleModel();
        if (ModuleImageQuery::create()->filterByModule($module)->count() == 0) {
            $this->deployImageFolder($module, sprintf('%s/media', __DIR__), $con);
        }

        /* set module description */
        $enUSDescription = <<<US_DESC
            Discover delivery Predict:
            <ul>
                <li>You place your order and choose to have it delivered with Predict by Exapaq</li>
                <li>Once your order is prepared, we send you an SMS with several choices of dates and delivery slots</li>
                <li>You select the date and the time slot that suits you by answering directly by SMS (price of a standard SMS) or by going to the space available on <a href="http://destinataires.exapaq.com">http://destinataires.exapaq.com</a> Recipient</li>
                <li>The day of delivery, you will receive an SMS reminding you the time slot.</li>
            </ul>
US_DESC;

        $frFRDescription = <<<FR_DESC
            Découvrez la livraison Predict :
            <ul>
                <li>Vous faites votre commande et choisissez de vous faire livrer avec Predict par Exapaq</li>
                <li>Une fois votre commande préparée, nous vous envoyons un SMS avec plusieurs choix de dates et créneaux horaires de livraison</li>
                <li>Vous sélectionnez la date et le créneau qui vous conviennent le mieux en répondant directement par SMS (prix d’un SMS standard) ou en allant sur l’Espace Destinataire disponible sur <a href="http://destinataires.exapaq.com">http://destinataires.exapaq.com</a></li>
                <li>Le jour de la livraison, vous recevez un SMS vous rappelant le créneau horaire.</li>
            </ul>
FR_DESC;



        $this->getModuleModel()
            ->setLocale()
            ->setDescription($enUSDescription)
            ->setLocale("fr_FR")
            ->setDescription($frFRDescription)
            ->save()
        ;
    }

    public static function getModuleId()
    {
        return ModuleQuery::create()->findOneByCode("Predict")->getId();
    }


    public static function getFreeShippingAmount()
    {
        if (!null !== $amount = self::getConfigValue('free_shipping_amount')) {
            return (float) $amount;
        }

        return 0;
    }

    public static function setFreeShippingAmount($amount)
    {
        self::setConfigValue('free_shipping_amount', $amount);
    }
}
