<?php
/**
 * Plugin Name:         PlanetPlanet - RSS feed aggregator
 * Description:         Setting up an RSS feed aggregator site on WordPress is easy with the PlanetPlanet plugin - just install the plugin, and add the RSS feeds
 * Plugin URI:          https://plugins.seindal.dk/plugins/planetplanet/
 * Author:              René Seindal
 * Author URI:          https://plugins.seindal.dk/
 * Donate link:         https://mypos.com/@historywalks
 * License:             GPL v2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         planetplanet
 * Domain Path:         /languages
 * Requires PHP:        7.4
 * Requires at least:   5.0
 * Version:             1.1
 **/

namespace ReneSeindal\PlanetPlanet;

require_once( ABSPATH . 'wp-admin/includes/taxonomy.php' );
require_once( ABSPATH . 'wp-admin/includes/bookmark.php' );
// for media upload metadata
require_once( ABSPATH . 'wp-admin/includes/image.php' );
require_once( ABSPATH . 'wp-admin/includes/media.php' );

require_once( __DIR__ . '/logger.php' );

class PlanetPlanetPlugin {
    protected $is_cli = FALSE;
    protected $logger = NULL;

	function __construct() {
        $this->is_cli = ( PHP_SAPI == 'cli' );

        $this->set_loglevel();

        // Activate the Links section in WP
        add_filter( 'pre_option_link_manager_enabled', '__return_true' );

        // Automatick setup of ajax handlers, actions, filters and shortcodes
        // based on available class methods
        $methods = get_class_methods( $this );
        foreach ( $methods as $m ) {
            if ( preg_match( '/^ajax_(.*)_handler$/', $m, $match ) ) {
                add_action( "wp_ajax_{$match[1]}", [ $this, "ajax_{$match[1]}_handler" ] );
            }
            elseif ( preg_match( '/^do_(.*)_shortcode$/', $m, $match ) ) {
                add_shortcode( $match[1], [ $this, "do_{$match[1]}_shortcode" ] );
            }
            elseif ( preg_match( '/^do_(.*)_action$/', $m, $match ) ) {
                add_action( $match[1], [ $this, "do_{$match[1]}_action" ] );
            }
            elseif ( preg_match( '/^do_(.*)_filter$/', $m, $match ) ) {
                add_filter( $match[1], [ $this, "do_{$match[1]}_filter" ] );
            }
        }
	}

    public function set_loglevel( $level = NULL ) {
        $loglevel = $level ?? $this->get_option( 'loglevel' );

        $debug = ( $loglevel == 'debug' );
        $silent = ( $loglevel == 'errors' );

        $this->logger = new PolyLogger( $debug, $silent );

        $upload = wp_get_upload_dir();
        $logfile = sprintf( '%s/%s-%s.log', $upload['basedir'], __CLASS__, date( 'Y-m-d' ) );
        if ( !$this->is_cli or is_writable( $logfile ) )
            $this->logger->add( new FileLogger( $logfile, true ) );

        if ( $this->is_cli )
            $this->logger->add( new CLILogger( $debug, $silent ) );
        elseif ( $this->has_option( 'email' ) )
            $this->logger->add( new MailLogger( $this->get_option( 'email' ), get_bloginfo( 'name' ), $debug, $silent ) );
    }


    /************************************************************************
     *
     *	Helpers
     *
     ************************************************************************/

    function safe_datetime( $time ) {
        try {
            return new \DateTime( $time );
        } catch ( \Exception $e ) {
            return NULL;
        }
    }

    // Safely read a feed, web page or image
    // - return response or WP_Error

    function get_web_page( $url ) {
        $args = [
            'sslverify' => false,
        ];

        if ( $this->has_option( 'timeout' ) )
            $args['timeout'] = $this->get_option( 'timeout' );

        if ( $this->has_option( 'user_agent' ) )
            $args['user-agent'] = $this->get_option( 'user_agent' );

        $response = ( new \WP_Http() )->get( $url, $args );

        if ( is_wp_error( $response ) )
            return $response;

        if ( $response['response']['code'] != \WP_Http::OK )
            return new \WP_Error( 'http_error',
                                 sprintf( __('HTTP response %d %s', 'planetplanet'),
                                          $response['response']['code'],
                                          $response['response']['message']
                                 )
            );

        return $response;
    }

    /************************************************************************
     *
     *	Options
     *
     ************************************************************************/

    function get_option( $name, $default = NULL ) {
        $options = get_option( 'planetplanet_options' );
        if ( empty( $options ) ) return $default;
        if ( array_key_exists( $name, $options ) ) return $options[$name];
        if ( array_key_exists( "planetplanet_$name", $options ) ) return $options["planetplanet_$name"];
        return $default;
    }
    function has_option( $name ) {
        return !empty( $this->get_option( $name ) );
    }


