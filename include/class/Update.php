<?php

namespace NikolayS93\Exchange;

use NikolayS93\Exchange\Utils;
use NikolayS93\Exchange\Model\TermModel;
use NikolayS93\Exchange\Model\ProductModel;

use NikolayS93\Exchange\Model\ExchangeTerm;
use NikolayS93\Exchange\Model\ExchangeAttribute;
use NikolayS93\Exchange\Model\ExchangePost;

// post_mode  '' | update | off
// post_name  '' | update | translit
// skip_post_author           '' | on
// skip_post_title            '' | on
// skip_post_content          '' | on
// skip_post_excerpt          '' | on

// skip_post_attribute_value  '' | on

// offer_mode  '' | update | off
// skip_offer_price  '' | on
// skip_offer_qty    '' | on
// skip_offer_unit   '' | on
// skip_offer_weight '' | on

// attribute_mode
// category_mode
// developer_mode
// warehouse_mode

// post_relationship
// post_attribute

class Update
{

    static function get_sql_placeholder( $structure )
    {
        $values = array();
        foreach ($structure as $column => $format) {
            $values[] = "'$format'";
        }

        $query = '(' . implode(",", $values) . ')';

        return $query;
    }

    static function get_sql_structure( $structure )
    {
        return '('. implode(', ', array_keys( $structure )) .')';
    }

    static function get_sql_duplicate( $structure )
    {
        $values = array();
        foreach ($structure as $column => $format) {
            $values[] = "$column = VALUES($column)";
        }

        $query = implode(",\n", $values) . ';';

        return $query;
    }

    static function get_sql_update($bd_name, $structure, $insert, $placeholders, $duplicate)
    {
        global $wpdb;

        $query = "INSERT INTO $bd_name $structure VALUES " . implode(', ', $placeholders);
        $query.= " ON DUPLICATE KEY UPDATE " . $duplicate;

        return $wpdb->prepare($query, $insert);
    }

    public static function posts( &$products )
    {
        global $wpdb, $user_id; //, $site_url
        // $site_url = get_site_url();

        // If data is empty
        if( empty($products) ) return;

        // If disabled option
        if( 'off' === ($post_mode = Plugin::get('post_mode')) ) return;

        // Current date for update modify
        $date_now = current_time('mysql');
        $gmdate_now = gmdate('Y-m-d H:i:s');

        // Generate DUPLICATE KEY UPDATE structure
        $posts_structure = ExchangePost::get_structure('posts');

        $structure = static::get_sql_structure( $posts_structure );
        $duplicate = static::get_sql_duplicate( $posts_structure );
        $sql_placeholder = static::get_sql_placeholder( $posts_structure );

        // define empty data for fill
        $insert = array();
        $filler = array();

        // Define empty result
        $results = array(
            'create' => 0,
            'update' => 0,
        );

        /**
         * Prepare data
         * @var $product NikolayS93\Exchange\Model\ExchangePost
         */
        foreach ($products as &$product)
        {
            $product->prepare();
            $p = $product->getObject();

            if( !$product->get_id() ) {
                /** if is update only */
                if( 'update' === $post_mode ) continue;

                $results['create']++;
            }
            else {
                $results['update']++;
            }

            // Is date null set now
            if( !(int) preg_replace('/[^0-9]/', '', $p->post_date) ) $p->post_date = $date_now;
            if( !(int) preg_replace('/[^0-9]/', '', $p->post_date_gmt) ) $p->post_date_gmt = $gmdate_now;

            array_push($insert,
                $p->ID,
                $p->post_author,
                $p->post_date,
                $p->post_date_gmt,
                $p->post_content,
                $p->post_title,
                $p->post_excerpt,
                $p->post_status,
                $p->comment_status,
                $p->ping_status,
                $p->post_password,
                $p->post_name,
                $p->to_ping,
                $p->pinged,
                $p->post_modified = $date_now,
                $p->post_modified_gmt = $gmdate_now,
                $p->post_content_filtered,
                $p->post_parent,
                $p->guid,
                $p->menu_order,
                $p->post_type,
                $p->post_mime_type,
                $p->comment_count
            );

            array_push($filler, $sql_placeholder);
        }

        /**
         * Execute: sql update (DUPLICATE KEY) posts
         */
        if( sizeof($insert) && sizeof($filler) ) {
            $query = static::get_sql_update($wpdb->posts, $structure, $insert, $filler, $duplicate);
            $wpdb->query( $query );
        }

        return $results;
    }

