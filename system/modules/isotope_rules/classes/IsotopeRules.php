<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2012 Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://www.isotopeecommerce.com
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 */

namespace Isotope;

use Isotope\Isotope;
use Isotope\Interfaces\IsotopeProduct;
use Isotope\Model\ProductCollection\Cart;


/**
 * Class IsotopeRules
 *
 * @copyright  Isotope eCommerce Workgroup 2009-2012
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 */
class IsotopeRules extends \Controller
{

    /**
     * Current object instance (Singleton)
     * @var object
     */
    protected static $objInstance;

    /**
     * Prevent cloning of the object (Singleton)
     */
    final private function __clone() {}


    /**
     * Prevent direct instantiation (Singleton)
     */
    protected function __construct()
    {
        parent::__construct();

        $this->import('Database');

        // User object must be loaded from cart, e.g. for postsale handling
        if (Isotope::getCart()->member > 0) {
            $this->User = \Database::getInstance()->prepare("SELECT * FROM tl_member WHERE id=?")->execute(Isotope::getCart()->member);
        }
    }


    /**
     * Instantiate a database driver object and return it (Factory)
     *
     * @return object
     */
    public static function getInstance()
    {
        if (!is_object(self::$objInstance))
        {
            self::$objInstance = new \IsotopeRules();
        }

        return self::$objInstance;
    }


    /**
     * Calculate the price for a product, applying rules and coupons
     *
     * @param    float
     * @param    object
     * @param    string
     * @param    int
     * @return float
     */
    public function calculatePrice($fltPrice, $objSource, $strField, $intTaxClass)
    {
        if ($objSource instanceof IsotopeProduct && ($strField == 'price' || $strField == 'low_price'))
        {
            $objRules = $this->findRules(array("type='product'"), array(), array($objSource), ($strField == 'low_price' ? true : false), array($strField => $fltPrice));

            while ($objRules->next())
            {
                // Check cart quantity
                if ($objRules->minItemQuantity > 0 || $objRules->maxItemQuantity > 0)
                {
                    if ($objRules->quantityMode == 'cart_products' || $objRules->quantityMode == 'cart_items')
                    {
                        $intTotal = 0;
                        foreach ($this->Isotope->Cart->getProducts() as $objProduct)
                        {
                              $intTotal += $objRules->quantityMode == 'cart_items' ? $objProduct->quantity_requested : 1;
                        }
                    }

                    if (($objRules->minItemQuantity > 0 && $objRules->minItemQuantity > $intTotal) || ($objRules->maxItemQuantity > 0 && $objRules->maxItemQuantity < $intTotal))
                    {
                        continue;
                    }
                }

                // We're unable to apply variant price rules to low_price (see #3189)
                if ($strField == 'low_price' && $objRules->productRestrictions == 'variants')
                {
                    continue;
                }

                if (strpos($objRules->discount, '%') !== false)
                {
                    $fltDiscount = 100 + rtrim($objRules->discount, '%');
                    $fltDiscount = round($fltPrice - ($fltPrice / 100 * $fltDiscount), 10);
                    $fltDiscount = $fltDiscount > 0 ? (floor($fltDiscount * 100) / 100) : (ceil($fltDiscount * 100) / 100);

                    $fltPrice = $fltPrice - $fltDiscount;
                }
                else
                {
                    $fltPrice = $fltPrice + $objRules->discount;
                }
            }
        }

        return $fltPrice;
    }


    /**
     * Add cart rules to surcharges
     */
    public function getSurcharges($arrSurcharges)
    {
        $objRules = $this->findRules(array("type='cart'", "enableCode=''"));

        while( $objRules->next() )
        {
            $arrSurcharge = $this->calculateProductSurcharge($objRules->row());

            if (is_array($arrSurcharge))
                $arrSurcharges[] = $arrSurcharge;
        }

        $arrCoupons = deserialize(Isotope::getCart()->coupons);
        if (is_array($arrCoupons) && !empty($arrCoupons))
        {
            $arrDropped = array();

            foreach( $arrCoupons as $code )
            {
                $arrRule = $this->findCoupon($code, Isotope::getCart()->getProducts());

                if ($arrRule === false)
                {
                    $arrDropped[] = $code;
                }
                else
                {
                    //cart rules should total all eligible products for the cart discount and apply the discount to that amount rather than individual products.
                    $arrSurcharge = $this->calculateProductSurcharge($arrRule);

                    if (is_array($arrSurcharge))
                        $arrSurcharges[] = $arrSurcharge;
                }
            }

            if (!empty($arrDropped))
            {
                // @todo show dropped coupons
                $arrCoupons = array_diff($arrCoupons, $arrDropped);
                $this->Database->query("UPDATE tl_iso_cart SET coupons='" . serialize($arrCoupons) . "' WHERE id=".(int) Isotope::getCart()->id);
            }
        }

        return $arrSurcharges;
    }


