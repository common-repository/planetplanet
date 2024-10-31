<?php

namespace ReneSeindal\PlanetPlanet;

/**
 * PlanetPlanet plugin CLI commands
 *
 * ## EXAMPLES
 *
 *     wp planet update
 *
 * @when after_wp_load
 */

class PlanetPlanetCLI {

    /**
     * List all feeds
     *
     * ## OPTIONS
     *
     * [--match=<string>]
     * : Search for <string> in feed name, site url or feed url
     *
     * [--fields=<fields>]
     * : Limit the output to specific object fields.
     * ---
     * default: link_id,link_name,link_rss
     * ---
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - count
     *   - yaml
     * ---
     *
     * @subcommand list
     */

    public function list_feeds( $args, $assoc ) {
        $links = get_bookmarks();

        if ( isset( $assoc['match'] ) ) {
            $s = $assoc['match'];
            $links = array_filter( $links, function ( $link ) use ( $s ) {
                if ( stripos( $link->link_name, $s ) !== false ) return true;
                if ( stripos( $link->link_url,  $s ) !== false ) return true;
                if ( stripos( $link->link_rss,  $s ) !== false ) return true;
                return false;
            } );
        }

        \WP_CLI\Utils\format_items( $assoc['format'], $links, $assoc['fields'] );
    }

    /**
     * Updates all feeds
     *
     * ## OPTIONS
     *
     * [--log-level=<level>]
     * : Change the log-level from what is configured in the plugin
     * ---
     * levels:
     *   - debug
     *   - messages
     *   - errors
     * ---
     *
     * @subcommand update-all
     */

    public function update_all( $args, $assoc ) {
        global $planetplanet;

        if ( isset( $assoc['log-level'] ) )
            $planetplanet->set_loglevel( $assoc['log-level'] );

        $planetplanet->update_all_feeds();
        \WP_CLI::success( __('Done.', 'planetplanet') );
    }

    /**
     * Updates all suspended feeds
     *
     * ## OPTIONS
     *
     * [--log-level=<level>]
     * : Change the log-level from what is configured in the plugin
     * ---
     * levels:
     *   - debug
     *   - messages
     *   - errors
     * ---
     *
     * @subcommand update-suspended
     */

    public function update_suspended( $args, $assoc ) {
        global $planetplanet;

        if ( isset( $assoc['log-level'] ) )
            $planetplanet->set_loglevel( $assoc['log-level'] );

        $links = get_bookmarks( [ 'hide_invisible' => false ] );
        if ( empty( $links ) ) return;

        foreach ( $links as $link ) {
            if ( $link->link_visible == 'Y' )
                continue;

            $planetplanet->update_feed( $link );
        }

        \WP_CLI::success( __('Done.', 'planetplanet') );
    }

    /**
     * Updates selected feeds
     *
     * ## OPTIONS
b     *
     * <feed-id>...
     * : Update given feeds
     *
     * [--log-level=<level>]
     * : Change the log-level from what is configured in the plugin
     * ---
     * levels:
     *   - debug
     *   - messages
     *   - errors
     * ---
     *
     * ## EXAMPLES
     *
     *     wp planet update 9
     */

    public function update( $args, $assoc ) {
        global $planetplanet;

        if ( isset( $assoc['log-level'] ) )
            $planetplanet->set_loglevel( $assoc['log-level']);

        foreach ( $args as $arg ) {
            $link = get_bookmark( (int)$arg );
            if ( isset( $link ) ) {
                $planetplanet->update_feed( $link );
                \WP_CLI::success( sprintf( __('Updated feed %d %s', 'planetplanet'), $link->link_id, $link->link_name) );
            } else {
                \WP_CLI::warning( sprintf( __('No feed with ID %s', 'planetplanet'), $arg) );
            }
        }
    }


    /**
     * Purge old posts
     *
     * ## OPTIONS
     *
     * [--log-level=<level>]
     * : Change the log-level from what is configured in the plugin
     * ---
     * levels:
     *   - debug
     *   - messages
     *   - errors
     * ---
     *
     */

    public function purge( $args, $assoc ) {
        global $planetplanet;

        if ( isset( $assoc['log-level'] ) )
            $planetplanet->set_loglevel( $assoc['log-level'] );

        $planetplanet->purge_posts();
        \WP_CLI::success( __('Done.', 'planetplanet') );
    }

