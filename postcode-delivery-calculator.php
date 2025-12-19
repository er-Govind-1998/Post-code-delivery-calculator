<?php

/**

 * Plugin Name: Postcode Delivery Calculator

 * Plugin URI: https://woodelivery.com

 * Description: Professional delivery cost calculation for postcodes with WooCommerce shipping integration

 * Version: 1.0.0

 * Author: Goverdhan verma

 * License: GPL v2 or later

 * Text Domain: postcode-delivery-calculator

 * Requires at least: 5.0

 * Tested up to: 6.9

 * WC requires at least: 5.0

 * WC tested up to: 8.0

 */



// Prevent direct access

if (!defined('ABSPATH')) {

    exit;

}



// Check if WooCommerce is active

add_action('plugins_loaded', 'postcode_delivery_check_woocommerce');

function postcode_delivery_check_woocommerce() {

    if (!class_exists('WooCommerce')) {

        add_action('admin_notices', function() {

            echo '<div class="notice notice-error"><p>Postcode Delivery Calculator requires WooCommerce to be installed and active.</p></div>';

        });

        return;

    }

    

    // Initialize plugin only if WooCommerce is available

    new Postcode_Delivery_Calculator();

}



// Define shipping method class after WooCommerce shipping is initialized

add_action('woocommerce_shipping_init', 'postcode_delivery_init_shipping_method');

function postcode_delivery_init_shipping_method() {

    if (!class_exists('Postcode_Delivery_Calculator_Shipping_Method')) {

        class Postcode_Delivery_Calculator_Shipping_Method extends WC_Shipping_Method {

            

            public function __construct($instance_id = 0) {

                $this->id = 'postcode_delivery';

                $this->instance_id = absint($instance_id);

                $this->method_title = __('Postcode Delivery Calculator', 'postcode-delivery-calculator');

                $this->method_description = __('Calculate delivery costs based on UK postcodes', 'postcode-delivery-calculator');

                

                $this->supports = array(

                    'shipping-zones',

                    'instance-settings',

                );

                

                $this->init();

            }

            

            public function init() {

                $this->init_form_fields();

                $this->init_settings();

                

                add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));

            }

            

            public function init_form_fields() {

                $this->form_fields = array(

                    'title' => array(

                        'title' => __('Title', 'postcode-delivery-calculator'),

                        'type' => 'text',

                        'description' => __('This controls the title which the user sees during checkout.', 'postcode-delivery-calculator'),

                        'default' => __('Postcode Delivery', 'postcode-delivery-calculator'),

                        'desc_tip' => true,

                    ),

                    'description' => array(

                        'title' => __('Description', 'postcode-delivery-calculator'),

                        'type' => 'textarea',

                        'description' => __('This controls the description which the user sees during checkout.', 'postcode-delivery-calculator'),

                        'default' => __('Delivery cost calculated based on your postcode', 'postcode-delivery-calculator'),

                        'desc_tip' => true,

                    ),

                );

            }

            

            public function calculate_shipping($package = array()) {

                // Get delivery options from session

                $delivery_options = WC()->session ? WC()->session->get('available_delivery_options') : array();

                

                if (empty($delivery_options)) {

                    // Show default message prompting user to enter postcode

                    $rate = array(

                        'id' => $this->id . '_default',

                        'label' => 'Enter postcode below to see delivery options',

                        'cost' => 0,

                        'meta_data' => array()

                    );

                    $this->add_rate($rate);

                    return;

                }

                

                // Add all delivery options as shipping rates so user can select

                foreach ($delivery_options as $option) {

                    $cost = ($option['type'] === 'collection') ? 0 : $option['cost_inc_vat'];

                    

                    $rate = array(

                        'id' => $this->id . '_' . $option['id'],

                        'label' => $option['name'],

                        'cost' => $cost,

                        'meta_data' => array(

                            'delivery_option' => $option

                        )

                    );

                    

                    $this->add_rate($rate);

                }

            }

        }

    }

}



class Postcode_Delivery_Calculator {

    private $plugin_path;

    private $plugin_url;

    private $table_zones;

    private $table_postcodes;

    private $table_pricing_tiers;

    private $delivery_calculator_shown = false;

    

    public function __construct() {

        $this->plugin_path = plugin_dir_path(__FILE__);

        $this->plugin_url = plugin_dir_url(__FILE__);

        

        // Initialize database table names

        global $wpdb;

        $this->table_zones = $wpdb->prefix . 'postcode_delivery_zones';

        $this->table_postcodes = $wpdb->prefix . 'postcode_delivery_postcodes';

        $this->table_pricing_tiers = $wpdb->prefix . 'postcode_delivery_pricing_tiers';

        

        // Create database tables on activation

        register_activation_hook(__FILE__, array($this, 'create_database_tables'));

        

        // Also check and create tables on init if they don't exist

        add_action('init', array($this, 'check_and_create_tables'));

        add_action('init', array($this, 'init'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        

        // WooCommerce shipping integration

        add_action('woocommerce_shipping_init', array($this, 'init_shipping_method'));

        add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));

        

        // Add postcode input before shipping section

        add_action('woocommerce_cart_totals_before_shipping', array($this, 'add_postcode_input_in_shipping'));

        add_action('woocommerce_review_order_before_shipping', array($this, 'add_postcode_input_in_shipping'));

        

        // Hook to save selected shipping method as delivery option

        add_action('woocommerce_checkout_update_order_review', array($this, 'save_selected_shipping_as_delivery_option'));

        

        // AJAX hooks

        add_action('wp_ajax_calculate_delivery_cost', array($this, 'ajax_calculate_delivery_cost'));

        add_action('wp_ajax_nopriv_calculate_delivery_cost', array($this, 'ajax_calculate_delivery_cost'));

        add_action('wp_ajax_update_shipping_postcode', array($this, 'ajax_update_shipping_postcode'));

        add_action('wp_ajax_nopriv_update_shipping_postcode', array($this, 'ajax_update_shipping_postcode'));

        add_action('wp_ajax_select_delivery_option', array($this, 'ajax_select_delivery_option'));

        add_action('wp_ajax_nopriv_select_delivery_option', array($this, 'ajax_select_delivery_option'));

        add_action('wp_ajax_save_zone_pricing', array($this, 'ajax_save_zone_pricing'));

        

        // Hook to recalculate shipping when cart changes

        add_action('woocommerce_cart_updated', array($this, 'recalculate_delivery_on_cart_change'));

        

        // Email hooks

        add_action('woocommerce_email_order_meta', array($this, 'add_delivery_info_to_emails'), 10, 3);

        

        // Admin hooks

        add_action('admin_menu', array($this, 'add_admin_menu'));

        

        // Checkout field modification

        add_filter('woocommerce_checkout_fields', array($this, 'modify_checkout_fields'));

        

        // Order hooks

        add_action('woocommerce_checkout_create_order_shipping_item', array($this, 'save_delivery_info_to_order_shipping'), 10, 4);

        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_delivery_info_to_order'));

        

        // Custom shipping method display (to avoid price duplication)

        add_filter('woocommerce_cart_shipping_method_full_label', array($this, 'custom_shipping_method_label'), 10, 2);



        // Don't add delivery fees as separate line items - add them to shipping instead

        // add_action('woocommerce_cart_calculate_fees', array($this, 'add_delivery_fee_to_cart'));

    }

    

    /**

     * Create database tables for storing delivery zones, postcodes, and pricing tiers

     */