    public static function postmeta( $products )
    {
        $results = array(
            'update' => 0,
        );

        $skip_post_meta_value = Plugin::get('skip_post_meta_value', false);
        $skip_post_meta_sku   = Plugin::get('skip_post_meta_sku', false);
        $skip_post_meta_unit  = Plugin::get('skip_post_meta_unit', false);
        $skip_post_meta_tax   = Plugin::get('skip_post_meta_tax', false);

        foreach ($products as $product)
        {
            if( $skip_post_meta_value && !$product->isNew() ) continue;

            /**
             * @todo @fixed think how to get inserted meta
             * We fillExists oraphned before postmeta update
             * @todo check this
             */
            if( !$post_id = $product->get_id() ) continue;

            /**
             * Get list of all meta by product
             * ["_sku"], ["_unit"], ["_tax"], ["{$custom}"],
             */
            $productMeta = $product->getProductMeta();

            if( !$product->isNew() ) {
                if( $skip_post_meta_sku ) unset( $productMeta["_sku"] );
                if( $skip_post_meta_unit ) unset( $productMeta["_unit"] );
                if( $skip_post_meta_tax ) unset( $productMeta["_tax"] );
            }

            foreach ($productMeta as $mkey => $mvalue)
            {
                update_post_meta( $post_id, $mkey, trim($mvalue) );
                $results['update']++;
            }
        }

        return $results;
    }

    /**
     * @param  array  &$terms  as $rawExt => ExchangeTerm
     */
    public static function terms( &$terms )
    {
        global $wpdb, $user_id;

        $updated = array();

        foreach ($terms as &$term)
        {
            $term->prepare();

            /**
             * @var Int WP_Term->term_id
             */
        	$term_id = $term->get_id();

            /**
             * @var WP_Term
             */
            $obTerm = $term->getTerm();

            /**
             * @var array
             */
            $arTerm = (array) $obTerm;

            /**
             * @todo Check why need double iteration for parents
             * @note do not update exists terms
             * @note So, i think, we can write author's user_id and do not touch if is manual edited by adminisrator
             */

            if( 'product_cat' == $arTerm['taxonomy'] ) {
                if( 'off' === ($category_mode = Plugin::get('category_mode')) ) continue;
                if( 'create' == $category_mode && $term_id ) continue;
                if( 'update' == $category_mode && !$term_id ) continue;

                if( $term_id ) {
                    if( !Plugin::get('cat_name') )   unset($arTerm['name']);
                    if( !Plugin::get('cat_desc') )   unset($arTerm['description']);
                    if( !Plugin::get('cat_parent') ) unset($arTerm['parent']);
                }
            }

            elseif( apply_filters( 'developerTaxonomySlug', DEFAULT_DEVELOPER_TAX_SLUG ) == $arTerm['taxonomy'] ) {
                if( 'off' === ($developer_mode = Plugin::get('developer_mode')) ) continue;
                if( 'create' == $developer_mode && $term_id ) continue;
                if( 'update' == $developer_mode && !$term_id ) continue;

                if( $term_id ) {
                    if( !Plugin::get('dev_name') ) unset($arTerm['name']);
                    if( !Plugin::get('dev_desc') ) unset($arTerm['description']);
                }
            }

            elseif( apply_filters( 'warehouseTaxonomySlug', DEFAULT_WAREHOUSE_TAX_SLUG ) == $arTerm['taxonomy'] ) {
                if( 'off' === ($warehouse_mode = Plugin::get('warehouse_mode')) ) continue;
                if( 'create' == $warehouse_mode && $term_id ) continue;
                if( 'update' == $warehouse_mode && !$term_id ) continue;

                if( $term_id ) {
                    if( !Plugin::get('wh_name') ) unset($arTerm['name']);
                    if( !Plugin::get('wh_desc') ) unset($arTerm['description']);
                }
            }

            // attributes
            else {
                if( 'off' === ($attribute_mode = Plugin::get('attribute_mode')) ) continue;
                if( 'create' == $attribute_mode && $term_id ) continue;
                if( 'update' == $attribute_mode && !$term_id ) continue;

                if( $term_id ) {
                    if( !Plugin::get('pa_name') ) unset($arTerm['name']);
                    if( !Plugin::get('pa_desc') ) unset($arTerm['description']);
                }
            }

            if( $term_id ) {
                $result = wp_update_term( $term_id, $arTerm['taxonomy'], $arTerm );
            }
            else {
                $result = wp_insert_term( $arTerm['name'], $arTerm['taxonomy'], $arTerm );
            }

            if( !is_wp_error($result) ) {
                $term->set_id( $result['term_id'] );
                $updated[ $result['term_id'] ] = $term;

                foreach ($terms as &$oTerm)
                {
                    if( $term->getExternal() === $oTerm->getParentExternal() ) {
                        $oTerm->set_parent_id( $term->get_id() );
                    }
                }
            }
            else {
                Utils::addLog( $result, $arTerm );
            }
        }

        return $updated;
    }

