<?php

/*
 * Plugin Name: 1c4wp
 * Plugin URI: https://github.com/nikolays93
 * Description: 1c exchange prototype
 * Version: 0.2
 * Author: NikolayS93
 * Author URI: https://vk.com/nikolays_93
 * Author EMAIL: NikolayS93@ya.ru
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: 1c4wp
 * Domain Path: /languages/
 */

namespace NikolayS93\Exchange;

// for debug
$_SERVER['PHP_AUTH_USER'] = 'root';
$_SERVER['PHP_AUTH_PW'] = 'q1w2';

// define('EX_DEBUG_ONLY', TRUE);

if ( !defined( 'ABSPATH' ) ) exit('You shall not pass');
if (version_compare(PHP_VERSION, '5.4') < 0) {
    throw new \Exception('Plugin requires PHP 5.4 or above');
}

if( !defined(__NAMESPACE__ . '\PLUGIN_DIR') ) define(__NAMESPACE__ . '\PLUGIN_DIR', __DIR__);
if( !defined(__NAMESPACE__ . '\PLUGIN_FILE') ) define(__NAMESPACE__ . '\PLUGIN_FILE', __FILE__);

require_once ABSPATH . "wp-admin/includes/plugin.php";
require_once PLUGIN_DIR . '/vendor/autoload.php';

/**
 * Uniq prefix
 */
if(!defined(__NAMESPACE__ . '\DOMAIN')) define(__NAMESPACE__ . '\DOMAIN', Plugin::get_plugin_data('TextDomain'));

/**
 * Server can get max size
 */
if(!defined(__NAMESPACE__ . '\FILE_LIMIT')) define(__NAMESPACE__ . '\FILE_LIMIT', null);

/**
 * Work in charset
 */
if(!defined(__NAMESPACE__ . '\XML_CHARSET') ) define(__NAMESPACE__ . '\XML_CHARSET', 'UTF-8');

/**
 * Notice type
 * @todo check this
 */
if (!defined(__NAMESPACE__ . '\SUPPRESS_NOTICES')) define(__NAMESPACE__ . '\SUPPRESS_NOTICES', false);

/**
 * Simple products only
 * @todo check this
 */
if (!defined(__NAMESPACE__ . '\DISABLE_VARIATIONS')) define(__NAMESPACE__ . '\DISABLE_VARIATIONS', false);

/**
 * Current timestamp
 */
if (!defined(__NAMESPACE__ . '\TIMESTAMP')) define(__NAMESPACE__ . '\TIMESTAMP', time());

/**
 * Auth cookie name
 */
if (!defined(__NAMESPACE__ . '\COOKIENAME')) define(__NAMESPACE__ . '\COOKIENAME', 'ex-auth');

/**
 * Woocommerce currency for single price type
 * @todo move to function
 */
if (!defined(__NAMESPACE__ . '\CURRENCY')) define(__NAMESPACE__ . '\CURRENCY', null);
if(!defined('NikolayS93\Exchange\Model\EXT_ID')) define('NikolayS93\Exchange\Model\EXT_ID', '_ext_ID');
if (!defined('EX_EXT_METAFIELD')) define('EX_EXT_METAFIELD', 'EXT_ID');

require_once PLUGIN_DIR . '/.register.php';

