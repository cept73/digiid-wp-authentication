<?php
/**
 * @package Digi-ID Authentication
 * @author Taranov Sergey (Cept)
 * @version 1.0.13
 */
/*
Plugin Name: Digi-ID Authentication
Description: Digi-ID Authentication, extends WordPress default authentication with the Digi-ID protocol
Version: 1.0.13
Author: Taranov Sergey (Cept), digicontributor
Author URI: http://github.com/cept73
*/

namespace DigiIdAuthentication;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
DEFINE("DIGIID_AUTHENTICATION_PLUGIN_VERSION", '1.0.13');


	require_once ('required_classes.php');

	register_activation_hook( __FILE__, '\DigiIdAuthentication\digiid_install' );

	add_action( 'admin_init', '\DigiIdAuthentication\digiid_dashboard_admin_access' );
	add_action( 'plugins_loaded', '\DigiIdAuthentication\digiid_update_db_check' );
	add_action( 'plugins_loaded', '\DigiIdAuthentication\digiid_load_translation' );
	add_action( 'login_enqueue_scripts', '\DigiIdAuthentication\digiid_login_script' );
	add_action( 'wp_logout', '\DigiIdAuthentication\digiid_exit');
	add_action( 'admin_menu', '\DigiIdAuthentication\digiid_menu' );
	add_action( 'template_redirect', '\DigiIdAuthentication\digiid_callback_test' );
	add_action( 'wp_ajax_nopriv_digiid', '\DigiIdAuthentication\digiid_ajax' );
	add_action( 'wp_ajax_digiid', '\DigiIdAuthentication\digiid_ajax' );

	// Login form
	add_filter( 'login_message', '\DigiIdAuthentication\digiid_login_header' );


	// Custom field Digi-ID
	// 1) Registration form
	add_action( 'register_form', '\DigiIdAuthentication\digiid_register_form' );
	// 2) Unique check
	add_filter( 'registration_errors', '\DigiIdAuthentication\digiid_unique_check', 10, 3 );
	// 3) Store field in metadata
	add_action( 'user_register', '\DigiIdAuthentication\digiid_register_after' );


	// Variables
	// Session init
	$digiid_session_id = session_id();
	if (!$digiid_session_id)
	{
		session_start();
		$digiid_session_id = session_id();
	}

	// Woocommerce on?
	$woocommerce_is_activated = in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));



	// Global table names
	$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";
	$table_name_links = "{$GLOBALS['wpdb']->prefix}digiid_userlink";
	$table_name_users = "{$GLOBALS['wpdb']->prefix}users";


	// Deny access to admin panel for customers
	function digiid_dashboard_admin_access() {
		global $current_user;
		$redirect = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : home_url( '/' );
		$user_roles = $current_user->roles;
		$user_role = array_shift($user_roles);
		if ($user_role === 'Customer')
		{
			exit(wp_redirect($redirect));
		}
	}


	/* Initializing. Used after QR generation! */
	function digiid_init($action = null)
	{
		// Global require
		if (!defined('DIGIID_AUTHENTICATION_INIT')) {
			// Run only once
			wp_enqueue_script('digiid_digiqr', plugin_dir_url(__FILE__) . 'digiQR.min.js');
			wp_enqueue_script('digiid_custom_js', plugin_dir_url(__FILE__) . 'functions.js?16', array('jquery'));
			wp_enqueue_style('digiid_custom_css', plugin_dir_url(__FILE__) . 'styles.css?16');
			wp_add_inline_script('digiid_custom_js', "digiid_base_ajax = '".admin_url('admin-ajax.php?action=digiid')."'");

			DEFINE("DIGIID_AUTHENTICATION_INIT", true);
		}

		// Get just created object id
		$id = digiid_qr_last_id();

		// JS init
		if (!$action)
			$action = (isset($_REQUEST['action'])) ? $_REQUEST['action'] : 'login';

		// Autodetect
		$start_state = '';
		// Usual login, but hidden by default
		if ($action == 'wc-login-widget') {
			$start_state = 'hide';
			$action = 'wc-login';
		}
		if ($action == 'wc-dashboard') {
			$start_state = 'hide';
			$action = 'add';
		}

		$ajax_url = admin_url('admin-ajax.php?action=digiid&type=' . $action);
		$url = digiid_get_callback_url(NULL, $action);

		$js = <<<JS
        jQuery(function() {
			let obj_name = '$id';
			digiid_config[obj_name] = {'action': '$action', 'ajax_url': '$ajax_url'};
			digiid_qr_change_visibility(obj_name, '$start_state');
			jQuery('#'+obj_name+' .digiid_qr img').attr('src', DigiQR.id('$url', 200, 3, 0));
			return true;
		});
JS;
		$js = str_replace("\t","", $js);
		wp_add_inline_script('digiid_custom_js', $js);
	}


	function digiid_get_template($filename, $variables)
	{
		// Extract variables
		extract ($variables);

		// Collect HTML to variable
		ob_start();
		require "template/$filename";
		$html = ob_get_contents();
		ob_end_clean();

		// Return output
		return $html;
	}


	/* REGISTER FORM */
	/* Modifying register form: add Digi-ID */
	function digiid_register_form()
	{
		// If we had a address in session, take it
		$digiid_addr = (!empty($_SESSION['digiid_addr'])) ? trim($_SESSION['digiid_addr']) : '';

		// Show form
		$label = __('Digi-ID', 'Digi-ID-Authentication');
		$addr_placeholder = __('Digi-ID for this site (scan QR to fill)', 'Digi-ID-Authentication');
		$button_showqr = __('Show QR for scan', 'Digi-ID-Authentication');
		$addr =  esc_attr(wp_unslash($digiid_addr));
		$dir = plugin_dir_url(__FILE__);

		$js = <<<JS
		jQuery(function() {
			digiid_on_change_reg_input_addr();
		});
JS;
		$js = str_replace("\t","", $js);
		wp_add_inline_script('digiid_custom_js', $js);
	
		// Collect HTML
		echo digiid_get_template('register_form.html', compact('label', 'addr_placeholder', 'addr', 'button_showqr', 'dir'));
	}


	$digiid_html_code_id = 0;
	function digiid_qr_last_id()
	{
		global $digiid_html_code_id;
		return 'digiid_' . $digiid_html_code_id;
	}


	function digiid_qr_html($force_action = null)
	{
		global $digiid_html_code_id;
		// If one on screen ON
		// if (defined("DIGIID_QR_GENERATED")) return '';
		// define("DIGIID_QR_GENERATED", true);

		// Default
		$title = 'Digi-ID login';
		$action = 'login';
		$titles = array(
			// /wp-admin
			'login' => "Digi-ID login",
			'register' => "", //New Digi-ID user",
			'add' => "Add Digi-ID",
			// /my-account
			'wc-login' => ''
		);
		$html = '';
		$dialog_tabs_html = '';

		// Give each block unique ID
		$digiid_html_code_id ++;
		$unique_id = digiid_qr_last_id();

		// Forced action specified
		if ($force_action != null)
		{
			$action = $force_action;
		}

		// Login / Register panel
		else
		{
			$user_can_register = get_option('users_can_register');
			if (!$user_can_register)
				$available_actions = array('login');
			else
				$available_actions = array('login','register');

			if (!empty($_REQUEST['action']))
			{
				if (!in_array($_REQUEST['action'], $available_actions)) {
					return $messages;
				} 
				$title = '';
				$action = $_REQUEST['action'];
			}
			else
			{
				$action = 'login';
			}

			// Collect Login, Register buttons
			$show_acts = array();
			if (in_array('register', $available_actions))
				$show_acts['register'] = array('caption' => 'Registration',	'url' => home_url('wp-login.php?action=register'));
			if (in_array('login', $available_actions))
				$show_acts['login'] = array('caption' => 'Login', 'url' => home_url('wp-login.php?action=login'));

			if (count($show_acts) > 1) {
			
				// Current
				if (isset($show_acts[ $action ]))
				{
					$params = $show_acts[ $action ];
					$dialog_tabs_html = "<a class='button active' href='" . esc_url($params['url']) . "'>{$params['caption']}</a>";
					// Others
					unset($show_acts[ $action ]);
				}
				// Others
				foreach ($show_acts as $show_act => $params)
				$dialog_tabs_html .= "<a class='button' href='" . esc_url($params['url']) . "'>{$params['caption']}</a>";
				
				$dialog_tabs_html = <<<HTML
			<div id="digiid_select_dialog">
				$dialog_tabs_html
			</div>
HTML;

			}
			else {
				$dialog_tabs_html = '';
			}

		}

		$url = digiid_get_callback_url(NULL, $action);
		if (!$url) {
			return '';
		}
		$alt_text = __("QR-code for Digi-ID", 'Digi-ID-Authentication');
		$url_encoded_url = urlencode($url);

		if (isset($titles[$action])) $title = $titles[$action];
		if (!empty($title)) $title = '<h1>' . __($title, 'Digi-ID-Authentication') . '</h1>';

		// Show block
		return digiid_get_template('qr.html', compact('dialog_tabs_html', 'title', 'url', 'alt_text', 'alt_text', 'unique_id'));
	}


	/* Custom field validation */
	function digiid_unique_check ( $errors, $sanitized_user_login, $user_email ) {
		global $table_name_links;

		if (empty($_POST['digiid_addr'])) 
			return $errors;

		$address = $_POST['digiid_addr'];
		$digiid = new DigiID();

		/*if (!$digiid->isAddressValid($address, FALSE) || !$digiid->isAddressValid($address, TRUE))
		{
			$errors->add('digiid_unique_check_error', __('<strong>ERROR</strong>: Incorrect Digi-ID address.', 'Digi-ID-Authentication'));
			return $errors;
		}*/
		
		$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_links} WHERE address = %s", $address);
		$info = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
		if (!empty($info)) 
		{
			$errors->add('digiid_unique_check_error', __('<strong>ERROR</strong>: Digi-ID already registered to other user.', 'Digi-ID-Authentication') . $info['address']);
		}

		return $errors;
	}


	/* After registration - store custom val and clear session */
	function digiid_register_after( $user_id ) {
		global $table_name_links;

		if (!empty($_POST['digiid_addr']))
		{
			// Fill the line and insert
			$userlink_row = array();
			$userlink_row['user_id'] = $user_id;
			$userlink_row['address'] = $_POST['digiid_addr'];
			$userlink_row['birth'] = current_time('mysql');
			$GLOBALS['wpdb']->insert( $table_name_links, $userlink_row );
		}



		// Forget about data
		digiid_exit();
	}
	/* /REGISTER FORM */



	/* Check version on load */
	function digiid_update_db_check()
	{
		//if (get_site_option( "digiid_plugin_version") !=  DIGIID_AUTHENTICATION_PLUGIN_VERSION )
		digiid_install();
	}


	/* Install plugin, add all tables or modifications */
	function digiid_check_installed()
	{
		global $table_name_nonce, $table_name_links, $table_name_users;

		// Detect current engine, use the same
		$get_engine_table_users = "SELECT count(*) c FROM information_schema WHERE TABLE_NAME = '{$GLOBALS['wpdb']->prefix}users'";
		$exist = $GLOBALS['wpdb']->get_var($get_engine_table_users);
		return $exist != 0;
	}


	/* Install plugin, add all tables or modifications */
	function digiid_install()
	{
		global $table_name_nonce, $table_name_links, $table_name_users;
		$wpdb = $GLOBALS['wpdb'];

		// Detect current engine, use the same
		$get_engine_table_users = "SELECT engine FROM information_schema.TABLES WHERE TABLE_NAME='{$wpdb->prefix}users'";
		$db_engine = $wpdb->get_var($get_engine_table_users);
		if (!$db_engine) $db_engine = "InnoDB"; // if some error while detection, use InnoDB

		$create_sql_params = " ENGINE=$db_engine DEFAULT CHARSET=utf8 COLLATE=utf8_bin";

		$create_table_nonce = <<<SQL
CREATE TABLE IF NOT EXISTS `$table_name_nonce` (
	`nonce` VARCHAR(32) NOT NULL,
	`address` VARCHAR(34) DEFAULT NULL,
	`session_id` VARCHAR(40) NOT NULL,
	`user_id` BIGINT(20) UNSIGNED NOT NULL,
	`nonce_action` VARCHAR(40) NOT NULL,
	`birth` DATETIME NOT NULL,
	PRIMARY KEY `pk_{$table_name_nonce}_nonce` (`nonce`)
)
$create_sql_params
SQL;

		$create_table_links = <<<SQL
CREATE TABLE IF NOT EXISTS `$table_name_links` (
	`user_id` BIGINT(20) UNSIGNED NOT NULL,
	`birth` DATETIME NOT NULL,
	`address` VARCHAR(34) NOT NULL,
	`pulse` DATETIME NOT NULL,
	PRIMARY KEY `pk_{$table_name_links}_address` (`address`),
	KEY `uq_{$table_name_links}_user_id` (`user_id`),
	FOREIGN KEY `fk_{$table_name_links}_user_id` (`user_id`) REFERENCES `$table_name_users` (`id`) 
		ON UPDATE CASCADE ON DELETE CASCADE
)
$create_sql_params
SQL;

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$wpdb->query( $create_table_nonce );
		$wpdb->query( $create_table_links );

		update_option( "digiid_plugin_version", DIGIID_AUTHENTICATION_PLUGIN_VERSION );
	}

	
	function digiid_menu()
	{
		// add_options_page( 'Digi-ID Options', 'Digi-ID', 'edit_users', 'Digi-ID-Authentication', 'digiid_option_page' );
		add_users_page(
			_x('My Digi-ID', 'page_title', 'Digi-ID-Authentication'),
			_x('Digi-ID', 'menu_name', 'Digi-ID-Authentication'),
			'read',
			'my-digiid',
			'\DigiIdAuthentication\digiid_my_option_page'
		);
	}

	function digiid_my_option_page()
	{
		global $table_name_links;

		$user_id = get_current_user_id();
		if (!$user_id) return;

		$addresses = digiid_list_users_addresses($user_id);

		$action = "";
		if (isset($_REQUEST['action2']) && $_REQUEST['action2'] != '' && $_REQUEST['action2'] != -1)
			$action = $_REQUEST['action2'];
		if (isset($_REQUEST['action']) && $_REQUEST['action'] != '' && $_REQUEST['action'] != -1)
			$action = $_REQUEST['action'];

		if ($action)
		{
			switch($action)
			{
				case 'add':
				{
					if (isset($_POST['address']))
					{
						$address = sanitize_text_field ($_POST['address']);
						$default_address = $address;
						$digiid = new DigiID();

						if ($digiid->isAddressValid($address, FALSE) OR $digiid->isAddressValid($address, TRUE))
						/* Registration? */
						{
							$userlink_row = array();
							$userlink_row['user_id'] = $user_id;
							$userlink_row['address'] = $address;
							$userlink_row['birth'] = current_time('mysql');
							$result = $GLOBALS['wpdb']->insert( $table_name_links, $userlink_row );

							if ($result)
							{
								echo digiid_admin_notice(sprintf(__("The address '%s' is now linked to your account.", 'Digi-ID-Authentication'), $address));
								$addresses = digiid_list_users_addresses($user_id);
							}
							else
								echo digiid_admin_notice(sprintf(__("Failed to link address '%s' to your account.", 'Digi-ID-Authentication'), $address), 'error');
						}
						else
							echo digiid_admin_notice(sprintf(__("The address '%s' isn't valid.", 'Digi-ID-Authentication'), $address), 'error');
					}
					else
						$default_address = sanitize_text_field($_REQUEST['address']);


					$page = sanitize_text_field($_REQUEST['page']); 
					$back_url = esc_url("?page=$page");

					echo <<<HTML
<div style='width: 320px; margin-top: 20px'>
<input type='hidden' name='redirect_to' value='$back_url'>
HTML;
					echo digiid_qr_html('add');
					digiid_init('add');
					echo "</div>";
					break;
				}

				case 'delete':
				{
					$found_addresses = array();
					$try_addresses = array();
					$deleted_addresses = array();
					$failed_addresses = array();

					if (isset($_REQUEST['digiid_row']))
					{
						foreach ($_REQUEST['digiid_row'] as $address)
							$found_addresses[$address] = sanitize_text_field($address);
					}
					else if (isset($_REQUEST['address']))
					{
						$address = $_REQUEST['address'];
						$found_addresses[$address] = sanitize_text_field($address);
					}

					if (!$found_addresses)
					{
						if ($_POST)
							echo digiid_admin_notice(__("Select some rows before asking to delete them", 'Digi-ID-Authentication'), 'error');
						else
							echo digiid_admin_notice(sprintf(__("Missing paramater '%s'", 'Digi-ID-Authentication'), 'address'), 'error');
						break;
					}

					foreach ($addresses as $current_address)
					{
						$address = $current_address['address'];
						if (isset($found_addresses[$address]))
						{
							$try_addresses[$address] = $address;
							unset($found_addresses[$address]);

							if (!$found_addresses)
							{
								break;
							}
						}
					}

					if ($found_addresses)
					{
						echo digiid_admin_notice(
							sprintf(
								_n(
									"The address %s isn't connected to your account.",
									"Those addresses %s arn't connected to your account.",
									count($found_addresses),
									'Digi-ID-Authentication'
								),
								"'" . implode("', '", $found_addresses) . "'"
							),
							'error'
						);
					}

					if (!$try_addresses)
					{
						break;
					}

					$table_name_links = "{$GLOBALS['wpdb']->prefix}digiid_userlink";

					foreach ($try_addresses as $address)
					{
						$db_result = $GLOBALS['wpdb']->delete($table_name_links, array('address' => $address, 'user_id' => $user_id));

						if ($db_result)
						{
							$deleted_addresses[$address] = $address;
						}
						else
						{
							$failed_addresses[$address] = $address;
						}
					}

					if ($failed_addresses)
					{
						echo digiid_admin_notice(
							sprintf(
								_n(
									"Failed to remove the address %s.",
									"Failed to remove those addresses %s.",
									count($failed_addresses),
									'Digi-ID-Authentication'
								),
								"'" . implode("', '", $failed_addresses) . "'"
							),
							'error'
						);
					}

					if ($deleted_addresses)
					{
						echo digiid_admin_notice(
							sprintf(
								_n(
									"The address %s is no longer linked to your account.",
									"Those addresses %s is no longer linked to your account.",
									count($deleted_addresses),
									'Digi-ID-Authentication'
								),
								"'" . implode("', '", $deleted_addresses) . "'"
							),
							'error'
						);

						$addresses = digiid_list_users_addresses($user_id);
					}

					break;
				}
				default:
				{
					echo digiid_admin_notice("Unknowed action: " . sanitize_text_field($_REQUEST['action']), 'error');
					break;
				}
			}
		}

		$page_title = _x("My Digi-ID addresses", "page_title", 'Digi-ID-Authentication');
		$add_link_title = __("Add New");
		$page = sanitize_text_field($_REQUEST['page']); 
		$url = esc_url("?page=$page&action=add");

		echo <<<HTML
<div class="wrap">
	<h2>
		<span>{$page_title}</span>
		<a class="add-new-h2" href="$url">{$add_link_title}</a>
	</h2>

HTML;

		if (!$addresses)
		{
			echo digiid_admin_notice(__("You have no Digi-ID addresses connected to your account.", 'Digi-ID-Authentication'));
			return;
		}

		class my_digiid_addresses extends \WP_List_Table
		{

			function get_columns()
			{
				return array(
					'cb' => '<input type="checkbox" />',
					'address' => _x('Digi-ID address', 'column_name', 'Digi-ID-Authentication'),
					'birth' => _x('Added', 'column_name', 'Digi-ID-Authentication'),
					'pulse' => _x('Last time used', 'column_name', 'Digi-ID-Authentication'),
				);
			}

			function get_sortable_columns()
			{
				return array(
					'address'  => array('address',false),
					'birth' => array('birth',false),
					'pulse'   => array('pulse',false)
				);
			}

			function usort_reorder( $a, $b )
			{
				// If no sort, default to title
				$orderby = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'address';
				// If no order, default to asc
				$order = ( ! empty($_GET['order'] ) ) ? $_GET['order'] : 'asc';
				// Determine sort order
				$result = strcmp( $a[$orderby], $b[$orderby] );
				// Send final sort direction to usort
				return ( $order === 'asc' ) ? $result : -$result;
			}

			function prepare_items()
			{
				$columns = $this->get_columns();
				$hidden = array();
				$sortable = $this->get_sortable_columns();
				$this->_column_headers = array($columns, $hidden, $sortable);
				$user_id = get_current_user_id();
				$addr_list = digiid_list_users_addresses($user_id);
				usort( $addr_list, array( &$this, 'usort_reorder' ) );
				$this->items = $addr_list;
			}

			function column_default($item, $column_name)
			{
				return $item[$column_name];
			}

			function column_address($item)
			{
				$actions = array(
					'delete'    => sprintf(
						'<a href="?page=%s&action=%s&address=%s">%s</a>', 
						sanitize_text_field ($_REQUEST['page']), 
						'delete', 
						sanitize_text_field ($item['address']), 
						__('Remove')
					),
				);
				return sanitize_text_field ($item['address']) . $this->row_actions($actions);
			}

			function get_bulk_actions()
			{
				return array(
					'delete' => __('Delete'),
				);
			}

			function column_cb($item)
			{
				return sprintf(
					'<input type="checkbox" name="digiid_row[]" value="%s" />', 
					sanitize_text_field ($item['address']) );
			}
		}

		$url = esc_url('?page=' . sanitize_text_field($_REQUEST['page']));

		echo "<form action='$url' method='post'>";
		$my_digiid_addresses = new my_digiid_addresses();
		$my_digiid_addresses->prepare_items();
		$my_digiid_addresses->display();
		echo "	</form>\n</div>";
	}

	function digiid_get_nonce($nonce_action)
	{
		global $digiid_session_id, $table_name_nonce;

		$query = "DELETE FROM {$table_name_nonce} WHERE birth is not null AND birth < NOW() - INTERVAL 60 MINUTE";
		$GLOBALS['wpdb']->query($query);

		//$query = $GLOBALS['wpdb']->prepare("DELETE FROM {$table_name_nonce} WHERE session_id = %s", $digiid_session_id);
		$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_nonce} WHERE nonce_action = %s AND session_id = %s", $nonce_action, $digiid_session_id);
		$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
		if ($nonce_row)
			return $nonce_row['nonce'];

		$nonce_row = array();
		$nonce_row['nonce'] = DigiID::generateNonce();
		$nonce_row['nonce_action'] = $nonce_action;
		$nonce_row['session_id'] = $digiid_session_id;
		$nonce_row['birth'] = current_time('mysql');

		if ($user_id = get_current_user_id())
			$nonce_row['user_id'] = $user_id;
		else
			$nonce_row['user_id'] = false;

		$db_result = $GLOBALS['wpdb']->insert( $table_name_nonce, $nonce_row );
		if ($db_result)
			return $nonce_row['nonce'];
		
		return $db_result;
	}

	function digiid_get_callback_url($nonce = NULL, $nonce_action = NULL)
	{
		if (!$nonce && $nonce_action)
			$nonce = digiid_get_nonce($nonce_action);

		if (!$nonce)
			return FALSE;

		$url = home_url("digiid/callback?x=" . $nonce);

		if (substr($url, 0, 8) == 'https://')
			return 'digiid://' . substr($url, 8);
		else
			return 'digiid://' . substr($url, 7) . "&u=1";
	}

	function digiid_login_header($messages)
	{
		$messages = '';
		$messages .= digiid_qr_html();
		digiid_init();
		return $messages;
	}


	function digiid_login_script()
	{
/*		$url = digiid_get_callback_url(NULL, 'login');
		wp_add_inline_script('digiid_custom_js', <<<JS
	
			// Show QR
			digiid_onload_add(function() { digiid_qr_change_visibility(); });

JS
		);*/
	}

	function digiid_exit()
	{
		global $digiid_session_id, $table_name_nonce;

		//$GLOBALS['wpdb']->delete($table_name_nonce, array('session_id' => $digiid_session_id));
		$GLOBALS['wpdb']->delete($table_name_nonce, array('address' => $_SESSION['digiid_addr']));
		unset ($_SESSION['digiid_addr']);
		$digiid_session_id = false;
	}

	function digiid_callback_test()
	{
		$digiid_callback_url = "/digiid/callback";
		if (strstr($_SERVER['REQUEST_URI'], $digiid_callback_url))
		{
			require_once("callback.php");
		}
	}

	function digiid_ajax()
	{
		require_once("ajax.php");
	}

	function digiid_list_users_addresses($user_id)
	{
		global $table_name_links;

		if ($user_id === TRUE)
		{
			$query = "SELECT * FROM {$table_name_links}";
			return $GLOBALS['wpdb']->get_results($query, ARRAY_A);
		}
		else
		{
			$query = "SELECT * FROM {$table_name_links} WHERE user_id = %d";
			$query = $GLOBALS['wpdb']->prepare($query, (int) $user_id);
			return $GLOBALS['wpdb']->get_results($query, ARRAY_A);
		}
	}

	function digiid_admin_notice($text, $class = 'updated')
	{
		return  <<<HTML
	<div class='{$class}'>
		<p>{$text}</p>
	</div>

HTML;
	}

	function digiid_load_translation()
	{
		$plugin_dir = basename(dirname(__FILE__));
		load_plugin_textdomain( 'Digi-ID-Authentication', false, $plugin_dir );
	}

	/*
	* Get Digi-ID address by user_id 
	*/
	function digiid_get_addr($user_id = null)
	{
		global $table_name_links;

		// Current user by default
		if ($user_id == null) $user_id = get_current_user_id();

		$query = $GLOBALS['wpdb']->prepare($ask = "SELECT * FROM {$table_name_links} WHERE user_id = %d", $user_id);
		$user_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);

		return ($user_row) ? $user_row['address'] : null;
	}


