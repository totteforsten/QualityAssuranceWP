<?php
namespace Flavor_QA\Scanners;

use Flavor_QA\Builders\Elementor_Parser;
use Flavor_QA\Builders\Breakdance_Parser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsive Design Scanner.
 * Checks for responsive design issues in CSS, Elementor, and Breakdance content.
 */
class Responsive_Scanner extends Scanner_Base {

    /** Standard breakpoints to check. */
    private $breakpoints = [
        'mobile'       => 767,
        'tablet'       => 1024,
        'desktop'      => 1440,
    ];

    public function get_type() {
        return 'responsive';
    }

    public function scan_post( $scan_id, $post_id ) {
        $builder = $this->detect_builder( $post_id );

        switch ( $builder ) {
            case 'elementor':
                $this->scan_elementor_responsive( $scan_id, $post_id );
                break;
            case 'breakdance':
                $this->scan_breakdance_responsive( $scan_id, $post_id );
                break;
            default:
                $this->scan_html_responsive( $scan_id, $post_id );
                break;
        }

        // Also check the rendered HTML for general responsive issues.
        $this->scan_rendered_css( $scan_id, $post_id );
    }

    /**
     * Scan Elementor data for responsive issues.
     */
    private function scan_elementor_responsive( $scan_id, $post_id ) {
        $parser = new Elementor_Parser();
        $data = $parser->get_parsed_data( $post_id );

        if ( empty( $data ) ) {
            return;
        }

        $elements = $parser->flatten_elements( $data );

        foreach ( $elements as $element ) {
            $this->check_elementor_element( $scan_id, $post_id, $element, $parser );
        }
    }

    /**
     * Check a single Elementor element for responsive issues.
     */
    private function check_elementor_element( $scan_id, $post_id, $element, $parser ) {
        $settings = $element['settings'] ?? [];
        $el_type  = $element['elType'] ?? '';
        $widget   = $element['widgetType'] ?? $el_type;
        $el_id    = $element['id'] ?? '';

        // 1. Check for fixed pixel widths that aren't responsive.
        $this->check_fixed_widths( $scan_id, $post_id, $settings, $widget, $el_id );

        // 2. Check typography responsive settings.
        $this->check_typography_responsive( $scan_id, $post_id, $settings, $widget, $el_id );

        // 3. Check padding/margin consistency.
        $this->check_spacing_responsive( $scan_id, $post_id, $settings, $widget, $el_id );

        // 4. Check for hidden elements abuse.
        $this->check_hidden_elements( $scan_id, $post_id, $settings, $widget, $el_id );

        // 5. Check column widths.
        if ( 'column' === $el_type ) {
            $this->check_column_responsive( $scan_id, $post_id, $settings, $el_id );
        }
    }

    private function check_fixed_widths( $scan_id, $post_id, $settings, $widget, $el_id ) {
        $width_keys = [ '_element_width', 'width', 'custom_width' ];

        foreach ( $width_keys as $key ) {
            if ( ! isset( $settings[ $key ] ) ) {
                continue;
            }
            $value = $settings[ $key ];

            if ( is_array( $value ) && isset( $value['size'] ) && isset( $value['unit'] ) ) {
                if ( 'px' === $value['unit'] && (int) $value['size'] > 600 ) {
                    // Check if there's a responsive version.
                    $has_tablet = isset( $settings[ $key . '_tablet' ] );
                    $has_mobile = isset( $settings[ $key . '_mobile' ] );

                    if ( ! $has_tablet || ! $has_mobile ) {
                        $this->report_issue( $scan_id, $post_id, [
                            'severity'    => 'warning',
                            'category'    => 'fixed_width',
                            'title'       => sprintf( 'Large fixed width without responsive override (%s)', $widget ),
                            'description' => sprintf(
                                'Element "%s" has a fixed width of %dpx with no %s override. This may cause overflow on smaller screens.',
                                $widget,
                                (int) $value['size'],
                                ! $has_tablet ? 'tablet' : 'mobile'
                            ),
                            'location'       => sprintf( 'Element ID: %s, Setting: %s', $el_id, $key ),
                            'current_value'  => sprintf( '%d%s', (int) $value['size'], $value['unit'] ),
                            'suggested_value' => 'Add responsive values or use % / vw units',
                            'auto_fixable'   => 1,
                            'meta_data'      => [
                                'element_id' => $el_id,
                                'widget'     => $widget,
                                'setting'    => $key,
                                'builder'    => 'elementor',
                            ],
                        ] );
                    }
                }
            }
        }
    }