add_action( '1c4wp_exchange', __NAMESPACE__ . '\doExchange', 10 );
function doExchange() {
    /**
     * Start buffer in strict mode
     */
    Utils::start_exchange_session();

    /**
     * Check required arguments
     */
    if ( !$type = Utils::get_type() ) Utils::error("No type");
    if ( !$mode = Utils::get_mode() ) Utils::error("No mode");

    /**
     * CGI fix
     */
    if ( !$_GET && isset($_SERVER['REQUEST_URI']) ) {
        $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        parse_str($query, $_GET);
    }

    /**
     * Get status (from request for debug)
     */
    $status = ( !empty($_GET['status']) ) ? Utils::get_status(intval($_GET['status'])) : Plugin::get( 'status', false );

    /**
     * CommerceML protocol version
     * @var string (float value)
     */
    $version = get_option( 'exchange_version', '' );

    /**
     * @url http://v8.1c.ru/edi/edi_stnd/131/
     *
     * A. Начало сеанса (Авторизация)
     * Выгрузка данных начинается с того, что система "1С:Предприятие" отправляет http-запрос следующего вида:
     * http://<сайт>/<путь>/1c_exchange.php?type=catalog&mode=checkauth.
     *
     * A. Начало сеанса
     * http://<сайт>/<путь> /1c_exchange.php?type=sale&mode=checkauth.
     * @return 'success\nCookie\nCookie_value'
     */
    if ( 'checkauth' == $mode ) {
        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $server_key) {
            if ( !isset($_SERVER[ $server_key ]) ) continue;

            list(, $auth_value) = explode(' ', $_SERVER[$server_key], 2);
            $auth_value = base64_decode($auth_value);
            list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $auth_value);

            break;
        }

        if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            Utils::error("No authentication credentials");
        }

        $user = wp_authenticate($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        if ( is_wp_error($user) ) Utils::wp_error($user);
        Utils::check_user_permissions($user);

        $expiration = TIMESTAMP + apply_filters('auth_cookie_expiration', DAY_IN_SECONDS, $user->ID, false);
        $auth_cookie = wp_generate_auth_cookie($user->ID, $expiration);

        exit("success\n". COOKIENAME ."\n$auth_cookie");
    }

    Utils::check_wp_auth();

    /**
     * B. Запрос параметров от сайта
     * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=init
     * B. Уточнение параметров сеанса
     * http://<сайт>/<путь> /1c_exchange.php?type=sale&mode=init
     *
     * @return
     * zip=yes|no - Сервер поддерживает Zip
     * file_limit=<число> - максимально допустимый размер файла в байтах для передачи за один запрос
     */
    if ( 'init' == $mode ) {
        /** Zip required (if no - must die) */
        Utils::check_zip();

        /**
         * Option is empty then exchange end
         * @var [type]
         */
        if( !$start = get_option( 'exchange_start-date', '' ) ) {
            /**
             * Refresh exchange version
             * @var float isset($_GET['version']) ? ver >= 3.0 : ver <= 2.99
             */
            update_option( 'exchange_version', !empty($_GET['version']) ? $_GET['version'] : '' );

            /**
             * Set start wp date sql format
             */
            update_option( 'exchange_start-date', date('Y-m-d H:i:s') );
        }

        exit("zip=yes\nfile_limit=" . Utils::get_filesize_limit());
    }

    /**
     * C. Получение файла обмена с сайта
     * http://<сайт>/<путь> /1c_exchange.php?type=sale&mode=query.
     */
    elseif ( 'query' == $mode ) {
        // ex_mode__query($_REQUEST['type']);
    }

    /**
     * C. Выгрузка на сайт файлов обмена
     * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=file&filename=<имя файла>
     * D. Отправка файла обмена на сайт
     * http://<сайт>/<путь> /1c_exchange.php?type=sale&mode=file&filename=<имя файла>
     *
     * Загрузка CommerceML2 файла или его части в виде POST.
     * @return success
     */
    elseif ( 'file' == $mode ) {
        /**
         * Принимает файл и распаковывает его
         */
        $filename = Utils::get_filename();
        $path_dir = Parser::get_dir( Utils::get_type() );

        if ( !empty($filename) ) {
            $path = $path_dir . '/' . ltrim($filename, "./\\");

            $input_file = fopen("php://input", 'r');
            $temp_path = "$path~";
            $temp_file = fopen($temp_path, 'w');
            stream_copy_to_stream($input_file, $temp_file);

            if ( is_file($path) ) {
                $temp_header = file_get_contents($temp_path, false, null, 0, 32);
                if (strpos($temp_header, "<?xml ") !== false) unlink($path);
            }

            $temp_file = fopen($temp_path, 'r');
            $file = fopen($path, 'a');
            stream_copy_to_stream($temp_file, $file);
            fclose($temp_file);
            unlink($temp_path);

            if( 0 == filesize( $path ) ) {
                Utils::error( sprintf("File %s is empty", $path) );
            }
        }

        $zip_paths = glob("$path_dir/*.zip");

        $r = Utils::unzip( $zip_paths, $path_dir, $remove = true );
        if( true !== $r ) Utils::error($r);

        if ('catalog' == Utils::get_type()) exit("success\nФайл принят.");
    }

     /**
     * D. Пошаговая загрузка данных
     * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=import&filename=<имя файла>
     * @return 'progress|success|failure'
     */
    elseif ( 'import' == $mode ) {

        $filename = Utils::get_filename();

        if( !$filename ) {
            Utils::error( "Filename is empty" );
        }

        /**
         * Answer: COMMIT || ROLLBACK on end
         */
        Utils::set_transaction_mode();

        /**
         * Parse
         */
        $Parser = Parser::getInstance( $filename, $fillExists = true );

        $categories = $Parser->getCategories();
        $properties = $Parser->getProperties();
        $developers = $Parser->getDevelopers();
        $warehouses = $Parser->getWarehouses();

        $products = $Parser->getProducts();
        $offers = $Parser->getOffers();

        $attributeValues = array();
        foreach ($properties as $property)
        {
            /** Collection to simple array */
            foreach ($property->getTerms() as $term)
            {
                $attributeValues[] = $term;
            }
        }

        /**
         * Write
         */
        Update::terms( $categories );
        Update::termmeta( $categories );

        Update::terms( $developers );
        Update::termmeta( $developers );

        Update::terms( $warehouses );
        Update::termmeta( $warehouses );

        Update::properties( $properties );

        Update::terms( $attributeValues );
        Update::termmeta( $attributeValues );

        $pluginMode = '';
        if( !empty($products) || (!empty($offers) && 0 !== strpos($filename, 'rest') && 0 !== strpos($filename, 'price')) ) {

            if( 'relationships' != ($pluginMode = Plugin::get('mode')) ) {
                Update::posts( $products );
                Update::postmeta( $products );

                Update::offers( $offers );
                Update::offerPostMetas( $offers );

                Plugin::set('mode', 'relationships');
                exit("progress\nNeed_relationships");
            }
            else {
                Update::relationships( $products );
                Update::relationships( $offers );

                Plugin::set('mode', '');
            }
        }

        exit("success\nStatus: $status\nMode: $mode\nPluginMode: $pluginMode");
    }

    /**
     * E. Деактивация данных
     * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=deactivate
     * @since  3.0
     * @return 'progress|success|failure'
     */
    elseif ( 'deactivate' == $mode ) {
        /**
         * Reset start date
         */
        update_option( 'exchange_start-date', '' );

        /**
         * Refresh version
         */
        update_option( 'exchange_version', '' );

        /**
         * Чистим и пересчитываем количество записей в терминах
         * /
        $filename = Utils::get_filename();

        /**
         * Get valid namespace ('import', 'offers', 'orders')
         * /
        // $namespace = $filename ?
        //     preg_replace("/^([a-zA-Z]+).+/", '$1', $filename) : 'import';

        // rest, prices (need debug in new sheme version)
        // if (!in_array($namespace, array('import', 'offers', 'orders'))) {
        //     Utils::error( sprintf("Unknown import file type: %s", $namespace) );
        // }

        $dir = PathFinder::get_dir('catalog');

        /**
         * Get import filepath
         * /
        if( $filename && is_readable($dir . $filename) ) {
            $path = $dir . $filename;
        }
        else {
            $filename = PathFinder::get_files( $namespace );
            // check in once
            $path = current($filename);
        }

        list($is_full, $is_moysklad) = ex_check_head_meta($path);


        $catalogFiles = PathFinder::get_files();
        $bkpDir = PathFinder::get_dir( '_backup' );

        foreach ($catalogFiles as $i => $file) {
            // remove
            // @unlink($file); or move:
            rename( $file, $bkpDir . basename($file));
        }

        /**
         * Need products to archive
         * /
        // if( $is_full ) {
        //     Archive::posts( $products );
        // }

        /**
         * Insert count the number of records in a category
         * /
        Update::update_term_counts();
        */
        // flush_rewrite_rules();

        delete_transient( 'wc_attribute_taxonomies' );

        Plugin::set( array(
            'status' => Utils::get_status(0),
            'last_update' => date('Y-m-d H:i:s'),
        ) );

        exit("success\nдеактивация товаров завершена");
    }

    /**
     * F. Завершающее событие загрузки данных
     * http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=complete
     * @since  3.0
     */
    elseif ('complete' == $mode) {

        exit("success\nВыгрузка данных завершена");
    }

    /**
     * http://<сайт>/<путь> /1c_exchange.php?type=sale&mode=success
     */
    elseif ('success' == $mode) {
        // ex_mode__success($_REQUEST['type']);
    }

    else {
        Utils::error("Unknown mode");
    }

    /** Need early end */
    exit("failure\n". 'with status: ' . $status);
}