    public static function termmeta($terms)
    {
        global $wpdb;

        $insert = array();
        $phs = array();

        $meta_structure = ExchangeTerm::get_structure('termmeta');

        $structure = static::get_sql_structure( $meta_structure );
        $duplicate = static::get_sql_duplicate( $meta_structure );
        $sql_placeholder = static::get_sql_placeholder( $meta_structure );

        foreach ($terms as $term)
        {
            if( !$term->get_id() ) { // Utils::addLog( new WP_Error() );
                continue;
            }

            array_push($insert, $term->meta_id, $term->get_id(), $term->getExtID(), $term->getExternal());
            array_push($phs, $sql_placeholder);
        }

        $updated_rows = 0;
        if( !empty($insert) && !empty($phs) ) {
            $query = static::get_sql_update($wpdb->termmeta, $structure, $insert, $phs, $duplicate);
            $updated_rows = $wpdb->query( $query );
        }

        return $updated_rows;
    }

    public static function properties( &$properties = array() )
    {
        global $wpdb;

        $retry = false;

        foreach ($properties as $propSlug => $property)
        {
            $slug = $property->getSlug();

            /**
             * Register Property's Taxonomies;
             */
            if( 'select' == $property->getType() && !$property->get_id() && !taxonomy_exists($slug) ) {
                /**
                 * @var Array
                 */
                $external = $property->getExternal();
                $attribute = $property->fetch();

                $result = wc_create_attribute( $attribute );

                if( is_wp_error( $result ) ) Utils::addLog( $result, $attribute );

                $attribute_id = intval( $result );
                if( 0 < $attribute_id ) {
                    if( $external ) {
                        $property->set_id( $attribute_id );

                        $insert = $wpdb->insert(
                            $wpdb->prefix . 'woocommerce_attribute_taxonomymeta',
                            array(
                                'meta_id'    => null,
                                'tax_id'     => $attribute_id,
                                'meta_key'   => ExchangeTerm::getExtID(),
                                'meta_value' => $external,
                            ),
                            array( '%s', '%d', '%s', '%s' )
                        );
                    }
                    else {
                        Utils::addLog(new \WP_Error( 'error', __('Empty attr insert or attr external by ' . $attribute['attribute_label'])));
                    }

                    $retry = true;
                }

                /**
                 * @var bool
                 * Почему то термины не вставляются сразу же после вставки таксономии (proccess_add_attribute)
                 * Нужно будет пройтись еще раз и вставить термины.
                 */
                $retry = true;
            }

            if( !taxonomy_exists($slug) ) {
                /**
                 * For exists imitation
                 */
                register_taxonomy( $slug, 'product' );
            }
        }

        return $retry;
    }