    private function check_typography_responsive( $scan_id, $post_id, $settings, $widget, $el_id ) {
        // Check typography font size.
        $typo_keys = [
            'typography_font_size',
            'title_typography_font_size',
            'description_typography_font_size',
        ];

        foreach ( $typo_keys as $key ) {
            if ( ! isset( $settings[ $key ] ) ) {
                continue;
            }

            $value = $settings[ $key ];
            if ( ! is_array( $value ) || ! isset( $value['size'] ) ) {
                continue;
            }

            $size = (int) $value['size'];
            $unit = $value['unit'] ?? 'px';

            // Flag large font sizes without responsive override.
            if ( 'px' === $unit && $size > 24 ) {
                $has_tablet = isset( $settings[ $key . '_tablet' ] );
                $has_mobile = isset( $settings[ $key . '_mobile' ] );

                if ( ! $has_mobile ) {
                    $this->report_issue( $scan_id, $post_id, [
                        'severity'    => 'warning',
                        'category'    => 'typography_responsive',
                        'title'       => sprintf( 'Large font size needs mobile override (%s)', $widget ),
                        'description' => sprintf(
                            '%s has font-size %d%s with no mobile override. Large text can cause layout issues on mobile.',
                            $widget,
                            $size,
                            $unit
                        ),
                        'location'       => sprintf( 'Element ID: %s', $el_id ),
                        'current_value'  => sprintf( '%d%s', $size, $unit ),
                        'suggested_value' => sprintf( '%d%s for mobile', max( 16, intdiv( $size, 2 ) + 4 ), $unit ),
                        'auto_fixable'   => 1,
                        'meta_data'      => [
                            'element_id' => $el_id,
                            'widget'     => $widget,
                            'setting'    => $key,
                            'builder'    => 'elementor',
                        ],
                    ] );
                }
            }

            // Flag tiny font sizes.
            if ( 'px' === $unit && $size < 12 && $size > 0 ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => 'warning',
                    'category'    => 'small_font',
                    'title'       => sprintf( 'Font size too small (%s)', $widget ),
                    'description' => sprintf(
                        '%s has font-size %d%s. Text below 12px is hard to read and hurts accessibility.',
                        $widget,
                        $size,
                        $unit
                    ),
                    'location'      => sprintf( 'Element ID: %s', $el_id ),
                    'current_value' => sprintf( '%d%s', $size, $unit ),
                    'suggested_value' => '14px or larger',
                ] );
            }
        }
    }

    private function check_spacing_responsive( $scan_id, $post_id, $settings, $widget, $el_id ) {
        $spacing_keys = [ '_margin', '_padding', 'margin', 'padding' ];

        foreach ( $spacing_keys as $key ) {
            if ( ! isset( $settings[ $key ] ) ) {
                continue;
            }

            $value = $settings[ $key ];
            if ( ! is_array( $value ) ) {
                continue;
            }

            $unit = $value['unit'] ?? 'px';

            // Check for excessively large padding/margin values.
            $directions = [ 'top', 'right', 'bottom', 'left' ];
            foreach ( $directions as $dir ) {
                if ( isset( $value[ $dir ] ) && 'px' === $unit ) {
                    $px_val = (int) $value[ $dir ];
                    if ( abs( $px_val ) > 100 ) {
                        $has_mobile = isset( $settings[ $key . '_mobile' ] );
                        if ( ! $has_mobile ) {
                            $type = strpos( $key, 'margin' ) !== false ? 'margin' : 'padding';
                            $this->report_issue( $scan_id, $post_id, [
                                'severity'    => 'warning',
                                'category'    => 'large_spacing',
                                'title'       => sprintf( 'Large %s-%s without mobile override (%s)', $type, $dir, $widget ),
                                'description' => sprintf(
                                    '%s has %s-%s of %dpx. This may cause issues on smaller screens without a mobile override.',
                                    $widget,
                                    $type,
                                    $dir,
                                    $px_val
                                ),
                                'location'       => sprintf( 'Element ID: %s', $el_id ),
                                'current_value'  => sprintf( '%dpx', $px_val ),
                                'auto_fixable'   => 1,
                                'meta_data'      => [
                                    'element_id' => $el_id,
                                    'widget'     => $widget,
                                    'setting'    => $key,
                                    'direction'  => $dir,
                                    'builder'    => 'elementor',
                                ],
                            ] );
                            break; // One issue per spacing setting is enough.
                        }
                    }
                }
            }

            // Check for negative margins that might break layout.
            foreach ( $directions as $dir ) {
                if ( isset( $value[ $dir ] ) && (int) $value[ $dir ] < -50 ) {
                    $this->report_issue( $scan_id, $post_id, [
                        'severity'    => 'warning',
                        'category'    => 'negative_margin',
                        'title'       => sprintf( 'Large negative margin (%s)', $widget ),
                        'description' => sprintf(
                            '%s has margin-%s of %dpx. Large negative margins can cause layout overlap and content clipping.',
                            $widget,
                            $dir,
                            (int) $value[ $dir ]
                        ),
                        'location'      => sprintf( 'Element ID: %s', $el_id ),
                        'current_value' => sprintf( '%dpx', (int) $value[ $dir ] ),
                    ] );
                }
            }
        }
    }

    private function check_hidden_elements( $scan_id, $post_id, $settings, $widget, $el_id ) {
        $hidden_desktop = ! empty( $settings['hide_desktop'] );
        $hidden_tablet  = ! empty( $settings['hide_tablet'] );
        $hidden_mobile  = ! empty( $settings['hide_mobile'] );

        // Element hidden on all devices.
        if ( $hidden_desktop && $hidden_tablet && $hidden_mobile ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'info',
                'category'    => 'hidden_all_devices',
                'title'       => sprintf( 'Element hidden on all devices (%s)', $widget ),
                'description' => 'This element is hidden on desktop, tablet, and mobile. Consider removing it entirely.',
                'location'    => sprintf( 'Element ID: %s', $el_id ),
                'meta_data'   => [ 'element_id' => $el_id, 'widget' => $widget, 'builder' => 'elementor' ],
            ] );
        }
    }

    private function check_column_responsive( $scan_id, $post_id, $settings, $el_id ) {
        $width = $settings['_column_size'] ?? null;
        $custom = $settings['_inline_size'] ?? null;

        if ( $custom && (float) $custom < 20 ) {
            $has_tablet = isset( $settings['_inline_size_tablet'] );
            $has_mobile = isset( $settings['_inline_size_mobile'] );

            if ( ! $has_mobile ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => 'warning',
                    'category'    => 'narrow_column',
                    'title'       => 'Narrow column without mobile override',
                    'description' => sprintf(
                        'Column is %s%% wide with no mobile override. Narrow columns need to stack or expand on mobile.',
                        $custom
                    ),
                    'location'       => sprintf( 'Column ID: %s', $el_id ),
                    'current_value'  => $custom . '%',
                    'suggested_value' => '100% on mobile',
                    'auto_fixable'   => 1,
                    'meta_data'      => [ 'element_id' => $el_id, 'builder' => 'elementor' ],
                ] );
            }
        }
    }

    /**
     * Scan Breakdance data for responsive issues.
     */
    private function scan_breakdance_responsive( $scan_id, $post_id ) {
        $parser = new Breakdance_Parser();
        $data = $parser->get_parsed_data( $post_id );

        if ( empty( $data ) ) {
            return;
        }

        $elements = $parser->flatten_elements( $data );

        foreach ( $elements as $element ) {
            $this->check_breakdance_element( $scan_id, $post_id, $element, $parser );
        }
    }

    private function check_breakdance_element( $scan_id, $post_id, $element, $parser ) {
        $properties = $element['properties'] ?? [];
        $tag        = $element['shortName'] ?? $element['slug'] ?? 'unknown';
        $el_id      = $element['id'] ?? '';

        // Breakdance stores styles per breakpoint in properties.design.
        $design = $properties['design'] ?? [];

        if ( empty( $design ) ) {
            return;
        }

        // Check desktop-only styles that need responsive overrides.
        $this->check_breakdance_spacing( $scan_id, $post_id, $design, $tag, $el_id );
        $this->check_breakdance_typography( $scan_id, $post_id, $design, $tag, $el_id );
        $this->check_breakdance_sizing( $scan_id, $post_id, $design, $tag, $el_id );
    }

    private function check_breakdance_spacing( $scan_id, $post_id, $design, $tag, $el_id ) {
        $spacing = $design['spacing'] ?? [];
        if ( empty( $spacing ) ) {
            return;
        }

        // Check margin/padding at desktop level.
        foreach ( [ 'margin', 'padding' ] as $type ) {
            if ( empty( $spacing[ $type ] ) ) {
                continue;
            }

            $desktop = $spacing[ $type ];

            foreach ( [ 'top', 'right', 'bottom', 'left' ] as $dir ) {
                $val = $desktop[ $dir ] ?? null;
                if ( null === $val ) {
                    continue;
                }

                // Parse value and unit.
                if ( preg_match( '/^(-?\d+(?:\.\d+)?)(px|em|rem|%|vw|vh)?$/', (string) $val, $m ) ) {
                    $num  = (float) $m[1];
                    $unit = $m[2] ?? 'px';

                    if ( 'px' === $unit && abs( $num ) > 80 ) {
                        $this->report_issue( $scan_id, $post_id, [
                            'severity'    => 'warning',
                            'category'    => 'large_spacing',
                            'title'       => sprintf( 'Large %s-%s on %s', $type, $dir, $tag ),
                            'description' => sprintf(
                                '%s has %s-%s of %s. Review for smaller screens.',
                                $tag, $type, $dir, $val
                            ),
                            'location'      => sprintf( 'Element ID: %s', $el_id ),
                            'current_value' => $val,
                            'auto_fixable'  => 1,
                            'meta_data'     => [
                                'element_id' => $el_id,
                                'tag'        => $tag,
                                'builder'    => 'breakdance',
                            ],
                        ] );
                    }
                }
            }
        }
    }

    private function check_breakdance_typography( $scan_id, $post_id, $design, $tag, $el_id ) {
        $typography = $design['typography'] ?? [];
        if ( empty( $typography ) ) {
            return;
        }

        $font_size = $typography['fontSize'] ?? null;
        if ( ! $font_size ) {
            return;
        }

        if ( preg_match( '/^(\d+(?:\.\d+)?)(px|em|rem|%|vw)?$/', (string) $font_size, $m ) ) {
            $num  = (float) $m[1];
            $unit = $m[2] ?? 'px';

            if ( 'px' === $unit && $num > 28 ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => 'warning',
                    'category'    => 'typography_responsive',
                    'title'       => sprintf( 'Large font on %s may need responsive adjustment', $tag ),
                    'description' => sprintf(
                        '%s has font-size %s. Verify it looks good on tablet and mobile.',
                        $tag, $font_size
                    ),
                    'location'       => sprintf( 'Element ID: %s', $el_id ),
                    'current_value'  => $font_size,
                    'suggested_value' => 'Use clamp() or set mobile breakpoint override',
                    'meta_data'      => [
                        'element_id' => $el_id,
                        'tag'        => $tag,
                        'builder'    => 'breakdance',
                    ],
                ] );
            }

            if ( 'px' === $unit && $num < 12 && $num > 0 ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => 'warning',
                    'category'    => 'small_font',
                    'title'       => sprintf( 'Font too small on %s', $tag ),
                    'description' => sprintf(
                        '%s has font-size %s. Text under 12px is hard to read.',
                        $tag, $font_size
                    ),
                    'location'      => sprintf( 'Element ID: %s', $el_id ),
                    'current_value' => $font_size,
                ] );
            }
        }
    }

    private function check_breakdance_sizing( $scan_id, $post_id, $design, $tag, $el_id ) {
        $sizing = $design['sizing'] ?? $design['layout'] ?? [];
        if ( empty( $sizing ) ) {
            return;
        }

        $width = $sizing['width'] ?? $sizing['customWidth'] ?? null;
        if ( ! $width ) {
            return;
        }

        if ( preg_match( '/^(\d+(?:\.\d+)?)(px)?$/', (string) $width, $m ) ) {
            $num = (float) $m[1];
            if ( $num > 600 ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => 'warning',
                    'category'    => 'fixed_width',
                    'title'       => sprintf( 'Large fixed width on %s', $tag ),
                    'description' => sprintf(
                        '%s has a fixed width of %spx. This may overflow on smaller screens.',
                        $tag, $width
                    ),
                    'location'       => sprintf( 'Element ID: %s', $el_id ),
                    'current_value'  => $width . 'px',
                    'suggested_value' => 'Use percentages or max-width',
                    'auto_fixable'   => 1,
                    'meta_data'      => [
                        'element_id' => $el_id,
                        'tag'        => $tag,
                        'builder'    => 'breakdance',
                    ],
                ] );
            }
        }
    }

    /**
     * Check raw post content for general responsive CSS issues.
     */
    private function scan_rendered_css( $scan_id, $post_id ) {
        $html = $this->get_rendered_html( $post_id );
        if ( empty( $html ) ) {
            return;
        }

        // Check inline styles and CSS patterns in post content.
        $this->check_inline_styles( $scan_id, $post_id, $html );
        // Note: viewport meta check is skipped — it's set by the theme, not post content.
        $this->check_horizontal_scroll_risks( $scan_id, $post_id, $html );
    }

    private function check_inline_styles( $scan_id, $post_id, $html ) {
        // Find elements with inline style containing fixed widths.
        preg_match_all( '/style="([^"]*width\s*:\s*(\d+)px[^"]*)"/i', $html, $matches, PREG_SET_ORDER );

        $large_fixed = 0;
        foreach ( $matches as $match ) {
            $width = (int) $match[2];
            if ( $width > 500 ) {
                $large_fixed++;
            }
        }

        if ( $large_fixed > 0 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'warning',
                'category'    => 'inline_fixed_width',
                'title'       => sprintf( '%d element(s) with large inline fixed widths', $large_fixed ),
                'description' => 'Elements with inline style fixed widths over 500px detected. These may cause horizontal scrolling on mobile.',
            ] );
        }
    }

    private function check_viewport_meta( $scan_id, $post_id, $html ) {
        if ( strpos( $html, 'viewport' ) === false ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'       => 'critical',
                'category'       => 'missing_viewport',
                'title'          => 'Missing viewport meta tag',
                'description'    => 'No viewport meta tag found. Mobile devices will render the page at desktop width.',
                'suggested_value' => '<meta name="viewport" content="width=device-width, initial-scale=1">',
            ] );
        }
    }

    private function check_horizontal_scroll_risks( $scan_id, $post_id, $html ) {
        // Check for 100vw usage without accounting for scrollbar.
        if ( preg_match_all( '/width\s*:\s*100vw/i', $html, $m ) ) {
            $count = count( $m[0] );
            if ( $count > 0 ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => 'warning',
                    'category'    => 'vw_scrollbar',
                    'title'       => '100vw usage may cause horizontal scroll',
                    'description' => sprintf(
                        'Found %d instance(s) of width: 100vw. On desktop, 100vw includes the scrollbar width, causing horizontal overflow.',
                        $count
                    ),
                    'suggested_value' => 'Use width: 100% instead, or calc(100vw - var(--scrollbar-width))',
                ] );
            }
        }
    }
}
