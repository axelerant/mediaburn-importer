=== MediaBurn Importer ===
Contributors: comprock
Donate link: http://aihr.us/about-aihrus/donate/
Tags: vzaar, importer, mediaburn
Requires at least: 3.0.0
Tested up to: 3.3
Stable tag: trunk

Easily assign Vzaar media records to WordPress videos.

== Description ==
Easily assign Vzaar media records to WordPress videos.

== Installation ==
1. Upload the `mediaburn-importer` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Set TYPO3 access via Settings > MediaBurn Importer
1. Import via Tools > MediaBurn Importer
1. In lib/, svn co http://vzaar.googlecode.com/svn/trunk/api/php/trunk vzaar

== Frequently Asked Questions ==
= Can I sponsor importing ______? =
Yes. Any sponsoring would be greatly welcome. Please [donate](http://typo3vagabond.com/about-typo3-vagabond/donate/ "Help sponsor TYPO3 Importer") and let me know what's wanted

== Screenshots ==
1. TBD

== Changelog ==
= vzaar =
* Removed TYPO3 remnants
* Focus on Vzaar importing only

= trunk =
* Revise data refresh handling
* Revise insert/update handling
* Remove unused options
* Remove comments handling
* Revise for video/document removal
* Import documents sans actual materials
* Implement refresh data option
* Include relationships between videos
* update_post_meta vs add_post_meta
* Connect to Alfresco
* Import document_assets
* Create links to read PDFs online - links work, but stil not really working
* Import thumbnails from Alfresco
* Move document embed to post_meta
* Revise user creation
* Option Don't Import MediaBurn Records
* Option Don't Import Users
* Put in get_user(s) code
* Load users and documents before MB records
* Put role into base query
* Option Delete Taxonomy, All, Users
* Fix date of birth polling
* Enabled relate_items
* Mark attachments as part of import
-

== Upgrade Notice ==
* None

== TODOs ==
* TBD