// Add Link (Tab) to My Account menu
add_filter ('woocommerce_account_menu_items', '\DigiIdAuthentication\digiid_wc_account_menu', 40);
function digiid_wc_account_menu($menu_links) {
 
	// Insert after 5th point - on some environment do not work, so disabled
	/*
	$menu_links = array_slice($menu_links, 0, 5, true) 
		+ array('digiid' => 'Digi-ID')
		+ array_slice($menu_links, 5, NULL, true);*/
 
	return $menu_links;
 
}


// Register Permalink Endpoint
add_action( 'init', '\DigiIdAuthentication\digiid_wc_menu_endpoint' );
function digiid_wc_menu_endpoint() 
{

	// WP_Rewrite is my Achilles' heel, so please do not ask me for detailed explanation
	add_rewrite_endpoint('digiid', EP_PAGES);

}


add_action( 'woocommerce_account_digiid_endpoint', '\DigiIdAuthentication\digiid_wc_endpoint_content' );
function digiid_wc_endpoint_content()
{
	$add_qr_html = digiid_qr_html('add');
	digiid_init('add');

	$user_id = get_current_user_id();
	$addr_list = digiid_list_users_addresses($user_id);

	echo digiid_get_template('digiid_menu_content.html', compact('user_id', 'addr_list', 'add_qr_html'));
}


