=== PlanetPlanet - RSS feed aggregator ===
Contributors: seindal
Tags: feed aggregator, planet planet, rss
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert a WordPress site into an RSS/Atom feed aggregator like the old 'planet' software.

== Description ==

The PlanetPlanet plugin will convert a WordPress site into an RSS/Atom feed aggregator by regularly reading all the configured feeds and importing the items in the feeds to WordPress posts.

The feeds are registered in the old WordPress links/bookmarks section, which is automatically reactivated. Feeds can be added, modified and deleted there.

The imported posts contain title, excerpt and maybe content from the feed items. Additional custom fields are added with some data that doesn't intuitively map to WordPress posts. If the feed item has a featured image, that is imported too.

Links to the imported posts redirect to the original post.

A post category is created automatically for each feed. The category page redirects to the originating site.

Imported posts are purged automatically after a configurable period.

Importing and purging articles can be scheduled using wp-cron. Output from scheduled actions can be mailed.

The plugin adds a WP-CLI command 'planet' with sub-commands for listing feeds, adding feeds, updating individual or all feeds, purging posts, and scanning web pages for available feeds. This allows most administration and actions to be run without using wp-cron.

Implementation notes: feeds errors are counted in the link_rating field, and the feed is marked not visible if it generates too many errors.

The fields in the Links section are used like this:

* Name: site title
* Web address: main site url
* Advanced / Image Address: featured image override url
* Advanced / RSS Address: site feed url
* Advanced / Notes: last update time or last error message
* Advanced / Rating: error count
* Keep this link private: private links are ignored

== Installation ==

The plugin can be installed as any other plugin.It requires not external setup.

== Configuration ==

The plugin adds a 'Planet Planet' sub-menu under Settings.

* How often to check feeds: the choices are from the WP scheduler. 'None' means no automatic updates. The site can still be updated through the WP-CLI interface.
* Discard posts older than this: the value can be anything the PHP class DateTime can parse into a past date, which includes values like '6 months ago'. Too old posts are never imported, and they're purged automatically. If the field is empty, even very old feed items are imported and never purged.
* Number of errors before feed is suspended: see below.
* Email for updates: insert an email if you want the output from schedule actions (updates and purges) mailed to you. Leave empty for no mails.
* Level of detail in mails: should be self-evident.
* Timeout for feed requests: how long to wait for a reply from remote servers.
* User-Agent: some servers filter on the User-Agent header.

== How to setup an RSS feed aggregator site ==

1. Setup and configure an empty WordPress site
2. Install and activate the PlanetPlanet plugin
3. Setup automatic updates either through the plugin configuration page or externally through WP-CLI.
4. Add links in the "Links" section on the right hand admin menu. You need to add Link name, URL and RSS URL for each feed.
5. Find or create a WP theme that shows the posts the way you want, remembering that certain links will redirect automatically to the originating site of the posts.

== Feed errors ==

Whenever a feed update fails, the error messages is recorded in the 'description' field for the feed in the WP links manager. The error count is registered in the link rating field, so a higher rating means more consecutive errors.

If a feed generates too many (configurable) consecutive errors, it is marked as 'not visible'. It will not be updated any more. It can always be reactivated in the links manager.

Old posts from a disabled or deleted feed are not removed. They can be easily identified through the post category for the feed.

The error count and the saved error message are reset with each successful update.

== Frequently Asked Questions ==

Nothing yet.

== Changelog ==

= 1.1 =

* Tested with WP 6.5
* The link category now indicates feed status (OK, Errors, Suspended), so the links list can be filtered on status.
* The At-a-Glance dashboard widget now shows number of feeds with each status (OK, Errors, Suspended), with links to the filtered links page.
* A link to the Settings page now appear under the plugin on the plugins page.

= 1.0 =

* Tested with WP 6.4
* Fixed a bug in first time activation
* Link field 'link_image' can be used to override site thumbnail

= 0.13 =

* First published version.

== Upgrade Notice ==

Nothing yet.