    /************************************************************************
     *
     *	Dashboard
     *
     ************************************************************************/

    function do_dashboard_glance_items_filter( $items ) {
        $output = array_map(
            function( $term ) {
                $name = $term->name;
                $num = number_format_i18n( $term->count );

                $url = add_query_arg( [ 'cat_id' => $term->term_id ], admin_url( 'link-manager.php' ) );

                return sprintf( '<a class="%s" href="%s">%s %s</a>', "at-a-glance-link-count", $url, $num, $name );
            }, get_terms( [ 'taxonomy' => 'link_category', ] )
        );

        return array_merge( $output, $items);
    }

    // Add the links iconc to the At a Glance dashboard widget
    function do_admin_head_action() {
        printf('<style type="text/css">#dashboard_right_now li a.at-a-glance-link-count::before { content: "\%x"}</style>', 61699);
    }



    /************************************************************************
     *
     *	Settings
     *
     ************************************************************************/

    /* top level menu */
    function do_admin_menu_action() {
        // add top level menu page
        add_submenu_page(
            'options-general.php',
            'Planet Planet',
            'Planet Planet',
            'manage_options',
            'planetplanet',
            [ $this, 'planetplanet_options_page_html' ]
        );

        // Settings link in plugin list
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
            $url = add_query_arg( [ 'page' => 'planetplanet' ], admin_url( 'options-general.php' ) );
            $text = __( 'Settings', 'planetplanet' );
            $links[] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $text ) );
            return $links;
        } );
    }

    /* top level menu: callback functions */
    function planetplanet_options_page_html() {
        // check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // check if the user have submitted the settings
        // wordpress will add the "settings-updated" $_GET parameter to the url
        if ( isset( $_GET['settings-updated'] ) ) {
            add_settings_error( 'planetplanet', 'settings_saved', __( 'Settings Saved', 'planetplanet' ), 'updated' );
        }

        // show error/update messages
        // settings_errors('planetplanet');

        print( '<div class="wrap">' );
        printf( '<h1>%s</h1>', esc_html( get_admin_page_title()  ) );
        print( '<form action="options.php" method="post">' );

        settings_fields( 'planetplanet' );
        do_settings_sections( 'planetplanet' );
        submit_button( 'Save Settings' );

        print( '</form>' );
        print( '</div>' );
    }



    /* custom option and settings */
    function do_admin_init_action() {
        // register a new setting for "planetplanet" page
        register_setting(
            'planetplanet', 'planetplanet_options',
            [ 'sanitize_callback' => [ $this, 'planetplanet_options_validate' ]],
            [
                'planetplanet_schedule' => 'hourly',
                'planetplanet_retain_limit' => '1 year ago',
                'planetplanet_email' => '',
                'planetplanet_loglevel' => 'errors',
            ],
        );

        // register a new section in the "planetplanet" page
        add_settings_section(
            'planetplanet_schedule_section',
            __( 'Update schedule and retention', 'planetplanet' ),
            [ $this, 'planetplanet_settings_html' ],
            'planetplanet'
        );

        $schedule_menu = [ 'none' => __('None', 'planetplanet') ];
        foreach ( wp_get_schedules() as $k => $v )
            $schedule_menu[$k] = $v['display'];

        add_settings_field(
            'schedule',
            __( 'How often to check feeds', 'planetplanet' ),
            [$this, 'planetplanet_options_simple_menu_html'],
            'planetplanet',
            'planetplanet_schedule_section',
            [
                'field' => 'planetplanet_schedule',
                'label_for' => 'planetplanet_schedule',
                'settings' => 'planetplanet_options',

                'default' => 'hourly',
                'menu' => $schedule_menu,
            ]
        );

        add_settings_field(
            'retain_limit',
            __( 'Discard posts older than this', 'planetplanet' ),
            [$this, 'planetplanet_options_simple_input_html'],
            'planetplanet',
            'planetplanet_schedule_section',
            [
                'field' => 'planetplanet_retain_limit',
                'label_for' => 'planetplanet_retain_limit',
                'settings' => 'planetplanet_options',
                'help' => __('e.g., "1 year ago", "-6 months", "72 days ago"', 'planetplanet'),
            ]
        );

        add_settings_field(
            'max_errors',
            __( 'Number of errors before feed is suspended', 'planetplanet' ),
            [$this, 'planetplanet_options_simple_menu_html'],
            'planetplanet',
            'planetplanet_schedule_section',
            [
                'field' => 'planetplanet_max_errors',
                'label_for' => 'planetplanet_loglevel',
                'settings' => 'planetplanet_options',

                'default' => '5',
                'menu' => [
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                    '5' => '5',
                    '6' => '6',
                    '7' => '7',
                    '8' => '8',
                    '9' => '9',
                    '10' => '10',
                ],

                'help' => __('Feeds can always be reactivated in the Links manager', 'planetplanet'),
            ]
        );

        add_settings_section(
            'planetplanet_notification_section',
            __( 'Notification and detail', 'planetplanet' ),
            [$this, 'planetplanet_settings_html'],
            'planetplanet'
        );

        add_settings_field(
            'email',
            __( 'Email for updates', 'planetplanet' ),
            [$this, 'planetplanet_options_simple_input_html'],
            'planetplanet',
            'planetplanet_notification_section',
            [
                'field' => 'planetplanet_email',
                'label_for' => 'planetplanet_email',
                'settings' => 'planetplanet_options',

                'validator' => 'is_email',
                'error' => __('Invalid email address will be ignored', 'planetplanet'),
            ]
        );

        add_settings_field(
            'loglevel',
            __( 'Level of detail in mails', 'planetplanet' ),
            [$this, 'planetplanet_options_simple_menu_html'],
            'planetplanet',
            'planetplanet_notification_section',
            [
                'field' => 'planetplanet_loglevel',
                'label_for' => 'planetplanet_loglevel',
                'settings' => 'planetplanet_options',

                'default' => 'errors',
                'menu' => [
                    'errors' => __('Only error messages', 'planetplanet'),
                    'messages' => __('Errors and updates', 'planetplanet'),
                    'debug' => __('Everything', 'planetplanet'),
                ],
            ]
        );





        add_settings_section(
            'planetplanet_network_section',
            __( 'Network', 'planetplanet' ),
            [ $this, 'planetplanet_settings_html' ],
            'planetplanet'
        );

        add_settings_field(
            'timeout',
            __( 'Timeout for feed requests', 'planetplanet' ),
            [ $this, 'planetplanet_options_simple_menu_html' ],
            'planetplanet',
            'planetplanet_network_section',
            [
                'field' => 'planetplanet_timeout',
                'label_for' => 'planetplanet_timeout',
                'settings' => 'planetplanet_options',

                'default' => '5',
                'menu' => [
                    '5' => '5 seconds',
                    '10' => '10 seconds',
                    '15' => '15 seconds',
                    '30' => '30 seconds',
                ],
            ]
        );

        add_settings_field(
            'user_agent',
            __( 'User-Agent', 'planetplanet' ),
            [ $this, 'planetplanet_options_simple_input_html' ],
            'planetplanet',
            'planetplanet_network_section',
            [
                'field' => 'planetplanet_user_agent',
                'label_for' => 'planetplanet_user_agent',
                'settings' => 'planetplanet_options',
            ]
        );
    }

    /**
     * custom option and settings:
     * callback functions
     */

    function planetplanet_options_validate( $input = NULl ) {
        if ( !empty( $input['planetplanet_email'] ) and !is_email( $input['planetplanet_email'] ) )
            add_settings_error( 'planetplanet', 'invalid_email', __( 'Invalid email address for updates', 'planetplanet' ) );

        if ( isset( $input['planetplanet_retain_limit'] ) ) {
            if ( !$this->safe_datetime( $input['planetplanet_retain_limit'] ) ) {
                add_settings_error( 'planetplanet', 'invalid_date', __( 'Invalid date value for discard limit', 'planetplanet' ) );
                $input['planetplanet_retain_limit'] = NULL;
            }
        }

        return $input;
    }

    function planetplanet_settings_html( $args ) {
    }

    function planetplanet_options_simple_menu_html( $args ) {
        $value = $this->get_option( $args['field'], $args['default'] );

        printf( '<select id="%s" name="%s[%s]">',
                esc_attr( $args['field'] ),
                esc_attr( $args['settings'] ),
                esc_attr( $args['field'] ),
        );
        foreach ( $args['menu'] as $k => $v )
            printf( '<option value="%s" %s>%s</option>',
                    esc_attr( $k ),
                    ( $value == $k ? 'selected' : '' ),
                    esc_html( $v )
            );
        printf( '</select>' );

        if ( isset( $args['help'] ) ) {
            printf( '<p>%s</p>', $args['help'] );
        }
    }

    function planetplanet_options_simple_input_html( $args ) {
        $options = get_option( 'planetplanet_options' );
        $value = isset( $options[$args['field']] ) ? $options[$args['field']] : '';
        printf( '<input type="text" id="%s" name="%s[%s]" value="%s">',
               esc_attr( $args['field'] ),
               esc_attr( $args['settings'] ),
               esc_attr( $args['field'] ),
               esc_attr( $value )
         );

        if ( isset( $args['validator'] ) and is_callable( $args['validator'] ) and !empty( $value ) and !empty( $args['error'] ) ) {
            $valid = call_user_func( $args['validator'], $value );
            if ( !$valid )
                printf( '<div style="color:red">%s</div>', $args['error'] );
        }
        if ( isset( $args['help'] ) ) {
            printf( '<div>%s</div>', $args['help'] );
        }
    }



    /************************************************************************
     *
     *	Redirects for imported posts
     *
     ************************************************************************/

    function redirect( $url ) {
        wp_redirect( $url );
        die;
    }

    // Allow posts and pages to redirect using a custom field 'item_link'
    function do_template_redirect_action() {
        global $post;

        if ( !$post ) return;

		if ( is_single() ) {
	    	if ( $target = $post->item_link ) {
                # If it looks like an URL go there
                if ( strpos( $target, 'https://' ) === 0 || strpos( $target, 'http://' ) === 0 ) {
                    $this->redirect( $target );
                }
            }
	    }
        elseif ( is_category() ) {
            $cat = get_queried_object();
            $target = $this->get_category_link( $cat );
            if ( $target )
                $this->redirect( $target );
        }
    }

    /************************************************************************
     *
     *	Scheduling
     *
     ************************************************************************/

    function do_pp_update_feeds_action() {
        $this->update_all_feeds();
    }

    function do_pp_purge_posts_action() {
        $this->purge_posts();
    }

    function do_init_action() {
        register_deactivation_hook(  __FILE__, function () {
            wp_clear_scheduled_hook( 'pp_update_feeds' );
            wp_clear_scheduled_hook( 'pp_purge_posts' );
        } );

        $schedule = $this->get_option( 'schedule' );
        if ( isset( $schedule ) and $schedule == 'none' )
            $schedule = NULL;

        if ( $schedule ) {
            $schedules = wp_get_schedules();
            if ( !isset( $schedules[$schedule] ) )
                $schedule = NULL;
        }

        // Next scheduled event
        $event = wp_get_scheduled_event( 'pp_update_feeds' );

        if ( $schedule ) {
            if ( $event === false ) {
                // Not scheduled but should be
                wp_schedule_event( time(), $schedule, 'pp_update_feeds' );
            }
            elseif ( $event->schedule != $schedule ) {
                // Scheduled but not correctly
                wp_clear_scheduled_hook( 'pp_update_feeds' );

                $when = $event->timestamp;
                if ( $when > time() + $schedules[$schedule]['interval'] )
                    $when = time() + $schedules[$schedule]['interval'];

                wp_schedule_event( $when, $schedule, 'pp_update_feeds' );
            }
        } else {
            // Shouldn't be scheduled
            if ( $event )
                wp_clear_scheduled_hook( 'pp_update_feeds' );
        }

        // Do the purge handler too
        if ( !wp_next_scheduled( 'pp_purge_posts' ) ) {
            wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', 'pp_purge_posts' );
        }
    }


    /************************************************************************
     *
     *	Feed parser
     *  - return data suitable for creating posts, categories and updating links
     *
     ************************************************************************/

    function parse_feed( $feed ) {
        $error_mode = libxml_use_internal_errors( true );
        try {
            $xml = new \SimpleXMLElement( $feed );
        } catch ( \Exception $e ) {
            $xml = NULL;
        }
        libxml_use_internal_errors( $error_mode );

        if ( empty( $xml ) ) {
            return new WP_Error( 'xml_parse_error', "Feed is not XML" );
        }

        $ns = $xml->GetDocNamespaces();

        if ( $xml->getName() == "rss" ) {
            $output = [
                'title' => (string)$xml->channel->title,
                'description' => (string)$xml->channel->description,
                'link' => (string)$xml->channel->link,
            ];

            if ( empty( $output['title'] ) )
                $output['title'] = $output['link'];

            if ( isset( $ns['atom'] ) )
                $feed_url = $xml->channel->children( $ns['atom'] )->link;

            if ( isset( $feed_url ) and count( $feed_url ) > 0 )
                if ( (string)$feed_url->attributes()->type == 'application/rss+xml' )
                    $output['link_rss'] = (string)$feed_url->attributes()->href;

            $output['posts'] = [];
            foreach ( $xml->channel->item as $item ) {
                if ( isset( $ns['dc'] ) )
                    $author = (string)$item->children( $ns['dc'] )->creator;
                if ( empty( $author ) )
                    $author = (string)$item->author;

                $post_date = \DateTime::createFromFormat( \DateTime::RSS, (string)$item->pubDate );
                if ( !$post_date ) {
                    continue;
                }

                if ( isset($ns['content']) )
                    $post_content = (string)$item->children( $ns['content'] )->encoded;

                $guid = (string)$item->guid ?: (string)$item->link;
                $post = [
                    'post_title' => (string)$item->title,
                    'post_date' => $post_date->format( 'Y-m-d H:i:s' ),

                    'post_excerpt' => strip_tags( (string)$item->description ),
                    'post_content' => $post_content ?? '',

                    'guid' => site_url( $guid, 'https' ),

                    'meta_input' => [
                        'item_guid' => $guid,
                        'item_link' => (string)$item->link,
                        'item_author' => $author,
                    ],
                ];

                $output['posts'][] = $post;
            }
        }
        elseif ( $xml->getName() == "feed" ) {
            $output = [
                'title' => (string)$xml->title,
                'description' => (string)$xml->subtitle,
            ];

            foreach ( $xml->link as $link ) {
                if ( $link->attributes()->rel == "alternate" and $link->attributes()->type == "text/html" ) {
                    $output['link'] = (string)$link->attributes()->href;
                    break;
                }
            }

            if ( empty( $output['title'] ) )
                $output['title'] = $output['link'];

            $output['posts'] = [];
            foreach ( $xml->entry as $item ) {
                $item_link = NULL;
                foreach ( $item->link as $link ) {
                    if ( $link->attributes()->rel == "alternate" and $link->attributes()->type == "text/html" ) {
                        $item_link = (string)$link->attributes()->href;
                        break;
                    }
                }
                $post_date = \DateTime::createFromFormat( \DateTime::RFC3339, (string)$item->published );
                if ( !$post_date )
                    $post_date = \DateTime::createFromFormat( \DateTime::RFC3339_EXTENDED, (string)$item->published );
                if ( !$post_date )
                    continue;   // Skip if we can't understand the date

                $post = [
                    'post_title' => (string)$item->title,
                    'post_date' => $post_date->format( 'Y-m-d H:i:s' ),

                    'post_excerpt' => (string)$item->summary,
                    'post_content' => (string)$item->content,

                    'guid' => site_url( (string)$item->id, 'https' ),

                    'meta_input' => [
                        'item_guid' => (string)$item->id,
                        'item_link' => $item_link,
                        'item_author' => (string)$item->author->name,
                    ],
                ];

                // print_r( $post );

                $output['posts'][] = $post;
            }
        }
        else {
            return "Unknown feed type " . $xml->getName();
        }

        foreach ( $output['posts'] as &$post ) {
            $post['ID'] = 0;
            $post['post_status'] = 'publish';

            if ( empty( trim( $post['post_title'] ) ) )
                $post['post_title'] = 'No title';

            $post['meta_input']['feed_title'] = $output['title'];
            $post['meta_input']['feed_link'] = $output['link'];
        }

        return $output;
    }


    /************************************************************************
     *
     *	Links manager helpers
     *
     ************************************************************************/

    function find_feed( $url ) {
        $links = get_bookmarks();
        if ( $links ) {
            $url = htmlentities( $url ); // link_rss seems to be encoded
            foreach ($links as $link) {
                // $this->logger->debug( 'Testing link «%s» ~ «%s»', $link->link_rss, $url );
                if ( !empty( $link->link_rss ) and $link->link_rss == $url )
                    return $link;
            }
        }
        return NULL;
    }

    /************************************************************************
     *
     *	Categories - one per link for the imported posts
     *
     ************************************************************************/

    function generate_category_slug( $url ) {
        if ( 0 === stripos( $url, 'http://' ) )
            $url = substr( $url, 7 );
        elseif ( 0 === stripos( $url, 'https://' ) )
            $url = substr( $url, 8 );

        return sanitize_title( $url );
    }

    function get_link_category( $link ) {
        $cat_slug = $this->generate_category_slug( $link->link_url );
        $cat = get_term_by( 'slug', $cat_slug, 'category' );

        if ( $cat !== False )
            return $cat->term_id;

        $this->logger->debug( __( "Creating category %d", 'planetplanet' ), $cat_slug );

        $cat_id = wp_insert_category( [
            'cat_ID' => 0,
            'cat_name' => $link->link_name,
            'category_nicename' => $cat_slug,
            'category_description' => sprintf( "<!-- %s -->
", $link->link_url ),
        ], true );

        if ( is_wp_error( $cat_id ) ) {
            $this->logger->error( __( 'Failed to create category %s: %s', 'planetplanet' ),
                                  $cat_slug,
                                  $cat_id->get_error_message()
            );
            return false;
        }

        return $cat_id;
    }

    function get_category_link_object( $cat ) {
        $linkmap = get_transient( 'planetplanet_category_linkmap' );

        $cat_id = $cat->term_id;

        if ( isset( $linkmap ) and isset( $linkmap[$cat_id] ) )
            return $linkmap[$cat_id];

        $linkmap = [];
        foreach ( get_bookmarks() as $link ) {
            $slug = $this->generate_category_slug( $link->link_url );
            $linkcat = get_term_by( 'slug', $slug, 'category' );

            if ( $linkcat )
                $linkmap[$linkcat->term_id] = $link;
        }
        set_transient( 'planetplanet_category_linkmap', $linkmap );

        return isset( $linkmap[$cat_id] ) ? $linkmap[$cat_id] : NULL;
    }

    function get_category_link( $cat ) {
        $link = $this->get_category_link_object( $cat );
        return isset( $link ) ? $link->link_url : NULL;
    }

    /************************************************************************
     *
     *	Imports posts from link (a link in the WP db)
     *
     ************************************************************************/

    function import_feed_items( $link ) {
        $response = $this->get_web_page( $link->link_rss );
        if ( is_wp_error( $response ) ) return $response;

        $data = $this->parse_feed( $response['body'] );
        if ( is_wp_error( $data ) ) return $data;

        // find feed category and create if necessary
        $cat_id = $this->get_link_category( $link );
        $this->logger->debug( __( "Feed category ID %d", 'planetplanet' ), $cat_id );

        $posts = $data['posts'];
        if ( empty( $posts ) ) return false; // nothing to import

        $limit = NULL;
        if ($this->has_option( 'retain_limit' ))
            $limit = $this->safe_datetime( $this->get_option('retain_limit') );

        $old = 0;
        $seen = 0;
        $new = 0;
        $mtime = NULL;

        foreach ( $posts as $post ) {
            $dt = new \DateTime( $post['post_date'] );

            if ( !$mtime or $dt > $mtime )
                $mtime = $dt;

            if ( $limit ) {
                // Check post age - this if for recentish stuff
                if ($dt < $limit) {
                    $old = $old + 1;
                    $this->logger->debug( __( "Old post %s - %s", 'planetplanet' ), $post['post_title'], $post['post_date'] );
                    continue;
                }
            }

            // Check that we don't already have the post
            $olds = get_posts( [
                'post_type' => 'post',
                'meta_key' => 'item_guid',
                'meta_value' =>  $post['meta_input']['item_guid'],
            ] );
            if ( !empty($olds) ) {
                $seen = $seen + 1;
                $this->logger->debug( __( "Seen post %s - %s", 'planetplanet' ), $post['post_title'], $post['post_date'] );
                continue;
            }

            $new = $new + 1;
            $this->logger->message( __( "New post found for %s - %s", 'planetplanet' ), $link->link_name, $link->link_rss );
            $this->logger->message( __( " - new post %s - %s", 'planetplanet' ), $post['post_title'], $post['meta_input']['item_link'] );

            // Add category for post
            $post['post_category'] = [ $cat_id ];
            $post_id = wp_insert_post( $post );
            if ( is_wp_error( $post_id ) ) return $post_id;

            // Add feature image if found
            $this->add_featured_image( $post_id, $link->link_image );
        }

        if ( $new )
            $this->logger->message( __( 'Feed status %3d "%s" - %d new, %d seen, %d old', 'planetplanet' ),
                                    $link->link_id, $link->link_name,
                                    $new, $seen, $old
            );

        return $mtime ? $mtime->format( 'Y-m-d H:i:s' ) : false;
    }

    function add_featured_image( $post_id, $image_url = NULL ) {
        $post = get_post( (int)$post_id );

        if ( !$post ) return;
        if ( has_post_thumbnail( $post ) ) return;

        if ( empty( $image_url ) )
            $image_url = $this->get_featured_image_from_content( $post );
        if ( empty( $image_url ) )
            $image_url = $this->get_featured_image_from_original_post( $post );

        if ( $image_url ) {
            $this->logger->debug( __( 'Found image url %s', 'planetplanet' ), $image_url );
            $this->upload_media( $post, $image_url );
        } else {
            $this->logger->debug( __( 'No image found', 'planetplanet' ) );
        }
    }

    function get_featured_image_from_content( $post ) {
        if ( empty($post->post_content ) ) return;

        $html = mb_convert_encoding( $post->post_content, 'HTML-ENTITIES', 'UTF-8' );
        $xml = new \DOMDocument( '1.0', 'UTF-8' );

        $error_mode = libxml_use_internal_errors( true );

        $image_url = NULL;
        if ( $xml->LoadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
            $elems = $xml->getElementsByTagName( 'img' );

            foreach ( $elems as $elem ) {
                $attrs = $elem->attributes;

                $attr = $attrs->getNamedItem( 'src' );
                if ( isset( $attr ) ) {
                    $image_url = (string) $attr->value;
                    break;
                }
            }
        }
        libxml_use_internal_errors( $error_mode );

        return $image_url;
    }


    function get_featured_image_from_original_post( $post ) {
        if ( !$post->item_link ) return;

        $response = $this->get_web_page( $post->item_link );
        if ( is_wp_error( $response ) ) return;

        $html = mb_convert_encoding( $response['body'], 'HTML-ENTITIES', 'UTF-8' );
        $xml = new \DOMDocument( '1.0', 'UTF-8' );
        $error_mode = libxml_use_internal_errors( true );

        $image_url = NULL;
        if ( $xml->LoadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
            $elems = $xml->getElementsByTagName( 'meta' );

            foreach ( $elems as $elem ) {
                $attrs = $elem->attributes;

                $attr = $attrs->getNamedItem( 'property' );
                if ( isset( $attr ) and $attr->value == 'og:image' ) {
                    $attr = $attrs->getNamedItem( 'content' );
                    if ( isset( $attr ) and !empty( $attr->value ) ) {
                        $image_url = $attr->value;
                        break;
                    }
                }
            }
        }
        libxml_use_internal_errors( $error_mode );

        if ( empty( $image_url ) ) {
            $this->logger->message( __( 'No image found in %s', 'planetplanet' ), $post->item_link );
            return;
        };

        return \WP_Http::make_absolute_url( $image_url, $post->item_link );
    }



    // Upload featured image from external site to an imported post

    function upload_media( $post, $image_url ) {

        // Check if we already has that image saved
        $images = get_posts( [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_key' => 'source_url',
            'meta_value' => $image_url,
        ] );

        if ( empty( $images ) ) {
            // Download the image and upload it to the site

            $response = $this->get_web_page( $image_url );
            if ( is_wp_error( $response ) ) {
                $this->logger->error( __( 'Failed to retrieve %s: %s', 'planetplanet' ), $image_url, $response->get_error_message(0) );
                return;
            }

            $bits = $response['body'];
            $filename = basename( parse_url( $image_url, PHP_URL_PATH ) );

            // If no filename extension, check http_response_headers
            if ( empty( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
                $content_disp = $response['headers']['Content-Disposition'];

                if ( preg_match( '/filename="(.*?)"/', $content_disp, $m ) ) {
                    $this->logger->debug( __( 'Using filename from HTTP response «%s»', 'planetplanet' ), $m[1] );
                    $filename = $m[1];
                }
                elseif ( preg_match( '/filename=(.*?)$/', $content_disp, $m ) ) {
                    $this->logger->debug( __( 'Using filename from HTTP response «%s»', 'planetplanet' ), $m[1] );
                    $filename = $m[1];
                }
            }

            $upload_file = wp_upload_bits( $filename, NULL, $bits );

            if ( $upload_file['error'] ) {
                $this->logger->error( __( 'Image upload error %s', 'planetplanet' ), $upload_file['error'] );
                return;
            }

            $filetype = wp_check_filetype( $upload_file['file'], NULL );

            $attachment = [
                'guid' => $upload_file['url'],
                'post_mime_type' => $filetype['type'],
                'post_title' => sanitize_file_name( $filename ),
                'post_content' => '',
                'post_status' => 'inherit',
                'post_date' => $post->post_date,
            ];

            $this->logger->debug( __( 'Registering attachment %s', 'planetplanet' ), $upload_file['file'] );

            $attachment_id = wp_insert_attachment( $attachment, $upload_file['file'], $post->ID, true );
            if ( is_wp_error( $attachment_id ) ) {
                $this->logger->error( __( 'Image attachment error %s', 'planetplanet' ), $attachment_id->get_error_message() );
                return;
            }

            $this->logger->debug( __( 'Created attachment id %d', 'planetplanet' ), $attachment_id );

            $attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload_file['file'] );
            wp_update_attachment_metadata( $attachment_id,  $attachment_data );

            // Save the source of the image
            add_post_meta( $attachment_id, 'source_url', $image_url );
        } else {
            $attachment_id = $images[0]->ID;
            $this->logger->debug( __( 'Using existing attachment id %d', 'planetplanet' ), $attachment_id );
        }

        // Attach image to post
        $success = set_post_thumbnail( $post, $attachment_id );
        if ( !$success ) return;

        $this->logger->message( __( 'Downloaded post thumbnail', 'planetplanet' ) );
        return $success;
    }


    // WP db links

    function update_feed( $link ) {
        $this->logger->debug( __( 'Starting import of %s - %s', 'planetplanet' ), $link->link_name, $link->link_url );

        $mtime = $this->import_feed_items( $link );

        if ( is_wp_error( $mtime ) ) {
            $msg = $mtime->get_error_message();
            $link->link_notes = substr( $msg, 0, 255 );
            $link->link_rating = $link->link_rating + 1;

            $max_errors = $this->get_option( 'max_errors', 5 );

            $this->logger->error( __( 'Feed %d "%s" - Error #%d/%d: %s: %s', 'planetplanet' ),
                                  $link->link_id,
                                  $link->link_name,
                                  $link->link_rating,
                                  $max_errors,
                                  $link->link_rss,
                                  $msg
            );

            if ( $link->link_rating >= $max_errors ) {
                $this->logger->error( __( 'Feed %d "%s" suspended: too many errors', 'planetplanet' ),
                                      $link->link_id, $link->link_name );
                $link->link_visible = 'N';
            }

            $mtime = false;
        } else {
            $link->link_rating = 0;
            $link->link_visible = 'Y';
            $link->link_notes = "Updated: $mtime";
        }

        $this->save_feed( $link, $mtime );
    }

    function save_feed( $link, $mtime ) {
        $this->logger->debug( __( 'Updating %d "%s" - mtime %s', 'planetplanet' ),
                              $link->link_id, $link->link_name, $mtime );

        $cat_name = __( 'OK', 'planetplanet' );
        if ( $link->link_visible == 'N' )
            $cat_name = __( 'Suspended', 'planetplanet' );
        else if ( $link->link_rating > 0 )
            $cat_name = __( 'Errors', 'planetplanet' );

        $link->link_category = wp_create_term( $cat_name, 'link_category' );

        $link_id = wp_insert_link( get_object_vars( $link ), true );
        if ( is_wp_error( $link_id ) )
            $this->logger->error( __( 'Saving link %d failed: %s', 'planetplanet' ),
                                  $link->link_id, $link_id->get_error_message() );

        // NOT NICE - wp_update_link() doesn't update the link_updated field

        if ( $mtime ) {
            global $wpdb;
            $wpdb->update( "{$wpdb->prefix}links",
                           [ 'link_updated' => $mtime ],
                           [ 'link_id' => $link->link_id ]
            );
        }
    }

    function update_all_feeds() {
        $links = get_bookmarks();
        if ( empty( $links ) ) return;

        $now = time();
        $this->logger->debug( __( 'START %s', 'planetplanet' ), current_time('mysql') );
        foreach ( $links as $link ) {
            $this->update_feed( $link );
        }
        $this->logger->debug( __( 'END %s - elapsed %ds', 'planetplanet' ), current_time( 'mysql' ), time() - $now );
    }


    /************************************************************************
     *
     *	Purge old posts and images from the site
     *
     ************************************************************************/

    function purge_posts() {
        $limit = $this->get_option( 'retain_limit' );
        if ( !$limit ) return;
        if ( !$this->safe_datetime( $limit ) ) return;

        // Query to find some of the oldest posts before the retain limit
        $query = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'date_query' => [ 'before' => $limit ],
            'order' => 'ASC',
            'numberposts' => 20,
        ];

        while ( !empty( $posts = get_posts( $query ) ) ) {
            foreach ( $posts as $post ) {
                $this->logger->debug( __( 'Found post %4d %s %s', 'planetplanet' ),
                                      $post->ID, $post->post_date, $post->post_title );

                $image_id = get_post_thumbnail_id($post->ID);
                if ( $image_id ) {
                    $image = get_post( $image_id );
                    $this->logger->debug( __( '> found image %4d %s %s', 'planetplanet' ),
                                          $image_id, $image->post_date, $image->post_title );

                    if ( $image ) {
                        $image_posts = get_posts( [
                            'meta_key' => '_thumbnail_id',
                            'meta_value' => $image_id,
                            'numberposts' => -1,
                        ] );

                        $this->logger->debug( __( '>> image is attached to %d posts', 'planetplanet' ),
                                              count($image_posts) );

                        // Delete image only if not in use by other posts
                        if ( 1 == count( $image_posts ) ) {
                            if ( wp_delete_attachment( $image_id, true ) )
                                $this->logger->message( __( 'Deleted image %d - %s', 'planetplanet' ),
                                                        $image_id, $image->post_title );
                            else
                                $this->logger->error( __( 'Deletion of image %d - %s failed', 'planetplanet' ),
                                                      $image_id, $image->post_title );
                        }
                    }
                }

                if ( wp_delete_post( $post->ID, true ) )
                    $this->logger->message( __( 'Deleted post %d - %s', 'planetplanet' ),
                                            $post->ID, $post->post_title );
                else
                    $this->logger->error( __( 'Deletion of post %d - %s failed', 'planetplanet' ),
                                          $post->ID, $post->post_title );
            }
        }
    }
}

global $planetplanet;
$planetplanet = new PlanetPlanetPlugin();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once dirname( __FILE__ ) . '/planetplanet-cli.php';
}