// Login form
add_action('woocommerce_before_customer_login_form', '\DigiIdAuthentication\woocommerce_before_customer_login_form');
function woocommerce_before_customer_login_form()
{
	$qr_html = digiid_qr_html('wc-myaccount');
	digiid_init('wc-myaccount');

	echo digiid_get_template('before_login_form.html', compact('qr_html'));
}


add_action('woocommerce_register_form_start', '\DigiIdAuthentication\digiid_wc_extra_register_fields', 10, 0);
function digiid_wc_extra_register_fields()
{
	$digiid_address = isset($_REQUEST['digiid_addr']) ? sanitize_text_field($_REQUEST['digiid_addr']) : '';
	$unique_id = digiid_qr_last_id();

	$js = <<<JS
	jQuery(function() {
		jQuery('#digiid_addr').change(function(){
			val = jQuery('#digiid_addr').val();
			digiid_qr_change_visibility('$unique_id', (val == '') ? 'show' : 'hide')
		}).change()

	});
JS;
	$js = str_replace("\t","", $js);
	wp_add_inline_script('digiid_custom_js', $js);

?>
	<p class="form-row form-row-wide">
	<label for="digiid_addr"><?php _e('Digi-ID address (optional)', 'woocommerce') ?></label>
	<input type="text" class="input-text" name="digiid_addr" id="digiid_addr" value="<?= $digiid_address ?>" />
	</p>
	<div class="clear"></div>
	<?php
}