    /**
     * Scan a web site for available feeds
     *
     * ## OPTIONS
     *
     * <site-url>
     * : Parse the site to find feeds
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - count
     *   - yaml
     * ---
     *
     *
     */
    function scan( $args, $assoc ) {
        global $planetplanet;

        $site = $args[0];

        $response = $planetplanet->get_web_page( $site );
        if ( is_wp_error( $response ) )
            \WP_CLI::error( $response );

        $feed_links = [];

        $input = mb_convert_encoding( $response['body'], 'HTML-ENTITIES', 'UTF-8' );
        $xml = new \DOMDocument( '1.0', 'UTF-8' );
        $error_mode = libxml_use_internal_errors( true );

        if ($xml->LoadHTML( $input, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD )) {
            $links = $xml->getElementsByTagName( 'link' );

            foreach ( $links as $link ) {
                $attrs = $link->attributes;

                // printf("LINK %s\n", $link->ownerDocument->saveXML($link));

                $attr = $attrs->getNamedItem( 'rel' );
                if ( !isset( $attr ) or $attr->value != 'alternate' ) continue;

                $attr = $attrs->getNamedItem( 'type' );
                if ( !isset( $attr ) or $attr->value != 'application/rss+xml' ) continue;

                $attr = $attrs->getNamedItem( 'href' );
                if ( !isset( $attr ) or empty( $attr->value ) ) continue;
                $href = $attr->value;

                $attr = $attrs->getNamedItem( 'title' );
                if ( !isset( $attr ) or empty( $attr->value ) ) continue;
                $title = $attr->value;

                $feed_links[] = [ __('Title', 'planetplanet') => $title, __('Feed url', 'planetplanet') => $href ];
            }
        }
        libxml_use_internal_errors( $error_mode );

        if ( $feed_links ) {
            \WP_CLI\Utils\format_items( $assoc['format'], $feed_links, join(',', array_keys($feed_links[0])) );
            \WP_CLI::success( sprintf( __('Found %d feeds.', 'planetplanet'), count( $feed_links )) );
        } else {
            \WP_CLI::warning(__('No feeds found.', 'planetplanet'));
        }
    }

    /**
     * Add new feed url to the planet
     *
     * ## OPTIONS
     *
     * <feed-url>
     * : Add the feed to the planet
     *
     */

    function add( $args, $assoc ) {
        global $planetplanet;

        $url = $args[0];

        if ( $planetplanet->find_feed( $url ) )
            \WP_CLI::error( sprintf( __("Feed already configured: %s", 'planetplanet'), $url ) );

        $response = $planetplanet->get_web_page( $url );
        if ( is_wp_error( $response ) )
            \WP_CLI::error( $response );

        $data = $planetplanet->parse_feed( $response['body'] );
        if ( is_wp_error( $data ) )
            \WP_CLI::error( $data );

        if ( isset( $data['link_rss'] ) and $data['link_rss'] != $url ) {
            if ( $planetplanet->find_feed( $data['link_rss'] ) )
                \WP_CLI::error( sprintf( __("Feed already configured: %s", 'planetplanet'), $data['link_rss'] ) );
        }

        $link = [
            'link_id' => 0,
            'link_url' => $data['link'],
            'link_rss' => ( !empty( $data['link_rss'] ) ? $data['link_rss'] : $url ),
            'link_name' => $data['title'],
        ];

        \WP_CLI::log( sprintf( __("Site name: %s", 'planetplanet'), $link['link_name'] ) );
        \WP_CLI::log( sprintf( __("Site url: %s",  'planetplanet'), $link['link_url'] ) );
        \WP_CLI::log( sprintf( __("Feed url: %s", 'planetplanet' ), $link['link_rss'] ) );

        $id = wp_insert_link( $link, true );
        if ( is_wp_error( $id ) )
            \WP_CLI::error( $id );

        \WP_CLI::success( __("Link added", 'planetplanet' ) );
    }


    /**
     * Add featured image to posts
     *
     * ## OPTIONS
     *
     * <post-id>...
     * : Update given posts
     *
     * [--force]
     * : Force the change
     *
     * [--log-level=<level>]
     * : Change the log-level from what is configured in the plugin
     * ---
     * levels:
     *   - debug
     *   - messages
     *   - errors
     * ---
     *
     * ## EXAMPLES
     *
     *     wp planet thumbnail 9
     *
     * @alias thumb
     */

    public function thumbnail( $args, $assoc ) {
        global $planetplanet;

        if ( isset( $assoc['log-level'] ) )
            $planetplanet->set_loglevel( $assoc['log-level']);

        $force = false;
        if ( isset( $assoc['force'] ) )
            $force = true;

        foreach ( $args as $arg ) {
			\WP_CLI::log( sprintf( __("Updating thumbnail for post %d", 'planetplanet' ), $arg ) );

            $post_id = (int)$arg;
            $image_url = NULL;

            $cats = wp_get_post_categories( $post_id, [ 'fields' => 'all'] );
            if ( ! is_wp_error( $cats ) ) {
                $link = $planetplanet->get_category_link_object( $cats[0] );

                if ( isset( $link ) )
                    $image_url = $link->link_image;
            }

            if ( $force )
                delete_post_thumbnail( $post_id );

			$planetplanet->add_featured_image( $post_id, $image_url );
        }
    }


}

\WP_CLI::add_command( 'planet', new PlanetPlanetCLI() );
