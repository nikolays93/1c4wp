<?php

namespace NikolayS93\Exchanger\Model;

use CommerceMLParser\Model\Types\BaseUnit;
use NikolayS93\Exchanger\Model\Abstracts\Term;
use NikolayS93\Exchanger\Model\Interfaces\Identifiable;
use NikolayS93\Exchanger\Parser;
use NikolayS93\Exchanger\Model\Category;
use NikolayS93\Exchanger\ORM\Collection;
use NikolayS93\Exchanger\Plugin;
use function NikolayS93\Exchanger\Error;
use function NikolayS93\Exchanger\Plugin;

/**
 * Content: {
 *     Variables
 *     Utils
 *     Construct
 *     Relatives
 *     CRUD
 * }
 */
class ExchangeProduct extends ExchangePost {
	/**
	 * "Product_cat" type wordpress terms
	 * @var Collection
	 */
	public $categories;

	/**
	 * Product properties with link by term (has taxonomy/term)
	 * @var Collection
	 */
	public $attributes;

	/**
	 * @param \CommerceMLParser\ORM\Collection $base_unit
	 */
	public function get_current_base_unit( $base_unit ) {
		/** @var BaseUnit */
		$base_unit_current = $base_unit->current();
		if ( ! $base_unit_name = $base_unit_current->getNameInterShort() ) {
			$base_unit_name = $base_unit_current->getNameFull();
		}

		return $base_unit_name;
	}

	/**
	 * @param \CommerceMLParser\ORM\Collection $taxRatesCollection СтавкиНалогов
	 */
	public function get_current_tax_rate( $taxRatesCollection ) {
		return $taxRatesCollection->current()->getRate();
	}

	function __construct( Array $post, $ext = '', $meta = array() ) {
		parent::__construct( $post, $ext, $meta );

		$this->categories = new Collection();
		$this->attributes = new Collection();
	}

	/**************************************************** Relatives ***************************************************/

	public function get_category( $CollectionItemKey = '' ) {
		$category = $CollectionItemKey ?
			$this->categories->offsetGet( $CollectionItemKey ) :
			$this->categories->first();

		return $category;
	}

	public function add_category( Category $ExchangeTerm ) {
		$this->categories->add( $ExchangeTerm );

		return $this;
	}

	public function get_attribute( $CollectionItemKey = '' ) {
		$attribute = $CollectionItemKey ?
			$this->attributes->offsetGet( $CollectionItemKey ) :
			$this->attributes->first();

		return $attribute;
	}

	public function add_attribute( Attribute $ProductAttribute ) {
		$this->attributes->add( $ProductAttribute );

		return $this;
	}

	/****************************************************** CRUD ******************************************************/
	public function fetch( $key = null ) {
		$data                       = parent::fetch();
		$data['term_relationships'] = array();

		$fetch = function ( Identifiable $item ) use ( &$data ) {
			if ( $this->get_id() && $item->get_id() ) {
				$data['term_relationships'][] = array(
					'object_id'        => $this->get_id(),
					'term_taxonomy_id' => $item->get_id(),
					'term_order'       => 0,
				);
			}
		};

		array_map( $fetch, $this->categories->fetch() );
		array_map( $fetch, $this->attributes->fetch() );

		if ( null === $key || ( $key && ! isset( $data[ $key ] ) ) ) {
			return $data;
		}

		return $data[ $key ];
	}

	function update_attributes() {

		/**
		 * Set attribute properties
		 */
//        $arAttributes = array();
//
//        if ( 'off' === ( $post_attribute_mode = Plugin()->get_setting( 'post_attribute' ) ) ) {
//            return;
//        }
//
//        foreach ( $this->properties as $property ) {
//            $label          = $property->get_name();
//            $external_code  = $property->get_external();
//            $property_value = $property->get_value();
//            $taxonomy       = $property->get_slug();
//            $type           = $property->get_type();
//            $is_visible     = 0;
//
//            /**
//             * I can write relation if term exists (term as value)
//             */
//            if ( $property_value instanceof Category ) {
//                $arAttributes[ $taxonomy ] = array(
//                    'name'         => $taxonomy,
//                    'value'        => '',
//                    'position'     => 10,
//                    'is_visible'   => 0,
//                    'is_variation' => 0,
//                    'is_taxonomy'  => 1,
//                );
//            } else {
//                // Try set as text if term is not exists
//                // @todo check this
//                if ( 'text' != $type && 'text' == $post_attribute_mode && $taxonomy && $external_code ) {
//                    $is_visible = 0;
//
//                    /**
//                     * Try set attribute name by exists terms
//                     * Get all properties from parser
//                     */
//                    if ( empty( $allProperties ) ) {
//                        $Parser        = Parser::get_instance();
//                        $allProperties = $Parser->get_properties();
//                    }
//
//                    foreach ( $allProperties as $_property ) {
//                        if ( $_property->get_slug() == $taxonomy && ( $_terms = $_property->get_terms() ) ) {
//                            if ( isset( $_terms[ $external_code ] ) ) {
//                                $_term = $_terms[ $external_code ];
//
//                                if ( $_term instanceof Category ) {
//                                    $label = $_property->get_name();
//                                    $property->set_value( $_term->get_name() );
//                                    break;
//                                }
//                            }
//                        }
//                    }
//                }
//
//                $arAttributes[ $taxonomy ] = array(
//                    'name'         => $label ? $label : $taxonomy,
//                    'value'        => $property->get_value(),
//                    'position'     => 10,
//                    'is_visible'   => $is_visible,
//                    'is_variation' => 0,
//                    'is_taxonomy'  => 0,
//                );
//            }
//        }
//
//        update_post_meta( $this->get_id(), '_product_attributes', $arAttributes );
	}

	function update_object_terms() {
		$product_id = $this->get_id();
		$count      = 0;

		/**
		 * @param Term $term
		 */
		$update_object_terms = function ( $term ) use ( $product_id, &$count ) {
			if ( $term->update_object_term( $product_id ) ) {
				$count ++;
			}
		};

		$this->categories->walk( $update_object_terms );
		// @todo
//        if ( ! $this->attributes->isEmpty() ) {
//            if ( 'off' !== ( $post_attribute = Plugin::get_instance()->get_setting( 'post_attribute' ) ) ) {
//                /**
//                 * @param Attribute $attribute
//                 */
//                $update_object_attribute_terms = function ($attribute) use ($update_object_terms) {
//                    /** @var AttributeValue $attribute_value */
//                    $attribute_value = $attribute->get_value();
//                    $attribute_value->walk( $update_object_terms );
//                };
//
//                $this->attributes->walk( $update_object_attribute_terms );
//            }
//        }
//
//        /**
//         * Update product's properties
//         */
//        if ( ! $this->properties->isEmpty() ) {
//            $terms_id = array();
//
//            /** @var Attribute attribute */
//            foreach ( $this->properties as $attribute ) {
//                if ( $taxonomy = $attribute->getSlug() ) {
//                    if ( ! isset( $terms_id[ $taxonomy ] ) ) {
//                        $terms_id[ $taxonomy ] = array();
//                    }
//
//                    $value = $attribute->getValue();
//                    if ( $term_id = $value->get_id() ) {
//                        $terms_id[ $taxonomy ][] = $term_id;
//                    }
//                }
//            }
//
//            foreach ( $terms_id as $tax => $terms ) {
//                $count += $this->update_object_term( $product_id, $terms, $tax );
//            }
//        }

		return $count;
	}
}