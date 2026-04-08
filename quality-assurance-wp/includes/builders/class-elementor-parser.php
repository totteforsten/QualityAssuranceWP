<?php
namespace Flavor_QA\Builders;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Elementor page builder parser.
 * Reads and modifies Elementor JSON data structures.
 */
class Elementor_Parser extends Builder_Base {

    public function get_type() {
        return 'elementor';
    }

    public function is_active( $post_id ) {
        $data = get_post_meta( $post_id, '_elementor_data', true );
        return ! empty( $data );
    }

    /**
     * Get and parse Elementor data for a post.
     * Elementor stores data as JSON in _elementor_data postmeta.
     */
    public function get_parsed_data( $post_id ) {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( empty( $raw ) ) {
            return [];
        }

        if ( is_string( $raw ) ) {
            $data = json_decode( $raw, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return [];
            }
            return $data;
        }

        return is_array( $raw ) ? $raw : [];
    }

    /**
     * Flatten nested Elementor element tree.
     * Elementor structure: sections > columns > widgets (nested elements array).
     */
    public function flatten_elements( $data, &$result = [] ) {
        if ( ! is_array( $data ) ) {
            return $result;
        }

        foreach ( $data as $element ) {
            $result[] = $element;
            if ( ! empty( $element['elements'] ) ) {
                $this->flatten_elements( $element['elements'], $result );
            }
        }

        return $result;
    }

    /**
     * Update the full Elementor data for a post.
     */
    public function update_data( $post_id, $data ) {
        $json = wp_json_encode( $data );
        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

        // Clear Elementor cache.
        delete_post_meta( $post_id, '_elementor_css' );
        if ( class_exists( '\Elementor\Plugin' ) ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
    }

    /**
     * Find elements by widget type.
     */
    public function find_elements_by_type( $data, $type ) {
        $found = [];
        $all = $this->flatten_elements( $data );

        foreach ( $all as $element ) {
            $el_type = $element['widgetType'] ?? $element['elType'] ?? '';
            if ( $el_type === $type ) {
                $found[] = $element;
            }
        }

        return $found;
    }

    /**
     * Update specific element settings by element ID.
     */
    public function update_element_settings( $post_id, $element_id, $new_settings ) {
        $data = $this->get_parsed_data( $post_id );
        if ( empty( $data ) ) {
            return false;
        }

        $updated = $this->update_element_recursive( $data, $element_id, $new_settings );
        if ( $updated ) {
            $this->update_data( $post_id, $data );
            return true;
        }

        return false;
    }

    /**
     * Recursively find and update an element.
     */
    private function update_element_recursive( &$elements, $element_id, $new_settings ) {
        foreach ( $elements as &$element ) {
            if ( isset( $element['id'] ) && $element['id'] === $element_id ) {
                $element['settings'] = array_merge(
                    $element['settings'] ?? [],
                    $new_settings
                );
                return true;
            }

            if ( ! empty( $element['elements'] ) ) {
                if ( $this->update_element_recursive( $element['elements'], $element_id, $new_settings ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract all text content from Elementor data.
     */
    public function extract_text_content( $data ) {
        $texts = [];
        $all = $this->flatten_elements( $data );

        foreach ( $all as $element ) {
            $settings = $element['settings'] ?? [];

            // Common text fields in Elementor widgets.
            $text_keys = [
                'title', 'editor', 'description', 'text',
                'heading_title', 'description_text', 'button_text',
                'testimonial_content', 'testimonial_name',
                'alert_title', 'alert_description',
                'tab_title', 'tab_content',
            ];

            foreach ( $text_keys as $key ) {
                if ( ! empty( $settings[ $key ] ) ) {
                    $texts[] = [
                        'element_id' => $element['id'] ?? '',
                        'widget'     => $element['widgetType'] ?? $element['elType'] ?? '',
                        'field'      => $key,
                        'content'    => $settings[ $key ],
                    ];
                }
            }
        }

        return $texts;
    }

    /**
     * Extract all image elements.
     */
    public function extract_images( $data ) {
        $images = [];
        $all = $this->flatten_elements( $data );

        foreach ( $all as $element ) {
            $settings = $element['settings'] ?? [];

            // Image widget.
            if ( ! empty( $settings['image']['url'] ) ) {
                $images[] = [
                    'element_id' => $element['id'] ?? '',
                    'widget'     => $element['widgetType'] ?? '',
                    'url'        => $settings['image']['url'],
                    'alt'        => $settings['image']['alt'] ?? '',
                    'id'         => $settings['image']['id'] ?? 0,
                ];
            }

            // Background image.
            if ( ! empty( $settings['background_image']['url'] ) ) {
                $images[] = [
                    'element_id' => $element['id'] ?? '',
                    'widget'     => 'background',
                    'url'        => $settings['background_image']['url'],
                    'alt'        => '',
                    'id'         => $settings['background_image']['id'] ?? 0,
                ];
            }
        }

        return $images;
    }

    /**
     * Extract all links from Elementor data.
     */
    public function extract_links( $data ) {
        $links = [];
        $all = $this->flatten_elements( $data );

        foreach ( $all as $element ) {
            $settings = $element['settings'] ?? [];

            // Button links, link fields.
            $link_keys = [ 'link', 'button_link', 'url', 'website_link' ];
            foreach ( $link_keys as $key ) {
                if ( ! empty( $settings[ $key ]['url'] ) ) {
                    $links[] = [
                        'element_id' => $element['id'] ?? '',
                        'widget'     => $element['widgetType'] ?? '',
                        'url'        => $settings[ $key ]['url'],
                        'field'      => $key,
                    ];
                }
            }
        }

        return $links;
    }
}
