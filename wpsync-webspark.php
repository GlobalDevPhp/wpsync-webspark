<?php
/**
 * Plugin Name: wpsync-webspark
 * Plugin URI: http://woocommerce.com/products/wpsync-webspark/
 * Description: Test task for the position WordPress developer - synchronization of the DB of products with the rest
 * Version: 1.0.0
 * Author: Khromykh E.
 * Author URI: https://itnotes.org.ua/
 * Developer: Khromykh E.
 * Developer URI: https://webspark.dev/
 * Text Domain: wpsync-webspark
 * Domain Path: /languages
 *
 * Woo: 12345:342928dfsfhsf8429842374wdf4234sfd
 * WC requires at least: 7.3.0
 * WC tested up to: 7.3.0
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


/**
 * WPSYNC version
 *
 * @since 1.0.0
 */
define('WPSYNC_VERSION', '1.0.0');
//require_once __DIR__ . '/lib/FileChanger.php';

class wpSync {
    /**
     * Url to webspark products api.
     * Todo: create options page and add this field
     * @access public
     * 
     * @var string $api_url 
     */
    public $api_url = 'https://wp.webspark.dev/wp-api/products';
    
    /**
     * Constructor method
     *
     * @since 1.0.0
     *
     * @access public
     */
    public function __construct() {
        // Add Plugin Hooks.
        add_action('plugins_loaded', array($this, 'add_hooks'));

        // Plugin Activation/Deactivation.
        register_activation_hook(__FILE__, array($this, 'plugin_activation'));
        register_deactivation_hook(__FILE__, array($this, 'plugin_deactivation'));
    }

    /**
     * Adds all the plugin hooks
     *
     * @since 1.0.0
     *
     * @access public
     * @return void
     */
    public function add_hooks() {
        // Actions.
        add_action( 'wpsync_hourly_hook', array( $this, 'wpsync_hourly_hook' ) );
        // Load Translation.
        load_plugin_textdomain('wpsync-webspark', false, basename(dirname(__FILE__)) . '/languages');
    }


    /**
     * What to do when the plugin is being deactivated
     *
     * @since 1.0.0
     *
     * @access public
     * @return void
     */
    public function plugin_activation() {
        if ( ! wp_next_scheduled( 'wpsync_hourly_hook' ) ) {
            wp_schedule_event( time(), 'hourly', 'wpsync_hourly_hook' );
        }    
    }

    /**
     * What to do when the plugin is being activated
     *
     * @since 1.0.0
     *
     * @access public
     * @param boolean $network_wide Is network wide.
     * @return void
     */
    public function plugin_deactivation($network_wide) {
        wp_clear_scheduled_hook('wpsync_hourly_hook');
    }

    /**
     * Check Woocommerce is activated or not
     *
     * @since 1.0.0
     *
     * @access private
     * @return boolean 
     */
    public static function check_woo_is_activated() {
        // Test to see if WooCommerce is active (including network activated).
        $plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

        if ( in_array( $plugin_path, wp_get_active_and_valid_plugins() ) ) {
            return true;
        } else
            return false;
    }
    