    public function create_database_tables() {

        global $wpdb;

        

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        

        // Create delivery zones table

        $sql_zones = "CREATE TABLE {$this->table_zones} (

            id int(11) NOT NULL AUTO_INCREMENT,

            name varchar(255) NOT NULL,

            enabled tinyint(1) DEFAULT 1,

            delivery_time varchar(255) DEFAULT '',

            description text DEFAULT '',

            vat_setting enum('global','custom','none') DEFAULT 'global',

            custom_vat_rate decimal(5,2) DEFAULT 0.00,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id)

        ) {$wpdb->get_charset_collate()};";

        

        // Create postcodes table (linked to zones)

        $sql_postcodes = "CREATE TABLE {$this->table_postcodes} (

            id int(11) NOT NULL AUTO_INCREMENT,

            zone_id int(11) NOT NULL,

            postcode varchar(10) NOT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY zone_id (zone_id),

            KEY postcode (postcode),

            FOREIGN KEY (zone_id) REFERENCES {$this->table_zones}(id) ON DELETE CASCADE

        ) {$wpdb->get_charset_collate()};";

        

        // Create pricing tiers table (linked to zones)

        $sql_pricing = "CREATE TABLE {$this->table_pricing_tiers} (

            id int(11) NOT NULL AUTO_INCREMENT,

            zone_id int(11) NOT NULL,

            min_purchase decimal(10,2) DEFAULT 0.00,

            max_purchase decimal(10,2) DEFAULT 999999.99,

            base_cost decimal(10,2) NOT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY zone_id (zone_id),

            FOREIGN KEY (zone_id) REFERENCES {$this->table_zones}(id) ON DELETE CASCADE

        ) {$wpdb->get_charset_collate()};";

        

        dbDelta($sql_zones);

        dbDelta($sql_postcodes);

        dbDelta($sql_pricing);

        

        // Insert default data if tables are empty

        $this->insert_default_data();

        

        // Migrate old data if it exists

        $this->migrate_old_data();

        

        // Update database version

        update_option('postcode_delivery_db_version', '1.0');

    }

    

    /**

     * Check if tables exist and create them if they don't

     */

    public function check_and_create_tables() {

        global $wpdb;

        

        // Check if zones table exists
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $zones_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_zones}'") == $this->table_zones;

        

        // If zones table doesn't exist, create all tables

        if (!$zones_table_exists) {

            $this->create_database_tables();

        }

    }

    

    /**

     * Insert default data into database tables

     */

    private function insert_default_data() {

        global $wpdb;

        

        // Check if zones already exist

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing_zones = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_zones}");

        

        if ($existing_zones == 0) {

            // Insert default zones

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_zones, array(

                'name' => 'Zone 1 - Local',

                'enabled' => 1,

                'delivery_time' => '1-2 working days',

                'description' => 'Local delivery area',

                'vat_setting' => 'global',

                'custom_vat_rate' => 0.00

            ));

            $zone1_id = $wpdb->insert_id;

            

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_zones, array(

                'name' => 'Zone 2 - Regional',

                'enabled' => 1,

                'delivery_time' => '2-3 working days',

                'description' => 'Regional delivery area',

                'vat_setting' => 'global',

                'custom_vat_rate' => 0.00

            ));

            $zone2_id = $wpdb->insert_id;

            

            // Insert default postcodes

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_postcodes, array('zone_id' => $zone1_id, 'postcode' => 'CF'));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_postcodes, array('zone_id' => $zone2_id, 'postcode' => 'B'));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_postcodes, array('zone_id' => $zone2_id, 'postcode' => 'M'));

            

            // Insert default pricing tiers

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_pricing_tiers, array(

                'zone_id' => $zone1_id,

                'min_purchase' => 0,

                'max_purchase' => 100,

                'base_cost' => 20

            ));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_pricing_tiers, array(

                'zone_id' => $zone1_id,

                'min_purchase' => 100.01,

                'max_purchase' => 999999.99,

                'base_cost' => 10

            ));

            

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_pricing_tiers, array(

                'zone_id' => $zone2_id,

                'min_purchase' => 0,

                'max_purchase' => 200,

                'base_cost' => 30

            ));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_pricing_tiers, array(

                'zone_id' => $zone2_id,

                'min_purchase' => 200.01,

                'max_purchase' => 999999.99,

                'base_cost' => 15

            ));

        }

    }

    

    /**

     * Migrate old option-based data to database tables

     */

    private function migrate_old_data() {

        global $wpdb;

        

        // Check if old data exists

        $old_zones = get_option('postcode_delivery_zones', array());

        

        if (!empty($old_zones) && is_array($old_zones)) {

            // Check if we already have data in the database

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing_zones = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_zones}");

            

            if ($existing_zones == 0) {

                // Migrate old zones to database

                foreach ($old_zones as $zone_id => $zone_data) {

                    // Insert zone

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_zones, array(

                        'name' => isset($zone_data['name']) ? $zone_data['name'] : "Zone {$zone_id}",

                        'enabled' => isset($zone_data['enabled']) ? ($zone_data['enabled'] ? 1 : 0) : 1,

                        'delivery_time' => isset($zone_data['delivery_time']) ? $zone_data['delivery_time'] : '',

                        'description' => isset($zone_data['description']) ? $zone_data['description'] : '',

                        'vat_setting' => isset($zone_data['vat_setting']) ? $zone_data['vat_setting'] : 'global',

                        'custom_vat_rate' => isset($zone_data['custom_vat_rate']) ? floatval($zone_data['custom_vat_rate']) : 0.00

                    ));

                    

                    $new_zone_id = $wpdb->insert_id;

                    

                    // Insert postcodes

                    if (isset($zone_data['postcodes']) && is_array($zone_data['postcodes'])) {

                        foreach ($zone_data['postcodes'] as $postcode) {

                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_postcodes, array(

                                'zone_id' => $new_zone_id,

                                'postcode' => strtoupper(trim($postcode))

                            ));

                        }

                    }

                    

                    // Insert pricing tiers

                    if (isset($zone_data['pricing_tiers']) && is_array($zone_data['pricing_tiers'])) {

                        foreach ($zone_data['pricing_tiers'] as $tier) {

                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_pricing_tiers, array(

                                'zone_id' => $new_zone_id,

                                'min_purchase' => isset($tier['min_purchase']) ? floatval($tier['min_purchase']) : 0,

                                'max_purchase' => isset($tier['max_purchase']) ? floatval($tier['max_purchase']) : 999999.99,

                                'base_cost' => isset($tier['base_cost']) ? floatval($tier['base_cost']) : 0

                            ));

                        }

                    } else {

                        // Create default pricing tier from old cost field

                        $cost = isset($zone_data['cost']) ? floatval($zone_data['cost']) : 0;

                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($this->table_pricing_tiers, array(

                            'zone_id' => $new_zone_id,

                            'min_purchase' => 0,

                            'max_purchase' => 999999.99,

                            'base_cost' => $cost

                        ));

                    }

                }

                

                // Backup old data and remove it

                update_option('postcode_delivery_zones_backup', $old_zones);

                delete_option('postcode_delivery_zones');

            }

        }

    }

    

    public function init() {

        // Reset delivery calculator shown flag on each page load

        $this->delivery_calculator_shown = false;

    }

    

    public function enqueue_scripts() {

        if (is_cart() || is_checkout()) {

            // Enqueue jQuery (required)

            wp_enqueue_script('jquery');

            

            // Add inline JavaScript with AJAX variables

            add_action('wp_footer', array($this, 'add_inline_javascript'));

        }

    }

    

    public function init_shipping_method() {

        // The shipping method class is defined outside this class

    }

    

    public function add_shipping_method($methods) {

        $methods['postcode_delivery'] = 'Postcode_Delivery_Calculator_Shipping_Method';

        return $methods;

    }

    

    /**

     * Add postcode input inside shipping section

     */

    public function add_postcode_input_in_shipping() {

        if (!WC()->cart || WC()->cart->is_empty()) {

            return;

        }

        

        // Only show if not already shown

        if ($this->delivery_calculator_shown) {

            return;

        }

        $this->delivery_calculator_shown = true;

        

        echo '<tr class="postcode-delivery-input">';

        echo '<td colspan="2" class="postcode-delivery-container">';

        echo '<div class="postcode-delivery-simple">';

        echo '<strong>' . esc_html(__('Check Delivery Options', 'postcode-delivery-calculator')) . '</strong>';

        echo '<p>' . esc_html(__('Enter your postcode to see available delivery methods', 'postcode-delivery-calculator')) . '</p>';

        echo '<div class="postcode-input-row">';

        echo '<input type="text" class="delivery-postcode-input" placeholder="' . esc_attr(__('Enter postcode (e.g. CM, CR)', 'postcode-delivery-calculator')) . '" maxlength="10" />';

        echo '<button type="button" class="calculate-delivery-btn" onclick="handleDeliveryButtonClick(this);">' . esc_html(__('Check Options', 'postcode-delivery-calculator')) . '</button>';

        echo '</div>';

        echo '<div class="delivery-status">';

        echo '<div class="delivery-success"><div class="delivery-success-message"></div></div>';

        echo '<div class="delivery-error"></div>';

        echo '</div>';

        echo '</div>';

        echo '</td>';

        echo '</tr>';

    }

    

    /**

     * Add postcode input before shipping row (delivery options will appear in shipping section) - LEGACY

     */

    public function add_postcode_input_before_shipping() {

        if (!WC()->cart || WC()->cart->is_empty()) {

            return;

        }

        

        // Only show if not already shown

        if ($this->delivery_calculator_shown) {

            return;

        }

        $this->delivery_calculator_shown = true;

        

        echo '<tr class="postcode-delivery-input">';

        echo '<th>' . esc_html(__('Delivery Options', 'postcode-delivery-calculator')) . '</th>';

        echo '<td>';

        echo '<input type="text" id="delivery_postcode" placeholder="' . esc_attr(__('Enter postcode', 'postcode-delivery-calculator')) . '" maxlength="10" style="width: 150px; margin-right: 10px;" />';

        echo '<button type="button" id="check_delivery_options" class="button">' . esc_html(__('Check Options', 'postcode-delivery-calculator')) . '</button>';

        echo '<div id="delivery_options_result" style="margin-top: 10px;"></div>';

        echo '</td>';

        echo '</tr>';

    }

    

    /**

     * Add delivery calculator before shipping row (so shipping fees appear after delivery options)

     */

    public function add_delivery_calculator_before_shipping() {

        if (!WC()->cart || WC()->cart->is_empty()) {

            return;

        }

        

        if (!$this->delivery_calculator_shown) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in the method
            echo $this->render_delivery_calculator_row();

            $this->delivery_calculator_shown = true;

        }

    }

    



    

    private function render_delivery_calculator($context = 'cart') {

        // Get current delivery postcode from session

        $delivery_postcode = '';

        if (WC()->session) {

            $delivery_postcode = WC()->session->get('delivery_postcode', '');

        }

        

        ob_start();

        ?>

        <tr class="shipping-calculator-form">

        <td colspan="2">

            <div class="postcode-delivery-calculator" data-context="<?php echo esc_attr($context); ?>">

                <h4><?php esc_html_e('Calculate Delivery Options', 'postcode-delivery-calculator'); ?></h4>

                <div class="postcode-input-wrapper">

                    <label for="delivery_postcode_<?php echo esc_attr($context); ?>"><?php esc_html_e('Enter postcode area:', 'postcode-delivery-calculator'); ?></label>

                    <div class="postcode-input-group">

                        <input type="text" id="delivery_postcode_<?php echo esc_attr($context); ?>" name="delivery_postcode" 

                                class="delivery-postcode-input"

                                placeholder="<?php esc_html_e('e.g., SW1A 1AA, PA21, CF', 'postcode-delivery-calculator'); ?>" 

                                maxlength="10" 

                                value="<?php echo esc_attr($delivery_postcode); ?>"

                                style="text-transform: uppercase;" />

                        <button type="button" class="calculate-delivery-btn button" onclick="handleDeliveryButtonClick(this);">

                            <?php esc_html_e('Check Options', 'postcode-delivery-calculator'); ?>

                        </button>

                        <button type="button" class="test-btn button" onclick="

                            alert('Test button works! jQuery available: ' + (typeof jQuery !== 'undefined') + 

                                  ', handleDeliveryButtonClick available: ' + (typeof handleDeliveryButtonClick !== 'undefined') +

                                  ', Postcode value: ' + document.querySelector('.delivery-postcode-input').value);

                        " style="margin-left: 10px;">

                            Test

                        </button>

                    </div>

                    <p class="description" style="margin-top: 5px; font-size: 0.9em; color: #666;">

                        <?php esc_html_e('Enter your full postcode or postcode area to see available delivery options and timeframes.', 'postcode-delivery-calculator'); ?>

                    </p>

                </div>

                

                <div class="delivery-status" style="display: none;">

                    <div class="delivery-success" style="display: none;">

                        <p class="delivery-success-message"></p>

                    </div>

                    <div class="delivery-error" style="display: none;">

                        <p class="delivery-error-message"></p>

                    </div>

                </div>

            </div>

        </td>

    </tr>



    <style>

    /* Enhanced styles for better display */

    .postcode-delivery-calculator {

        background: #f8f9fa;

        border: 1px solid #e9ecef;

        border-radius: 6px;

        padding: 15px;

        margin: 10px 0;

    }

    .postcode-delivery-calculator h4 {

        margin: 0 0 15px 0;

        color: #2c8aa6;

        font-size: 16px;

        font-weight: 600;

    }

    .postcode-input-wrapper label {

        display: block;

        font-weight: 600;

        margin-bottom: 8px;

        color: #333;

    }

    .postcode-input-group {

        display: flex;

        gap: 10px;

        align-items: center;

        flex-wrap: wrap;

    }

    .delivery-postcode-input {

        flex: 0 0 120px;

        padding: 8px 12px;

        border: 2px solid #ddd;

        border-radius: 4px;

        font-size: 14px;

        font-weight: 600;

        text-transform: uppercase !important;

        background: white;

    }

    .delivery-postcode-input:focus {

        border-color: #2c8aa6;

        outline: none;

        box-shadow: 0 0 0 2px rgba(44, 138, 166, 0.1);

    }

    .calculate-delivery-btn {

        background: #2c8aa6 !important;

        color: white !important;

        border: none !important;

        padding: 13px 18px !important;

        border-radius: 4px !important;

        font-weight: 600 !important;

        cursor: pointer !important;

        transition: background-color 0.3s ease !important;

    }

    .calculate-delivery-btn:hover {

        background: #2c8aa6 !important;

    }

    .calculate-delivery-btn:disabled {

        background: #ccc !important;

        cursor: not-allowed !important;

    }

    .delivery-status {

        margin-top: 15px;

    }

    .delivery-success {

        background: #d4edda;

        border: 1px solid #c3e6cb;

        color: #155724;

        padding: 12px;

        border-radius: 4px;

    }

    .delivery-error {

        background: #f8d7da;

        border: 1px solid #f5c6cb;

        color: #721c24;

        padding: 12px;

        border-radius: 4px;

    }

    .delivery-success p, .delivery-error p {

        margin: 0;

        font-weight: 600;

    }

    

    @media (max-width: 768px) {

        .postcode-input-group {

            flex-direction: column;

            align-items: stretch;

        }

        .delivery-postcode-input {

            flex: 1;

            margin-bottom: 10px;

        }

    }

    </style>

    <?php

    

    return ob_get_clean();

    }

    

    private function render_delivery_calculator_standalone($context = 'cart') {

        // Get current delivery postcode from session

        $delivery_postcode = '';

        if (WC()->session) {

            $delivery_postcode = WC()->session->get('delivery_postcode', '');

        }

        

        ob_start();

        ?>

        <div class="postcode-delivery-calculator-standalone" data-context="<?php echo esc_attr($context); ?>">

            <div class="postcode-delivery-calculator" data-context="<?php echo esc_attr($context); ?>">

                <h4><?php esc_html_e('Calculate Delivery Options', 'postcode-delivery-calculator'); ?></h4>

                <div class="postcode-input-wrapper">

                    <label for="delivery_postcode_<?php echo esc_attr($context); ?>_standalone"><?php esc_html_e('Enter postcode area:', 'postcode-delivery-calculator'); ?></label>

                    <div class="postcode-input-group">

                        <input type="text" id="delivery_postcode_<?php echo esc_attr($context); ?>_standalone" name="delivery_postcode" 

                                class="delivery-postcode-input"

                                placeholder="<?php esc_html_e('e.g., SW1A 1AA, PA21, CF', 'postcode-delivery-calculator'); ?>" 

                                maxlength="10" 

                                value="<?php echo esc_attr($delivery_postcode); ?>"

                                style="text-transform: uppercase;" />

                        <button type="button" class="calculate-delivery-btn button" onclick="handleDeliveryButtonClick(this);">

                            <?php esc_html_e('Check Options', 'postcode-delivery-calculator'); ?>

                        </button>

                        <button type="button" class="test-btn button" onclick="

                            alert('Test button works! jQuery available: ' + (typeof jQuery !== 'undefined') + 

                                  ', handleDeliveryButtonClick available: ' + (typeof handleDeliveryButtonClick !== 'undefined') +

                                  ', Postcode value: ' + document.querySelector('.delivery-postcode-input').value);

                        " style="margin-left: 10px;">

                            Test

                        </button>

                    </div>

                    <p class="description" style="margin-top: 5px; font-size: 0.9em; color: #666;">

                        <?php esc_html_e('Enter your full postcode or postcode area to see available delivery options and timeframes.', 'postcode-delivery-calculator'); ?>

                    </p>

                </div>

                

                <div class="delivery-status" style="display: none;">

                    <div class="delivery-success" style="display: none;">

                        <p class="delivery-success-message"></p>

                    </div>

                    <div class="delivery-error" style="display: none;">

                        <p class="delivery-error-message"></p>

                    </div>

                </div>

            </div>

        </div>



        <style>

        /* Enhanced styles for standalone delivery calculator */

        .postcode-delivery-calculator-standalone {

            margin: 20px 0;

            clear: both;

        }

        .postcode-delivery-calculator {

            background: #f8f9fa;

            border: 1px solid #e9ecef;

            border-radius: 6px;

            padding: 15px;

            margin: 10px 0;

        }

        .postcode-delivery-calculator h4 {

            margin: 0 0 15px 0;

            color: #2c8aa6;

            font-size: 16px;

            font-weight: 600;

        }

        .postcode-input-wrapper label {

            display: block;

            font-weight: 600;

            margin-bottom: 8px;

            color: #333;

        }

        .postcode-input-group {

            display: flex;

            gap: 10px;

            align-items: center;

            flex-wrap: wrap;

        }

        .delivery-postcode-input {

            flex: 0 0 120px;

            padding: 8px 12px;

            border: 2px solid #ddd;

            border-radius: 4px;

            font-size: 14px;

            font-weight: 600;

            text-transform: uppercase !important;

            background: white;

        }

        .delivery-postcode-input:focus {

            border-color: #2c8aa6;

            outline: none;

            box-shadow: 0 0 0 2px rgba(44, 138, 166, 0.1);

        }

        .calculate-delivery-btn, .test-btn {

            background: #2c8aa6;

            color: white;

            border: none;

            padding: 8px 16px;

            border-radius: 4px;

            font-weight: 600;

            cursor: pointer;

            transition: background-color 0.2s;

        }

        .calculate-delivery-btn:hover, .test-btn:hover {

            background: #1e6b7a;

        }

        .calculate-delivery-btn:disabled {

            background: #ccc;

            cursor: not-allowed;

        }

        .delivery-status {

            margin-top: 15px;

        }

        .delivery-success {

            background: #d4edda;

            border: 1px solid #c3e6cb;

            color: #155724;

            padding: 12px;

            border-radius: 4px;

        }

        .delivery-error {

            background: #f8d7da;

            border: 1px solid #f5c6cb;

            color: #721c24;

            padding: 12px;

            border-radius: 4px;

        }

        .delivery-success p, .delivery-error p {

            margin: 0;

            font-weight: 600;

        }

        

        @media (max-width: 768px) {

            .postcode-input-group {

                flex-direction: column;

                align-items: stretch;

            }

            .delivery-postcode-input {

                flex: 1;

                margin-bottom: 10px;

            }

        }

        </style>

        <?php

        

        return ob_get_clean();

    }

    

    /**

     * Render delivery calculator as a table row after shipping

     */

    private function render_delivery_calculator_row() {

        // Get current delivery postcode and selected option from session

        $delivery_postcode = '';

        $selected_option = null;

        $delivery_cost = 0;

        $delivery_name = '';

        

        if (WC()->session) {

            $delivery_postcode = WC()->session->get('delivery_postcode', '');

            $selected_option = WC()->session->get('selected_delivery_option');

            

            if ($selected_option) {

                $delivery_cost = $selected_option['cost'];

                $available_options = WC()->session->get('available_delivery_options', array());

                foreach ($available_options as $option) {

                    if ($option['id'] === $selected_option['id']) {

                        $delivery_name = $option['name'];

                        break;

                    }

                }

            }

        }

        

        ob_start();

        ?>

        <tr class="delivery-options-row">

            <th><?php esc_html_e('Delivery Options', 'postcode-delivery-calculator'); ?></th>

            <td data-title="<?php esc_attr_e('Delivery Options', 'postcode-delivery-calculator'); ?>">

                <div class="postcode-delivery-calculator" data-context="cart" style="margin: 0;">

                    <!-- Postcode Input Section -->

                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px;">

                        <input type="text" 

                               id="delivery-postcode-input" 

                               class="delivery-postcode-input" 

                               value="<?php echo esc_attr($delivery_postcode); ?>" 

                               placeholder="Enter postcode (e.g. SW1A 1AA, PA21, CF)" 

                               style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">

                        <button type="button" 

                                class="button calculate-delivery-btn" 

                                style="padding: 8px 16px; font-size: 14px; white-space: nowrap; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;">

                            <?php esc_html_e('Check Options', 'postcode-delivery-calculator'); ?>

                        </button>

                    </div>

                    

                    <!-- Current Selection Display -->

                    <?php if ($delivery_cost > 0 && $delivery_name): ?>

                    <div class="current-delivery-selection" style="margin-bottom: 10px; padding: 10px; background: #e8f5e8; border: 1px solid #4caf50; border-radius: 4px;">

                        <strong>Selected:</strong> <?php echo esc_html($delivery_name); ?> - 

                        <span style="color: #2e7d32; font-weight: bold;">£<?php echo number_format($delivery_cost, 2); ?></span>

                    </div>

                    <?php elseif ($selected_option && $selected_option['type'] === 'collection'): ?>

                    <div class="current-delivery-selection" style="margin-bottom: 10px; padding: 10px; background: #e8f5e8; border: 1px solid #4caf50; border-radius: 4px;">

                        <strong>Selected:</strong> <?php echo esc_html($delivery_name ?: 'Click & Collect'); ?> - 

                        <span style="color: #2e7d32; font-weight: bold;">FREE</span>

                    </div>

                    <?php else: ?>

                    <div class="no-delivery-selected" style="margin-bottom: 10px; padding: 10px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; color: #666; text-align: center;">

                        <em>Enter your postcode above to see available delivery options</em>

                    </div>

                    <?php endif; ?>

                    

                    <!-- Status Messages -->

                    <div class="delivery-status" style="display: none;">

                        <div class="delivery-success" style="display: none;">

                            <div class="delivery-success-message" style="color: #0073aa; font-size: 14px; margin-top: 10px;"></div>

                        </div>

                        <div class="delivery-error" style="display: none;">

                            <div class="delivery-error-message" style="color: #d63638; font-size: 14px; margin-top: 10px;"></div>

                        </div>

                    </div>

                </div>

            </td>

        </tr>

        <?php

        return ob_get_clean();

    }





    public function ajax_calculate_delivery_cost() {

        // Verify nonce

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'postcode_delivery_nonce')) {

            wp_die('Security check failed');

        }

        

        $postcode = isset($_POST['postcode']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['postcode']))) : '';

        $context = isset($_POST['context']) ? sanitize_text_field(wp_unslash($_POST['context'])) : 'cart';

        

        if (empty($postcode)) {

            wp_send_json_error(array('message' => __('Please enter a postcode', 'postcode-delivery-calculator')));

        }

        

        // Check if postcode is excluded

        $excluded_postcodes = get_option('postcode_delivery_excluded', array());

        if (is_array($excluded_postcodes)) {

            foreach ($excluded_postcodes as $excluded) {

                $excluded = strtoupper(trim($excluded));

                if (strpos($postcode, $excluded) === 0) {

                    wp_send_json_error(array('message' => __('Sorry, we do not deliver to this postcode area.', 'postcode-delivery-calculator')));

                }

            }

        }

        

        // Get delivery options

        $delivery_options = $this->get_delivery_options($postcode);

        

        // Store in session

        if (WC()->session) {

            WC()->session->set('delivery_postcode', $postcode);

            WC()->session->set('available_delivery_options', $delivery_options);

        }

        

        // Check if we have any delivery options

        if (empty($delivery_options)) {

            // No options at all (not even collection) - this shouldn't happen normally

            wp_send_json_error(array('message' => __('No delivery or collection options available.', 'postcode-delivery-calculator')));

        }

        

        // Format response message

        $delivery_count = 0;

        $collection_count = 0;

        foreach ($delivery_options as $option) {

            if ($option['type'] === 'delivery') {

                $delivery_count++;

            } elseif ($option['type'] === 'collection') {

                $collection_count++;

            }

        }

        

        if ($delivery_count > 0) {

            $options_text = sprintf(

                /* translators: %d: number of delivery options */

                _n('%d delivery option found', '%d delivery options found', $delivery_count, 'postcode-delivery-calculator'),

                $delivery_count

            );

        } else {

            // No delivery zones registered for this postcode

            $options_text = __('This zone is not registered for delivery', 'postcode-delivery-calculator');

        }

        

        // Format detailed response message with selectable options

        if ($delivery_count > 0) {

            $message = 'Available delivery options:';

        } else {

            $message = 'This zone is not registered for delivery. Available options:';

        }

        $options_html = '<div class="delivery-options-selector" style="margin-top: 10px;">';

        

        foreach ($delivery_options as $index => $option) {

            $option_id = $option['id'];

            $cost_display = ($option['type'] === 'collection') ? 'FREE' : '£' . number_format($option['cost_inc_vat'], 2);

            $checked = ''; // Don't pre-select any option - let user choose

            

            $options_html .= '<div style="margin: 5px 0;">';

            $options_html .= '<label style="display: flex; align-items: center; cursor: pointer;">';

            $options_html .= '<input type="radio" name="delivery_option" value="' . esc_attr($option_id) . '" ' . $checked . ' style="margin-right: 8px;" data-cost="' . $option['cost_inc_vat'] . '" data-type="' . $option['type'] . '">';

            $options_html .= '<span><strong>' . esc_html($option['name']) . '</strong> - ' . $cost_display;
           
            if (!empty($option['delivery_time'])) {

                $options_html .= ' (' . esc_html($option['delivery_time']) . ')';

            }

            $options_html .= '</span>';

            $options_html .= '</label>';

            $options_html .= '</div>';

        }

        

        $options_html .= '</div>';

        $options_html .= '<div style="margin-top: 5px; font-size: 12px; color: #666;">Shipping to: ' . $postcode . '</div>';

        

        wp_send_json_success(array(

            'message' => $message,

            'options' => $delivery_options,

            'options_html' => $options_html,

            'postcode' => $postcode

        ));

    }

    

    public function ajax_update_shipping_postcode() {

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'postcode_delivery_nonce')) {

            wp_die('Security check failed');

        }

        

        $postcode = isset($_POST['postcode']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['postcode']))) : '';

        

        // Update customer shipping postcode

        if (WC()->customer && !empty($postcode)) {

            WC()->customer->set_shipping_postcode($postcode);

            WC()->customer->save();

        }

        

        wp_send_json_success(array('message' => 'Shipping postcode updated'));

    }

    

    public function ajax_select_delivery_option() {

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'postcode_delivery_nonce')) {

            wp_die('Security check failed');

        }

        

        $option_id = isset($_POST['option_id']) ? sanitize_text_field(wp_unslash($_POST['option_id'])) : '';

        $cost = isset($_POST['cost']) ? floatval($_POST['cost']) : 0;

        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';

        

        // Store selected option in session

        if (WC()->session) {

            WC()->session->set('selected_delivery_option', array(

                'id' => $option_id,

                'cost' => $cost,

                'type' => $type

            ));

        }

        

        // Trigger cart and shipping calculation to update totals and show shipping options

        if (WC()->cart) {

            // Clear shipping cache to force recalculation

            WC()->shipping()->reset_shipping();

            WC()->cart->calculate_shipping();

            WC()->cart->calculate_totals();

        }

        

        wp_send_json_success(array(

            'message' => 'Delivery option selected',

            'cost' => $cost,

            'type' => $type

        ));

    }

    

    public function ajax_save_zone_pricing() {

        // Verify nonce

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'postcode_delivery_admin')) {

            wp_send_json_error(array('message' => 'Security check failed'));

        }

        

        // Check user permissions

        if (!current_user_can('manage_options')) {

            wp_send_json_error(array('message' => 'Insufficient permissions'));

        }

        

        $zone_id = isset($_POST['zone_id']) ? intval($_POST['zone_id']) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array is sanitized in validate_pricing_tiers
        $pricing_tiers = isset($_POST['pricing_tiers']) ? wp_unslash($_POST['pricing_tiers']) : array();

        

        if (empty($zone_id)) {

            wp_send_json_error(array('message' => 'Invalid zone ID'));

        }

        

        // Validate pricing tiers

        $validation_result = $this->validate_pricing_tiers($pricing_tiers);

        if (!$validation_result['valid']) {

            wp_send_json_error(array('message' => $validation_result['message']));

        }

        

        // Save pricing tiers to database

        $result = $this->save_zone_pricing_to_db($zone_id, $pricing_tiers);

        

        if ($result) {

            wp_send_json_success(array(

                'message' => 'Pricing saved successfully!',

                'zone_id' => $zone_id,

                'tiers_count' => count($pricing_tiers)

            ));

        } else {

            wp_send_json_error(array('message' => 'Failed to save pricing. Please try again.'));

        }

    }

    

    /**

     * Validate pricing tiers for overlaps and logical errors

     */

    private function validate_pricing_tiers($pricing_tiers) {

        if (empty($pricing_tiers)) {

            return array('valid' => false, 'message' => 'At least one pricing tier is required');

        }

        

        $tiers = array();

        foreach ($pricing_tiers as $tier) {

            $min_purchase = floatval($tier['min_purchase']);

            $max_purchase = floatval($tier['max_purchase']);

            $base_cost = floatval($tier['base_cost']);

            

            // Validate individual tier

            if ($min_purchase < 0) {

                return array('valid' => false, 'message' => 'Minimum purchase cannot be negative');

            }

            

            if ($max_purchase <= 0) {

                return array('valid' => false, 'message' => 'Maximum purchase must be greater than 0');

            }

            

            if ($min_purchase >= $max_purchase) {

                return array('valid' => false, 'message' => 'Minimum purchase must be less than maximum purchase');

            }

            

            if ($base_cost < 0) {

                return array('valid' => false, 'message' => 'Base cost cannot be negative');

            }

            

            $tiers[] = array(

                'min' => $min_purchase,

                'max' => $max_purchase,

                'cost' => $base_cost

            );

        }

        

        // Sort tiers by min_purchase for overlap checking

        usort($tiers, function($a, $b) {

            return $a['min'] <=> $b['min'];

        });

        

        // Check for overlaps

        for ($i = 0; $i < count($tiers) - 1; $i++) {

            $current = $tiers[$i];

            $next = $tiers[$i + 1];

            

            if ($current['max'] >= $next['min']) {

                return array('valid' => false, 'message' => 'Pricing tiers cannot overlap. Check ranges: £' . number_format($current['min'], 2) . '-£' . number_format($current['max'], 2) . ' and £' . number_format($next['min'], 2) . '-£' . number_format($next['max'], 2));

            }

        }

        

        return array('valid' => true, 'message' => 'Validation passed');

    }

    

    /**

     * Save zone pricing tiers to database

     */

    private function save_zone_pricing_to_db($zone_id, $pricing_tiers) {

        global $wpdb;

        

        // Check if zone exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $zone_exists = $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) FROM {$this->table_zones} WHERE id = %d",
            $zone_id
        ));

        

        if (!$zone_exists) {

            return false;

        }

        

        // Start transaction
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query('START TRANSACTION');

        

        try {

            // Delete existing pricing tiers for this zone
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->delete(

                $this->table_pricing_tiers,

                array('zone_id' => $zone_id),

                array('%d')

            );

            

            // Insert new pricing tiers
            foreach ($pricing_tiers as $tier) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->insert(

                    $this->table_pricing_tiers,

                    array(

                        'zone_id' => $zone_id,

                        'min_purchase' => floatval($tier['min_purchase']),

                        'max_purchase' => floatval($tier['max_purchase']),

                        'base_cost' => floatval($tier['base_cost'])

                    ),

                    array('%d', '%f', '%f', '%f')

                );

                

                if ($result === false) {

                    throw new Exception('Failed to insert pricing tier');

                }

            }

            

            // Commit transaction
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('COMMIT');

            return true;

            

        } catch (Exception $e) {

            // Rollback transaction
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('ROLLBACK');

            return false;

        }

    }

    

    public function recalculate_delivery_on_cart_change() {

        // If we have a delivery postcode stored, recalculate options

        if (WC()->session) {

            $delivery_postcode = WC()->session->get('delivery_postcode');

            $selected_option = WC()->session->get('selected_delivery_option');

            

            if ($delivery_postcode) {

                $delivery_options = $this->get_delivery_options($delivery_postcode);

                WC()->session->set('available_delivery_options', $delivery_options);

                

                // If we have a selected option, update its cost based on new cart total

                if ($selected_option && isset($selected_option['id'])) {

                    foreach ($delivery_options as $option) {

                        if ($option['id'] === $selected_option['id']) {

                            // Update the selected option with new cost

                            WC()->session->set('selected_delivery_option', array(

                                'id' => $option['id'],

                                'cost' => $option['cost_inc_vat'],

                                'type' => $option['type']

                            ));

                            break;

                        }

                    }

                }

            }

        }

    }

    

    private function get_delivery_options($postcode) {

        global $wpdb;

        

        $options = array();

        

        // Add collection option (always available)

        $collection_option = get_option('postcode_delivery_collection', $this->get_default_collection());

        if (isset($collection_option['enabled']) && $collection_option['enabled']) {

            $options[] = array(

                'id' => 'collection',

                'name' => isset($collection_option['name']) ? $collection_option['name'] : 'Click & Collect',

                'type' => 'collection',

                'cost_ex_vat' => 0,

                'cost_inc_vat' => 0,

                'vat_amount' => 0,

                'delivery_time' => 'Available for collection',

                'description' => isset($collection_option['description']) ? $collection_option['description'] : 'Collect your order from our store'

            );

        }

        

        // Get current cart total for pricing tier calculation

        $cart_total = 0;

        if (WC()->cart) {

            $cart_total = WC()->cart->get_subtotal();

        }

        

        // Debug: Log cart total for troubleshooting

        if (defined('WP_DEBUG') && WP_DEBUG) {

            // error_log("Postcode Delivery Debug - Cart Total: £" . $cart_total . " for postcode: " . $postcode);

        }

        

        // Find matching delivery zones from database

        $matching_zones = $this->get_matching_zones_from_db($postcode);

        

        foreach ($matching_zones as $zone) {

            if (!$zone->enabled) {

                continue; // Zone is disabled, skip

            }

            

            // Calculate cost based on pricing tiers from database

            $pricing_result = $this->calculate_zone_pricing_from_db($zone->id, $cart_total);

            

            if ($pricing_result === false) {

                // Cart total doesn't meet any pricing tier requirements

                continue;

            }

            

            $options[] = array(

                'id' => 'zone_' . $zone->id,

                'name' => $zone->name,

                'type' => 'delivery',

                'cost_ex_vat' => $pricing_result['cost_ex_vat'],

                'cost_inc_vat' => $pricing_result['cost_inc_vat'],

                'vat_amount' => $pricing_result['vat_amount'],

                'delivery_time' => $zone->delivery_time,

                'description' => $zone->description,

                'pricing_tier' => $pricing_result['tier_info'],

                'zone_data' => array(

                    'vat_setting' => $zone->vat_setting,

                    'custom_vat_rate' => $zone->custom_vat_rate

                )

            );

        }

        

        return $options;

    }

    

    /**

     * Get matching zones from database based on postcode

     */

    private function get_matching_zones_from_db($postcode) {

        global $wpdb;

        

        $postcode = strtoupper(trim($postcode));

        

        // Extract different parts of the postcode for matching

        $postcode_parts = $this->extract_postcode_parts($postcode);

        

        // Build dynamic WHERE conditions for flexible matching

        $where_conditions = array();

        $prepare_values = array();

        

        // Add exact match

        $where_conditions[] = "p.postcode = %s";

        $prepare_values[] = $postcode;

        

        // Add matches for each postcode part

        foreach ($postcode_parts as $part) {

            if (!empty($part)) {

                // Exact match for this part

                $where_conditions[] = "p.postcode = %s";

                $prepare_values[] = $part;

                

                // Pattern matching - postcode starts with this part

                $where_conditions[] = "%s LIKE CONCAT(p.postcode, '%%')";

                $prepare_values[] = $postcode;

                

                // Pattern matching - database entry starts with this part

                $where_conditions[] = "p.postcode LIKE CONCAT(%s, '%%')";

                $prepare_values[] = $part;

            }

        }

        

        // Build the final query

        $sql = "SELECT DISTINCT z.* 

                FROM {$this->table_zones} z

                INNER JOIN {$this->table_postcodes} p ON z.id = p.zone_id

                WHERE z.enabled = 1 

                AND (" . implode(' OR ', $where_conditions) . ")

                ORDER BY z.id";

        

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built dynamically with safe placeholders
        return $wpdb->get_results($wpdb->prepare($sql, ...$prepare_values));

    }

    

    /**

     * Extract different parts of a postcode for flexible matching

     * For SW1A 1AA returns: ['SW1A', 'SW1', 'SW']

     * For PA21 returns: ['PA21', 'PA2', 'PA']

     * For B returns: ['B']

     */

    private function extract_postcode_parts($postcode) {

        $postcode = strtoupper(trim(str_replace(' ', '', $postcode))); // Remove spaces

        $parts = array();

        

        if (empty($postcode)) {

            return $parts;

        }

        

        // Add the full postcode (without spaces)

        $parts[] = $postcode;

        

        // For full UK postcodes like SW1A1AA, extract first 4 characters (SW1A)

        if (strlen($postcode) >= 4) {

            $first_four = substr($postcode, 0, 4);

            // Only add if it looks like a valid UK postcode area (letters + numbers)

            if (preg_match('/^[A-Z]{1,2}[0-9]{1,2}[A-Z]?$/', $first_four)) {

                $parts[] = $first_four;

            }

        }

        

        // Extract area + district (first 2-4 characters before final letter/number)

        // For SW1A1AA -> SW1A, SW1

        // For PA21 -> PA2, PA

        if (strlen($postcode) >= 3) {

            // Try different lengths for area+district

            for ($i = 3; $i >= 2; $i--) {

                if (strlen($postcode) >= $i) {

                    $part = substr($postcode, 0, $i);

                    // Check if this looks like a valid UK postcode area

                    if (preg_match('/^[A-Z]{1,2}[0-9]{1,2}$/', $part)) {

                        $parts[] = $part;

                    }

                }

            }

        }

        

        // Extract just the area (first 1-2 letters)

        if (preg_match('/^([A-Z]{1,2})/', $postcode, $matches)) {

            $area = $matches[1];

            if (!in_array($area, $parts)) {

                $parts[] = $area;

            }

        }

        

        // Remove duplicates and return

        return array_unique($parts);

    }

    

    /**

     * Calculate zone pricing from database pricing tiers

     */

    private function calculate_zone_pricing_from_db($zone_id, $cart_total) {

        global $wpdb;

        

        // Get pricing tiers for this zone
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pricing_tiers = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$this->table_pricing_tiers} 
             WHERE zone_id = %d 
             ORDER BY min_purchase ASC",
            $zone_id
        ));

        

        if (empty($pricing_tiers)) {

            return false;

        }

        

        // Get zone data for VAT calculation
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $zone = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$this->table_zones} WHERE id = %d",
            $zone_id
        ));

        

        if (!$zone) {

            return false;

        }

        

        // Find matching pricing tier

        foreach ($pricing_tiers as $tier) {

            $min_purchase = floatval($tier->min_purchase);

            $max_purchase = floatval($tier->max_purchase);

            

            // Debug: Log tier matching

            if (defined('WP_DEBUG') && WP_DEBUG) {

                // error_log("Checking tier: £{$min_purchase} - £{$max_purchase}, Base: £{$tier->base_cost}, Cart: £{$cart_total}");

            }

            

            if ($cart_total >= $min_purchase && $cart_total <= $max_purchase) {

                $cost_ex_vat = floatval($tier->base_cost);

                $vat_rate = $this->get_zone_vat_rate_from_db($zone);

                $vat_amount = $cost_ex_vat * $vat_rate;

                $cost_inc_vat = $cost_ex_vat + $vat_amount;

                

                // Debug: Log selected tier

                if (defined('WP_DEBUG') && WP_DEBUG) {

                    // error_log("Selected tier: Base £{$cost_ex_vat}, VAT {$vat_rate}%, Total £{$cost_inc_vat}");

                }

                

                return array(

                    'cost_ex_vat' => $cost_ex_vat,

                    'vat_amount' => $vat_amount,

                    'cost_inc_vat' => $cost_inc_vat,

                    'tier_info' => array(

                        'min_purchase' => $min_purchase,

                        'max_purchase' => $max_purchase,

                        'base_cost' => $cost_ex_vat

                    )

                );

            }

        }

        

        // No matching tier found

        return false;

    }

    

    /**

     * Get VAT rate for a zone from database

     */

    private function get_zone_vat_rate_from_db($zone) {

        if ($zone->vat_setting === 'none') {

            return 0;

        }

        

        if ($zone->vat_setting === 'custom' && $zone->custom_vat_rate > 0) {

            return floatval($zone->custom_vat_rate) / 100;

        }

        

        // Use WooCommerce VAT rate dynamically

        return $this->get_woocommerce_vat_rate();

    }

    

    private function calculate_zone_pricing($zone, $cart_total) {

        // Check if zone has pricing tiers

        $pricing_tiers = isset($zone['pricing_tiers']) ? $zone['pricing_tiers'] : array();

        

        if (empty($pricing_tiers)) {

            // Fallback to old cost system

            $cost_ex_vat = isset($zone['cost']) ? floatval($zone['cost']) : 0;

            $vat_rate = $this->get_zone_vat_rate($zone);

            $vat_amount = $cost_ex_vat * $vat_rate;

            $cost_inc_vat = $cost_ex_vat + $vat_amount;

            

            return array(

                'cost_ex_vat' => $cost_ex_vat,

                'vat_amount' => $vat_amount,

                'cost_inc_vat' => $cost_inc_vat,

                'tier_info' => array(

                    'min_purchase' => 0,

                    'max_purchase' => 999999,

                    'base_cost' => $cost_ex_vat

                )

            );

        }

        

        // Find matching pricing tier

        foreach ($pricing_tiers as $tier) {

            $min_purchase = floatval($tier['min_purchase']);

            $max_purchase = floatval($tier['max_purchase']);

            

            // Debug: Log tier matching

            if (defined('WP_DEBUG') && WP_DEBUG) {

                // error_log("Checking tier: £{$min_purchase} - £{$max_purchase}, Base: £{$tier['base_cost']}, Cart: £{$cart_total}");

            }

            

            if ($cart_total >= $min_purchase && $cart_total <= $max_purchase) {

                $cost_ex_vat = floatval($tier['base_cost']);

                $vat_rate = $this->get_zone_vat_rate($zone);

                $vat_amount = $cost_ex_vat * $vat_rate;

                $cost_inc_vat = $cost_ex_vat + $vat_amount;

                

                // Debug: Log selected tier

                if (defined('WP_DEBUG') && WP_DEBUG) {

                    // error_log("Selected tier: Base £{$cost_ex_vat}, VAT {$vat_rate}%, Total £{$cost_inc_vat}");

                }

                

                return array(

                    'cost_ex_vat' => $cost_ex_vat,

                    'vat_amount' => $vat_amount,

                    'cost_inc_vat' => $cost_inc_vat,

                    'tier_info' => $tier

                );

            }

        }

        

        // No matching tier found

        return false;

    }

    

    private function get_zone_vat_rate($zone) {

        $vat_setting = isset($zone['vat_setting']) ? $zone['vat_setting'] : 'global';

        

        if ($vat_setting === 'none') {

            return 0;

        }

        

        if ($vat_setting === 'custom' && isset($zone['custom_vat_rate'])) {

            return floatval($zone['custom_vat_rate']) / 100;

        }

        

        // Use WooCommerce VAT rate dynamically

        return $this->get_woocommerce_vat_rate();

    }

    

    /**

     * Get VAT rate dynamically from WooCommerce

     */

    private function get_woocommerce_vat_rate() {

        // Check if WooCommerce is available

        if (!class_exists('WooCommerce') || !WC()) {

            return 0;

        }

        

        // Get customer location for tax calculation

        $customer = WC()->customer;

        if (!$customer) {

            return 0;

        }

        

        // Get tax rates for shipping

        $tax_rates = WC_Tax::get_shipping_tax_rates();

        

        if (empty($tax_rates)) {

            // Fallback: get standard tax rates

            $tax_rates = WC_Tax::get_rates();

        }

        

        if (!empty($tax_rates)) {

            // Get the first tax rate (most common scenario)

            $tax_rate = reset($tax_rates);

            return floatval($tax_rate['rate']) / 100;

        }

        

        // Final fallback: check if taxes are enabled

        if (wc_tax_enabled()) {

            // Get default tax rate from WooCommerce settings

            $standard_rates = WC_Tax::get_rates_for_tax_class('');

            if (!empty($standard_rates)) {

                $rate = reset($standard_rates);

                return floatval($rate['rate']) / 100;

            }

        }

        

        return 0; // No tax

    }

    

    /**

     * Get zones from database (replaces hardcoded get_default_zones)

     */

    private function get_zones_from_db() {

        global $wpdb;

        

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results("SELECT * FROM {$this->table_zones} ORDER BY id");

    }

    

    private function get_default_collection() {

        return array(

            'enabled' => true,

            'name' => 'Collection from Store',

            'description' => 'Collect your order from our store location'

        );

    }

    

    // 4. Update the JavaScript validation as well

    public function add_inline_javascript() {

        ?>

        <script>

        // Define AJAX variables

        var postcode_delivery_ajax = {

            ajax_url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',

            nonce: '<?php echo esc_attr(wp_create_nonce('postcode_delivery_nonce')); ?>'

        };

        

        // Ensure jQuery is available as $ within this scope

        (function($) {

        

        // Global function for onclick handler (fallback method)

        window.handleDeliveryButtonClick = function(button) {

            if (typeof jQuery !== 'undefined') {

                if (typeof window.handleDeliveryCalculation === 'function') {

                    window.handleDeliveryCalculation(jQuery(button));

                } else {

                    alert('Delivery calculation function not available. Please refresh the page.');

                }

            } else {

                alert('Required scripts not loaded. Please refresh the page.');

            }

        };

        

        // Use both document ready and window load to ensure everything is loaded

        $(document).ready(function() {

            initializeDeliveryCalculator($);

        });

        

        $(window).on('load', function() {

            initializeDeliveryCalculator($);

        });

        

        function initializeDeliveryCalculator($) {

            

            // Remove any existing handlers to avoid duplicates

            $(document).off('click', '.calculate-delivery-btn');

            $('.calculate-delivery-btn').off('click');

            

            // Add multiple selectors to test

            $('.calculate-delivery-btn, button.calculate-delivery-btn, .button.calculate-delivery-btn').on('click', function(e) {

                e.preventDefault();

                handleDeliveryCalculation($(this));

            });

            

            // Handle postcode calculation with event delegation (backup)

            $(document).on('click', '.calculate-delivery-btn', function(e) {

                e.preventDefault();

                handleDeliveryCalculation($(this));

            });

            

            // Simple Enter key support

            $(document).on('keypress', '.delivery-postcode-input', function(e) {

                if (e.which === 13) { // Enter key

                    e.preventDefault();

                    var $btn = $(this).siblings('.calculate-delivery-btn');

                    if ($btn.length === 0) {

                        $btn = $(this).closest('.postcode-delivery-calculator').find('.calculate-delivery-btn');

                    }

                    handleDeliveryCalculation($btn);

                }

            });

        }

            

            // Main delivery calculation function (make it global)

            window.handleDeliveryCalculation = function($btn) {

                

                // For new structure, find elements in the same row or document

                var $calculator = $btn.closest('.postcode-delivery-input');

                if ($calculator.length === 0) {

                    // Fallback to old structure

                    $calculator = $btn.closest('.postcode-delivery-calculator');

                }

                

                var $input = $calculator.find('.delivery-postcode-input');

                if ($input.length === 0) {

                    // Fallback to document search

                    $input = $('.delivery-postcode-input').first();

                }

                

                var $status = $calculator.find('.delivery-status');

                if ($status.length === 0) {

                    $status = $('.delivery-status').first();

                }

                

                var $success = $calculator.find('.delivery-success');

                if ($success.length === 0) {

                    $success = $('.delivery-success').first();

                }

                

                var $error = $calculator.find('.delivery-error');

                if ($error.length === 0) {

                    $error = $('.delivery-error').first();

                }

                

                var postcode = $input.val().trim().toUpperCase();

                var context = $calculator.data('context') || 'cart';

                

                if (!postcode) {

                    showError($status, $error, 'Please enter a postcode');

                    return;

                }

                

                // Updated validation - allow letters only or letters + numbers

                if (postcode.length < 2 || postcode.length > 4) {

                    showError($status, $error, 'Please enter 2-4 characters (e.g., CR, CV, DT1, DY8)');

                    return;

                }

                

                // Allow letters only (like CR, CV) or letters + numbers (like CR1, DY8)

                if (!postcode.match(/^[A-Z]{1,2}[0-9]{0,2}$/)) {

                    showError($status, $error, 'Please enter a valid UK postcode format (e.g., CR, CV, DT1, DY8)');

                    return;

                }

                

                // Simple loading state

                $btn.prop('disabled', true).text('Checking...');

                

                // Hide previous status messages

                $status.find('.delivery-success, .delivery-error').hide();

                

                jQuery.ajax({

                    url: postcode_delivery_ajax.ajax_url,

                    type: 'POST',

                    data: {

                        action: 'calculate_delivery_cost',

                        postcode: postcode,

                        context: context,

                        nonce: postcode_delivery_ajax.nonce

                    },

                    success: function(response) {

                        

                        if (response.success) {

                            var options = response.data.options || [];

                            var options_html = response.data.options_html || '';

                            

                            if (options.length > 0 && options_html) {

                                // Show the selectable options HTML

                                $success.find('.delivery-success-message').html(options_html);

                                $status.find('.delivery-error').hide();

                                $success.show();

                                $status.show();

                                

                                // Don't auto-select any option - let user choose

                                

                                // Add event handler for when users change delivery option selection

                                jQuery(document).off('change', 'input[name="delivery_option"]').on('change', 'input[name="delivery_option"]', function() {

                                    var selectedOption = jQuery('input[name="delivery_option"]:checked');

                                    if (selectedOption.length > 0) {

                                        var optionId = selectedOption.val();

                                        var cost = parseFloat(selectedOption.data('cost'));

                                        var type = selectedOption.data('type');

                                        

                                        // Send AJAX request to select the option

                                        jQuery.ajax({

                                            url: postcode_delivery_ajax.ajax_url,

                                            type: 'POST',

                                            data: {

                                                action: 'select_delivery_option',

                                                option_id: optionId,

                                                cost: cost,

                                                type: type,

                                                nonce: postcode_delivery_ajax.nonce

                                            },

                                            success: function(selectResponse) {

                                                if (selectResponse.success) {

                                                    console.log('Delivery option changed and selected:', optionId);

                                                    

                                                    // Update cart totals automatically

                                                    setTimeout(function() {

                                                        if (context === 'checkout') {

                                                            jQuery('body').trigger('update_checkout');

                                                        } else {

                                                            jQuery('body').trigger('wc_update_cart');

                                                        }

                                                    }, 500);

                                                }

                                            },

                                            error: function() {

                                                console.error('Error selecting delivery option');

                                            }

                                        });

                                    }

                                });

                            } else {

                                showError($status, $error, 'No delivery options available for this postcode area');

                            }

                            

                            // Update shipping postcode

                            updateShippingPostcode(postcode);

                            

                            // Trigger cart update to refresh shipping section with new delivery options

                            setTimeout(function() {

                                if (context === 'checkout') {

                                    jQuery('body').trigger('update_checkout');

                                } else {

                                    jQuery('body').trigger('wc_update_cart');

                                }

                                

                                // Show simple success message

                                setTimeout(function() {

                                    $success.find('.delivery-success-message').text('Delivery options updated! Choose your preferred method in the shipping section above.');

                                    $success.show();

                                }, 500);

                            }, 500);

                        } else {

                            showError($status, $error, response.data.message || 'Unknown error occurred');

                        }

                    },

                    error: function(xhr, status, error) {

                        showError($status, $error, 'Connection error. Please try again.');

                    },

                    complete: function() {

                        // Reset button state

                        $btn.prop('disabled', false).text('Check Options');

                    }

                });

            }

            

            // Rest of your JavaScript functions...

            // (keeping the same helper functions)

            

            window.updateShippingPostcode = function(postcode) {

                jQuery.ajax({

                    url: postcode_delivery_ajax.ajax_url,

                    type: 'POST',

                    data: {

                        action: 'update_shipping_postcode',

                        postcode: postcode,

                        nonce: postcode_delivery_ajax.nonce

                    }

                });

            }

            

            window.showSuccess = function($status, $success, message) {

                if (typeof message === 'string') {

                    $success.find('.delivery-success-message').text(message);

                } else {

                    $success.find('.delivery-success-message').html(message);

                }

                $status.find('.delivery-error').hide();

                $success.show();

                $status.show();

            }

            

            window.showError = function($status, $error, message) {

                // Simple error display

                $error.text(message);

                $status.find('.delivery-success').hide();

                $error.show();

            }

            

        })(jQuery); // Close jQuery wrapper

        </script>

        

        <style>

        /* Simple Postcode Delivery Calculator Styles */

        .postcode-delivery-container {

            padding: 0 !important;

            background: none !important;

            border: none !important;

        }

        

        .postcode-delivery-simple {

            background: #f8f9fa;

            border: 1px solid #dee2e6;

            border-radius: 5px;

            padding: 15px;

            margin: 10px 0;

        }

        

        .postcode-delivery-simple strong {

            font-size: 16px;

            color: #333;

            display: block;

            margin-bottom: 5px;

        }

        

        .postcode-delivery-simple p {

            font-size: 14px;

            color: #666;

            margin: 0 0 15px 0;

        }

        

        .postcode-input-row {

            display: flex;

            gap: 10px;

            align-items: center;

            margin-bottom: 10px;

        }

        

        .delivery-postcode-input {

            flex: 1;

            padding: 8px 12px;

            border: 1px solid #ccc;

            border-radius: 4px;

            background: #fff;

            font-size: 14px;

            text-transform: uppercase;

        }

        

        .delivery-postcode-input:focus {

            outline: none;

            border-color: #007cba;

        }

        

        .calculate-delivery-btn {

            padding: 8px 16px;

            background: #007cba;

            color: white;

            border: none;

            border-radius: 4px;

            font-size: 14px;

            cursor: pointer;

        }

        

        .calculate-delivery-btn:hover {

            background: #005a87;

        }

        

        .calculate-delivery-btn:disabled {

            background: #ccc;

            cursor: not-allowed;

        }

        

        .delivery-status {

            margin-top: 10px;

        }

        

        .delivery-success {

            background: #d4edda;

            border: 1px solid #c3e6cb;

            border-radius: 4px;

            padding: 10px;

            margin-top: 10px;

            display: none;

        }

        

        .delivery-success-message {

            color: #155724;

            font-size: 14px;

        }

        

        .delivery-error {

            background: #f8d7da;

            border: 1px solid #f5c6cb;

            border-radius: 4px;

            padding: 10px;

            margin-top: 10px;

            color: #721c24;

            font-size: 14px;

            display: none;

        }

        

        /* Mobile responsiveness */

        @media (max-width: 768px) {

            .postcode-input-row {

                flex-direction: column;

                gap: 8px;

            }

            

            .delivery-postcode-input {

                width: 100%;

            }

            

            .calculate-delivery-btn {

                width: 100%;

            }

        }

        </style>

        <?php

    }

    

    public function add_admin_menu() {

        add_submenu_page(

            'woocommerce',

            __('Delivery Zones', 'postcode-delivery-calculator'),

            __('Delivery Zones', 'postcode-delivery-calculator'),

            'manage_woocommerce',

            'postcode-delivery-settings',

            array($this, 'admin_page')

        );

    }

    

    public function admin_page() {

        // Handle form submissions

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the save methods
        if (isset($_POST['save_zones'])) {
            if (check_admin_referer('postcode_delivery_admin', 'postcode_delivery_nonce')) {
                $this->save_delivery_zones();
            }
        } elseif (isset($_POST['save_excluded'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (check_admin_referer('postcode_delivery_admin', 'postcode_delivery_nonce')) {
                $this->save_excluded_postcodes();
            }
        } elseif (isset($_POST['save_collection'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (check_admin_referer('postcode_delivery_admin', 'postcode_delivery_nonce')) {
                $this->save_collection_option();
            }
        }

        

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking tab for display

        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'zones';

        ?>

        <div class="wrap">

            <h1><?php esc_html_e('Postcode Delivery Calculator', 'postcode-delivery-calculator'); ?></h1>

            

            <h2 class="nav-tab-wrapper">

                <a href="?page=postcode-delivery-settings&tab=zones" class="nav-tab <?php echo $active_tab == 'zones' ? 'nav-tab-active' : ''; ?>">

                    <?php esc_html_e('Delivery Zones', 'postcode-delivery-calculator'); ?>

                </a>

                <a href="?page=postcode-delivery-settings&tab=excluded" class="nav-tab <?php echo $active_tab == 'excluded' ? 'nav-tab-active' : ''; ?>">

                    <?php esc_html_e('Excluded Postcodes', 'postcode-delivery-calculator'); ?>

                </a>

                <a href="?page=postcode-delivery-settings&tab=collection" class="nav-tab <?php echo $active_tab == 'collection' ? 'nav-tab-active' : ''; ?>">

                    <?php esc_html_e('Collection Option', 'postcode-delivery-calculator'); ?>

                </a>

            </h2>

            

            <?php

            switch ($active_tab) {

                case 'zones':

                    $this->render_zones_tab();

                    break;

                case 'excluded':

                    $this->render_excluded_tab();

                    break;

                case 'collection':

                    $this->render_collection_tab();

                    break;

            }

            ?>

        </div>

        <?php

    }

    

    private function render_zones_tab() {

        global $wpdb;

        

        // Get zones from database

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $zones = $wpdb->get_results("SELECT * FROM {$this->table_zones} ORDER BY id");

        $next_zone_id = empty($zones) ? 1 : max(array_column($zones, 'id')) + 1;

        ?>

        <form method="post" action="">

            <?php wp_nonce_field('postcode_delivery_admin', 'postcode_delivery_nonce'); ?>

            

            <div style="margin: 20px 0;">

                <button type="button" id="add-zone-btn" class="button button-secondary">

                    <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>

                    <?php esc_html_e('Add New Zone', 'postcode-delivery-calculator'); ?>

                </button>

                <span class="description" style="margin-left: 15px;margin-top: 5px;">

                    <?php esc_html_e('Configure delivery zones with different costs and postcodes', 'postcode-delivery-calculator'); ?>

                </span>

            </div>

            

            <table class="wp-list-table widefat fixed striped" id="zones-table">

                <thead>

                    <tr>

                        <th width="60"><?php esc_html_e('Enabled', 'postcode-delivery-calculator'); ?></th>

                        <th><?php esc_html_e('Zone Name', 'postcode-delivery-calculator'); ?></th>

                        <th><?php esc_html_e('Postcodes (comma separated)', 'postcode-delivery-calculator'); ?></th>

                        <th><?php esc_html_e('Delivery Time', 'postcode-delivery-calculator'); ?></th>

                        <th><?php esc_html_e('Description', 'postcode-delivery-calculator'); ?></th>

                        <th><?php esc_html_e('VAT Setting', 'postcode-delivery-calculator'); ?></th>

                        <th width="120"><?php esc_html_e('Actions', 'postcode-delivery-calculator'); ?></th>

                    </tr>

                </thead>

                <tbody id="zones-tbody">

                    <?php if (empty($zones)): ?>

                        <tr id="no-zones-row">

                            <td colspan="7" style="text-align: center; padding: 20px; color: #666;">

                                <?php esc_html_e('No zones configured yet. Click "Add New Zone" to create your first delivery zone.', 'postcode-delivery-calculator'); ?>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($zones as $zone): 

                            // Get postcodes for this zone
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                            $zone_postcodes = $wpdb->get_results($wpdb->prepare(
                                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                "SELECT postcode FROM {$this->table_postcodes} WHERE zone_id = %d ORDER BY postcode",
                                $zone->id
                            ));

                            $postcodes_string = implode(', ', array_column($zone_postcodes, 'postcode'));

                        ?>

                            <tr class="zone-row" data-zone-id="<?php echo esc_attr($zone->id); ?>">

                                <td>

                                    <input type="checkbox" name="zones[<?php echo esc_attr($zone->id); ?>][enabled]" value="1" 

                                           <?php checked($zone->enabled); ?> />

                                </td>

                                <td>

                                    <input type="text" name="zones[<?php echo esc_attr($zone->id); ?>][name]" 

                                           value="<?php echo esc_attr($zone->name); ?>" 

                                           placeholder="e.g., Zone <?php echo esc_attr($zone->id); ?> - Local" />

                                </td>

                                <td>

                                    <input type="text" name="zones[<?php echo esc_attr($zone->id); ?>][postcodes]" 

                                           value="<?php echo esc_attr($postcodes_string); ?>" 

                                           placeholder="e.g., CF, B, M" />

                                </td>

                                <td>

                                    <input type="text" name="zones[<?php echo esc_attr($zone->id); ?>][delivery_time]" 

                                           value="<?php echo esc_attr($zone->delivery_time); ?>" 

                                           placeholder="e.g., 1-2 working days" />

                                </td>

                                <td>

                                    <input type="text" name="zones[<?php echo esc_attr($zone->id); ?>][description]" 

                                           value="<?php echo esc_attr($zone->description); ?>" 

                                           placeholder="Zone description" />

                                </td>

                                <td>

                                    <select name="zones[<?php echo esc_attr($zone->id); ?>][vat_setting]" class="vat-setting-select" data-zone-id="<?php echo esc_attr($zone->id); ?>">

                                        <option value="global" <?php selected($zone->vat_setting, 'global'); ?>>

                                            <?php esc_html_e('Use WooCommerce VAT', 'postcode-delivery-calculator'); ?>

                                        </option>

                                        <option value="custom" <?php selected($zone->vat_setting, 'custom'); ?>>

                                            <?php esc_html_e('Custom VAT Rate', 'postcode-delivery-calculator'); ?>

                                        </option>

                                        <option value="none" <?php selected($zone->vat_setting, 'none'); ?>>

                                            <?php esc_html_e('No VAT', 'postcode-delivery-calculator'); ?>

                                        </option>

                                    </select>

                                    <div class="custom-vat-rate" style="<?php echo ($zone->vat_setting === 'custom') ? '' : 'display:none;'; ?>margin-top:5px;">

                                        <input type="number" step="0.01" min="0" max="100" 

                                               name="zones[<?php echo esc_attr($zone->id); ?>][custom_vat_rate]" 

                                               value="<?php echo esc_attr($zone->custom_vat_rate); ?>" 

                                               placeholder="20" style="width:60px;" /> %

                                    </div>

                                </td>

                                <td >

                                    <?php 

                                    $has_pricing = !empty($pricing_tiers);

                                    $pricing_class = $has_pricing ? 'pricing-btn configured' : 'pricing-btn';

                                    $pricing_title = $has_pricing ? 

                                        /* translators: %d: number of pricing tiers */

                                        sprintf(__('Pricing configured (%d tiers)', 'postcode-delivery-calculator'), count($pricing_tiers)) : 

                                        __('Configure Pricing', 'postcode-delivery-calculator');

                                    ?>

                                    <button type="button" class="button button-small <?php echo esc_attr($pricing_class); ?>" data-zone-id="<?php echo esc_attr($zone->id); ?>" title="<?php echo esc_attr($pricing_title); ?>">

                                        <span class="dashicons dashicons-money-alt" style="vertical-align: middle;"></span>

                                        <?php esc_html_e('Pricing', 'postcode-delivery-calculator'); ?>

                                    </button>

                                    <br style="margin-bottom: 5px;">

                                    <button type="button" class="button button-small remove-zone-btn" title="<?php esc_attr_e('Remove Zone', 'postcode-delivery-calculator'); ?>">

                                        <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>

                                        <?php esc_html_e('Delete', 'postcode-delivery-calculator'); ?>

                                    </button>

                                    

                                    <!-- Hidden pricing tiers data -->

                                    <?php 

                                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                                    $pricing_tiers = $wpdb->get_results($wpdb->prepare(
                                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                        "SELECT * FROM {$this->table_pricing_tiers} WHERE zone_id = %d ORDER BY min_purchase",
                                        $zone->id
                                    ));

                                    $pricing_data = array();

                                    foreach ($pricing_tiers as $tier) {

                                        $pricing_data[] = array(

                                            'min_purchase' => $tier->min_purchase,

                                            'max_purchase' => $tier->max_purchase,

                                            'base_cost' => $tier->base_cost

                                        );

                                    }

                                    ?>

                                    <input type="hidden" name="zones[<?php echo esc_attr($zone->id); ?>][pricing_tiers]" 

                                           value="<?php echo esc_attr(json_encode($pricing_data)); ?>" 

                                           class="pricing-tiers-data" />

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

            

            <p class="submit">

                <input type="submit" name="save_zones" class="button-primary" value="<?php esc_attr_e('Save Delivery Zones', 'postcode-delivery-calculator'); ?>" />

            </p>

        </form>

        

        <!-- Pricing Configuration Modal -->

        <div id="pricing-modal" style="display: none;">

            <div class="pricing-modal-overlay">

                <div class="pricing-modal-content">

                    <div class="pricing-modal-header">

                        <h2><?php esc_html_e('Configure Pricing for:', 'postcode-delivery-calculator'); ?> <span id="pricing-zone-name"></span></h2>

                        <button type="button" class="pricing-modal-close">&times;</button>

                    </div>

                    <div class="pricing-modal-body">

                        <h3><?php esc_html_e('Pricing Tiers', 'postcode-delivery-calculator'); ?></h3>

                        <p><?php esc_html_e('Configure different delivery costs based on cart total value.', 'postcode-delivery-calculator'); ?></p>

                        

                        <div class="pricing-tiers-container">

                            <!-- Pricing tiers will be dynamically added here -->

                        </div>

                        

                        <button type="button" class="button button-secondary add-pricing-tier">

                            <span class="dashicons dashicons-plus-alt"></span>

                            <?php esc_html_e('Add Pricing Tier', 'postcode-delivery-calculator'); ?>

                        </button>

                    </div>

                    <div class="pricing-modal-footer">

                        <button type="button" class="button button-primary save-pricing"><?php esc_html_e('Save Pricing', 'postcode-delivery-calculator'); ?></button>

                        <button type="button" class="button pricing-modal-cancel"><?php esc_html_e('Cancel', 'postcode-delivery-calculator'); ?></button>

                    </div>

                </div>

            </div>

        </div>

        

        <!-- Pricing Tier Template -->

        <script type="text/template" id="pricing-tier-template">

            <div class="pricing-tier">

                <div class="pricing-tier-fields">

                    <div class="pricing-field">

                        <label><?php esc_html_e('Min Purchase (£):', 'postcode-delivery-calculator'); ?></label>

                        <input type="number" step="0.01" min="0" class="min-purchase" value="{{MIN_PURCHASE}}" placeholder="0" />

                    </div>

                    <div class="pricing-field">

                        <label><?php esc_html_e('Max Purchase (£):', 'postcode-delivery-calculator'); ?></label>

                        <input type="number" step="0.01" min="0" class="max-purchase" value="{{MAX_PURCHASE}}" placeholder="100" />

                    </div>

                    <div class="pricing-field">

                        <label><?php esc_html_e('Base Cost (£ ex VAT):', 'postcode-delivery-calculator'); ?></label>

                        <input type="number" step="0.01" min="0" class="base-cost" value="{{BASE_COST}}" placeholder="20" />

                    </div>

                    <div class="pricing-field">

                        <button type="button" class="button button-small remove-pricing-tier"><?php esc_html_e('Remove', 'postcode-delivery-calculator'); ?></button>

                    </div>

                </div>

            </div>

        </script>

        

        <script>

        jQuery(document).ready(function($) {

            var nextZoneId = <?php echo intval($next_zone_id); ?>;

            var currentZoneId = null;

            

            // VAT setting change handler

            $(document).on('change', '.vat-setting-select', function() {

                var $select = $(this);

                var $customVatDiv = $select.siblings('.custom-vat-rate');

                

                if ($select.val() === 'custom') {

                    $customVatDiv.show();

                } else {

                    $customVatDiv.hide();

                }

            });

            

            // Add new zone handler

            $('#add-zone-btn').on('click', function() {

                // Hide "no zones" row if it exists

                $('#no-zones-row').hide();

                

                // Create new zone row

                var newZoneHtml = '<tr class="zone-row" data-zone-id="' + nextZoneId + '">' +

                    '<td>' +

                        '<input type="checkbox" name="zones[' + nextZoneId + '][enabled]" value="1" checked />' +

                    '</td>' +

                    '<td>' +

                        '<input type="text" name="zones[' + nextZoneId + '][name]" value="" placeholder="e.g., Zone ' + nextZoneId + ' - Local" />' +

                    '</td>' +

                    '<td>' +

                        '<input type="text" name="zones[' + nextZoneId + '][postcodes]" value="" placeholder="e.g., CF, B, M" />' +

                    '</td>' +

                    '<td>' +

                        '<input type="text" name="zones[' + nextZoneId + '][delivery_time]" value="" placeholder="e.g., 1-2 working days" />' +

                    '</td>' +

                    '<td>' +

                        '<input type="text" name="zones[' + nextZoneId + '][description]" value="" placeholder="Zone description" />' +

                    '</td>' +

                    '<td>' +

                        '<select name="zones[' + nextZoneId + '][vat_setting]" class="vat-setting-select" data-zone-id="' + nextZoneId + '">' +

                            '<option value="global" selected><?php esc_html_e("Use WooCommerce VAT", "postcode-delivery-calculator"); ?></option>' +

                            '<option value="custom"><?php esc_html_e("Custom VAT Rate", "postcode-delivery-calculator"); ?></option>' +

                            '<option value="none"><?php esc_html_e("No VAT", "postcode-delivery-calculator"); ?></option>' +

                        '</select>' +

                        '<div class="custom-vat-rate" style="display:none;margin-top:5px;">' +

                            '<input type="number" step="0.01" min="0" max="100" name="zones[' + nextZoneId + '][custom_vat_rate]" value="0" placeholder="20" style="width:60px;" /> %' +

                        '</div>' +

                    '</td>' +

                    '<td>' +

                        '<button type="button" class="button button-small pricing-btn" data-zone-id="' + nextZoneId + '" title="<?php esc_attr_e("Configure Pricing", "postcode-delivery-calculator"); ?>">' +

                            '<span class="dashicons dashicons-money-alt" style="vertical-align: middle;"></span> <?php esc_html_e("Pricing", "postcode-delivery-calculator"); ?>' +

                        '</button>' +

                        '<br style="margin-bottom: 5px;">' +

                        '<button type="button" class="button button-small remove-zone-btn" title="<?php esc_attr_e("Remove Zone", "postcode-delivery-calculator"); ?>">' +

                            '<span class="dashicons dashicons-trash" style="vertical-align: middle;"></span> <?php esc_html_e("Delete", "postcode-delivery-calculator"); ?>' +

                        '</button>' +

                        '<input type="hidden" name="zones[' + nextZoneId + '][pricing_tiers]" value="[]" class="pricing-tiers-data" />' +

                    '</td>' +

                '</tr>';

                

                // Add the new row to the table

                $('#zones-tbody').append(newZoneHtml);

                

                // Increment the next zone ID

                nextZoneId++;

                

                // Focus on the zone name field

                $('#zones-tbody tr:last-child input[name*="[name]"]').focus();

            });

            

            // Remove zone handler

            $(document).on('click', '.remove-zone-btn', function() {

                var $row = $(this).closest('tr');

                $row.remove();

                

                // Show "no zones" row if no zones remain

                if ($('#zones-tbody .zone-row').length === 0) {

                    $('#no-zones-row').show();

                }

            });

            

            // Pricing configuration modal

            $(document).on('click', '.pricing-btn', function() {

                currentZoneId = $(this).data('zone-id');

                var zoneName = $(this).closest('tr').find('input[name*="[name]"]').val() || 'Zone ' + currentZoneId;

                var pricingData = $(this).closest('tr').find('.pricing-tiers-data').val();

                

                $('#pricing-zone-name').text(zoneName);

                loadPricingTiers(pricingData);

                $('#pricing-modal').show();

            });

            

            // Close pricing modal

            $('.pricing-modal-close, .pricing-modal-cancel').on('click', function() {

                $('#pricing-modal').hide();

            });

            

            // Add pricing tier

            $('.add-pricing-tier').on('click', function() {

                addPricingTier();

            });

            

            // Remove pricing tier

            $(document).on('click', '.remove-pricing-tier', function() {

                $(this).closest('.pricing-tier').remove();

            });

            

            // Real-time validation for pricing inputs

            $(document).on('input', '.pricing-tier input[type="number"]', function() {

                validatePricingInputRealTime($(this));

            });

            

            // Save pricing

            $('.save-pricing').on('click', function() {

                savePricingTiersToDatabase();

            });

            

            // Pricing tier functions

            function loadPricingTiers(pricingDataJson) {

                var pricingData = [];

                try {

                    pricingData = JSON.parse(pricingDataJson || '[]');

                } catch (e) {

                    pricingData = [];

                }

                

                $('.pricing-tiers-container').empty();

                

                if (pricingData.length === 0) {

                    // Add default tier

                    addPricingTier(0, 999999, 0);

                } else {

                    pricingData.forEach(function(tier) {

                        addPricingTier(tier.min_purchase, tier.max_purchase, tier.base_cost);

                    });

                }

            }

            

            function addPricingTier(minPurchase, maxPurchase, baseCost) {

                var template = $('#pricing-tier-template').html();

                var tierHtml = template

                    .replace('{{MIN_PURCHASE}}', minPurchase || '')

                    .replace('{{MAX_PURCHASE}}', maxPurchase || '')

                    .replace('{{BASE_COST}}', baseCost || '');

                

                $('.pricing-tiers-container').append(tierHtml);

            }

            

            function savePricingTiers() {

                var tiers = [];

                

                $('.pricing-tier').each(function() {

                    var $tier = $(this);

                    var minPurchase = parseFloat($tier.find('.min-purchase').val()) || 0;

                    var maxPurchase = parseFloat($tier.find('.max-purchase').val()) || 999999;

                    var baseCost = parseFloat($tier.find('.base-cost').val()) || 0;

                    

                    tiers.push({

                        min_purchase: minPurchase,

                        max_purchase: maxPurchase,

                        base_cost: baseCost

                    });

                });

                

                // Sort tiers by min_purchase

                tiers.sort(function(a, b) {

                    return a.min_purchase - b.min_purchase;

                });

                

                // Save to hidden field (for backward compatibility)

                var $row = $('.zone-row[data-zone-id="' + currentZoneId + '"]');

                $row.find('.pricing-tiers-data').val(JSON.stringify(tiers));

                

                return tiers;

            }

            

            function savePricingTiersToDatabase() {

                // Validate inputs first

                var validationErrors = validatePricingTiers();

                if (validationErrors.length > 0) {

                    alert('Validation Error:\n' + validationErrors.join('\n'));

                    return;

                }

                

                // Get pricing tiers data

                var tiers = savePricingTiers();

                

                if (tiers.length === 0) {

                    alert('Please add at least one pricing tier.');

                    return;

                }

                

                // Show loading state

                var $saveBtn = $('.save-pricing');

                var originalText = $saveBtn.text();

                $saveBtn.prop('disabled', true).text('Saving...');

                

                // Send AJAX request

                jQuery.ajax({

                    url: ajaxurl,

                    type: 'POST',

                    data: {

                        action: 'save_zone_pricing',

                        zone_id: currentZoneId,

                        pricing_tiers: tiers,

                        nonce: '<?php echo esc_js(wp_create_nonce('postcode_delivery_admin')); ?>'

                    },

                    success: function(response) {

                        if (response.success) {

                            alert('Success: ' + response.data.message);

                            $('#pricing-modal').hide();

                            

                            // Update the pricing button to show it has been configured

                            var $pricingBtn = $('.zone-row[data-zone-id="' + currentZoneId + '"] .pricing-btn');

                            $pricingBtn.addClass('configured').attr('title', 'Pricing configured (' + response.data.tiers_count + ' tiers)');

                        } else {

                            alert('Error: ' + response.data.message);

                        }

                    },

                    error: function(xhr, status, error) {

                        alert('Connection error: ' + error);

                    },

                    complete: function() {

                        // Reset button state

                        $saveBtn.prop('disabled', false).text(originalText);

                    }

                });

            }

            

            function validatePricingTiers() {

                var errors = [];

                var tiers = [];

                

                $('.pricing-tier').each(function(index) {

                    var $tier = $(this);

                    var minPurchase = parseFloat($tier.find('.min-purchase').val());

                    var maxPurchase = parseFloat($tier.find('.max-purchase').val());

                    var baseCost = parseFloat($tier.find('.base-cost').val());

                    

                    // Check for empty or invalid values

                    if (isNaN(minPurchase) || minPurchase < 0) {

                        errors.push('Tier ' + (index + 1) + ': Minimum purchase must be a valid number ≥ 0');

                    }

                    

                    if (isNaN(maxPurchase) || maxPurchase <= 0) {

                        errors.push('Tier ' + (index + 1) + ': Maximum purchase must be a valid number > 0');

                    }

                    

                    if (isNaN(baseCost) || baseCost < 0) {

                        errors.push('Tier ' + (index + 1) + ': Base cost must be a valid number ≥ 0');

                    }

                    

                    if (!isNaN(minPurchase) && !isNaN(maxPurchase) && minPurchase >= maxPurchase) {

                        errors.push('Tier ' + (index + 1) + ': Minimum purchase must be less than maximum purchase');

                    }

                    

                    if (!isNaN(minPurchase) && !isNaN(maxPurchase) && !isNaN(baseCost)) {

                        tiers.push({

                            min: minPurchase,

                            max: maxPurchase,

                            cost: baseCost,

                            index: index + 1

                        });

                    }

                });

                

                // Check for overlaps

                tiers.sort(function(a, b) {

                    return a.min - b.min;

                });

                

                for (var i = 0; i < tiers.length - 1; i++) {

                    var current = tiers[i];

                    var next = tiers[i + 1];

                    

                    if (current.max >= next.min) {

                        errors.push('Pricing tiers cannot overlap: Tier ' + current.index + ' (£' + current.min.toFixed(2) + '-£' + current.max.toFixed(2) + ') overlaps with Tier ' + next.index + ' (£' + next.min.toFixed(2) + '-£' + next.max.toFixed(2) + ')');

                    }

                }

                

                return errors;

            }

            

            function validatePricingInputRealTime($input) {

                var value = parseFloat($input.val());

                var fieldType = '';

                

                if ($input.hasClass('min-purchase')) {

                    fieldType = 'min';

                } else if ($input.hasClass('max-purchase')) {

                    fieldType = 'max';

                } else if ($input.hasClass('base-cost')) {

                    fieldType = 'cost';

                }

                

                // Remove existing error styling

                $input.removeClass('error');

                

                // Validate based on field type

                if (fieldType === 'min' && (isNaN(value) || value < 0)) {

                    $input.addClass('error');

                } else if (fieldType === 'max' && (isNaN(value) || value <= 0)) {

                    $input.addClass('error');

                } else if (fieldType === 'cost' && (isNaN(value) || value < 0)) {

                    $input.addClass('error');

                }

                

                // Check min/max relationship within the same tier

                if (fieldType === 'min' || fieldType === 'max') {

                    var $tier = $input.closest('.pricing-tier');

                    var minVal = parseFloat($tier.find('.min-purchase').val());

                    var maxVal = parseFloat($tier.find('.max-purchase').val());

                    

                    if (!isNaN(minVal) && !isNaN(maxVal) && minVal >= maxVal) {

                        $tier.find('.min-purchase, .max-purchase').addClass('error');

                    } else {

                        $tier.find('.min-purchase, .max-purchase').removeClass('error');

                    }

                }

            }

        });

        </script>

        

        <style>

        /* Pricing Modal Styles */

        #pricing-modal {

            position: fixed;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            z-index: 100000;

        }

        .pricing-modal-overlay {

            position: absolute;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            background: rgba(0, 0, 0, 0.7);

            display: flex;

            align-items: center;

            justify-content: center;

        }

        .pricing-modal-content {

            background: white;

            border-radius: 8px;

            width: 90%;

            max-width: 800px;

            max-height: 90%;

            overflow-y: auto;

            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);

        }

        .pricing-modal-header {

            padding: 20px;

            border-bottom: 1px solid #ddd;

            display: flex;

            justify-content: space-between;

            align-items: center;

        }

        .pricing-modal-header h2 {

            margin: 0;

            color: #333;

        }

        .pricing-modal-close {

            background: none;

            border: none;

            font-size: 24px;

            cursor: pointer;

            color: #666;

            padding: 0;

            width: 30px;

            height: 30px;

            display: flex;

            align-items: center;

            justify-content: center;

        }

        .pricing-modal-close:hover {

            color: #000;

        }

        .pricing-modal-body {

            padding: 20px;

        }

        .pricing-modal-body h3 {

            margin-top: 0;

            color: #2c8aa6;

        }

        .pricing-tier {

            background: #f9f9f9;

            border: 1px solid #ddd;

            border-radius: 6px;

            padding: 15px;

            margin-bottom: 15px;

        }

        .pricing-tier-fields {

            display: grid;

            grid-template-columns: 1fr 1fr 1fr auto;

            gap: 15px;

            align-items: end;

        }

        .pricing-field label {

            display: block;

            font-weight: 600;

            margin-bottom: 5px;

            color: #333;

        }

        .pricing-field input[type="number"] {

            width: 100%;

            padding: 8px;

            border: 1px solid #ddd;

            border-radius: 4px;

        }

        .pricing-field input[type="number"].error {

            border-color: #dc3232;

            background-color: #ffeaea;

        }

        .pricing-modal-footer {

            padding: 20px;

            border-top: 1px solid #ddd;

            text-align: right;

        }

        .pricing-modal-footer .button {

            margin-left: 10px;

        }

        .add-pricing-tier {

            margin-top: 15px;

        }

        .custom-vat-rate {

            font-size: 12px;

        }

        .custom-vat-rate input {

            font-size: 12px;

        }

        .pricing-btn {

            margin-bottom: 5px !important;

        }

        .pricing-btn.configured {

            background-color: #46b450 !important;

            border-color: #46b450 !important;

            color: white !important;

        }

        .pricing-btn.configured:hover {

            background-color: #3e9f47 !important;

        }

        .button.button-small {
            min-height: 26px;
            line-height: 2.18181818;
            padding: 2px 8px 3px 8px;    
            font-size: 11px;
            margin: 0px 11px;
            margin-left: 10px;
        }

        @media (max-width: 768px) {

            .pricing-tier-fields {

                grid-template-columns: 1fr;

                gap: 10px;

            }

            .pricing-modal-content {

                width: 95%;

                margin: 20px;

            }

        }

        </style>

        <?php

    }

    

    private function render_excluded_tab() {

        $excluded = get_option('postcode_delivery_excluded', array());

        ?>

        <form method="post" action="">

            <?php wp_nonce_field('postcode_delivery_admin', 'postcode_delivery_nonce'); ?>

            

            <table class="form-table">

                <tr>

                    <th scope="row">

                        <label for="excluded_postcodes"><?php esc_html_e('Excluded Postcode Areas', 'postcode-delivery-calculator'); ?></label>

                    </th>

                    <td>

                        <textarea name="excluded_postcodes" rows="5" cols="50" class="large-text"><?php echo esc_textarea(is_array($excluded) ? implode(', ', $excluded) : ''); ?></textarea>

                        <p class="description"><?php esc_html_e('Enter postcode areas separated by commas. Examples: AB, IV, CA, NE', 'postcode-delivery-calculator'); ?></p>

                    </td>

                </tr>

            </table>

            

            <p class="submit">

                <input type="submit" name="save_excluded" class="button-primary" value="<?php esc_attr_e('Save Excluded Postcodes', 'postcode-delivery-calculator'); ?>" />

            </p>

        </form>

        <?php

    }

    

    private function render_collection_tab() {

        $collection = get_option('postcode_delivery_collection', $this->get_default_collection());

        ?>

        <form method="post" action="">

            <?php wp_nonce_field('postcode_delivery_admin', 'postcode_delivery_nonce'); ?>

            

            <table class="form-table">

                <tr>

                    <th scope="row">

                        <label for="collection_enabled"><?php esc_html_e('Enable Collection', 'postcode-delivery-calculator'); ?></label>

                    </th>

                    <td>

                        <input type="checkbox" name="collection[enabled]" value="1" <?php checked(isset($collection['enabled']) ? $collection['enabled'] : false); ?> />

                        <p class="description"><?php esc_html_e('Allow customers to collect orders instead of delivery', 'postcode-delivery-calculator'); ?></p>

                    </td>

                </tr>

                <tr>

                    <th scope="row">

                        <label for="collection_name"><?php esc_html_e('Collection Option Name', 'postcode-delivery-calculator'); ?></label>

                    </th>

                    <td>

                        <input type="text" name="collection[name]" value="<?php echo esc_attr(isset($collection['name']) ? $collection['name'] : ''); ?>" class="regular-text" />

                    </td>

                </tr>

                <tr>

                    <th scope="row">

                        <label for="collection_description"><?php esc_html_e('Collection Description', 'postcode-delivery-calculator'); ?></label>

                    </th>

                    <td>

                        <textarea name="collection[description]" rows="3" cols="50" class="large-text"><?php echo esc_textarea(isset($collection['description']) ? $collection['description'] : ''); ?></textarea>

                    </tr>

                </td>

            </table>

            

            <p class="submit">

                <input type="submit" name="save_collection" class="button-primary" value="<?php esc_attr_e('Save Collection Settings', 'postcode-delivery-calculator'); ?>" />

            </p>

        </form>

        <?php

    }

    

    private function save_delivery_zones() {

        if (!isset($_POST['postcode_delivery_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['postcode_delivery_nonce'])), 'postcode_delivery_admin')) {

            wp_die('Security check failed');

        }

        

        global $wpdb;

        $zone_count = 0;

        

        if (isset($_POST['zones']) && is_array($_POST['zones'])) {

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array values are sanitized inside the loop
            foreach (wp_unslash($_POST['zones']) as $zone_id => $zone_data) {

                // Skip empty zones (zones without name)

                $zone_name = isset($zone_data['name']) ? sanitize_text_field($zone_data['name']) : '';

                $postcodes = isset($zone_data['postcodes']) ? sanitize_text_field($zone_data['postcodes']) : '';

                

                // Skip if zone name is empty (postcodes can be added later)

                if (empty($zone_name)) {

                    continue;

                }

                

                $postcodes_array = array_map('trim', explode(',', $postcodes));

                $postcodes_array = array_filter($postcodes_array); // Remove empty values

                $postcodes_array = array_map('strtoupper', $postcodes_array); // Convert to uppercase

                

                // Prepare zone data

                $zone_db_data = array(

                    'name' => $zone_name,

                    'enabled' => isset($zone_data['enabled']) ? 1 : 0,

                    'delivery_time' => sanitize_text_field(isset($zone_data['delivery_time']) ? $zone_data['delivery_time'] : ''),

                    'description' => sanitize_text_field(isset($zone_data['description']) ? $zone_data['description'] : ''),

                    'vat_setting' => sanitize_text_field(isset($zone_data['vat_setting']) ? $zone_data['vat_setting'] : 'global'),

                    'custom_vat_rate' => floatval(isset($zone_data['custom_vat_rate']) ? $zone_data['custom_vat_rate'] : 0)

                );

                

                // Check if zone exists
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $existing_zone = $wpdb->get_row($wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT id FROM {$this->table_zones} WHERE id = %d",
                    $zone_id
                ));

                

                if ($existing_zone) {

                    // Update existing zone
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->update(

                        $this->table_zones,

                        $zone_db_data,

                        array('id' => $zone_id),

                        array('%s', '%d', '%s', '%s', '%s', '%f'),

                        array('%d')

                    );

                    $current_zone_id = $zone_id;

                } else {

                    // Insert new zone
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->insert(

                        $this->table_zones,

                        $zone_db_data,

                        array('%s', '%d', '%s', '%s', '%s', '%f')

                    );

                    $current_zone_id = $wpdb->insert_id;

                }

                

                // Delete existing postcodes for this zone
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->delete($this->table_postcodes, array('zone_id' => $current_zone_id), array('%d'));

                

                // Insert new postcodes

                foreach ($postcodes_array as $postcode) {

                    if (!empty($postcode)) {

                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->insert(
                            $this->table_postcodes,
                            array('zone_id' => $current_zone_id, 'postcode' => $postcode),
                            array('%d', '%s')
                        );

                    }

                }

                

                // Delete existing pricing tiers for this zone
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->delete($this->table_pricing_tiers, array('zone_id' => $current_zone_id), array('%d'));

                

                // Parse and insert pricing tiers

                $pricing_tiers_data = array();

                if (isset($zone_data['pricing_tiers']) && !empty($zone_data['pricing_tiers'])) {

                    $pricing_tiers_data = json_decode(stripslashes($zone_data['pricing_tiers']), true);

                }

                

                // If no pricing tiers provided, add a default one

                if (empty($pricing_tiers_data) || !is_array($pricing_tiers_data)) {

                    $pricing_tiers_data = array(

                        array(

                            'min_purchase' => 0,

                            'max_purchase' => 999999.99,

                            'base_cost' => 20

                        )

                    );

                }

                

                // Insert pricing tiers

                foreach ($pricing_tiers_data as $tier) {

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->insert(
                        $this->table_pricing_tiers,
                        array(
                            'zone_id' => $current_zone_id,
                            'min_purchase' => floatval($tier['min_purchase']),
                            'max_purchase' => floatval($tier['max_purchase']),
                            'base_cost' => floatval($tier['base_cost'])
                        ),
                        array('%d', '%f', '%f', '%f')
                    );

                }

                

                $zone_count++;

            }

        }

        

        if ($zone_count > 0) {

            $message = sprintf(

                /* translators: %d: number of zones saved */

                _n('%d delivery zone saved successfully!', '%d delivery zones saved successfully!', $zone_count, 'postcode-delivery-calculator'),

                $zone_count

            );

            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';

            

        } else {

            echo '<div class="notice notice-warning"><p>' . esc_html__('No zones were saved. Please add zones using the "Add New Zone" button and fill in at least the zone name.', 'postcode-delivery-calculator') . '</p></div>';

            

        }

    }

    

    private function save_excluded_postcodes() {

        if (!isset($_POST['postcode_delivery_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['postcode_delivery_nonce'])), 'postcode_delivery_admin')) {

            wp_die('Security check failed');

        }

        

        $excluded_input = isset($_POST['excluded_postcodes']) ? sanitize_text_field(wp_unslash($_POST['excluded_postcodes'])) : '';

        $excluded_array = array_map('trim', explode(',', $excluded_input));

        $excluded_array = array_filter($excluded_array); // Remove empty values

        $excluded_array = array_map('strtoupper', $excluded_array); // Convert to uppercase

        

        update_option('postcode_delivery_excluded', $excluded_array);

        echo '<div class="notice notice-success"><p>' . esc_html__('Excluded postcodes saved successfully!', 'postcode-delivery-calculator') . '</p></div>';

    }

    

    private function save_collection_option() {

        if (!isset($_POST['postcode_delivery_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['postcode_delivery_nonce'])), 'postcode_delivery_admin')) {

            wp_die('Security check failed');

        }

        

        $collection = array(

            'enabled' => isset($_POST['collection']['enabled']),

            'name' => isset($_POST['collection']['name']) ? sanitize_text_field(wp_unslash($_POST['collection']['name'])) : '',

            'description' => isset($_POST['collection']['description']) ? sanitize_textarea_field(wp_unslash($_POST['collection']['description'])) : ''

        );

        

        update_option('postcode_delivery_collection', $collection);

        echo '<div class="notice notice-success"><p>' . esc_html__('Collection settings saved successfully!', 'postcode-delivery-calculator') . '</p></div>';

    }

    



    



    



    



    

    /**

     * Find zone for a specific postcode

     */

    private function find_zone_for_postcode($postcode) {

        global $wpdb;

        

        $postcode = strtoupper(trim($postcode));

        

        // Query to find the first matching zone
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $zone = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT z.* " .
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "FROM {$this->table_zones} z " .
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "INNER JOIN {$this->table_postcodes} p ON z.id = p.zone_id " .
            "WHERE z.enabled = 1 
            AND (
                p.postcode = %s " .
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
                "OR %s LIKE CONCAT(p.postcode, '%%')
                OR p.postcode LIKE CONCAT(%s, '%%')
            )
            ORDER BY z.id
            LIMIT 1",
            $postcode, $postcode, $postcode
        ), ARRAY_A);

        

        return $zone;

    }

    

    /**

     * Calculate delivery cost for a zone

     */

    private function calculate_delivery_cost($zone, $cart_total) {

        global $wpdb;

        

        // Get pricing tiers for this zone from database
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pricing_tiers = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$this->table_pricing_tiers} 
             WHERE zone_id = %d 
             ORDER BY min_purchase ASC",
            $zone['id']
        ));

        

        if (empty($pricing_tiers)) {

            return false;

        }

        

        // Find matching pricing tier

        foreach ($pricing_tiers as $tier) {

            $min_purchase = floatval($tier->min_purchase);

            $max_purchase = floatval($tier->max_purchase);

            

            if ($cart_total >= $min_purchase && $cart_total <= $max_purchase) {

                $cost_ex_vat = floatval($tier->base_cost);

                $vat_rate = $this->get_zone_vat_rate($zone);

                $vat_amount = $cost_ex_vat * $vat_rate;

                $cost_inc_vat = $cost_ex_vat + $vat_amount;

                

                return array(

                    'cost_ex_vat' => $cost_ex_vat,

                    'vat_amount' => $vat_amount,

                    'cost_inc_vat' => $cost_inc_vat,

                    'tier_info' => array(

                        'min_purchase' => $min_purchase,

                        'max_purchase' => $max_purchase,

                        'base_cost' => $cost_ex_vat

                    )

                );

            }

        }

        

        return false;

    }

    

    /**

     * Add delivery fee to cart based on selected option

     */

    public function add_delivery_fee_to_cart() {

        if (is_admin() && !defined('DOING_AJAX')) {

            return;

        }

        

        if (!WC()->session) {

            return;

        }

        

        $selected_option = WC()->session->get('selected_delivery_option');

        

        if ($selected_option && isset($selected_option['cost']) && $selected_option['cost'] > 0) {

            $delivery_options = WC()->session->get('available_delivery_options', array());

            $option_name = 'Delivery';

            

            // Find the option name

            foreach ($delivery_options as $option) {

                if ($option['id'] === $selected_option['id']) {

                    $option_name = $option['name'];

                    break;

                }

            }

            

            // Add fee to cart

            WC()->cart->add_fee($option_name, $selected_option['cost']);

        }

    }

    

    /**

     * Save selected shipping method as delivery option

     */

    public function save_selected_shipping_as_delivery_option($posted_data = '') {

        if (!WC()->session) {

            return;

        }

        

        // Get chosen shipping methods

        $chosen_methods = WC()->session->get('chosen_shipping_methods');

        if (empty($chosen_methods)) {

            return;

        }

        

        $chosen_method = $chosen_methods[0];

        

        // Check if it's our delivery method

        if (strpos($chosen_method, 'postcode_delivery_') === 0) {

            $delivery_options = WC()->session->get('available_delivery_options', array());

            

            // Extract option ID from method ID

            $option_id = str_replace('postcode_delivery_', '', $chosen_method);

            

            // Find and save the selected option

            foreach ($delivery_options as $option) {

                if ($option['id'] === $option_id) {

                    WC()->session->set('selected_delivery_option', array(

                        'id' => $option['id'],

                        'cost' => $option['cost_inc_vat'],

                        'type' => $option['type']

                    ));

                    break;

                }

            }

        }

    }

    

    // Other functionality methods

    public function modify_checkout_fields($fields) { return $fields; }

    public function save_delivery_info_to_order_shipping($item, $package_key, $package, $order) {}

    public function save_delivery_info_to_order($order_id) {}

    public function custom_shipping_method_label($label, $method) { 

        // Check if this is our delivery method

        if (strpos($method->get_id(), 'postcode_delivery_') === 0) {

            $cost = $method->get_cost();

            if ($cost > 0) {

                // Add price to label if not already included

                if (strpos($label, '£') === false) {

                    $label .= ' - £' . number_format($cost, 2);

                }

            } elseif (strpos($method->get_id(), 'collection') !== false) {

                // For collection, ensure it shows as FREE

                // if (strpos($label, 'FREE') === false && strpos($label, '£0') === false) {

                //     $label .= ' - FREE';

                // }

            }

        }

        return $label; 

    }

    public function add_delivery_info_to_emails($order, $sent_to_admin, $plain_text) {}

}