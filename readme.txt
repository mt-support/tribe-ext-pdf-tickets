=== Event Tickets Extension: PDF Tickets ===
Contributors: theeventscalendar
Donate link: http://evnt.is/29
Tags: events, calendar
Requires at least: 5.8.5
Tested up to: 6.2
Requires PHP: 7.4
Stable tag: 1.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Event Tickets' RSVP, Tribe Commerce PayPal, WooCommerce, and/or Easy Digital Downloads ticket emails will become PDF files saved to your Uploads directory and then get attached to the ticket emails.

== Description ==

Event Tickets' RSVP, Tribe Commerce PayPal, WooCommerce, and/or Easy Digital Downloads ticket emails will become PDF files saved to your Uploads directory and then get attached to the ticket emails.

== Installation ==

Install and activate like any other plugin!

* You can upload the plugin zip file via the *Plugins ‣ Add New* screen
* You can unzip the plugin and then upload to your plugin directory (typically _wp-content/plugins)_ via FTP
* Once it has been installed or uploaded, simply visit the main plugin list and activate it

== Frequently Asked Questions ==

= Where can I find more extensions? =

Please visit our [extension library](https://theeventscalendar.com/extensions/) to learn about our complete range of extensions for The Events Calendar and its associated plugins.

= What if I experience problems? =

We're always interested in your feedback, and our [premium forums](https://theeventscalendar.com/support-forums/) are the best place to flag any issues. Do note, however, that the degree of support we provide for extensions like this one tends to be very limited.

== Changelog ==

= [1.2.4] 2023-07-18 =

* Version - The PDF Tickets is only compatible with PHP 7.4 or later.
* Version - The PDF Tickets is only compatible with Event Tickets 5.6.2 or later.
* Version - The PDF Tickets is only compatible with Event Tickets Plus 5.7.2 or later.
* Version - The PDF Tickets is only compatible with Community Tickets 4.9.3 or later.
* Fix - Correct some incompatibility with the new TEC container structure.

= [1.2.3] 2023-04-27 =

* Fix - Update the attachment handling for WooCommerce tickets in Outlook.
* Tweak - "WooCommerce tested up to" version changed from `4.2.0` to `7.6.1`.

= [1.2.2] 2020-06-10 =

* Fix - Update mPDF library version so this plugin now works with PHP 7.4. [EXT-211]
* Tweak - "WooCommerce tested up to" version changed from `3.7.0` to `4.2.0`. [EXT-211]

= [1.2.1] 2019-09-05 =

* Tweak - Now requires Event Tickets Plus version 4.7 or newer
* Tweak - "WooCommerce tested up to" version changed from `3.5.7` to `3.7.0`
* Fix - Attendee Information from Event Tickets Plus now appears correctly at time of emailing [120683]
* Fix - Bulk completing WooCommerce Orders only includes each Order's applicable PDF attachments [116980]
* Fix - No longer throws fatal error when Permalinks are disabled

= [1.2.0] 2019-03-29 =

* Feature - Ability to have a PDF Ticket template separate from Email template by creating a full HTML DOM file at `[your-theme]/tribe-events/tickets/pdf-tickets.php` [122414]
* Fix - Compatibility with the latest Event Tickets and Event Tickets Plus releases [122622]
* Fix - Avoid fatal on Events List admin screen when Event Tickets Plus is not active
* Tweak - Fixed notices around caching directories
* Tweak - Add "WC tested up to" to plugin header to avoid the "Not tested with the active version of WooCommerce" message within the WooCommerce Status page [117703]
* Tweak - Update mPDF library from version 7.0.3 to version 8.0.0

= [1.1.0] 2018-05-16 =

* Feature - Added support for Tribe Commerce PayPal tickets
* Feature - Added new public methods: `delete_all_tickets_for_event()`, `delete_all_tickets_for_event()`, `delete_single_pdf_ticket()`
* Tweak - Delete PDF files from server whenever they are detected to be outdated, such as when the Event or one of its attached Venues or Organizers is updated or when an Attendee's Additional Information is updated -- added multiple hooks to disable deleting upon these triggers if you choose
* Tweak - To be more extensible, made these methods public: `ticket_link()`, `get_pdf_link()`, `get_direct_pdf_url()`, `get_pdf_path()`
* Tweak - Update mPDF library from version 7.0.0 to version 7.0.3
* Tweak - Changed mPDF default arguments to default to letter-size (8.5 x 11 inches) instead of its default A4 (8.27 x 11.69 inches)
* Tweak - Added new `tribe_ext_pdf_tickets_mpdf_args` filter to customize the arguments sent to [mPDF](https://github.com/mpdf/mpdf)
* Fix - Protect against fatal error triggered when Event Tickets plugin got deactivated while this extension was still active
* Fix - Add additional action hooks for when a ticket is modified and then force regenerating the PDF so it always matches the HTML/email version
* Fix - WooCommerce - PDF email attachments now work according to your "When should attendee records be generated?" and "When should tickets be emailed to customers?" settings
* Fix - WooCommerce - PDF email attachments now work when performing "Resend tickets email" from WooCommerce's "Edit order" wp-admin screen
* Fix - Corrected text domain, load text domain, and add a .pot file to make this extension plugin translatable

= [1.0.0] 2017-12-06 =

* Initial release
* Requirements:
 * PHP version 5.6 or greater
 * Event Tickets version 4.5.2 or greater
 * (optional) Event Tickets Plus version 4.5.6 or greater
 * (optional) Community Events Tickets version 4.4.3 or greater
* License is GPLv2 (not "GPLv2 or any later version") to be compatible with mPDF's "GPL-2.0-only" license in its `composer.json` file