    /**
     * Returns a rule form if needed
     * @access public
     * @param  object $objModule
     * @return string
     */
    public function getCouponForm($objModule)
    {
        $arrCoupons = is_array(deserialize(Isotope::getCart()->coupons)) ? deserialize(Isotope::getCart()->coupons) : array();
        $strCoupon = \Input::get('coupon_'.$objModule->id);

        if ($strCoupon == '')
            $strCoupon = \Input::get('coupon');

        if ($strCoupon != '')
        {
            $arrRule = $this->findCoupon($strCoupon, Isotope::getCart()->getProducts());

            if ($arrRule === false)
            {
                $_SESSION['COUPON_FAILED'][$objModule->id] = sprintf($GLOBALS['TL_LANG']['MSC']['couponInvalid'], $strCoupon);
            }
            else
            {
                if (in_array(strtolower($strCoupon), array_map('strtolower', $arrCoupons)))
                {
                    $_SESSION['COUPON_FAILED'][$objModule->id] = sprintf($GLOBALS['TL_LANG']['MSC']['couponDuplicate'], $strCoupon);
                }
                else
                {
                    $arrCoupons[] = $arrRule['code'];

                    Isotope::getCart()->coupons = serialize($arrCoupons);
                    Isotope::getCart()->save();

                    $_SESSION['COUPON_SUCCESS'][$objModule->id] = sprintf($GLOBALS['TL_LANG']['MSC']['couponApplied'], $arrRule['code']);
                }
            }

            \Controller::redirect(preg_replace('@[?&]coupon(_[0-9]+)?=[^&]*@', '', \Environment::get('request')));
        }


        $objRules = $this->findRules(array("type='cart'", "enableCode='1'"));

        if (!$objRules->numRows || !count(array_diff($objRules->fetchEach('code'), $arrCoupons)))
            return '';


        //build template
        $objTemplate = new \Isotope\Template('iso_coupons');

        $objTemplate->id = $objModule->id;
        $objTemplate->action = \Environment::get('request');
        $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['couponHeadline'];
        $objTemplate->inputLabel = $GLOBALS['TL_LANG']['MSC']['couponLabel'];
        $objTemplate->sLabel = $GLOBALS['TL_LANG']['MSC']['couponApply'];

        if ($_SESSION['COUPON_FAILED'][$objModule->id] != '')
        {
            $objTemplate->message = $_SESSION['COUPON_FAILED'][$objModule->id];
            $objTemplate->mclass = 'failed';
            unset($_SESSION['COUPON_FAILED']);
        }
        elseif ($_SESSION['COUPON_SUCCESS'][$objModule->id] != '')
        {
            $objTemplate->message = $_SESSION['COUPON_SUCCESS'][$objModule->id];
            $objTemplate->mclass = 'success';
            unset($_SESSION['COUPON_SUCCESS']);
        }

        return $objTemplate->parse();
    }