// add_filter( '1c4wp_update_term', __NAMESPACE__ . '\update_term_filter', $priority = 10, $accepted_args = 1 );
function update_term_filter( $arTerm ) {
    /**
     * @todo fixit
     * #crunch
     * Update only parents (Need for second query)
     */
    $res['panret'] = $arTerm['parent'];

    return $res;
}

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\Plugin', 'activate' ) );
// register_uninstall_hook( __FILE__, array( __NAMESPACE__ . '\Plugin', 'uninstall' ) );
// register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\Plugin', 'deactivate' ) );

function getTaxonomyByExternal( $raw_ext_code )
{
    global $wpdb;

    $rsResult = $wpdb->get_results( $wpdb->prepare("
        SELECT wat.*, watm.* FROM {$wpdb->prefix}woocommerce_attribute_taxonomies AS wat
        INNER JOIN {$wpdb->prefix}woocommerce_attribute_taxonomymeta AS watm ON wat.attribute_id = watm.tax_id
        WHERE watm.meta_value = %d
        LIMIT 1
        ", $raw_ext_code) );

    if( $rsResult ) {
        $res = current($rsResult);
        $obResult = new ExchangeAttribute( $res, $res->meta_value );
    }

    return $obResult;
}

function getAttributesMap()
{
    global $wpdb;

    $arResult = array();
    $rsResult = $wpdb->get_results( "
        SELECT wat.*, watm.*, watm.meta_value as ext FROM {$wpdb->prefix}woocommerce_attribute_taxonomies AS wat
        INNER JOIN {$wpdb->prefix}woocommerce_attribute_taxonomymeta AS watm ON wat.attribute_id = watm.tax_id" );

    echo "<pre>";
    var_dump( $rsResult );
    echo "</pre>";
    die();

    foreach ($rsResult as $res)
    {
        $arResult[ $res->meta_value ] = new ExchangeAttribute( $res, $res->meta_value );
    }

    return $arResult;
}