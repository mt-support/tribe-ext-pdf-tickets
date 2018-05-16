=== Event Tickets Extension: PDF Tickets ===
Contributors: ModernTribe
Donate link: http://m.tri.be/29
Tags: events, calendar
Requires at least: 4.5
Tested up to: 4.9.5
Requires PHP: 5.6
Stable tag: 1.1.0
License: GPL version 2
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

We're always interested in your feedback and our [premium forums](https://theeventscalendar.com/support-forums/) are the best place to flag any issues. Do note, however, that the degree of support we provide for extensions like this one tends to be very limited.

== Changelog ==

= 1.1.0 2018-05-16 =

* Feature - Added support for Tribe Commerce PayPal tickets
* Feature - Added new public methods: `delete_all_tickets_for_event()`, `delete_all_tickets_for_event()`, `delete_single_pdf_ticket()`
* Tweak - Delete PDF files from server whenever they are detected to be outdated, such as when the Event or one of its attached Venues or Organizers is updated or when an Attendee's Additional Information is updated -- added multiple hooks to disable deleting upon these triggers if you choose
* Tweak - To be more extensible, made these methods public: `ticket_link()`, `get_pdf_link()`, `get_direct_pdf_url()`, `get_pdf_path()`
* Tweak - Added new `tribe_ext_pdf_tickets_mpdf_args` filter to customize the arguments sent to [mPDF](https://github.com/mpdf/mpdf)
* Tweak - Update mPDF library from version 7.0.0 to version 7.0.3
* Tweak - Changed mPDF default arguments to default to letter-size (8.5 x 11 inches) instead of its default A4 (8.27 x 11.69 inches), and arguments are now able to be filtered
* Fix - Protect against fatal error triggered when Event Tickets plugin got deactivated while this extension was still active
* Fix - Add additional action hooks for when a ticket is modified and then force regenerating the PDF so it always matches the HTML/email version
* Fix - WooCommerce - PDF email attachments now work according to your "When should attendee records be generated?" and "When should tickets be emailed to customers?" settings
* Fix - WooCommerce - PDF email attachments now work when performing "Resend tickets email" from WooCommerce's "Edit order" wp-admin screen
* Fix - Corrected text domain, load text domain, and add a .pot file to make this extension plugin translatable

= 1.0.0 2017-12-06 =

* Initial release
* Requirements:
 * PHP version 5.6 or greater
 * Event Tickets version 4.5.2 or greater
 * (optional) Event Tickets Plus version 4.5.6 or greater
 * (optional) Community Events Tickets version 4.4.3 or greater
* License is GPLv2 (not "GPLv2 or any later version") to be compatible with mPDF's "GPL-2.0-only" license in its `composer.json` file