    /**
     * Callback for checkout Hook. Transfer active rules to usage table.
     */
    public function writeRuleUsages($objOrder, $objCart)
    {
        $objRules = $this->findRules(array("(type='product' OR (type='cart' AND enableCode=''))"));
        $arrRules = $objRules->fetchEach('id');
        $arrCoupons = deserialize($objCart->coupons);

        if (is_array($arrCoupons) && !empty($arrCoupons))
        {
            $blnError = false;

            foreach ($arrCoupons as $k => $code)
            {
                $arrRule = $this->findCoupon($code, $objCart->getProducts());

                if ($arrRule === false)
                {
                    $_SESSION['ISO_ERROR'][] = sprintf($GLOBALS['TL_LANG']['ERR']['couponCodeDropped'], $code);
                    unset($arrCoupons[$k]);
                    $blnError = true;
                }
                else
                {
                    $arrRules[] = $arrRule['id'];
                }
            }

            if ($blnError)
            {
                $objCart->coupons = $arrCoupons;

                return false;
            }
        }

        if (!empty($arrRules))
        {
            $time = time();

            $this->Database->query("INSERT INTO tl_iso_rule_usage (pid,tstamp,order_id,config_id,member_id) VALUES (" . implode(", $time, {$objOrder->id}, ".(int) Isotope::getConfig()->id.", {$objOrder->pid}), (", $arrRules) . ", $time, {$objOrder->id}, ".(int) Isotope::getConfig()->id.", {$objOrder->pid})");
        }

        return true;
    }

    /**
     * Callback for checkout step "review". Remove rule usages if an order failed.
     */
    public function cleanRuleUsages(&$objModule)
    {
        $this->Database->query("DELETE FROM tl_iso_rule_usage WHERE pid=(SELECT id FROM tl_iso_product_collection WHERE type='Order' AND source_collection_id=".(int) Isotope::getCart()->id.")");

        return '';
    }


    /**
     * Transfer coupons from one cart to another. This happens if a guest cart is moved to user cart.
     * @param IsotopeProductCollection
     * @param IsotopeProductCollection
     * @param array
     */
    public function transferCoupons($objOldCollection, $objNewCollection, $arrIds)
    {
        if ($objOldCollection instanceof Cart && $objNewCollection instanceof Cart)
        {
            $objNewCollection->coupons = $objOldCollection->coupons;
        }
    }