    /**
     * Used like hook. Get data and call sync_products() if response correct.
     *
     * @since 1.0.0
     *
     * @access public
     * @return boolean 
     */
    public function wpsync_hourly_hook(): bool {
        $products = array();
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
            )
        );
        $response = wp_remote_get($this->api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
            )
        ));
        $logger = wc_get_logger();
        $response_code = wp_remote_retrieve_response_code( $response );

        // if we have error like "cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received"
        if ( is_wp_error( $response ) ){
			$logger->log('wpsync-webspark',__('WebSpark API responce error. Product import failed!', 'wpsync-webspark'));
			return false;
		} elseif($response_code  === 200 ) { // correct response code - do import
			$data_decoded = json_decode( wp_remote_retrieve_body( $response ) );
            if ( !empty($data_decoded->data) && is_array($data_decoded->data) ) {
                $this->sync_products( $data_decoded->data );
                unset( $data_decoded );
            } else
                $logger->log('wpsync-webspark',__('WebSpark API empty responce. Product import failed!', 'wpsync-webspark'));
            return true;
        } else { // NOT correct response code - save log
            $logger->log('wpsync-webspark',__('WebSpark API invalid responce code: '.$response_code.' .Product import failed!', 'wpsync-webspark'));
            return false;
        }
    }
    
    /**
     * Get products and sync with woo.
     *
     * @since 1.0.0
     *
     * @access private
     * @return boolean 
     */
    private function sync_products ( array $products ): bool {
        $new_products_id = array();
        foreach ($products as $item) {
            
            $pos = strpos($item->picture, '/abstract');
            if ($pos !== false) {
                $url_parced = parse_url($item->picture);
                $headers = wp_get_http_headers($item->picture);
                $item->picture = $url_parced['scheme'].'://'.$url_parced['host'].$headers['location'];
            }
            
            $product = wc_get_products( array( 'sku' => $item->sku ) );
            if ( empty($product) ) {
                $product_id = wp_insert_post( 
                    array(
                        'post_title' => $item->name,
                        'post_name' => sanitize_title( $item->name ),
                        'post_content' => $item->description,
                        'post_status' => 'publish',
                        'post_type' => "product",
                    ) 
                );
                wp_set_object_terms( $product_id, 'simple', 'product_type' );
                
                update_post_meta( $product_id, '_custom_image_url', $item->picture );
                update_post_meta( $product_id, '_price', $item->price );
                update_post_meta( $product_id, '_regular_price', $item->price  );
                update_post_meta( $product_id, '_sale_price', $item->price  );
                update_post_meta( $product_id, '_sku', $item->sku );
                
                update_post_meta( $product_id, '_visibility', 'visible' );
                update_post_meta( $product_id, '_stock_status', 'instock');
                update_post_meta( $product_id, 'total_sales', '0' );
                update_post_meta( $product_id, '_downloadable', 'no' );
                update_post_meta( $product_id, '_virtual', 'yes' );
                
                update_post_meta( $product_id, '_purchase_note', '' );
                update_post_meta( $product_id, '_featured', 'no' );
                update_post_meta( $product_id, '_weight', '' );
                update_post_meta( $product_id, '_length', '' );
                update_post_meta( $product_id, '_width', '' );
                update_post_meta( $product_id, '_height', '' );
                update_post_meta( $product_id, '_product_attributes', array() );
                update_post_meta( $product_id, '_sale_price_dates_from', '' );
                update_post_meta( $product_id, '_sale_price_dates_to', '' );
                update_post_meta( $product_id, '_manage_stock', 'yes' );
                update_post_meta( $product_id, '_backorders', 'no' );                
                update_post_meta( $product_id, '_stock', $item->in_stock );
                
                $this->upload_image( $item->picture, $product_id );
                $new_products_id[] = $product_id;
            } else {
                $product_id = $product[0]->id;
                
                $meta_input = array(
                    '_stock_status' => 'instock'
                );
                $price = get_post_meta( $product_id, '_price', true );
                if ( $price != $item->price ) {
                    $meta_input['_price'] = $meta_input['_regular_price'] = $meta_input['_sale_price'] = $item->price;
                }
                $stock = get_post_meta( $product_id, '_stock', true );
                if ( $stock != $item->in_stock ) {
                    $meta_input['_stock'] = $item->in_stock;
                }                
                $image_url = get_post_meta( $product_id, '_custom_image_url', true );
                if ( $image_url != $item->picture ) {
                    $meta_input['_custom_image_url'] = $item->picture;
                    wp_delete_attachment( get_post_thumbnail_id($product_id), true );                    
                    $this->upload_image( $item->picture, $product_id );
                } 
                $data = array(
                    'ID' => $product_id,
                    'post_title' => $item->name,
                    'post_name' => sanitize_title( $item->name ),
                    'post_content' => $item->description,
                    'post_status' => 'publish',
                    'meta_input' => $meta_input
                );
                $new_products_id[] = $product_id;
                wp_update_post( $data );
            }
        }
        $args = array(
            'post_status'   => 'publish',
            'post_type'     => "product",
            'post__not_in'  => $new_products_id,
        );
        
        $posts = get_posts($args);
        foreach ($posts as $post) {
            wp_delete_post($post->ID, true);
            wp_delete_attachment( get_post_thumbnail_id($post->ID), true );
        }
        return true;
    }
    
    /**
     * Used like hook. Get products and sync with woo.
     *
     * @since 1.0.0
     *
     * @access private
     * @return boolean 
     */
    private function upload_image( string $image_url, int $product_id ):bool {
        if (!empty($image_url)) {

            // update medatata, regenerate image sizes
            require_once( ABSPATH . 'wp-admin/includes/file.php' );            
            // download to temp dir
            $temp_file = download_url( $image_url );
            if( is_wp_error( $temp_file ) ) {
                return false;
            }
            // move the temp file into the uploads directory
            $file = array(
                'name'     => basename( $image_url ),
                'type'     => mime_content_type( $temp_file ),
                'tmp_name' => $temp_file,
                'size'     => filesize( $temp_file ),
            );
            $sideload = wp_handle_sideload(
                $file,
                array(
                    'test_form'   => false // no needs to check 'action' parameter
                )
            );

            if( ! empty( $sideload[ 'error' ] ) ) {
                // you may return error message if you want
                return false;
            }

            // it is time to add our uploaded image into WordPress media library
            $attachment_id = wp_insert_attachment(
                array(
                    'guid'           => $sideload[ 'url' ],
                    'post_mime_type' => $sideload[ 'type' ],
                    'post_title'     => basename( $sideload[ 'file' ] ),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ),
                $sideload[ 'file' ]
            );

            if( is_wp_error( $attachment_id ) || ! $attachment_id ) {
                return false;
            }

            // update medatata, regenerate image sizes
            require_once( ABSPATH . 'wp-admin/includes/image.php' );

            wp_update_attachment_metadata(
                $attachment_id,
                wp_generate_attachment_metadata( $attachment_id, $sideload[ 'file' ] )
            );
            
            set_post_thumbnail( $product_id, $attachment_id );            
            
            return true;            
        }
    }
    
}

if ( !wpSync::check_woo_is_activated() ) {
    add_action( 'admin_notices', 'activate_notice' );
    add_action( 'admin_init', 'wpsync_plugin_off' );
}
function wpsync_plugin_off() {
        deactivate_plugins( plugin_basename( __FILE__ ) );
}
function activate_notice() {
        echo '<div class="notice error is-dismissible">'.__( 'WooCommerce Plugin is not active. Please activate it before using wpsync-webspark plugin. wpsync-webspark deactivated.', 'wpsync-webspark' ).'</p></div>';
}
/**
 * Init wpSync
 */
$wpSync = new wpSync();

if (!defined('FP_ABSPATH')) {
    define('FP_ABSPATH', plugin_dir_path(__FILE__));
}

