<?php
/*
 *      OSCLass – software for creating and publishing online classified
 *                           advertising platforms
 *
 *                        Copyright (C) 2013 OSCLASS
 *
 *       This program is free software: you can redistribute it and/or
 *     modify it under the terms of the GNU Affero General Public License
 *     as published by the Free Software Foundation, either version 3 of
 *            the License, or (at your option) any later version.
 *
 *     This program is distributed in the hope that it will be useful, but
 *         WITHOUT ANY WARRANTY; without even the implied warranty of
 *        MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *             GNU Affero General Public License for more details.
 *
 *      You should have received a copy of the GNU Affero General Public
 * License along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/*
Plugin Name: Payment system
Plugin URI: http://www.osclass.org/
Description: Payment system
Version: 3.0.0
Author: OSClass
Author URI: http://www.osclass.org/
Short Name: payments
*/


    define('PAYMENT_CRYPT_KEY', 'randompasswordchangethis');
    // PAYMENT STATUS
    define('PAYMENT_FAILED', 0);
    define('PAYMENT_COMPLETED', 1);
    define('PAYMENT_PENDING', 2);
    define('PAYMENT_ALREADY_PAID', 3);


    // load necessary functions
    require_once osc_plugins_path() . osc_plugin_folder(__FILE__) . 'functions.php';
    require_once osc_plugins_path() . osc_plugin_folder(__FILE__) . 'ModelPayment.php';
    // Load different methods of payments
    if(osc_get_preference('paypal_enabled', 'payment')==1) {
        require_once osc_plugins_path() . osc_plugin_folder(__FILE__) . 'payments/paypal/Paypal.php';
    }
    if(osc_get_preference('blockchain_enabled', 'payment')==1) {
        require_once osc_plugins_path() . osc_plugin_folder(__FILE__) . 'payments/blockchain/Blockchain.php'; // Ready, but untested
    }
    if(osc_get_preference('braintree_enabled', 'payment')==1) {
        require_once osc_plugins_path() . osc_plugin_folder(__FILE__) . 'payments/braintree/BraintreePayment.php';
        osc_add_hook('ajax_braintree', array('BraintreePayment', 'ajaxPayment'));
    }
    if(osc_get_preference('stripe_enabled', 'payment')==1) {
        require_once osc_plugins_path() . osc_plugin_folder(__FILE__) . 'payments/stripe/StripePayment.php';
        osc_add_hook('ajax_stripe', array('StripePayment', 'ajaxPayment'));
    }
    if(osc_get_preference('coinjar_enabled', 'payment')==1) {
        require_once osc_plugins_path() . osc_plugin_folder(__FILE__) . 'payments/coinjar/CoinjarPayment.php';
        osc_add_route('coinjar-notify', 'payment/coinjar-notify/(.+)', 'payment/coinjar-notify/{extra}', osc_plugin_folder(__FILE__).'payments/coinjar/notify_url.php', true);
        osc_add_route('coinjar-return', 'payment/coinjar-return/(.+)', 'payment/coinjar-return/{extra}', osc_plugin_folder(__FILE__).'payments/coinjar/return.php', true);
        osc_add_route('coinjar-cancel', 'payment/coinjar-cancel/(.+)', 'payment/coinjar-cancel/{extra}', osc_plugin_folder(__FILE__).'payments/coinjar/cancel.php', true);
        osc_add_hook('ajax_coinjar', array('CoinjarPayment', 'createOrder'));
    }


    /**
    * Create tables and variables on t_preference and t_pages
    */
    function payment_install() {
        ModelPayment::newInstance()->install();
    }

    /**
    * Clean up all the tables and preferences
    */
    function payment_uninstall() {
        ModelPayment::newInstance()->uninstall();
    }

    /**
    * Create a menu on the admin panel
    */
    function payment_admin_menu() {
        osc_add_admin_submenu_divider('plugins', 'Payment plugin', 'payment_divider', 'administrator');
        osc_add_admin_submenu_page('plugins', __('Payment options', 'payment'), osc_route_admin_url('payment-admin-conf'), 'payment_settings', 'administrator');
        osc_add_admin_submenu_page('plugins', __('Categories fees', 'payment'), osc_route_admin_url('payment-admin-prices'), 'payment_prices', 'administrator');
        osc_add_admin_submenu_page('plugins', __('Configure packs', 'payment'), osc_route_admin_url('payment-admin-packs'), 'payment_packs', 'administrator');
    }

    /**
     * Load payment's js library
     */
    function payment_load_lib() {
        if(Params::getParam('page')=='custom') {
            osc_enqueue_style('payment-plugin', osc_base_url().'oc-content/plugins/'.osc_plugin_folder(__FILE__).'style.css');
            if(osc_get_preference('paypal_enabled', 'payment')==1) {
                osc_register_script('paypal', 'https://www.paypalobjects.com/js/external/dg.js', array('jquery'));
                osc_enqueue_script('paypal');
            }
            if(osc_get_preference('blockchain_enabled', 'payment')==1) {
                osc_register_script('blockchain', 'https://blockchain.info/Resources/wallet/pay-now-button.js', array('jquery'));
                osc_enqueue_script('blockchain');
            }
            if(osc_get_preference('stripe_enabled', 'payment')==1) {
                osc_register_script('stripe', 'https://checkout.stripe.com/v2/checkout.js', array('jquery'));
                osc_enqueue_script('stripe');
            }
        }
    }

    /**
     * Redirect to payment page after publishing an item
     *
     * @param integer $item
     */
    function payment_publish($item) {
        // Need to pay to publish ?
        if(osc_get_preference('pay_per_post', 'payment')==1) {
            $category_fee = ModelPayment::newInstance()->getPublishPrice($item['fk_i_category_id']);
            payment_send_email($item, $category_fee);
            if($category_fee>0) {
                // Catch and re-set FlashMessages
                osc_resend_flash_messages();
                $mItems = new ItemActions(false);
                $mItems->disable($item['pk_i_id']);
                ModelPayment::newInstance()->createItem($item['pk_i_id'],0);
                osc_redirect_to(osc_route_url('payment-publish', array('itemId' => $item['pk_i_id'])));
            } else {
                // PRICE IS ZERO
                ModelPayment::newInstance()->createItem($item['pk_i_id'], 1);
            }
        } else {
            // NO NEED TO PAY PUBLISH FEE
            payment_send_email($item, 0);
            if(osc_get_preference('allow_premium', 'payment')==1) {
                $premium_fee = ModelPayment::newInstance()->getPremiumPrice($item['fk_i_category_id']);
                if($premium_fee>0) {
                    osc_redirect_to(osc_route_url('payment-premium', array('itemId' => $item['pk_i_id'])));
                }
            }
        }
    }

    /**
     * Create a new menu option on users' dashboards
     */
    function payment_user_menu() {
        echo '<li class="opt_payment" ><a href="'.osc_route_url('payment-user-menu').'" >'.__("Listings payment status", "payment").'</a></li>' ;
        if((osc_get_preference('pack_price_1', 'payment')!='' && osc_get_preference('pack_price_1', 'payment')!='0')
            || (osc_get_preference('pack_price_2', 'payment')!='' && osc_get_preference('pack_price_2', 'payment')!='0')
            || (osc_get_preference('pack_price_3', 'payment')!='' && osc_get_preference('pack_price_3', 'payment')!='0')) {
                echo '<li class="opt_payment_pack" ><a href="'.osc_route_url('payment-user-pack').'" >'.__("Buy credit for payments", "payment").'</a></li>' ;
        }
    }

    /**
     * Executed hourly with cron to clean up the expired-premium ads
     */
    function payment_cron() {
        ModelPayment::newInstance()->purgeExpired();
    }

    /**
     * Executed when an item is manually set to NO-premium to clean up it on the plugin's table
     *
     * @param integer $id
     */
    function payment_premium_off($id) {
        ModelPayment::newInstance()->premiumOff($id);
    }

    /**
     * Executed before editing an item
     *
     * @param array $item
     */
    function payment_before_edit($item) {
        // avoid category changes once the item is paid
        if((osc_get_preference('pay_per_post', 'payment') == '1' && ModelPayment::newInstance()->publishFeeIsPaid($item['pk_i_id']))|| (osc_get_preference('allow_premium','payment') == '1' && ModelPayment::newInstance()->premiumFeeIsPaid($item['pk_i_id']))) {
            $cat[0] = Category::newInstance()->findByPrimaryKey($item['fk_i_category_id']);
            View::newInstance()->_exportVariableToView('categories', $cat);
        }
    }


    /**
     * Executed before showing an item
     *
     * @param array $item
     */
    function payment_show_item($item) {
        if(osc_get_preference("pay_per_post", "payment")=="1" && !ModelPayment::newInstance()->publishFeeIsPaid($item['pk_i_id']) ) {
            payment_publish($item);
        };
    };

    function payment_item_delete($itemId) {
        ModelPayment::newInstance()->deleteItem($itemId);
    }

    function payment_configure_link() {
        osc_redirect_to(osc_route_admin_url('payment-admin-conf'));
    }

    function payment_update_version() {
        ModelPayment::newInstance()->versionUpdate();
    }

    function payment_user_table($table) {
        $table->removeColumn("date");
        $table->removeColumn("update_date");
        $table->addColumn("payment_pack", __("Payment pack", "payment"));
        $table->addColumn('date', __('Date'));
        $table->addColumn('update_date', __('Update date'));
    }

    function payment_user_row($row, $aRow) {
        $wallet = ModelPayment::newInstance()->getUser($aRow['pk_i_id']);
        if(!isset($wallet['fk_i_pack_id']) || $wallet['fk_i_pack_id']==null || $wallet['fk_i_pack_id']==0) {
            $row['payment_pack'] = __('No payment pack', 'payment');
        } else {
            $pack = ModelPayment::newInstance()->getPack($wallet['fk_i_pack_id']);
            if(!isset($pack['pk_i_id'])) {
                $row['payment_pack'] = __('No payment pack', 'payment');
            } else {
                $row['payment_pack'] = $pack['s_title'];
            }
        }
        return $row;
    }

    /*function payment_user_form($user = null) {
        if(OC_ADMIN) {
            if($user!=null) { // OSCLASS 3.3
                $packs = ModelPayment::newInstance()->listPacks();
                $pack = ModelPayment::newInstance()->getUser($user['pk_i_id']);
                $pack_id = isset($pack['fk_i_pack_id'])?$pack['fk_i_pack_id']:0;
                ?>
                <h3 class="render-title"><?php _e('Payment packs', 'payment'); ?></h3>
                <div class="form-row">
                    <div class="form-label"><?php _e('Selected pack', 'payment'); ?></div>
                    <div class="form-controls">
                        <div class="select-box undefined">
                            <select name="payment_pack" id="payment_pack" >
                                <option value="0"><?php _e('No payment pack', 'payment'); ?></option>
                                <?php foreach($packs as $pack) {
                                    echo '<option value="'.$pack['pk_i_id'].'" '.($pack['pk_i_id']==$pack_id?'selected="selected"':'').'>'.$pack['s_title'].'</option>';
                                }; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php
            }
        }
    }

    function payment_user_edit($userId) {
        if(OC_ADMIN) {
            ModelPayment::newInstance()->updateUserPack($userId, Params::getParam('payment_pack'));
        }
    }*/

    /**
     * ADD ROUTES (VERSION 3.2+)
     */
    osc_add_route('payment-admin-conf', 'payment/admin/conf', 'payment/admin/conf', osc_plugin_folder(__FILE__).'admin/conf.php');
    osc_add_route('payment-admin-prices', 'payment/admin/prices', 'payment/admin/prices', osc_plugin_folder(__FILE__).'admin/conf_prices.php');
    osc_add_route('payment-admin-packs', 'payment/admin/packs', 'payment/admin/packs', osc_plugin_folder(__FILE__).'admin/conf_packs.php');
    osc_add_route('payment-publish', 'payment/publish/([0-9]+)', 'payment/publish/{itemId}', osc_plugin_folder(__FILE__).'user/payperpublish.php');
    osc_add_route('payment-premium', 'payment/premium/([0-9]+)', 'payment/premium/{itemId}', osc_plugin_folder(__FILE__).'user/makepremium.php');
    osc_add_route('payment-user-menu', 'payment/menu', 'payment/menu', osc_plugin_folder(__FILE__).'user/menu.php', true);
    osc_add_route('payment-user-pack', 'payment/pack', 'payment/pack', osc_plugin_folder(__FILE__).'user/pack.php', true);
    osc_add_route('payment-wallet', 'payment/wallet/([^\/]+)/([^\/]+)/([^\/]+)/(.+)', 'payment/wallet/{a}/{extra}/{desc}/{product}', osc_plugin_folder(__FILE__).'/user/wallet.php', true);



    /**
     * ADD HOOKS
     */
    osc_register_plugin(osc_plugin_path(__FILE__), 'payment_install');
    osc_add_hook(osc_plugin_path(__FILE__)."_configure", 'payment_configure_link');
    osc_add_hook(osc_plugin_path(__FILE__)."_uninstall", 'payment_uninstall');
    osc_add_hook(osc_plugin_path(__FILE__)."_enable", 'payment_update_version');

    osc_add_hook('admin_menu_init', 'payment_admin_menu');
    osc_add_hook('admin_users_table', 'payment_user_table');
    osc_add_filter('users_processing_row', 'payment_user_row');
    //osc_add_hook('user_form', 'payment_user_form');
    //osc_add_hook('user_edit_completed', 'payment_user_edit');

    osc_add_hook('init', 'payment_load_lib');
    osc_add_hook('posted_item', 'payment_publish', 10);
    osc_add_hook('user_menu', 'payment_user_menu');
    osc_add_hook('cron_hourly', 'payment_cron');
    osc_add_hook('item_premium_off', 'payment_premium_off');
    osc_add_hook('before_item_edit', 'payment_before_edit');
    osc_add_hook('show_item', 'payment_show_item');
    osc_add_hook('delete_item', 'payment_item_delete');

?>