    /**
     * @todo write it for mltile offers
     */
    public static function offers( Array &$offers )
    {
        return array(
            'create' => 0,
            'update' => 0,
        );
    }

    public static function offerPostMetas( Array &$offers ) // $columns = array('sku', 'unit', 'price', 'quantity', 'stock_wh')
    {
        global $wpdb, $user_id;

        $result = array(
            'update' => 0,
        );

        /** @var offer ExchangeOffer */
        foreach ($offers as $offer)
        {
            // if ($offer->isNew())
            if( !$post_id = $offer->get_id() ) continue;

            $properties = array();

            // its on post only?
            if( $unit = $offer->getMeta('unit') ) {
                $properties['_unit'] = $unit;
            }

            if( 'off' !== Plugin::get('offer_price', false) ) {
                if( $price = $offer->getMeta('price') ) {
                    $properties['_regular_price'] = $price;
                    $properties['_price'] = $price;
                }
            }

            if( 'off' !== Plugin::get('offer_qty', false) ) {
                $qty = $offer->get_quantity();

                $properties['_manage_stock'] = 'yes';
                $properties['_stock_status'] = 0 < $qty ? 'instock' : 'outofstock';
                $properties['_stock']        = $qty;

                if( $stock_wh = $offer->getMeta('stock_wh') ) {
                    $properties['_stock_wh'] = $stock_wh;
                }
            }

            if( 'off' !== Plugin::get('offer_weight', false) && $weight = $offer->getMeta('weight') ) {
                $properties['_weight'] = $weight;
            }

            // Типы цен
            // if( $exOffer->prices ) {
            //     $properties['_prices'] = $exOffer->prices;
            // }

            foreach ($properties as $property_key => $property)
            {
                update_post_meta( $post_id, $property_key, $property );
                // wp_cache_delete( $post_id, "{$property_key}_meta" );
            }
        }
    }

    /***************************************************************************
     * Update relationships
     */
    public static function relationships( Array $posts, $args = array() )
    {
        /** @global wpdb $wpdb built in wordpress db object */
        global $wpdb;

        $updated = 0;

        foreach ($posts as $post)
        {
            if( !$post_id = $post->get_id() ) continue;

            // if( method_exists($post, 'updateObjectTerms') ) {
            //     $updated += $post->updateObjectTerms();
            // }

            if( method_exists($post, 'updateAttributes') ) {
                $post->updateAttributes();
            }
        }

        return $updated;
    }

    // public static function warehouses_ext()
    // {
    //     $xmls = array(
    //         'primary'    => get_theme_mod( 'primary_XML_ID', '0' ),
    //         'secondary'  => get_theme_mod( 'secondary_XML_ID', '0' ),
    //         'company_3' => get_theme_mod( 'company_3_XML_ID', '0' ),
    //         'company_4' => get_theme_mod( 'company_4_XML_ID', '0' ),
    //         'company_5' => get_theme_mod( 'company_5_XML_ID', '0' ),
    //     );

    //     // $xmls = array_flip($xmls);
    //     // unset( $xmls[0] );

    //     // $wh_count = 0;
    //     // foreach ($warehouses as $wh_key => &$warehouse) {
    //     //     if( isset( $xmls[ $wh_key ] ) ) {
    //     //         $warehouse[ 'contact' ] = $xmls[ $wh_key ];
    //     //         $wh_count++;
    //     //     }
    //     // }

    //     // return $wh_count;
    // }

    public static function update_term_counts()
    {
        global $wpdb;

        /**
         * update taxonomy term counts
         */
        $wpdb->query("
            UPDATE {$wpdb->term_taxonomy} SET count = (
            SELECT COUNT(*) FROM {$wpdb->term_relationships} rel
            LEFT JOIN {$wpdb->posts} po
                ON (po.ID = rel.object_id)
            WHERE
                rel.term_taxonomy_id = {$wpdb->term_taxonomy}.term_taxonomy_id
            AND
                {$wpdb->term_taxonomy}.taxonomy NOT IN ('link_category')
            AND
                po.post_status IN ('publish', 'future')
        )");

        delete_transient( 'wc_term_counts' );
    }
}