add_action('woocommerce_account_dashboard', '\DigiIdAuthentication\digiid_wc_dashboard_after');
function digiid_wc_dashboard_after()
{
	$add_qr_html = digiid_qr_html('add');
	digiid_init('wc-dashboard');

	$user_id = get_current_user_id();
	$addr_list = digiid_list_users_addresses($user_id);

	echo digiid_get_template('dashboard.html', compact('user_id', 'addr_list', 'add_qr_html'));
}


// This adds support for a [digiid] shortcode
add_shortcode('digiid', '\DigiIdAuthentication\digiid_shortcode');
function digiid_shortcode()
{
	global $woocommerce_is_activated;

	// Already logged
	$user = wp_get_current_user();
	if ($user->exists())
	{
		// Try WooCommerce
		if ($woocommerce_is_activated)
		{
			$page_id = wc_get_page_id('myaccount');
			$url = wp_logout_url(get_permalink($page_id));
		}
		else
			$url = wp_logout_url();

 		return digiid_get_template('widget_logged_in.html', array(
			'login' => $user->user_login,
			'logout_url' => $url
			));
	}

	// Show QR
	$qr_html = digiid_qr_html('wc-login');
	digiid_init('wc-login-widget');
	return "<div class='digiid_shortcode'>$qr_html</div>";
}


add_filter('woocommerce_widget_shopping_cart_buttons', '\DigiIdAuthentication\digiid_wc_after_cart', 40);
function digiid_wc_after_cart()
{
	return digiid_shortcode();
}


// Digi-ID field Validating
add_action('woocommerce_register_post', 'digiid_wc_validate_extra_register_fields', 10, 3);
function digiid_wc_validate_extra_register_fields($username, $email, $validation_errors) 
{
	if (isset($_POST['digiid_addr']) && !empty($_POST['digiid_addr'])) {
		$address = $_POST['digiid_addr'];
		if (strlen($address)<10) {
			$validation_errors->add('digiid_address_error', __('<strong>Error</strong>: Incorrect Digi-ID address!', 'woocommerce'));
		}
	}
	return $validation_errors;
}


/**
 * Below code save extra fields.
 */
add_action('woocommerce_created_customer', 'digiid_wc_save_extra_register_fields');
function digiid_wc_save_extra_register_fields($customer_id)
{
    if (!empty($_POST['digiid_addr'])) {
		// Phone input filed which is used in WooCommerce
		update_user_meta($customer_id, 'digiid_addr', sanitize_text_field($_POST['digiid_addr']));
	}
}
