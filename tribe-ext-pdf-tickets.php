<?php
/**
 * Plugin Name:       Event Tickets Extension: PDF Tickets
 * Description:       Event Tickets' RSVP, Tribe Commerce PayPal, WooCommerce, and/or Easy Digital Downloads ticket emails will become PDF files saved to your Uploads directory and then get attached to the ticket emails.
 * Version:           1.1.0
 * Extension Class:   Tribe__Extension__PDF_Tickets
 * GitHub Plugin URI: https://github.com/mt-support/tribe-ext-pdf-tickets
 * Author:            Modern Tribe, Inc.
 * Author URI:        http://m.tri.be/1971
 * License:           GPL version 2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tribe-ext-pdf-tickets
 */

// Do not load unless Tribe Common is fully loaded and our class does not yet exist.
if (
	class_exists( 'Tribe__Extension' )
	&& ! class_exists( 'Tribe__Extension__PDF_Tickets' )
) {
	/**
	 * Extension main class, class begins loading on init() function.
	 */
	class Tribe__Extension__PDF_Tickets extends Tribe__Extension {

		/**
		 * The custom field name in which to store a ticket's Unique ID.
		 *
		 * For security purposes, We generate a Unique ID to be used as part of the
		 * generated file name. We need to store it in the database for
		 * lookup purposes.
		 *
		 * @var string
		 */
		public $pdf_ticket_meta_key = '_tribe_ext_pdf_tickets_unique_id';

		/**
		 * The query argument key for the Attendee ID.
		 *
		 * @var string
		 */
		public $pdf_unique_id_query_arg_key = 'tribe_ext_pdf_tickets_unique_id';

		/**
		 * The query argument key for retrying loading an attempted PDF.
		 *
		 * @var string
		 */
		public $pdf_retry_url_query_arg_key = 'tribe_ext_pdf_tickets_retry';

		/**
		 * An array of the absolute file paths of the PDF(s) to be attached
		 * to the ticket email.
		 *
		 * One PDF attachment per attendee, even in a single order.
		 *
		 * @var array
		 */
		protected $attachments_array = array();

		/**
		 * Active attendee post type keys.
		 *
		 * Not the same as the ticket post type keys.
		 *
		 * @var array
		 */
		protected $active_attendee_post_type_keys = array();

		/**
		 * Setup the Extension's properties.
		 *
		 * This always executes even if the required plugins are not present.
		 */
		public function construct() {
			$this->add_required_plugin( 'Tribe__Tickets__Main', '4.5.2' );

			add_action( 'tribe_plugins_loaded', array( $this, 'required_tribe_classes' ), 0 );

			$this->set_url( 'https://theeventscalendar.com/extensions/pdf-tickets/' );

			/**
			 * Ideally, we would only flush rewrite rules on plugin activation and
			 * deactivation, but we cannot on activation due to the way extensions
			 * get loaded. Therefore, we flush rewrite rules a different way while
			 * plugin is activated. The deactivation hook does work inside the
			 * extension class, though.
			 *
			 * @link https://developer.wordpress.org/reference/functions/flush_rewrite_rules/#comment-597
			 */
			add_action( 'admin_init', array( $this, 'admin_flush_rewrite_rules_if_needed' ) );
			register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

			// EDD must be added here, not in $this->init() does not run early enough for these to take effect.
			// Event Tickets Plus: Easy Digital Downloads
			add_action( 'event_ticket_edd_attendee_created', array( $this, 'do_upload_pdf' ), 50, 1 );
			// Piggy-backing off Tribe__Tickets_Plus__Commerce__EDD__Email::trigger()
			add_action( 'eddtickets-send-tickets-email', array( $this, 'do_upload_pdf' ), 50, 1 );
		}

		/**
		 * Check required plugins after all Tribe plugins have loaded.
		 */
		public function required_tribe_classes() {
			if ( Tribe__Dependency::instance()->is_plugin_active( 'Tribe__Tickets_Plus__Main' ) ) {
				$this->add_required_plugin( 'Tribe__Tickets_Plus__Main', '4.5.6' );

				if ( Tribe__Dependency::instance()->is_plugin_active( 'Tribe__Events__Community__Tickets__Main' ) ) {
					$this->add_required_plugin( 'Tribe__Events__Community__Tickets__Main', '4.4.3' );
				}

			}
		}

		/**
		 * Build the array of active ticket types' post type keys.
		 *
		 * @see Tribe__Tickets__Tickets::modules()
		 * @see Tribe__Extension__PDF_Tickets::$active_attendee_post_type_keys
		 */
		private function build_active_ticket_post_type_keys() {
			$active_modules = Tribe__Tickets__Tickets::modules();

			foreach ( $active_modules as $class => $name ) {
				$this->active_attendee_post_type_keys[ $name ] = $class::ATTENDEE_OBJECT;
			}
		}

		/**
		 * Extension initialization and hooks.
		 *
		 * mPDF version 7.0+ requires PHP 5.6+ with the mbstring and gd extensions.
		 * Permalinks are required to be set in order to use this plugin. If they
		 * are not set, display an informative admin error with a link to the
		 * Permalink Settings admin screen and do not load the rest of this plugin.
		 */
		public function init() {
			// Load plugin textdomain
			load_plugin_textdomain( 'tribe-ext-pdf-tickets', false, basename( dirname( __FILE__ ) ) . '/languages/' );

			/**
			 * Protect against fatals by specifying the required minimum PHP
			 * version. Make sure to match the readme.txt header.
			 *
			 * All extensions require PHP 5.3+.
			 * 5.6: Variadic Functions, Argument Unpacking, and Constant Expressions
			 *
			 * @link https://secure.php.net/manual/en/migration56.new-features.php
			 */
			$php_required_version = '5.6';

			if ( version_compare( PHP_VERSION, $php_required_version, '<' ) ) {
				if (
					is_admin()
					&& current_user_can( 'activate_plugins' )
				) {
					$message = '<p>';

					$message .= sprintf( __( '%s requires PHP version %s or newer to work (as well as the `mbstring` and `gd` PHP extensions). Please contact your website host and inquire about updating PHP.', 'tribe-ext-pdf-tickets' ), $this->get_name(), $php_required_version );

					$message .= sprintf( ' <a href="%1$s">%1$s</a>', 'https://wordpress.org/about/requirements/' );

					$message .= '</p>';

					tribe_notice( $this->get_name(), $message, 'type=error' );
				}

				return;
			}

			$this->build_active_ticket_post_type_keys();

			$permalink_structure = get_option( 'permalink_structure' );
			if ( ! empty( $permalink_structure ) ) {
				// Event Tickets
				add_filter( 'event_tickets_attendees_table_row_actions', array( $this, 'pdf_attendee_table_row_actions' ), 0, 2 );

				add_action( 'event_tickets_orders_attendee_contents', array( $this, 'pdf_attendee_table_row_action_contents' ), 10, 1 );

				add_action( 'init', array( $this, 'create_pdf_file_creation_deletion_triggers' ), 50 );

				// Add rewrite rules
				add_action( 'init', array( $this, 'add_pdf_file_rewrite_rules' ) );
				add_action( 'query_vars', array( $this, 'add_custom_query_vars' ) );
				add_action( 'redirect_canonical', array( $this, 'make_non_trailing_slash_the_canonical' ), 10, 2 );

				// For generating a PDF on the fly
				add_action( 'template_redirect', array( $this, 'load_pdf' ) );
			} else {
				if (
					! is_admin()
					|| (
						defined( 'DOING_AJAX' )
						&& DOING_AJAX
					)
				) {
					return;
				}

				global $pagenow; // an Admin global

				$message = '<p style="font-style: italic">';

				$message .= sprintf( esc_html__( 'Permalinks must be enabled in order to use %s.', 'tribe-ext-pdf-tickets' ), $this->get_name() );

				$message .= '</p>';

				// Do not display link to Permalink Settings page when we are on it.
				if ( 'options-permalink.php' !== $pagenow ) {
					$message .= '<p>';

					$message .= sprintf( __( '<a href="%s">Change your Permalink settings</a> or deactivate this plugin.', esc_url( admin_url( 'options-permalink.php' ) ), 'tribe-ext-pdf-tickets' ) );

					$message .= '</p>';
				}

				tribe_notice( $this->get_name(), $message, 'type=error' );
			}
		}

		/**
		 * Setup the hooks needed to trigger PDF Ticket file creation and
		 * deletion when appropriate.
		 *
		 * Cannot run in $this->init() because that is too early to run
		 * tribe_get_linked_post_types().
		 */
		public function create_pdf_file_creation_deletion_triggers() {
			// do_upload_pdf() when tickets are created
			add_action( 'event_tickets_rsvp_attendee_created', array( $this, 'do_upload_pdf' ), 50, 1 );

			// Event Tickets: Tribe PayPal
			add_action( 'event_tickets_tpp_attendee_created', array( $this, 'do_upload_pdf' ), 50, 1 );

			// Event Tickets Plus: WooCommerce
			add_action( 'event_ticket_woo_attendee_created', array( $this, 'do_upload_pdf' ), 50, 1 );
			// Tagging along with Tribe__Tickets_Plus__Commerce__WooCommerce__Email::trigger(), which passes Order ID, not Attendee ID
			add_action( 'wootickets-send-tickets-email', array( $this, 'woo_order_id_do_pdf_and_email' ), 1 );

			// EDD must be added in $this->construct(), not here, so it is early enough to take effect.

			// After modifying Attendee Information (e.g. self-service), delete its PDF Ticket file so it is no longer outdated.
			add_action( 'updated_postmeta', array( $this, 'process_updated_post_meta' ), 50, 4 );

			// After modifying an Attendee, delete its PDF Ticket file so it is no longer outdated. Not sure when it might be triggered but it's here for completeness.
			foreach ( $this->active_attendee_post_type_keys as $active_attendee_post_type_keys ) {
				add_action( 'save_post_' . $active_attendee_post_type_keys, array( $this, 'process_updated_attendee' ), 50, 3 );
			}

			// After modifying an existing Event with Tickets, delete all of its PDF Tickets files so they are no longer outdated.
			$post_types_tickets_enabled = (array) Tribe__Tickets__Main::instance()->post_types();
			foreach ( $post_types_tickets_enabled as $post_type ) {
				add_action( 'save_post_' . $post_type, array( $this, 'process_updated_event' ), 50, 3 );
			}

			// Tribe Events Linked Post Types
			if ( function_exists( 'tribe_get_linked_post_types' ) ) {
				foreach ( tribe_get_linked_post_types() as $linked_post_type => $value ) {
					add_action( 'save_post_' . $linked_post_type, array( $this, 'process_updated_tribe_event_linked_post_type' ), 50, 3 );
				}
			}
		}

		/**
		 * Do the PDF upload and attach to email when triggered via the
		 * WooCommerce email action hook, which passes the Order ID, not the
		 * Attendee ID.
		 *
		 * @see Tribe__Tickets_Plus__Commerce__WooCommerce__Main::send_tickets_email()
		 * @see Tribe__Tickets_Plus__Commerce__WooCommerce__Main::get_attendees_by_id()
		 *
		 * @param int $order_id
		 */
		public function woo_order_id_do_pdf_and_email( $order_id = 0 ) {
			$order_id = absint( $order_id );

			if ( 0 < $order_id ) {
				// Runs Tribe__Tickets_Plus__Commerce__WooCommerce__Main::get_attendees_by_order_id()
				$woo_main = Tribe__Tickets_Plus__Commerce__WooCommerce__Main::get_instance();

				$attendee_ids = $woo_main->get_attendees_by_id( $order_id );
				$attendee_ids = wp_list_pluck( $attendee_ids, 'attendee_id' );

				// Now that we have the Attendee IDs, we can do the PDF to build the attachments array
				foreach ( $attendee_ids as $attendee_id ) {
					$this->do_upload_pdf( $attendee_id );
				}

				// Now that $this->$attachments_array is expected not empty, send to the WooCommerce email via Tribe__Tickets_Plus__Commerce__WooCommerce__Email::trigger()
				add_filter( 'tribe_tickets_plus_woo_email_attachments', array( $this, 'email_attach_pdf' ) );
			}
		}

		/**
		 * Get the absolute path to the WordPress uploads directory,
		 * with a trailing slash.
		 *
		 * It will return a path to where the WordPress /uploads/ directory is,
		 * whether it is in the default location or whether a constant has been
		 * defined or a filter used to specify an alternate location. The path
		 * it returns will look something like
		 * /path/to/wordpress/wp-content/uploads/
		 * regardless of the "Organize my uploads into month- and year-based
		 * folders" option in wp-admin > Settings > Media.
		 *
		 * @return string The uploads directory path.
		 */
		protected function uploads_directory_path() {
			$upload_dir = wp_upload_dir();

			$upload_dir = trailingslashit( $upload_dir['basedir'] );

			/**
			 * Filter to change the path to where PDFs will be created.
			 *
			 * This could be useful if you wanted to tack on 'pdfs/' to put them in
			 * a subdirectory of the Uploads directory.
			 *
			 * @param $upload_dir
			 */
			return apply_filters( 'tribe_ext_pdf_tickets_uploads_dir_path', $upload_dir );
		}

		/**
		 * Get the URL to the WordPress uploads directory, with a trailing slash.
		 *
		 * It will return a URL to where the WordPress /uploads/ directory is,
		 * whether it is in the default location or whether a constant has been
		 * defined or a filter used to specify an alternate location. The URL
		 * it returns will look something like
		 * http://example.com/wp-content/uploads/ regardless of the current
		 * month we are in.
		 *
		 * @return string The uploads directory URL.
		 */
		protected function uploads_directory_url() {
			$upload_dir = wp_upload_dir();

			return trailingslashit( $upload_dir['baseurl'] );
		}

		/**
		 * The text before the {unique_id}.pdf in the file name.
		 *
		 * Default is "tribe_tickets_"
		 *
		 * @var string
		 *
		 * @return string
		 */
		private function get_file_name_prefix() {
			/**
			 * Filter to change the string before the Unique ID part of the
			 * generated file name.
			 *
			 * @param $prefix
			 */
			$prefix = apply_filters( 'tribe_ext_pdf_tickets_file_name_prefix', 'tribe_tickets_' );

			return (string) $prefix;
		}

		/**
		 * Prepend file name prefix to the Unique ID.
		 *
		 * Example: tribe_tickets_abc123xyz789
		 *
		 * @param $unique_id
		 *
		 * @return string
		 */
		private function combine_prefix_and_unique_id( $unique_id ) {
			return $this->get_file_name_prefix() . $unique_id;
		}


		/**
		 * Full PDF file name on the server.
		 *
		 * Does not include leading server path or URL.
		 * Does include the .pdf file extension.
		 *
		 * @param $attendee_id Ticket Attendee ID.
		 *
		 * @return string
		 */
		protected function get_pdf_name( $attendee_id = 0 ) {
			try {
				$unique_id = $this->get_unique_id_from_attendee_id( $attendee_id );
			} catch ( Exception $e ) {
				$unique_id = '';
			}

			if ( empty( $unique_id ) ) {
				// We wouldn't expect this to happen.
				$name = '';
			} else {
				$name = $this->combine_prefix_and_unique_id( $unique_id ) . '.pdf';
			}

			return $name;
		}

		/**
		 * Get absolute path to the PDF file, including ".pdf" at the end.
		 *
		 * @param $attendee_id
		 *
		 * @return string
		 */
		public function get_pdf_path( $attendee_id ) {
			$name = $this->get_pdf_name( $attendee_id );

			if ( empty( $name ) ) {
				return false;
			} else {
				return $this->uploads_directory_path() . $name;
			}
		}

		/**
		 * Get the Unique ID for the given Attendee ID.
		 *
		 * Lookup Unique ID in the database. If it does not exist yet, generate it
		 * and save it to the database for future lookups.
		 *
		 * @param int $attendee_id
		 *
		 * @return string
		 */
		private function get_unique_id_from_attendee_id( $attendee_id ) {
			// We need to short circuit here because if we are accidentally passing a non-integer, for example, a new $unique_id will get generated, which is misleading.
			if (
				! is_int( $attendee_id )
				|| 0 === absint( $attendee_id )
			) {
				throw new Exception( 'You did not pass a valid $attendee_id to Tribe__Extension__PDF_Tickets::get_unique_id_from_attendee_id()' );
			}

			$unique_id = get_post_meta( $attendee_id, $this->pdf_ticket_meta_key, true );

			if ( empty( $unique_id ) ) {
				$unique_id = uniqid( '', true );

				// uniqid() with more_entropy results in something like '59dfc07503b009.71316471'
				$unique_id = str_replace( '.', '', $unique_id );

				/**
				 * Filter to customize the Unique ID part of the generated PDF file name.
				 *
				 * If you use this filter, you may also need to use the
				 * tribe_ext_pdf_tickets_unique_id_regex filter.
				 *
				 * @param $unique_id
				 * @param $attendee_id
				 */
				$unique_id = apply_filters( 'tribe_ext_pdf_tickets_unique_id', $unique_id, $attendee_id );

				$unique_id = sanitize_file_name( $unique_id );

				add_post_meta( $attendee_id, $this->pdf_ticket_meta_key, $unique_id, true );
			}

			return $unique_id;
		}

		/**
		 * Get the Attendee ID from the Unique ID postmeta value.
		 *
		 * @param $unique_id
		 *
		 * @return int
		 */
		private function get_attendee_id_from_unique_id( $unique_id ) {
			$args = array(
				// cannot use 'post_type' => 'any' because these post types have `exclude_from_search` set to TRUE (because `public` is FALSE)
				'post_type'      => $this->active_attendee_post_type_keys,
				'nopaging'       => true,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => $this->pdf_ticket_meta_key,
						'value' => $unique_id,
					)
				)
			);

			$attendee_id_array = get_posts( $args );

			if ( empty( $attendee_id_array[0] ) ) {
				$attendee_id = 0;
			} else {
				$attendee_id = $attendee_id_array[0];
			}

			return $attendee_id;
		}

		/**
		 * Get the true full URL to the PDF file, including ".pdf" at the end.
		 *
		 * Example result: http://example.com/wp-content/uploads/tribe_tickets_{unique_id}.pdf
		 * Not used internally but may be useful when extending this plugin.
		 *
		 * @param int $attendee_id
		 *
		 * @return string
		 */
		public function get_direct_pdf_url( $attendee_id = 0 ) {
			$name = $this->get_pdf_name( $attendee_id );

			if ( empty( $name ) ) {
				return false;
			} else {
				$file_url = $this->uploads_directory_url() . $name;
				return esc_url( $file_url );
			}
		}

		/**
		 * The URL rewrite base for the file download.
		 *
		 * Example: tickets_download
		 *
		 * @return string
		 */
		private function get_download_base_slug() {
			$tickets_bases = Tribe__Tickets__Tickets_View::instance()->add_rewrite_base_slug();

			$base = sprintf( '%s_%s',
				sanitize_title_with_dashes( $tickets_bases['tickets'][0] ),
				sanitize_key( _x( 'download', 'The URL rewrite base for a PDF Ticket file download', 'tribe-ext-pdf-tickets' ) )
			);

			return $base;
		}

		/**
		 * Get the public-facing URL to the PDF file.
		 *
		 * Example: http://example.com/tickets_download/{unique_id}
		 *
		 * @param $attendee_id
		 *
		 * @return string
		 */
		public function get_pdf_link( $attendee_id ) {
			try {
				$unique_id = $this->get_unique_id_from_attendee_id( $attendee_id );
			} catch ( Exception $e ) {
				$unique_id = '';
			}

			if ( empty( $unique_id ) ) {
				// We wouldn't expect this to happen.
				$url = '';
			} else {
				$url = home_url( '/' ) . $this->get_download_base_slug();

				$url = trailingslashit( $url ) . $unique_id;
			}

			return esc_url( $url );
		}

		/**
		 * The regex to determine if a string is in the proper format to be a
		 * Unique ID in the context of this extension.
		 *
		 * @return string
		 */
		protected function get_unique_id_regex() {
			/**
			 * Filter to adapt the regex for matching Unique ID.
			 *
			 * Use in conjunction with the tribe_ext_pdf_tickets_unique_id filter.
			 *
			 * @param $regex_pattern
			 */
			$unique_id_regex = apply_filters( 'tribe_ext_pdf_tickets_unique_id_regex', '[a-z0-9]{1,}' );

			return (string) $unique_id_regex;
		}

		/**
		 * Regex for the file download rewrite rule.
		 *
		 * example.com/tickets_download/{unique_id} (without trailing slash)
		 *
		 * @return string
		 */
		protected function get_file_rewrite_regex() {
			// required by $this->get_download_base_slug()
			if ( ! class_exists( 'Tribe__Tickets__Tickets_View' ) ) {
				return '';
			}

			$regex_for_file = sprintf( '^%s/(%s)[/]?$', $this->get_download_base_slug(), $this->get_unique_id_regex() );

			return $regex_for_file;
		}

		/**
		 * Add the needed WordPress rewrite rules.
		 *
		 * example.com/tickets_download/{unique_id} (without trailing slash) goes
		 * to the PDF file, and
		 * example.com/tickets_download/ (with or without trailing slash) goes to
		 * the site's homepage for the sake of search engines or curious users
		 * exploring hackable URLs.
		 */
		public function add_pdf_file_rewrite_rules() {
			$query_for_file = sprintf( 'index.php?%s=$matches[1]', $this->pdf_unique_id_query_arg_key );

			add_rewrite_rule( $this->get_file_rewrite_regex(), $query_for_file, 'top' );

			// example.com/tickets_download/ (optional trailing slash) to home page
			add_rewrite_rule( '^' . $this->get_download_base_slug() . '[/]?$', 'index.php', 'top' );
		}

		/**
		 * Add the needed WordPress query variable to get the Unique ID.
		 *
		 * @param $vars
		 *
		 * @return array
		 */
		public function add_custom_query_vars( $vars ) {
			$vars[] = $this->pdf_unique_id_query_arg_key;

			return $vars;
		}

		/**
		 * Disable WordPress trying to add a trailing slash to our PDF file URLs.
		 *
		 * Example: http://example.com/tickets_download/{unique_id}
		 * Without the leading ^ because we are comparing against the full URL,
		 * not creating a rewrite rule. Without the ending $ because we might have a
		 * URL query string.
		 *
		 * @param $redirect_url  The URL with a trailing slash added (in most
		 *                       setups).
		 * @param $requested_url Our unmodified URL--without a trailing slash.
		 *
		 * @return bool|string
		 */
		public function make_non_trailing_slash_the_canonical( $redirect_url, $requested_url ) {
			$pattern_wo_slash = sprintf( '/\/%s\/(%s)/', $this->get_download_base_slug(), $this->get_unique_id_regex() );

			if ( preg_match( $pattern_wo_slash, $requested_url ) ) {
				return false;
			}

			return $redirect_url;
		}


		/**
		 * Ideally, we would only flush rewrite rules on plugin activation, but we
		 * cannot use register_activation_hook() due to the timing of when
		 * extensions load. Therefore, we flush rewrite rules on every visit to the
		 * wp-admin Plugins screen (where we'd expect you to be if you just
		 * activated a plugin)... only if our rewrite rule is not already in the
		 * rewrite rules array.
		 */
		public function admin_flush_rewrite_rules_if_needed() {
			global $pagenow;

			if ( 'plugins.php' !== $pagenow ) {
				return;
			}

			$rewrite_rules = get_option( 'rewrite_rules' );

			if ( empty( $rewrite_rules ) ) {
				return;
			}

			$file_rewrite_regex = $this->get_file_rewrite_regex();
			if (
				! empty( $file_rewrite_regex )
				&& ! array_key_exists( $file_rewrite_regex, $rewrite_rules )
			) {
				$this->add_pdf_file_rewrite_rules();

				flush_rewrite_rules();
			}
		}

		/**
		 * Determine an attendee's ticket type's class name.
		 *
		 * @return string
		 */
		public function get_attendee_ticket_type_class( $attendee_id = 0 ) {
			$ticket_instance = tribe_tickets_get_ticket_provider( $attendee_id );

			if ( is_object( $ticket_instance ) ) {
				return $ticket_instance->className;
			} else {
				return '';
			}
		}

		/**
		 * Create PDF, save to server, and add to email queue.
		 *
		 * @param      $attendee_id ID of attendee ticket.
		 * @param bool $email       Add PDF to email attachments array.
		 *
		 * @return bool
		 */
		public function do_upload_pdf( $attendee_id, $email = true ) {
			$successful = false;

			$ticket_instance = tribe_tickets_get_ticket_provider( $attendee_id );

			$ticket_class = $this->get_attendee_ticket_type_class( $attendee_id );

			if ( empty( $ticket_class ) ) {
				return $successful;
			}

			// should only be one result
			$event_ids = tribe_tickets_get_event_ids( $attendee_id );

			if ( ! empty( $event_ids ) ) {
				$event_id  = $event_ids[0];
				$attendees = $ticket_instance->get_attendees_array( $event_id );
			}

			if (
				empty( $attendees )
				|| ! is_array( $attendees )
			) {
				return $successful;
			}

			$attendees_array = array();

			foreach ( $attendees as $attendee ) {
				if ( $attendee['attendee_id'] == $attendee_id ) {
					$attendees_array[] = $attendee;
				}
			}

			if ( empty( $attendees_array ) ) {
				return $successful;
			}

			/**
			 * Because $html is the full HTML DOM sent to the PDF generator, adding
			 * anything to the beginning or the end would likely cause problems.
			 *
			 * If you want to alter what gets sent to the PDF generator, follow the
			 * Themer's Guide for tickets/email.php or use that template file's
			 * existing hooks.
			 *
			 * @link https://theeventscalendar.com/knowledgebase/themers-guide/#tickets
			 */
			$html = $ticket_instance->generate_tickets_email_content( $attendees_array );

			if ( empty( $html ) ) {
				return $successful;
			}

			$file_name = $this->get_pdf_path( $attendee_id );

			if ( empty( $file_name ) ) {
				return $successful;
			}

			if ( file_exists( $file_name ) ) {
				$successful = true;
			} else {
				$successful = $this->output_pdf( $html, $file_name );
			}

			/**
			 * Action hook after the PDF Ticket file gets created.
			 *
			 * Might be useful if you want the PDF Ticket file added to the
			 * Media Library via wp_insert_attachment(), for example.
			 *
			 * @param $ticket_class
			 * @param $event_id
			 * @param $attendee_id
			 * @param $file_name
			 */
			do_action( 'tribe_ext_pdf_tickets_uploaded_pdf', $ticket_class, $event_id, $attendee_id, $file_name );

			/**
			 * Filter to disable PDF email attachments, either entirely (just pass
			 * false) or per event, attendee, ticket type, or some other logic.
			 *
			 * @param $email
			 * @param $ticket_class
			 * @param $event_id
			 * @param $attendee_id
			 * @param $file_name
			 */
			$email = (bool) apply_filters( 'tribe_ext_pdf_tickets_attach_to_email', $email, $ticket_class, $event_id, $attendee_id, $file_name );

			if (
				true === $successful
				&& true === $email
			) {
				$this->attachments_array[] = $file_name;

				if ( 'Tribe__Tickets__RSVP' === $ticket_class ) {
					add_filter( 'tribe_rsvp_email_attachments', array( $this, 'email_attach_pdf' ) );
				} elseif ( 'Tribe__Tickets__Commerce__PayPal__Main' === $ticket_class ) {
					add_filter( 'tribe_tpp_email_attachments', array( $this, 'email_attach_pdf', ) );
				} elseif ( 'Tribe__Tickets_Plus__Commerce__WooCommerce__Main' === $ticket_class ) {
					add_filter( 'tribe_tickets_plus_woo_email_attachments', array( $this, 'email_attach_pdf' ) );
				} elseif ( 'Tribe__Tickets_Plus__Commerce__EDD__Main' === $ticket_class ) {
					add_filter( 'edd_ticket_receipt_attachments', array( $this, 'email_attach_pdf' ) );
				} else {
					// unknown ticket type so no emailing to do

					/**
					 * Action hook that fires during email attaching but only for
					 * unknown ticket types.
					 *
					 * @param $ticket_class
					 * @param $event_id
					 * @param $attendee_id
					 * @param $file_name
					 */
					do_action( 'tribe_ext_pdf_tickets_uploaded_to_email_unknown_ticket_type', $ticket_class, $event_id, $attendee_id, $file_name );
				}
			}

			return $successful;
		}

		/**
		 * Find all the PDF files in the Uploads directory that match our naming
		 * convention.
		 *
		 * Used to iterate over all the files, such as deleting all. Note that
		 * sorting is not applied.
		 *
		 * @since 1.1.0
		 *
		 * @link https://secure.php.net/manual/class.directoryiterator.php
		 * @link https://secure.php.net/manual/function.glob.php Running with GLOB_NOSORT has comparable speed but is not as flexible.
		 *
		 * @see Tribe__Extension__PDF_Tickets::uploads_directory_path()
		 *
		 * @return array
		 */
		public function find_all_pdf_ticket_files() {
			$uploads_dir = $this->uploads_directory_path();

			$file_name_prefix        = $this->get_file_name_prefix();
			$file_name_prefix_length = strlen( $file_name_prefix );

			$found_files = array();

			foreach ( new DirectoryIterator( $uploads_dir ) as $file_info ) {
				if (
					$file_info->isFile()
					&& 'pdf' === strtolower( $file_info->getExtension() )
					&& $file_name_prefix === substr( $file_info->getFilename(), 0, $file_name_prefix_length )
				) {
					$found_files[] = $uploads_dir . $file_info->getFilename();
				}
			}

			return $found_files;
		}

		/**
		 * Delete ALL of the PDF Ticket files from the upload directory.
		 *
		 * After trying to delete all found files, returns TRUE if there are no more
		 * found files, else FALSE (i.e. one or more files matching the pattern
		 * still exists).
		 *
		 * @since 1.1.0
		 *
		 * @link https://secure.php.net/manual/function.clearstatcache.php unlink() clears the file status cache automatically.
		 * @link https://secure.php.net/manual/function.unlink.php
		 *
		 * @see Tribe__Extension__PDF_Tickets::find_all_pdf_ticket_files()
		 *
		 * @return bool
		 */
		public function delete_all_pdf_tickets() {
			$found_files = $this->find_all_pdf_ticket_files();

			/**
			 * Action hook that fires before running Delete All PDF Ticket files.
			 *
			 * May be useful if you want to do your own iteration before deletion
			 * happens, such as moving to a different directory (backup) or
			 * renaming both of which would protect the files from being deleted.
			 *
			 * @since 1.1.0
			 *
			 * @param array $found_files
			 * @param string $match_pattern
			 */
			do_action( 'tribe_ext_pdf_tickets_before_delete_all_pdf_ticket_files', $found_files );

			foreach ( $found_files as $file ) {
				if ( ! @unlink( $file ) ) {
					throw new Exception( sprintf( '%s: Unable to delete file: %s', $this->get_name(), $file ) );
				}
			}

			$found_files = $this->find_all_pdf_ticket_files();

			return empty( $found_files );
		}

		/**
		 * Delete all an event's PDF Ticket files from the server.
		 *
		 * TRUE if the event does not have any tickets, does not have any attendees,
		 * or all the PDFs just got successfully deleted. Otherwise, FALSE (i.e. one
		 * or more PDF Ticket files for this event still exist on the server).
		 *
		 * @since 1.1.0
		 *
		 * @param int $event_id Post ID of a post type that has tickets enabled.
		 *
		 * @return bool
		 */
		public function delete_all_tickets_for_event( $event_id = 0 ) {
			$sucessful = true;

			if ( tribe_events_has_tickets( $event_id ) ) {
				$attendees = (array) tribe_tickets_get_attendees( $event_id );

				$attendee_ids = wp_list_pluck( $attendees, 'attendee_id' );

				if ( 0 < count( $attendee_ids ) ) {
					$success_array = array();

					foreach ( $attendee_ids as $attendee_id ) {
						$success_array[] = $this->delete_single_pdf_ticket( $attendee_id );
					}

					$sucessful = ! in_array( false, $success_array );
				}
			}

			return $sucessful;
		}

		/**
		 * Delete a single attendee's PDF Ticket file from the server.
		 *
		 * If, at the end of this method's logic, the file does not exist (either
		 * because it did not before or because it just got deleted), then return
		 * TRUE, else FALSE (i.e. it still does exist).
		 *
		 * @since 1.1.0
		 *
		 * @param int $attendee_id
		 *
		 * @return bool
		 */
		public function delete_single_pdf_ticket( $attendee_id = 0 ) {
			$file_name = $this->get_pdf_path( $attendee_id );

			if ( file_exists( $file_name ) ) {
				unlink( $file_name );
			}

			$result = ! file_exists( $file_name );

			/**
			 * Action fired after attempting to delete a single PDF Ticket file.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $result Whether or not the PDF Ticket file was
			 *                          successfully deleted.
			 * @param int $attendee_id
			 */
			do_action( 'tribe_ext_pdf_tickets_after_delete_single_pdf_ticket', $result, $attendee_id );

			return $result;
		}

		/**
		 * Upon updating an Attendee, delete its PDF Ticket file.
		 *
		 * We do not regenerate the PDF Ticket file because that will happen
		 * automatically if/when each PDF Ticket link is clicked in the future.
		 *
		 * @since 1.1.0
		 *
		 * @link https://developer.wordpress.org/reference/hooks/updated_postmeta/
		 *
		 * @see Tribe__Tickets_Plus__Meta::META_KEY
		 *
		 * @param int $meta_id
		 * @param int $object_id
		 * @param string $meta_key
		 * @param mixed $meta_value
		 *
		 * @return bool
		 */
		public function process_updated_post_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
			if (
				Tribe__Tickets_Plus__Meta::META_KEY !== $meta_key
			) {
				return false;
			}

			/**
			 * Filter to control whether or not an Attendee's PDF Ticket file
			 * gets deleted automatically upon the Attendee Information being
			 * updated.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $bail Set to TRUE to avoid deleting this Attendee's
			 *                   PDF Ticket file.
			 * @param int $meta_id
			 * @param int $object_id
			 * @param string $meta_key
			 * @param mixed $meta_value
			 */
			$bail = apply_filters( 'tribe_ext_pdf_tickets_process_updated_post_meta', false, $meta_id, $object_id, $meta_key, $meta_value );

			if ( true === $bail ) {
				return false;
			} else {
				return $this->delete_single_pdf_ticket( $object_id );
			}
		}

		/**
		 * Upon updating an Attendee, delete its PDF Ticket file.
		 *
		 * We do not regenerate the PDF Ticket file because that will happen
		 * automatically if/when each PDF Ticket link is clicked in the future.
		 *
		 * @since 1.1.0
		 *
		 * @link https://developer.wordpress.org/reference/hooks/save_post_post-post_type/
		 *
		 * @param int $attendee_id
		 * @param WP_Post $post
		 * @param bool $update
		 *
		 * @return bool
		 */
		public function process_updated_attendee( $attendee_id, $post, $update ) {
			$is_autosave = ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ? true : false;
			$is_revision = wp_is_post_revision( $attendee_id );

			if (
				empty( $update )
				|| $is_autosave
				|| $is_revision
			) {
				return false;
			}

			/**
			 * Filter to control whether or not an Attendee's PDF Ticket file gets
			 * deleted automatically upon being updated.
			 *
			 * Useful if you want to prevent the overhead of deleting a file, such
			 * as only wanting to delete if a certain piece of information changes.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $bail Set to TRUE to avoid deleting this Attendee's
			 *                   PDF Ticket file.
			 * @param int $attendee_id
			 * @param WP_Post $post
			 * @param bool $update
			 */
			$bail = apply_filters( 'tribe_ext_pdf_tickets_process_updated_attendee', false, $attendee_id, $post, $update );

			if ( true === $bail ) {
				return false;
			} else {
				return $this->delete_single_pdf_ticket( $attendee_id );
			}
		}

		/**
		 * Upon updating an Event (any post type with tickets), delete all of its
		 * PDF Ticket files so they are not outdated.
		 *
		 * We do not regenerate the PDF Ticket files because that will happen
		 * automatically if/when each PDF Ticket link is clicked in the future.
		 *
		 * @since 1.1.0
		 *
		 * @link https://developer.wordpress.org/reference/hooks/save_post_post-post_type/
		 *
		 * @param int $event_id Applies to all Post Types, not just Tribe Events.
		 * @param WP_Post $post
		 * @param bool $update
		 *
		 * @return bool
		 */
		public function process_updated_event( $event_id, $post, $update ) {
			$is_autosave = ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ? true : false;
			$is_revision = wp_is_post_revision( $event_id );

			if (
				empty( $update )
				|| $is_autosave
				|| $is_revision
			) {
				return false;
			}

			/**
			 * Filter to control whether or not an Event's PDF Ticket files get
			 * deleted automatically when the event (any post type) is updated.
			 *
			 * Useful if you want to prevent the overhead of deleting files,
			 * such as only wanting to delete if a specific piece of information
			 * (that is not displayed in your email template) got changed.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $bail Set to TRUE to avoid deleting this Attendee's
			 *                   PDF Ticket file.
			 * @param int $event_id
			 * @param WP_Post $post
			 * @param bool $update
			 */
			$bail = apply_filters( 'tribe_ext_pdf_tickets_process_updated_event', false, $event_id, $post, $update );

			if ( true === $bail ) {
				return false;
			} else {
				return $this->delete_all_tickets_for_event( $event_id );
			}
		}

		/**
		 * Upon updating a Tribe Event's Linked Post Type (e.g. Organizers,
		 * Venues), delete all of its attached Event Post Type's PDF Ticket
		 * files so they are not outdated.
		 *
		 * We do not regenerate the PDF Ticket files because that will happen
		 * automatically if/when each PDF Ticket link is clicked in the future.
		 *
		 * @since 1.1.0
		 *
		 * @link https://developer.wordpress.org/reference/hooks/save_post_post-post_type/
		 *
		 * @param int $linked_post_type_post_id Applies to all of Tribe Events'
		 *                                      Linked Post Types.
		 * @param WP_Post $post
		 * @param bool $update
		 *
		 * @return bool
		 */
		public function process_updated_tribe_event_linked_post_type( $linked_post_type_post_id, $post, $update ) {
			$is_autosave = ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ? true : false;
			$is_revision = wp_is_post_revision( $linked_post_type_post_id );

			if (
				empty( $update )
				|| $is_autosave
				|| $is_revision
				|| ! function_exists( 'tribe_get_linked_posts_by_post_type' )
			) {
				return false;
			}

			$linked_post_type = $post->post_type;

			$linked_events = tribe_get_linked_posts_by_post_type( $linked_post_type_post_id, Tribe__Events__Main::POSTTYPE );

			$event_ids = wp_list_pluck( $linked_events, 'ID' );

			$success_array = array();

			foreach ( $event_ids as $event_id ) {
				/**
				 * Filter to control whether or not an Event's PDF Ticket files
				 * get deleted automatically when any of a Tribe Event's Linked
				 * Post Type is updated.
				 *
				 * Useful if you want to prevent the overhead of deleting files, such
				 * as only wanting to delete if a specific piece of information got
				 * changed. For example, if your email HTML does not include the Venue
				 * information, you may not want to delete all the PDFs if only the
				 * Venue changed.
				 *
				 * @since 1.1.0
				 *
				 * @param bool $bail Set to TRUE to avoid deleting this Attendee's
				 *                   PDF Ticket file.
				 * @param string $linked_post_type Post Type (e.g. tribe_venue)
				 * @param int $linked_post_type_post_id
				 * @param int $event_id Tribe Event post type ID
				 * @param WP_Post $post
				 * @param bool $update
				 */
				$bail = apply_filters( 'tribe_ext_pdf_tickets_process_updated_event', false, $linked_post_type, $linked_post_type_post_id, $event_id, $post, $update );

				if ( true === $bail ) {
					$success_array[] = false;
				} else {
					$success_array[] = $this->delete_all_tickets_for_event( $event_id );
				}
			}

			$sucessful = ! in_array( false, $success_array );

			return $sucessful;
		}

		/**
		 * Attach the queued PDF(s) to the ticket email.
		 *
		 * RSVP, Tribe PayPal, WooCommerce, and EDD filters all just pass an
		 * attachments array so we can get away with a single, simple function here.
		 *
		 * @param $attachments
		 *
		 * @return array
		 */
		public function email_attach_pdf( $attachments ) {
			$attachments = array_merge( $attachments, $this->attachments_array );

			// just a sanity check
			$attachments = array_unique( $attachments );

			return $attachments;
		}

		/**
		 * Create the HTML link markup (a href) for a PDF Ticket file.
		 *
		 * @param $attendee_id
		 *
		 * @return string
		 */
		public function ticket_link( $attendee_id ) {
			$text = _x( 'PDF Ticket', 'The anchor text for a PDF Ticket link', 'tribe-ext-pdf-tickets' );

			/**
			 * Customize the ticket link's anchor text, such as to add the
			 * Attendee ID to the anchor text.
			 *
			 * @since 1.1.0
			 *
			 * @param $anchor_text
			 * @param $attendee_id
			 */
			$text = apply_filters( 'tribe_ext_pdf_tickets_link_anchor_text', $text, $attendee_id );

			$url = esc_url( $this->get_pdf_link( $attendee_id ) );

			if ( empty( $url ) ) {
				return '';
			}

			$output = sprintf(
				'<a href="%s"',
				$url
			);

			/**
			 * Control the link target for Attendees Report links.
			 *
			 * @param $target
			 */
			$target = apply_filters( 'tribe_ext_pdf_tickets_link_target', '_blank' );

			if ( ! empty( $target ) ) {
				$output .= sprintf( ' target="%s"',
					esc_attr( $target )
				);
			}

			$output .= sprintf(
				' class="tribe-ext-pdf-ticket-link">%s</a>',
				esc_html( $text )
			);

			return $output;
		}

		/**
		 * Add link to the PDF ticket to the front-end "View your Tickets" page.
		 *
		 * @see Tribe__Extension__PDF_Tickets::ticket_link()
		 *
		 * @param array $attendee The attendee record.
		 */
		public function pdf_attendee_table_row_action_contents( $attendee ) {
			if ( $this->attendee_allowed_to_and_expected_to_attend( $attendee['attendee_id'] ) ) {
				echo $this->ticket_link( $attendee['attendee_id'] );
			}
		}

		/**
		 * Determine if an attendee's ticket is in a status that is allowed
		 * to attend (e.g. paid) and expected to attend (e.g. not voided).
		 *
		 * Used to determine if the PDF Ticket link should appear alongside the
		 * Attendee record in the "View your RSVPs and Tickets" view.
		 *
		 * @since 1.1.0
		 *
		 * @return bool
		 */
		private function attendee_allowed_to_and_expected_to_attend( $attendee_id = 0 ) {
			$result = false;

			$ticket_class = $this->get_attendee_ticket_type_class( $attendee_id );

			if ( ! empty( $ticket_class ) ) {
				// Logic found in Tribe__Tickets_Plus__QR::admin_notice()
				$ticket_post_status = get_post_status( $attendee_id );
				if (
					! empty( $ticket_post_status )
					&& 'trash' !== $ticket_post_status
				) {
					// Each ticket type has its own logic
					if ( 'Tribe__Tickets__RSVP' === $ticket_class ) {
						// Get RSVP options, which are filterable
						$all_rsvp_statuses = Tribe__Tickets__Tickets_View::instance()->get_rsvp_options( null, false );

						$yes_statuses = array();

						foreach ( $all_rsvp_statuses as $key => $value ) {
							if ( ! isset( $value['decrease_stock_by'] ) ) {
								// If omitted, default value is 1
								$yes_statuses[] = $key;
							} elseif ( 0 < absint( $value['decrease_stock_by'] ) ) {
								$yes_statuses[] = $key;
							}
						}

						$status = get_post_meta( $attendee_id, Tribe__Tickets__RSVP::ATTENDEE_RSVP_KEY, true );

						if ( in_array( $status, $yes_statuses ) ) {
							$result = true;
						}
					} elseif ( 'Tribe__Tickets__Commerce__PayPal__Main' === $ticket_class ) {
						$attendee_array = Tribe__Tickets__Commerce__PayPal__Main::get_instance()->get_attendees_by_id( $attendee_id );

						if ( ! empty( $attendee_array[0] ) ) {
							$order_status = new Tribe__Tickets__Commerce__PayPal__Orders__Sales();

							$result = $order_status->is_order_completed( $attendee_array[0] );
						} else {
							$result = false;
						}
					} elseif ( 'Tribe__Tickets_Plus__Commerce__WooCommerce__Main' === $ticket_class ) {
						// Same logic as from Tribe__Tickets_Plus__Commerce__WooCommerce__Main::generate_tickets()
						$woo_settings = new Tribe__Tickets_Plus__Commerce__WooCommerce__Settings();

						$woo_default_dispatch_statuses = $woo_settings->get_default_ticket_generation_statuses();

						$statuses_when_tickets_emailed = (array) tribe_get_option( 'tickets-woo-dispatch-status', $woo_default_dispatch_statuses );

						$order_id = get_post_meta( $attendee_id, Tribe__Tickets_Plus__Commerce__WooCommerce__Main::get_instance()->attendee_order_key, true );

						$order_object = wc_get_order( $order_id );

						// WC_Abstract_Order::get_status() returns the order statuses without the "wc-" internal prefix, but $statuses_when_tickets_emailed includes it.
						$order_status = 'wc-' . $order_object->get_status();

						if ( in_array( $order_status, $statuses_when_tickets_emailed ) ) {
							$result = true;
						}
					} elseif ( 'Tribe__Tickets_Plus__Commerce__EDD__Main' === $ticket_class ) {
						$edd_payment_id = get_post_meta( $attendee_id, Tribe__Tickets_Plus__Commerce__EDD__Main::get_instance()->attendee_order_key, true );

						// Logic is different from Tribe__Tickets_Plus__Commerce__EDD__Stock_Control::get_valid_payment_statuses()
						if ( edd_is_payment_complete( $edd_payment_id ) ) {
							$result = true;
						}
					} else {
						// Unknown ticket type so do nothing.
					}
				}
			}

			/**
			 * Determine if an attendee's ticket is in a status that is allowed
			 * to attend (e.g. paid) and expected to attend (e.g. not voided).
			 *
			 * @param bool $result
			 * @param int $attendee_id
			 * @param string $ticket_class
			 *
			 * @return bool
			 */
			$result = apply_filters( 'tribe_ext_pdf_tickets_attendee_allowed_to_and_expected_to_attend', $result, $attendee_id, $ticket_class );

			return (bool) $result;
		}

		/**
		 * Add a link to each ticket's PDF ticket on the wp-admin Attendee List.
		 *
		 * Community Events Tickets' Attendee List/Table comes from the same
		 * source as the wp-admin one so no extra work to get it working there.
		 *
		 * @see Tribe__Extension__PDF_Tickets::ticket_link()
		 */
		public function pdf_attendee_table_row_actions( $row_actions, $item ) {
			$row_actions[] = $this->ticket_link( $item['attendee_id'] );

			return $row_actions;
		}

		/**
		 * Outputs PDF.
		 *
		 * @see  Tribe__Extension__PDF_Tickets::get_mpdf()
		 * @see  mPDF::Output()
		 *
		 * @link https://mpdf.github.io/reference/mpdf-functions/output.html
		 *
		 * @param string $html HTML content to be turned into PDF.
		 * @param string $file_name Full file name, including path on server.
		 *                          The name of the file. If not specified, the
		 *                          document will be sent to the browser
		 *                          (destination I).
		 *                          BLANK or omitted: "doc.pdf"
		 * @param string $dest I: send the file inline to the browser. The
		 *                     plug-in is used if available.
		 *                     The name given by $filename is used when one
		 *                     selects the "Save as" option on the link
		 *                     generating the PDF.
		 *                     D: send to the browser and force a file
		 *                     download with the name given by $filename.
		 *                     F: save to a local file with the name given by
		 *                     $filename (may include a path).
		 *                     S: return the document as a string.
		 *                     $filename is ignored.
		 *
		 * @return bool
		 */
		protected function output_pdf( $html, $file_name, $dest = 'F' ) {
			if ( empty( $file_name ) ) {
				return false;
			}

			/**
			 * Empty the output buffer to ensure the website page's HTML is not
			 * included by accident.
			 *
			 * @link https://mpdf.github.io/what-else-can-i-do/capture-html-output.html
			 * @link https://stackoverflow.com/a/35574170/893907
			 */
			ob_clean();

			$mpdf = $this->get_mpdf( $html );

			if ( ! empty( $mpdf ) ) {
				$mpdf->Output( $file_name, $dest );

				return true;
			} else {
				return false;
			}
		}

		/**
		 * Converts HTML to mPDF object.
		 *
		 * Will return an empty object if mPDF throws an exception.
		 *
		 * @see mPDF::WriteHTML()
		 *
		 * @param string $html The full HTML you want converted to a PDF.
		 *
		 * @return mPDF|stdClass|object
		 */
		protected function get_mpdf( $html ) {
			require_once( __DIR__ . '/vendor/autoload.php' );

			// to avoid this fatal error: https://github.com/mpdf/mpdf/issues/524
			$html = str_ireplace( ' !important', '', $html );

			$mpdf_args = array(
				// Use only core system fonts. If you change this, such as to blank, you will need to add the missing "vendor/mpdf/**/ttfonts" directory, which got excluded via Composer.
				'mode'   => 'c',
				// Default is A4
				'format' => 'LETTER',
			);

			/**
			 * Filter the arguments with which to run mPDF.
			 *
			 * Reference vendor/mpdf/config.php, especially since it may not match
			 * the documentation.
			 *
			 * @since 1.1.0
			 *
			 * @link https://mpdf.github.io/reference/mpdf-variables/overview.html An outdated reference.
			 *
			 * @param array $mpdf_args
			 */
			$mpdf_args = apply_filters( 'tribe_ext_pdf_tickets_mpdf_args', $mpdf_args );

			/**
			 * Creating and setting the PDF
			 *
			 * Reference vendor/mpdf/config.php, especially since it may not
			 * match the documentation.
			 * 'c' mode sets the mPDF Mode to use onlyCoreFonts so that we do not
			 * need to include any fonts (like the dejavu... ones) in
			 * vendor/mpdf/mpdf/ttfonts
			 * Therefore, this entire ttfonts directory is non-existent in the .zip
			 * build via Composer, which changes saves over 90 MB disk space
			 * unzipped and the .zip itself goes from over 40 MB to under 3 MB.
			 *
			 * @link https://mpdf.github.io/reference/mpdf-variables/overview.html
			 * @link https://github.com/mpdf/mpdf/pull/490
			 */
			try {
				$mpdf = new \Mpdf\Mpdf( $mpdf_args );
				$mpdf->WriteHTML( $html );
			} catch ( Exception $e ) {
				// an empty Object
				$mpdf = new stdClass();
			}

			return $mpdf;
		}

		/**
		 * Tell WordPress to 404 instead of continuing loading the template it would
		 * otherwise load, such as matching lower-priority rewrite rule matches
		 * (e.g. page or attachment).
		 */
		private function force_404() {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}

		/**
		 * Create and upload a 404'd PDF Ticket, then redirect to it now that
		 * it exists.
		 *
		 * If we attempted to load a PDF Ticket but it was not found (404), then
		 * create the PDF Ticket, upload it to the server, and reload the attempted
		 * URL, adding a query string on the end as a cache buster and so the
		 * 307 Temporary Redirect code is technically valid.
		 *
		 * @see Tribe__Extension__PDF_Tickets::do_upload_pdf()
		 */
		public function load_pdf() {
			// Must use get_query_var() because of working with WordPress' internal (private) rewrites, and tribe_get_request_var() can only get the $_GET superglobal.
			$unique_id = get_query_var( $this->pdf_unique_id_query_arg_key );

			if ( empty( $unique_id ) ) {
				// do not force 404 at this point
				return;
			}

			$attendee_id = $this->get_attendee_id_from_unique_id( $unique_id );

			if ( empty( $attendee_id ) ) {
				$this->force_404();

				return;
			} else {
				// if we have an Attendee ID but the URL ends with a backslash (wouldn't happen if we already redirected to create and retry), then redirect to version without trailing slash (for canonical purposes). Does not intercept if manually adding an unexpected query var but that's not a worry since this is already unlikely and just for canonical purposes.
				if ( '/' === substr( $_SERVER['REQUEST_URI'], -1, 1 ) ) {
					$url = rtrim( $_SERVER['REQUEST_URI'], '/' );

					wp_redirect( esc_url_raw( $url ), 301 ); // Moved Permanently
					exit;
				}
			}

			$file_name = $this->get_pdf_path( $attendee_id );

			if ( empty( $file_name ) ) {
				$this->force_404();

				return;
			}

			if ( file_exists( $file_name ) ) {
				header( 'Content-Type: application/pdf', true );

				header( "X-Robots-Tag: none", true );

				// inline tells the browser to display, not download, but some browsers (or browser settings) will always force downloading
				$disposition = sprintf( 'Content-Disposition: inline; filename="%s"', $this->get_pdf_name( $attendee_id ) );
				header( $disposition, true );

				// Optional but enables keeping track of the download progress and detecting if the download was interrupted
				header( 'Content-Length: ' . filesize( $file_name ), true );

				readfile( $file_name );
				exit;
			}


			// only retry once
			$retry_query_var = get_query_var( $this->pdf_retry_url_query_arg_key );
			if ( ! empty( $retry_query_var ) ) {
				$this->force_404();

				return;
			} else {
				$created_pdf = $this->do_upload_pdf( $attendee_id, false );

				if ( false === $created_pdf ) {
					$this->force_404();

					return;
				} else {
					/**
					 * Redirect to retrying reloading the PDF.
					 *
					 * Cache buster and technically a new URL so status code 307
					 * Temporary Redirect applies.
					 *
					 * @link https://en.wikipedia.org/wiki/List_of_HTTP_status_codes#3xx_Redirection
					 */
					$url = add_query_arg( $this->pdf_retry_url_query_arg_key, time(), $this->get_pdf_link( $attendee_id ) );

					wp_redirect( esc_url_raw( $url ), 307 );

					exit;
				}
			}
		}

	} // end class
} // end if class_exists check