    /**
     * Fetch rules
     */
    protected function findRules($arrProcedures, $arrValues=array(), $arrProducts=null, $blnIncludeVariants=false, $arrAttributeData=array())
    {
        // Only enabled rules
        $arrProcedures[] = "enabled='1'";

        // Date & Time restrictions
        $arrProcedures[] = "(startDate='' OR FROM_UNIXTIME(startDate,GET_FORMAT(DATE,'INTERNAL')) <= FROM_UNIXTIME(UNIX_TIMESTAMP(),GET_FORMAT(DATE,'INTERNAL')))";
        $arrProcedures[] = "(endDate='' OR FROM_UNIXTIME(endDate,GET_FORMAT(DATE,'INTERNAL')) >= FROM_UNIXTIME(UNIX_TIMESTAMP(),GET_FORMAT(DATE,'INTERNAL')))";
        $arrProcedures[] = "(startTime='' OR FROM_UNIXTIME(startTime,GET_FORMAT(TIME,'INTERNAL')) <= FROM_UNIXTIME(UNIX_TIMESTAMP(),GET_FORMAT(TIME,'INTERNAL')))";
        $arrProcedures[] = "(endTime='' OR FROM_UNIXTIME(endTime,GET_FORMAT(TIME,'INTERNAL')) >= FROM_UNIXTIME(UNIX_TIMESTAMP(),GET_FORMAT(TIME,'INTERNAL')))";


        // Limits
        $arrProcedures[] = "(limitPerConfig=0 OR limitPerConfig>(SELECT COUNT(*) FROM tl_iso_rule_usage WHERE pid=r.id AND config_id=".(int) Isotope::getConfig()->id." AND order_id NOT IN (SELECT id FROM tl_iso_product_collection WHERE type='Order' AND source_collection_id=".(int) Isotope::getCart()->id.")))";

        if (Isotope::getCart()->pid > 0)
        {
            $arrProcedures[] = "(limitPerMember=0 OR limitPerMember>(SELECT COUNT(*) FROM tl_iso_rule_usage WHERE pid=r.id AND member_id=".(int) $this->User->id." AND order_id NOT IN (SELECT id FROM tl_iso_product_collection WHERE type='Order' AND source_collection_id=".(int) Isotope::getCart()->id.")))";
        }

        // Store config restrictions
        $arrProcedures[] = "(configRestrictions=''
                            OR (configRestrictions='1' AND configCondition='' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='configs' AND object_id=".(int) Isotope::getConfig()->id.")>0)
                            OR (configRestrictions='1' AND configCondition='1' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='configs' AND object_id=".(int) Isotope::getConfig()->id.")=0))";


        // Member restrictions
        if (Isotope::getCart()->pid > 0)
        {
            $arrGroups = array_map('intval', deserialize($this->User->groups, true));

            $arrProcedures[] = "(memberRestrictions='none'
                                OR (memberRestrictions='guests' AND memberCondition='1')
                                OR (memberRestrictions='members' AND memberCondition='' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='members' AND object_id=".(int) $this->User->id.")>0)
                                OR (memberRestrictions='members' AND memberCondition='1' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='members' AND object_id=".(int) $this->User->id.")=0)
                                " . (!empty($arrGroups) ? "
                                OR (memberRestrictions='groups' AND memberCondition='' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='groups' AND object_id IN (" . implode(',', $arrGroups) . "))>0)
                                OR (memberRestrictions='groups' AND memberCondition='1' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='groups' AND object_id IN (" . implode(',', $arrGroups) . "))=0)" : '') . ")";
        }
        else
        {
            $arrProcedures[] = "(memberRestrictions='none' OR (memberRestrictions='guests' AND memberCondition=''))";
        }


        // Product restrictions
        if (!is_array($arrProducts))
        {
            $arrProducts = Isotope::getCart()->getProducts();
        }

        if (!empty($arrProducts))
        {
            $arrProductIds = array();
            $arrVariantIds = array();
            $arrAttributes = array();
            $arrTypes = array();

            // Prepare product attribute condition
            $objAttributeRules = $this->Database->execute("SELECT * FROM tl_iso_rules WHERE enabled='1' AND productRestrictions='attribute' AND attributeName!='' GROUP BY attributeName, attributeCondition");
            while( $objAttributeRules->next() )
            {
                $arrAttributes[] = array
                (
                    'attribute'    => $objAttributeRules->attributeName,
                    'condition'    => $objAttributeRules->attributeCondition,
                    'values'    => array(),
                );
            }

            foreach( $arrProducts as $objProduct )
            {
                $arrProductIds[] = $objProduct->pid ? $objProduct->pid : $objProduct->id;
                $arrVariantIds[] = $objProduct->id;
                $arrTypes[] = $objProduct->type;

                if ($objProduct->pid > 0)
                {
                    $arrVariantIds[] = $objProduct->pid;
                }

                if ($blnIncludeVariants)
                {
                    $arrVariantIds = array_merge($arrVariantIds, $objProduct->getVariantIds());
                }

                $arrOptions = $objProduct->getOptions();
                foreach( $arrAttributes as $k => $restriction )
                {
                    $varValue = null;

                    if (isset($arrAttributeData[$restriction['attribute']]))
                    {
                        $varValue = $arrAttributeData[$restriction['attribute']];
                    }
                    elseif (isset($arrOptions[$restriction['attribute']]))
                    {
                        $varValue = $arrOptions[$restriction['attribute']];
                    }
                    else
                    {
                        $varValue = $objProduct->{$restriction['attribute']};
                    }

                    if (!is_null($varValue))
                    {
                        $arrAttributes[$k]['values'][] = is_array($varValue) ? serialize($varValue) : $varValue;
                    }
                }
            }

            $arrProductIds = array_unique($arrProductIds);
            $arrVariantIds = array_unique($arrVariantIds);

            $arrRestrictions = array("productRestrictions='none'");
            $arrRestrictions[] = "(productRestrictions='producttypes' AND productCondition='' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='producttypes' AND object_id IN (" . implode(',', $arrTypes) . "))>0)";
            $arrRestrictions[] = "(productRestrictions='producttypes' AND productCondition='1' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='producttypes' AND object_id IN (" . implode(',', $arrTypes) . "))=0)";
            $arrRestrictions[] = "(productRestrictions='products' AND productCondition='' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='products' AND object_id IN (" . implode(',', $arrProductIds) . "))>0)";
            $arrRestrictions[] = "(productRestrictions='products' AND productCondition='1' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='products' AND object_id IN (" . implode(',', $arrProductIds) . "))=0)";
            $arrRestrictions[] = "(productRestrictions='variants' AND productCondition='' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='variants' AND object_id IN (" . implode(',', $arrVariantIds) . "))>0)";
            $arrRestrictions[] = "(productRestrictions='variants' AND productCondition='1' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='variants' AND object_id IN (" . implode(',', $arrVariantIds) . "))=0)";
            $arrRestrictions[] = "(productRestrictions='pages' AND productCondition='' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='pages' AND object_id IN (SELECT page_id FROM tl_iso_product_categories WHERE pid IN (" . implode(',', $arrProductIds) . ")))>0)";
            $arrRestrictions[] = "(productRestrictions='pages' AND productCondition='1' AND (SELECT COUNT(*) FROM tl_iso_rule_restrictions WHERE pid=r.id AND type='pages' AND object_id IN (SELECT page_id FROM tl_iso_product_categories WHERE pid IN (" . implode(',', $arrProductIds) . ")))=0)";

            foreach( $arrAttributes as $restriction )
            {
                if (empty($restriction['values']))
                    continue;

                $strRestriction = "(productRestrictions='attribute' AND attributeName='" . $restriction['attribute'] . "' AND attributeCondition='" . $restriction['condition'] . "' AND ";

                switch( $restriction['condition'] )
                {
                    case 'eq':
                    case 'neq':
                        $strRestriction .= "attributeValue" . ($restriction['condition'] == 'neq' ? " NOT" : '') . " IN ('" . implode("','", array_map('mysql_real_escape_string', $restriction['values'])) . "')";
                        break;

                    case 'lt':
                    case 'gt':
                    case 'elt':
                    case 'egt':
                        $arrOR = array();
                        foreach( $restriction['values'] as $value )
                        {
                            $arrOR[] = "attributeValue" . (($restriction['condition'] == 'lt' || $restriction['condition'] == 'elt') ? '>' : '<') . (($restriction['condition'] == 'elt' || $restriction['condition'] == 'egt') ? '=' : '') . '?';
                            $arrValues[] = $value;
                        }
                        $strRestriction .= '(' . implode(' OR ', $arrOR) . ')';
                        break;

                    case 'starts':
                    case 'ends':
                    case 'contains':
                        $arrOR = array();
                        foreach( $restriction['values'] as $value )
                        {
                            $arrOR[] = "? LIKE CONCAT(" . (($restriction['condition'] == 'ends' || $restriction['condition'] == 'contains') ? "'%', " : '') . "attributeValue" . (($restriction['condition'] == 'starts' || $restriction['condition'] == 'contains') ? ", '%'" : '') . ")";
                            $arrValues[] = $value;
                        }
                        $strRestriction .= '(' . implode(' OR ', $arrOR) . ')';
                        break;

                    default:
                        throw new \InvalidArgumentException('Unknown rule condition "' . $restrictions['condition'] . '"');
                }

                $arrRestrictions[] = $strRestriction . ')';
            }

            $arrProcedures[] = '(' . implode(' OR ', $arrRestrictions) . ')';
        }


        // Fetch and process rules
        return $this->Database->prepare("SELECT * FROM tl_iso_rules r WHERE " . implode(' AND ', $arrProcedures) . " ORDER BY sorting")->execute($arrValues);
    }


    /**
     * Find coupon matching a code
     */
    protected function findCoupon($strCode, $arrProducts)
    {
        $objRules = $this->findRules(array("type='cart'", "enableCode='1'", "code=?"), array($strCode), $arrProducts);

        return $objRules->numRows ? $objRules->row() : false;
    }


    /**
     * Calculate the total of all products to which apply a rule to
     */
    protected function calculateProductSurcharge($arrRule)
    {
        // Cart subtotal
        if (($arrRule['minSubtotal'] > 0 && Isotope::getCart()->getSubtotal() < $arrRule['minSubtotal']) || ($arrRule['maxSubtotal'] > 0 && Isotope::getCart()->getSubtotal() > $arrRule['maxSubtotal']))
        {
            return false;
        }

        $arrProducts = Isotope::getCart()->getProducts();

        $blnMatch = false;
        $blnPercentage = false;
        $fltTotal = 0;

        if (strpos($arrRule['discount'], '%') !== false)
        {
            $blnPercentage = true;
            $fltDiscount = rtrim($arrRule['discount'], '%');
        }

        $arrSurcharge = array
        (
            'label'            => Isotope::translate(($arrRule['label'] ? $arrRule['label'] : $arrRule['name'])),
            'price'            => ($blnPercentage ? $fltDiscount.'%' : ''),
            'total_price'    => 0,
            'tax_class'        => 0,
            'before_tax'    => true,
            'products'        => array(),
        );

        // Product or producttype restrictions
        if ($arrRule['productRestrictions'] != '' && $arrRule['productRestrictions'] != 'none')
        {
            $arrLimit = $this->Database->execute("SELECT object_id FROM tl_iso_rule_restrictions WHERE pid={$arrRule['id']} AND type='{$arrRule['productRestrictions']}'")->fetchEach('object_id');

            if ($arrRule['productRestrictions'] == 'pages' && !empty($arrLimit))
            {
                $arrLimit = $this->Database->execute("SELECT pid FROM tl_iso_product_categories WHERE page_id IN (" . implode(',', $arrLimit) . ")")->fetchEach('pid');
            }

            if ($arrRule['quantityMode'] == 'cart_products' || $arrRule['quantityMode'] == 'cart_items')
            {
                $intTotal = 0;
                foreach( $arrProducts as $objProduct )
                {
                    if ((($arrRule['productRestrictions'] == 'products' || $arrRule['productRestrictions'] == 'variants' || $arrRule['productRestrictions'] == 'pages')
                        && (in_array($objProduct->id, $arrLimit) || ($objProduct->pid > 0 && in_array($objProduct->pid, $arrLimit))))
                    || ($arrRule['productRestrictions'] == 'producttypes' && in_array($objProduct->type, $arrLimit)))
                    {
                        $intTotal += $arrRule['quantityMode'] == 'cart_items' ? $objProduct->quantity_requested : 1;
                    }
                }
            }
        }
        else
        {
            switch( $arrRule['quantityMode'] )
            {
                case 'cart_products':
                    $intTotal = Isotope::getCart()->products;
                    break;

                case 'cart_items':
                    $intTotal = Isotope::getCart()->items;
                    break;
            }
        }

        foreach( $arrProducts as $objProduct )
        {
            // Product restrictions
            if ((($arrRule['productRestrictions'] == 'products' || $arrRule['productRestrictions'] == 'variants' || $arrRule['productRestrictions'] == 'pages')
                && (!in_array($objProduct->id, $arrLimit) && ($objProduct->pid == 0 || !in_array($objProduct->pid, $arrLimit))))
            || ($arrRule['productRestrictions'] == 'producttypes' && !in_array($objProduct->type, $arrLimit)))
            {
                continue;
            }
            elseif ($arrRule['productRestrictions'] == 'attribute')
            {
                switch ($arrRule['attributeCondition'])
                {
                    case 'eq':
                        if (!($objProduct->{$arrRule['attributeName']} == $arrRule['attributeValue'])) {
                            continue(2);
                        }
                        break;

                    case 'neq':
                        if (!($objProduct->{$arrRule['attributeName']} != $arrRule['attributeValue'])) {
                            continue(2);
                        }
                        break;

                    case 'lt':
                        if (!($objProduct->{$arrRule['attributeName']} < $arrRule['attributeValue'])) {
                            continue(2);
                        }
                        break;

                    case 'gt':
                        if (!($objProduct->{$arrRule['attributeName']} > $arrRule['attributeValue'])) {
                            continue(2);
                        }
                        break;

                    case 'elt':
                        if (!($objProduct->{$arrRule['attributeName']} <= $arrRule['attributeValue'])) {
                            continue(2);
                        }
                        break;

                    case 'egt':
                        if (!($objProduct->{$arrRule['attributeName']} >= $arrRule['attributeValue'])) {
                            continue(2);
                        }
                        break;

                    case 'starts':
                        if (stripos($objProduct->{$arrRule['attributeName']}, $arrRule['attributeValue']) !== 0) {
                            continue(2);
                        }
                        break;

                    case 'ends':
                        if (strripos($objProduct->{$arrRule['attributeName']}, $arrRule['attributeValue']) !== (strlen($objProduct->{$arrRule['attributeName']}) - strlen($arrRule['attributeValue']))) {
                            continue(2);
                        }
                        break;

                    case 'contains':
                        if (stripos($objProduct->{$arrRule['attributeName']}, $arrRule['attributeValue']) === false) {
                            continue(2);
                        }
                        break;

                    default:
                        throw new Exception('Unknown rule condition "' . $arrRule['attributeCondition'] . '"');
                }
            }

            // Because we apply to the quantity of only this product, we override $intTotal in every foreach loop
            if ($arrRule['quantityMode'] != 'cart_products' && $arrRule['quantityMode'] != 'cart_items')
            {
                $intTotal = $objProduct->quantity_requested;
            }

            // Quantity does not match, do not apply to this product
            if (($arrRule['minItemQuantity'] > 0 && $arrRule['minItemQuantity'] > $intTotal) || ($arrRule['maxItemQuantity'] > 0 && $arrRule['maxItemQuantity'] < $intTotal))
            {
                continue;
            }

            // Apply To
            switch( $arrRule['applyTo'] )
            {
                case 'products':
                    // @todo $objProduct->total_price is no longer available
                    $fltPrice = $blnPercentage ? ($objProduct->total_price / 100 * $fltDiscount) : $arrRule['discount'];
                    $fltPrice = $fltPrice > 0 ? (floor($fltPrice * 100) / 100) : (ceil($fltPrice * 100) / 100);
                    $arrSurcharge['total_price'] += $fltPrice;
                    $arrSurcharge['products'][$objProduct->collection_id] = $fltPrice;
                    break;

                case 'items':
                    $fltPrice = ($blnPercentage ? ($objProduct->price / 100 * $fltDiscount) : $arrRule['discount']) * $objProduct->quantity_requested;
                    $fltPrice = $fltPrice > 0 ? (floor($fltPrice * 100) / 100) : (ceil($fltPrice * 100) / 100);
                    $arrSurcharge['total_price'] += $fltPrice;
                    $arrSurcharge['products'][$objProduct->collection_id] = $fltPrice;
                    break;

                case 'subtotal':
                    $blnMatch = true;
                    // @todo $objProduct->total_price is no longer available
                    $arrSurcharge['total_price'] += $objProduct->total_price;

                    if ($arrRule['tax_class'] == -1)
                    {
                        if ($blnPercentage)
                        {
                            $fltPrice = $objProduct->total_price / 100 * $fltDiscount;
                            $arrSurcharge['products'][$objProduct->collection_id] = $fltPrice;
                        }
                        else
                        {
                            $arrSubtract[] = $objProduct;
                            $fltTotal += (float) $objProduct->tax_free_total_price;
                            // @todo $objProduct->tax_free_total_price does no longer exist
                        }
                    }
                    break;
            }
        }

        if ($arrRule['applyTo'] == 'subtotal' && $blnMatch)
        {
            // discount total! not related to tax subtraction
            $fltPrice = $blnPercentage ? ($arrSurcharge['total_price'] / 100 * $fltDiscount) : $arrRule['discount'];
            $arrSurcharge['total_price'] = $fltPrice > 0 ? (floor(round($fltPrice * 100, 4)) / 100) : (ceil(round($fltPrice * 100, 4)) / 100);
            $arrSurcharge['before_tax'] = ($arrRule['tax_class'] != 0 ? true : false);
            $arrSurcharge['tax_class'] = ($arrRule['tax_class'] > 0 ? $arrRule['tax_class'] : 0);

            // If fixed price discount with splitted taxes, calculate total amount of discount per taxed product
            if ($arrRule['tax_class'] == -1 && !$blnPercentage)
            {
                foreach( $arrSubtract as $objProduct )
                {
                    // @todo $objProduct->tax_free_total_price does no longer exist
                    $arrSurcharge['products'][$objProduct->collection_id] = $arrRule['discount'] / 100 * (100 / $fltTotal * $objProduct->tax_free_total_price);
                }
            }
        }

        return $arrSurcharge['total_price'] == 0 ? false : $arrSurcharge;
    }
}
