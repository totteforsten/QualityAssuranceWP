<?php
namespace Flavor_QA\Builders;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract base class for page builder parsers.
 */
abstract class Builder_Base {

    /**
     * Get the builder identifier.
     */
    abstract public function get_type();

    /**
     * Check if this builder is active for a given post.
     */
    abstract public function is_active( $post_id );

    /**
     * Get parsed builder data for a post.
     */
    abstract public function get_parsed_data( $post_id );

    /**
     * Flatten nested element tree into a flat array.
     */
    abstract public function flatten_elements( $data );

    /**
     * Update builder data for a post.
     */
    abstract public function update_data( $post_id, $data );

    /**
     * Find elements by widget/component type.
     */
    abstract public function find_elements_by_type( $data, $type );

    /**
     * Update a specific element's settings by element ID.
     */
    abstract public function update_element_settings( $post_id, $element_id, $new_settings );
}
