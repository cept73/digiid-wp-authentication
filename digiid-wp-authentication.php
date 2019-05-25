<?php
/**
 * @package Digi-ID Authentication
 * @author Taranov Sergey (Cept)
 * @version 1.0.7
 */
/*
Plugin Name: Digi-ID Authentication
Description: Digi-ID Authentication, extends WordPress default authentication with the Digi-ID protocol
Version: 1.0.7
Author: Taranov Sergey (Cept), digicontributor
Author URI: http://github.com/cept73
*/

namespace DigiIdAuthentication;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
DEFINE("DIGIID_AUTHENTICATION_PLUGIN_VERSION", '1.0.7');

	require_once ('required_classes.php');

	register_activation_hook( __FILE__, '\DigiIdAuthentication\digiid_install' );


	//add_action( 'init', '\DigiIdAuthentication\digiid_init');
	add_action( 'plugins_loaded', '\DigiIdAuthentication\digiid_update_db_check' );
	add_action( 'plugins_loaded', '\DigiIdAuthentication\digiid_load_translation' );
	add_action( 'login_enqueue_scripts', '\DigiIdAuthentication\digiid_login_script' );
	add_action( 'wp_logout', '\DigiIdAuthentication\digiid_exit');
	add_action( 'admin_menu', '\DigiIdAuthentication\digiid_menu' );
	add_action( 'template_redirect', '\DigiIdAuthentication\digiid_callback_test' );
	add_action( 'wp_ajax_nopriv_digiid', '\DigiIdAuthentication\digiid_ajax' );


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


	/* Init */
	function digiid_init()
	{
		// Global require
		wp_enqueue_script('digiid_digiqr', plugin_dir_url(__FILE__) . 'digiQR.min.js');
		wp_enqueue_script('digiid_custom_js', plugin_dir_url(__FILE__) . 'functions.js?190519_1336');
		wp_enqueue_style('digiid_custom_css', plugin_dir_url(__FILE__) . 'styles.css?190519_1336');

		// JS init
		$action = (isset($_REQUEST) && $_REQUEST['action'] == 'register') ? 'register' : 'login';
		$ajax_url = admin_url('admin-ajax.php?action=digiid');
		$url = digiid_get_callback_url(NULL, $action);
		$js = <<<JS
        window.onload = function() {
			digiid_config = {'action': '$action', 'ajax_url': '$ajax_url'};
			digiid_qr_change_visibility();
			document.querySelector('#digiid_qr img').src = DigiQR.id('$url', 200, 3, 0);
		};
JS;
		$js = str_replace("\t","", $js);
		//$js = str_replace("\n","", $js);
		wp_add_inline_script('digiid_custom_js', $js);
	
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
		$qr_html = '';//digiid_qr_html();
		echo <<<HTML
			<div style="width:100%">
				<label for="digiid_addr">$label</label>

				<div style="clear:both"></div>

				<input type="text" name="digiid_addr" id="digiid_addr" class="input" placeholder="$addr_placeholder"
					value="$addr" size="37" 
					onchange="javascript: digiid_qr_change_visibility()" />
				<span id="digiid_btn_showqr" style="display: hidden">
					<a href="javascript:;" title="$button_showqr" onclick="javascript: digiid_clear_qr()">
						<img src="{$dir}/assets/qr-64x64.jpg" width="28px"><!-- scan-qr-64x64.png -->
					</a>
				</span>

				$qr_html
			</div>

			<div style="clear:both"></div>
HTML;
	}


	function digiid_qr_html ()
	{
		$html = '';
		$title = 'Digi-ID login';
		$action = 'login';

		// Login / Register panel
		if (get_option('users_can_register'))
		{
			$available_actions = array('login','register');

			if (!empty($_REQUEST['action']))
			{
				if (!in_array($_REQUEST['action'], $available_actions)) return $messages; 
				$title = '';
				$action = $_REQUEST['action'];
			}
			else
			{
				$action = 'login';
			}

			$title = $action == 'login' ? "Digi-ID login" : "New Digi-ID user";

			// Collect Login, Register buttons
			$show_acts = array();
			if (in_array('register', $available_actions))
				$show_acts['register'] = array('caption' => 'Registration',	'url' => home_url('wp-login.php?action=register'));
			if (in_array('login', $available_actions))
				$show_acts['login'] =	 array('caption' => 'Login',		'url' => home_url('wp-login.php?action=login'));

			// Current
			if (isset($show_acts[ $action ]))
			{
				$params = $show_acts[ $action ];
				$dialog_html = "<a class='button active' href='" . esc_url($params['url']) . "'>{$params['caption']}</a>";
				// Others
				unset($show_acts[ $action ]);
			}
			// Others
			foreach ($show_acts as $show_act => $params)
				$dialog_html .= "<a class='button' href='" . esc_url($params['url']) . "'>{$params['caption']}</a>";

			$html .= <<<HTML
			<div id="digiid_select_dialog">
				$dialog_html
			</div>
HTML;

		}

		$url = digiid_get_callback_url(NULL, $action);
		if (!$url) {
			return '';
		}
		$alt_text = __("QR-code for Digi-ID", 'Digi-ID-Authentication');
		$url_encoded_url = urlencode($url);

		$title = '<h1>' . __($title, 'Digi-ID-Authentication') . '</h1>';

		// Show block
		$html .= <<<HTML
		<div id='digiid_outer'>
			<div id='digiid' style='display:none'>
				<div style="padding: 24px">
					{$title}
					<div id="digiid_qr">
						<a href='$url'><img alt='$alt_text' title='$alt_text'></a>
					</div>
					<p class="know-more">To know more: <a href="https://www.digi-id.io" target="__blank">digi-id.io</a></p>
				</div>
				<div id='digiid_progress_full'>
					<div id='digiid_progress_bar'>
					</div>
				</div>
			</div>
		</div>
		<div id='digiid_msg'></div>

HTML;

		return $html;
	}


	/* Custom field validation */
	function digiid_unique_check ( $errors, $sanitized_user_login, $user_email ) {
		if (empty($_POST['digiid_addr'])) 
			return $errors;

		$address = $_POST['digiid_addr'];
		$digiid = new DigiID();

		/*if (!$digiid->isAddressValid($address, FALSE) || !$digiid->isAddressValid($address, TRUE))
		{
			$errors->add('digiid_unique_check_error', __('<strong>ERROR</strong>: Incorrect Digi-ID address.', 'Digi-ID-Authentication'));
			return $errors;
		}*/
		
		$table_name_userlink = "{$GLOBALS['wpdb']->prefix}digiid_userlink";
		$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_userlink} WHERE address = %s", $address);
		$info = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
		if (!empty($info)) 
		{
			$errors->add('digiid_unique_check_error', __('<strong>ERROR</strong>: Digi-ID already registered to other user.', 'Digi-ID-Authentication') . $info['address']);
		}

		return $errors;
	}


	/* After registration - store custom val and clear session */
	function digiid_register_after( $user_id ) {
		if (!empty($_POST['digiid_addr']))
		{
			// We will store record about users Digi-ID in this table
			$table_name_userlink = "{$GLOBALS['wpdb']->prefix}digiid_userlink";

			// Fill the line and insert
			$userlink_row = array();
			$userlink_row['user_id'] = $user_id;
			$userlink_row['address'] = $_POST['digiid_addr'];
			$userlink_row['birth'] = current_time('mysql');
			$GLOBALS['wpdb']->insert( $table_name_userlink, $userlink_row );
		}

		// Forget about data
		digiid_exit();
	}
	/* /REGISTER FORM */



	/* Check version on load */
	function digiid_update_db_check()
	{
		if(get_site_option( "digiid_plugin_version") !=  DIGIID_AUTHENTICATION_PLUGIN_VERSION )
			digiid_install();
	}

	/* Install plugin, add all tables or modifications */
	function digiid_install()
	{
		$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";
		$table_name_links = "{$GLOBALS['wpdb']->prefix}digiid_userlink";
		$table_name_users = "{$GLOBALS['wpdb']->prefix}users";

		// Detect current engine, use the same
		$get_engine_table_users = "SELECT engine FROM information_schema.TABLES WHERE TABLE_NAME='{$GLOBALS['wpdb']->prefix}users'";
		$db_engine = $GLOBALS['wpdb']->get_var($get_engine_table_users);
		if (!$db_engine) $db_engine = "InnoDB"; // if some error while detection, use InnoDB
		
		$create_table_nonce = <<<SQL
CREATE TABLE {$table_name_nonce} (
	nonce VARCHAR(32) NOT NULL,
	address VARCHAR(34) DEFAULT NULL,
	session_id VARCHAR(40) NOT NULL,
	user_id BIGINT(20) UNSIGNED NOT NULL,
	nonce_action VARCHAR(40) NOT NULL,
	birth DATETIME NOT NULL,
	PRIMARY KEY (nonce),
	KEY (birth)
)
ENGINE={$db_engine}
DEFAULT CHARSET=utf8
COLLATE=utf8_bin
SQL;

		$create_table_links = <<<SQL
CREATE TABLE {$table_name_links} (
	user_id BIGINT(20) UNSIGNED NOT NULL,
	address VARCHAR(34) NOT NULL,
	birth DATETIME NOT NULL,
	pulse DATETIME NOT NULL,
	PRIMARY KEY (address),
	KEY (user_id),
	KEY (birth),
	FOREIGN KEY (user_id) REFERENCES {$table_name_users}(ID)
)
ENGINE={$db_engine}
DEFAULT CHARSET=utf8
COLLATE=utf8_bin
SQL;

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $create_table_nonce );
		dbDelta( $create_table_links );

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

	function digiid_option_page()
	{
		echo "<h1>digiid_option_page</h1>";
		return "<h2>digiid_option_page()</h2>";
	}

	function digiid_my_option_page()
	{
		$user_id = get_current_user_id();
		if(!$user_id) return;

		$addresses = digiid_list_users_addresses($user_id);
		$table_name_links = "{$GLOBALS['wpdb']->prefix}digiid_userlink";

		$action = "";
		if(isset($_REQUEST['action2']) && $_REQUEST['action2'] != '' && $_REQUEST['action2'] != -1)
			$action = $_REQUEST['action2'];
		if(isset($_REQUEST['action']) && $_REQUEST['action'] != '' && $_REQUEST['action'] != -1)
			$action = $_REQUEST['action'];

		if($action)
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
						{
							/* Registration? */
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

					$legend_title = _x("Add Digi-ID address", 'legend_title', 'Digi-ID-Authentication');
					$label_scan = _x("Scan it till 'Digi-ID success'", 'label_scan', 'Digi-ID-Authentication');
					$label_title = _x("or specify here", 'input_title', 'Digi-ID-Authentication');
					$button_title = _x("Link to my account", 'button', 'Digi-ID-Authentication');

					$qr_url = digiid_get_callback_url(NULL, 'add');
					$url_encoded_url = urlencode($qr_url);
					$alt_text = htmlentities(_x("QR-code for Digi-ID", 'qr_alt_text', 'Digi-ID-Authentication'), ENT_QUOTES);

					$page = sanitize_text_field($_REQUEST['page']);
					$url = esc_url (plugin_dir_url(__FILE__) . "?page=$page&action=add");

					echo <<<HTML
<form action='$url' method='post' id='digiid-addnew'>
	<fieldset>
		<legend style='font-size: larger;'>
			<h2>{$legend_title}</h2>
		</legend>
		<div class='fieldset_content'>
			<center>
				<h2>{$label_scan}:</h2>
				<a href='{$url}'><img id="qr" alt='{$alt_text}' title='{$alt_text}' width='200px' height='200px' style="display: block"></a>
				<h2>{$label_title}:</h2>
				<label>
					<input type='text' name='address' value='{$default_address}' style='width: 100%; max-width: 400px; text-align: center' />
				</label>
				<br />
				<input type='submit' value='{$button_title}' />
			</center>
		</div>
	</fieldset>
</form>
HTML;
					break;
				}

				case 'delete':
				{
					$found_addresses = array();
					$try_addresses = array();
					$deleted_addresses = array();
					$failed_addresses = array();

					if(isset($_REQUEST['digiid_row']))
					{
						foreach($_REQUEST['digiid_row'] as $address)
							$found_addresses[$address] = sanitize_text_field($address);
					}
					else if(isset($_REQUEST['address']))
					{
						$address = $_REQUEST['address'];
						$found_addresses[$address] = sanitize_text_field($address);
					}

					if(!$found_addresses)
					{
						if($_POST)
							echo digiid_admin_notice(__("Select some rows before asking to delete them", 'Digi-ID-Authentication'), 'error');
						else
							echo digiid_admin_notice(sprintf(__("Missing paramater '%s'", 'Digi-ID-Authentication'), 'address'), 'error');
						break;
					}

					foreach($addresses as $current_address)
					{
						$address = $current_address['address'];
						if(isset($found_addresses[$address]))
						{
							$try_addresses[$address] = $address;
							unset($found_addresses[$address]);

							if(!$found_addresses)
							{
								break;
							}
						}
					}

					if($found_addresses)
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

					if(!$try_addresses)
					{
						break;
					}

					$table_name_links = "{$GLOBALS['wpdb']->prefix}digiid_userlink";

					foreach($try_addresses as $address)
					{
						$db_result = $GLOBALS['wpdb']->delete($table_name_links, array('address' => $address, 'user_id' => $user_id));

						if($db_result)
						{
							$deleted_addresses[$address] = $address;
						}
						else
						{
							$failed_addresses[$address] = $address;
						}
					}

					if($failed_addresses)
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

					if($deleted_addresses)
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

		echo <<<HTML_BLOCK
<div class="wrap">
	<h2>
		<span>{$page_title}</span>
		<a class="add-new-h2" href="$url">{$add_link_title}</a>
	</h2>

HTML_BLOCK;

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
		global $digiid_session_id;
		$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";

		$query = "DELETE FROM {$table_name_nonce} WHERE birth < NOW() - INTERVAL 30 MINUTE";
		$GLOBALS['wpdb']->query($query);

		$query = $GLOBALS['wpdb']->prepare("DELETE FROM {$table_name_nonce} WHERE session_id = %s", $digiid_session_id);
		$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_nonce} WHERE nonce_action = %s AND session_id = %s", $nonce_action, $digiid_session_id);
		$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
		if ($nonce_row)
			return $nonce_row['nonce'];

		$nonce_row = array();
		$nonce_row['nonce'] = DigiID::generateNonce();
		$nonce_row['nonce_action'] = $nonce_action;
		$nonce_row['session_id'] = $digiid_session_id;
		$nonce_row['birth'] = current_time('mysql');

		/*$user_id = get_current_user_id();
		if($user_id)
			$nonce_row['user_id'] = $user_id;*/

		$db_result = $GLOBALS['wpdb']->insert( $table_name_nonce, $nonce_row );
		if($db_result)
			return $nonce_row['nonce'];
		else
			return $db_result;
	}

	function digiid_get_callback_url($nonce = NULL, $nonce_action = NULL)
	{
		if(!$nonce && $nonce_action)
			$nonce = digiid_get_nonce($nonce_action);

		if(!$nonce)
			return FALSE;

		$url = home_url("digiid/callback?x=" . $nonce);

		if(substr($url, 0, 8) == 'https://')
			return 'digiid://' . substr($url, 8);
		else
			return 'digiid://' . substr($url, 7) . "&u=1";
	}

	function digiid_login_header($messages)
	{
		$messages = '';
		//$action = (isset($_REQUEST) && $_REQUEST['action'] == 'register') ? 'register' : 'login';
		//if ($action == 'login') 
		$messages .= digiid_qr_html();
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
		digiid_init();
	}

	function digiid_exit()
	{
		global $digiid_session_id;
		$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";

		$GLOBALS['wpdb']->delete($table_name_nonce, array('session_id' => $digiid_session_id));
		$GLOBALS['wpdb']->delete($table_name_nonce, array('address' => $_SESSION['digiid_addr']));
		unset ($_SESSION['digiid_addr']);
		$digiid_session_id = false;
	}

	function digiid_callback_test()
	{
		$digiid_callback_url = "/digiid/callback";
		if(strstr($_SERVER['REQUEST_URI'], $digiid_callback_url))
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
		$table_name_links = "{$GLOBALS['wpdb']->prefix}digiid_userlink";

		if($user_id === TRUE)
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

