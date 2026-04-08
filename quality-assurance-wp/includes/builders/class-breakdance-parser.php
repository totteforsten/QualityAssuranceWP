<?php
namespace Flavor_QA\Builders;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Breakdance page builder parser.
 * Reads and modifies Breakdance JSON data structures.
 */
class Breakdance_Parser extends Builder_Base {

    public function get_type() {
        return 'breakdance';
    }

    public function is_active( $post_id ) {
        $data = get_post_meta( $post_id, '_breakdance_data', true );
        return ! empty( $data );
    }

    /**
     * Get and parse Breakdance data for a post.
     * Breakdance stores data in _breakdance_data postmeta.
     * Structure: { tree_data: { root: { children: [...] } } }
     */
    public function get_parsed_data( $post_id ) {
        $raw = get_post_meta( $post_id, '_breakdance_data', true );
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
     * Get the element tree from Breakdance data.
     */
    public function get_tree( $data ) {
        // Breakdance can store data in different structures.
        if ( isset( $data['tree_data']['root'] ) ) {
            return $data['tree_data']['root'];
        }
        if ( isset( $data['root'] ) ) {
            return $data['root'];
        }
        return $data;
    }

    /**
     * Flatten nested Breakdance element tree.
     * Breakdance structure: root > children[] > children[] (recursive).
     */
    public function flatten_elements( $data, &$result = [] ) {
        $root = $this->get_tree( $data );

        if ( isset( $root['children'] ) ) {
            $this->flatten_recursive( $root['children'], $result );
        } elseif ( is_array( $data ) && ! isset( $data['tree_data'] ) ) {
            $this->flatten_recursive( $data, $result );
        }

        return $result;
    }

    private function flatten_recursive( $children, &$result ) {
        if ( ! is_array( $children ) ) {
            return;
        }

        foreach ( $children as $element ) {
            $result[] = $element;
            if ( ! empty( $element['children'] ) ) {
                $this->flatten_recursive( $element['children'], $result );
            }
        }
    }

    /**
     * Update the full Breakdance data for a post.
     */
    public function update_data( $post_id, $data ) {
        if ( is_array( $data ) ) {
            $data = wp_json_encode( $data );
        }
        update_post_meta( $post_id, '_breakdance_data', wp_slash( $data ) );

        // Clear Breakdance cache.
        delete_post_meta( $post_id, '_breakdance_css' );
        delete_post_meta( $post_id, '_breakdance_css_compiled' );
    }

    /**
     * Find elements by component slug/type.
     */
    public function find_elements_by_type( $data, $type ) {
        $found = [];
        $all = $this->flatten_elements( $data );

        foreach ( $all as $element ) {
            $slug = $element['slug'] ?? $element['shortName'] ?? '';
            if ( $slug === $type || ( isset( $element['shortName'] ) && $element['shortName'] === $type ) ) {
                $found[] = $element;
            }
        }

        return $found;
    }

    /**
     * Update specific element properties by element ID.
     */
    public function update_element_settings( $post_id, $element_id, $new_settings ) {
        $data = $this->get_parsed_data( $post_id );
        if ( empty( $data ) ) {
            return false;
        }

        $root = &$this->get_tree_ref( $data );
        if ( ! $root ) {
            return false;
        }

        $updated = $this->update_element_recursive( $root, $element_id, $new_settings );
        if ( $updated ) {
            $this->update_data( $post_id, $data );
            return true;
        }

        return false;
    }

    private function &get_tree_ref( &$data ) {
        if ( isset( $data['tree_data']['root'] ) ) {
            return $data['tree_data']['root'];
        }
        if ( isset( $data['root'] ) ) {
            return $data['root'];
        }
        return $data;
    }

    private function update_element_recursive( &$node, $element_id, $new_settings ) {
        if ( isset( $node['id'] ) && $node['id'] === $element_id ) {
            $node['properties'] = array_replace_recursive(
                $node['properties'] ?? [],
                $new_settings
            );
            return true;
        }

        if ( ! empty( $node['children'] ) ) {
            foreach ( $node['children'] as &$child ) {
                if ( $this->update_element_recursive( $child, $element_id, $new_settings ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract all text content from Breakdance data.
     */
    public function extract_text_content( $data ) {
        $texts = [];
        $all = $this->flatten_elements( $data );

        foreach ( $all as $element ) {
            $props = $element['properties'] ?? [];
            $content = $props['content'] ?? [];

            // Common content fields in Breakdance components.
            $text_keys = [
                'text', 'heading', 'content', 'title', 'description',
                'button_text', 'label', 'richText',
            ];

            foreach ( $text_keys as $key ) {
                if ( ! empty( $content[ $key ] ) ) {
                    $texts[] = [
                        'element_id' => $element['id'] ?? '',
                        'component'  => $element['shortName'] ?? $element['slug'] ?? '',
                        'field'      => $key,
                        'content'    => $content[ $key ],
                    ];
                }
            }

            // Also check nested content structures.
            if ( ! empty( $content['content'] ) && is_array( $content['content'] ) ) {
                foreach ( $content['content'] as $sub_key => $sub_val ) {
                    if ( is_string( $sub_val ) && strlen( $sub_val ) > 1 ) {
                        $texts[] = [
                            'element_id' => $element['id'] ?? '',
                            'component'  => $element['shortName'] ?? '',
                            'field'      => "content.{$sub_key}",
                            'content'    => $sub_val,
                        ];
                    }
                }
            }
        }

        return $texts;
    }

    /**
     * Extract all links from Breakdance data.
     */
    public function extract_links( $data ) {
        $links = [];
        $all = $this->flatten_elements( $data );

        foreach ( $all as $element ) {
            $props = $element['properties'] ?? [];
            $this->find_links_recursive( $props, $element, $links );
        }

        return $links;
    }

    /**
     * Recursively search for URL values in element properties.
     */
    private function find_links_recursive( $data, $element, &$links, $path = '' ) {
        if ( ! is_array( $data ) ) {
            return;
        }

        foreach ( $data as $key => $value ) {
            $current_path = $path ? "{$path}.{$key}" : $key;

            if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
                $links[] = [
                    'element_id' => $element['id'] ?? '',
                    'component'  => $element['shortName'] ?? '',
                    'url'        => $value,
                    'field'      => $current_path,
                ];
            } elseif ( is_array( $value ) ) {
                $this->find_links_recursive( $value, $element, $links, $current_path );
            }
        }
    }

    /**
     * Extract all images from Breakdance data.
     */
    public function extract_images( $data ) {
        $images = [];
        $all = $this->flatten_elements( $data );

        foreach ( $all as $element ) {
            $props = $element['properties'] ?? [];
            $this->find_images_recursive( $props, $element, $images );
        }

        return $images;
    }

    private function find_images_recursive( $data, $element, &$images, $path = '' ) {
        if ( ! is_array( $data ) ) {
            return;
        }

        foreach ( $data as $key => $value ) {
            $current_path = $path ? "{$path}.{$key}" : $key;

            if ( is_array( $value ) ) {
                // Check for image structure { url: ..., alt: ... } or { id: ..., url: ... }.
                if ( isset( $value['url'] ) && preg_match( '/\.(jpg|jpeg|png|gif|webp|svg|avif)(\?|$)/i', $value['url'] ) ) {
                    $images[] = [
                        'element_id' => $element['id'] ?? '',
                        'component'  => $element['shortName'] ?? '',
                        'url'        => $value['url'],
                        'alt'        => $value['alt'] ?? '',
                        'id'         => $value['id'] ?? 0,
                        'field'      => $current_path,
                    ];
                } else {
                    $this->find_images_recursive( $value, $element, $images, $current_path );
                }
            }
        }
    }

    /**
     * Get responsive breakpoints used by Breakdance.
     */
    public function get_breakpoints() {
        // Breakdance default breakpoints.
        return [
            'base'    => null,      // Desktop (no max-width).
            'tablet'  => 1119,
            'phone'   => 767,
        ];
    }
}
