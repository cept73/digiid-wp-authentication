<?php
/**
 * @package Digi-ID Authentication
 * @author Digicontributer (Gary Mckee)
 * @version 1.0.2
 */
/*
Plugin Name: Digi-ID Authentication
Description: Digi-ID Authentication, extends wordpress default authentication with the Digi-ID protocol
Version: 1.0.2
Author: Digicontributer (Gary Mckee)
Author URI: http://digibyte.io
*/
DEFINE("DIGIID_AUTHENTICATION_PLUGIN_VERSION", '1.0.2');

	require_once("digiid.php");

	register_activation_hook( __FILE__, 'digiid_install' );

	add_action( 'plugins_loaded', 'digiid_update_db_check' );
	add_action( 'login_enqueue_scripts', 'digiid_login_script' );
	add_action( 'wp_logout', 'digiid_exit');
	add_action( 'init', 'digiid_init');
	add_action( 'admin_menu', 'digiid_menu' );
	add_action( 'template_redirect', 'digiid_callback_test' );
	add_action( 'wp_ajax_nopriv_digiid', 'digiid_ajax' );
	add_action( 'plugins_loaded', 'digiid_load_translation' );

	add_filter( 'login_message', 'digiid_login_header' );

	function digiid_init()
	{
		$session_id = session_id();

		if(!$session_id)
		{
			session_start();
			$session_id = session_id();
		}
	}

	function digiid_load_translation()
	{
		$plugin_dir = basename(dirname(__FILE__));
		load_plugin_textdomain( 'Digi-ID-Authentication', FALSE, $plugin_dir );
	}

	/* check version on load */
	function digiid_update_db_check()
	{
		if(get_site_option( "digiid_plugin_version") !=  DIGIID_AUTHENTICATION_PLUGIN_VERSION )
		{
			digiid_install();
		}
	}

	/* install plugin, add all tables */
	function digiid_install()
	{
		$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";
		$table_name_links = "{$GLOBALS['wpdb']->prefix}digiid_userlink";
		$table_name_users = "{$GLOBALS['wpdb']->prefix}users";

		// Detect current engine, use the same
		$get_engine_table_users = "SELECT engine FROM information_schema.TABLES WHERE TABLE_NAME='wp_users'";
		$db_engine = $GLOBALS['wpdb']->get_var($get_engine_table_users);
		
		$create_table_nonce = <<<SQL_BLOCK
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
SQL_BLOCK;

		$create_table_links = <<<SQL_BLOCK
CREATE TABLE {$table_name_links} (
	user_id BIGINT(20) UNSIGNED NOT NULL,
	address VARCHAR(34) COLLATE utf8_bin DEFAULT NULL,
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
SQL_BLOCK;

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
			'digiid_my_option_page'
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
		if(!$user_id)
		{
			return;
		}

		$addresses = digiid_list_users_addresses($user_id);

		$action = "";
		if(isset($_REQUEST['action2']) AND $_REQUEST['action2'] != '' AND $_REQUEST['action2'] != -1)
		{
			$action = $_REQUEST['action2'];
		}
		if(isset($_REQUEST['action']) AND $_REQUEST['action'] != '' AND $_REQUEST['action'] != -1)
		{
			$action = $_REQUEST['action'];
		}
		if($action)
		{
			switch($action)
			{
				case 'add':
				{
					if(isset($_POST['address']))
					{
						$address = $_POST['address'];
						$default_address = $address;
						$digiid = new DigiID();
						if($digiid->isAddressValid($address, FALSE) OR $digiid->isAddressValid($address, TRUE))
						{
							$userlink_row = array();
							$userlink_row['user_id'] = $user_id;
							$userlink_row['address'] = $address;
							$userlink_row['birth'] = current_time('mysql');

							$table_name_links = "{$GLOBALS['wpdb']->prefix}digiid_userlink";

							$db_result = $GLOBALS['wpdb']->insert( $table_name_links, $userlink_row );

							if($db_result)
							{
								echo digiid_admin_notice(sprintf(__("The address '%s' is now linked to your account.", 'Digi-ID-Authentication'), $address));

								$addresses = digiid_list_users_addresses($user_id);
							}
							else
							{
								echo digiid_admin_notice(sprintf(__("Failed to link address '%s' to your account.", 'Digi-ID-Authentication'), $address), 'error');
							}
						}
						else
						{
							echo digiid_admin_notice(sprintf(__("The address '%s' isn't valid.", 'Digi-ID-Authentication'), $address), 'error');
						}
					}
					else
					{
						$default_address = (string) @$_REQUEST['address'];
					}

					$legend_title = _x("Add Digi-ID address", 'legend_title', 'Digi-ID-Authentication');
					$label_title = _x("Digi-ID address", 'input_label', 'Digi-ID-Authentication');
					$button_title = _x("Link to my account", 'button', 'Digi-ID-Authentication');

					$qr_url = digiid_get_callback_url(NULL, 'add');
					$url_encoded_url = urlencode($qr_url);

					$alt_text = htmlentities(_x("QR-code for Digi-ID", 'qr_alt_text', 'Digi-ID-Authentication'), ENT_QUOTES);

		$messages .= <<<HTML_BLOCK
<div id='digiid'>
	<p>
		<span>Add DigiByte Address:</span>
	</p>
</div>
HTML_BLOCK;


					echo <<<HTML_BLOCK
<form action='?page={$_REQUEST['page']}&action=add' method='post'>
	<fieldset style='border: solid black 2px; width: 40em; padding: 10px; margin: 10px;'>
		<legend style='font-size: larger;'>
			{$legend_title}
		</legend>
		<div class='fieldset_content'>
			<label>
				<span style='width: 10em; display: inline-block;'>
					{$label_title}:
				</span>
				<input type='text' name='address' value='{$default_address}' style='width: 25em;'/>
			</label>
			<br />
			<input type='submit' value='{$button_title}' style='margin-left: 10em;' />
		</div>
	</fieldset>
	<p>
		<a href='{$qr_url}'>
			<img src='https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl={$url_encoded_url}' alt='{$alt_text}' title='{$alt_text}' />
		</a>
	</p>
</form>
HTML_BLOCK;
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
						{
							$found_addresses[$address] = $address;
						}
					}
					else if(isset($_REQUEST['address']))
					{
						$address = $_REQUEST['address'];
						$found_addresses[$address] = $address;
					}

					if(!$found_addresses)
					{
						if($_POST)
						{
							echo digiid_admin_notice(__("Select some rows before asking to delete them", 'Digi-ID-Authentication'), 'error');
							break;
						}
						else
						{
							echo digiid_admin_notice(sprintf(__("Missing paramater '%s'", 'Digi-ID-Authentication'), 'address'), 'error');
							break;
						}
					}

					foreach($addresses as $current_adress)
					{
						$address = $current_adress['address'];
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
									"Failed to remove the adress %s.",
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
					echo digiid_admin_notice("Unknowed action: " . $_REQUEST['action'], 'error');
					break;
				}
			}
		}

		$page_title = _x("My Digi-ID addresses", "page_title", 'Digi-ID-Authentication');
		$add_link_title = __("Add New");

		echo <<<HTML_BLOCK
<div class="wrap">
	<h2>
		<span>{$page_title}</span>
		<a class="add-new-h2" href="?page={$_REQUEST['page']}&action=add">{$add_link_title}</a>
	</h2>

HTML_BLOCK;

		if(!$addresses)
		{
			echo digiid_admin_notice(__("You have no Digi-ID addresses connected to your account.", 'Digi-ID-Authentication'));
			return;
		}

		class my_digiid_addresses extends WP_List_Table
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
				$action_template = '<a href="?page=%s&action=%s&address=%s">%s</a>';
				$actions = array(
					'delete'    => sprintf($action_template, $_REQUEST['page'], 'delete', $item['address'], __('Remove')),
				);
				return $item['address'] . $this->row_actions($actions);
			}

			function get_bulk_actions()
			{
				return array(
					'delete' => __('Delete'),
				);
			}

			function column_cb($item)
			{
				return sprintf('<input type="checkbox" name="digiid_row[]" value="%s" />', $item['address'] );
			}
		}

		echo <<<HTML_BLOCK
	<form action='?page={$_REQUEST['page']}' method='post'>

HTML_BLOCK;
		$my_digiid_addresses = new my_digiid_addresses();
		$my_digiid_addresses->prepare_items();
		$my_digiid_addresses->display();

			echo <<<HTML_BLOCK
	</form>
</div>

HTML_BLOCK;
	}

	function digiid_get_nonce($nonce_action)
	{
		$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";

		$session_id = session_id();

		if(!$session_id)
		{
			session_start();
			$session_id = session_id();
		}

		if(!$session_id)
		{
			return FALSE;
		}

		$query = "DELETE FROM {$table_name_nonce} WHERE birth < NOW() - INTERVAL 3 HOUR";
		$GLOBALS['wpdb']->query($query);

		$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_nonce} WHERE nonce_action = %s AND session_id = %s", $nonce_action, $session_id);
		$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);

		if($nonce_row)
		{
			return $nonce_row['nonce'];
		}
		$nonce_row = array();
		$nonce_row['nonce'] = DigiID::generateNonce();
		$nonce_row['nonce_action'] = $nonce_action;
		$nonce_row['session_id'] = $session_id;
		$nonce_row['birth'] = current_time('mysql');

		$user_id = get_current_user_id();
		if($user_id)
		{
			$nonce_row['user_id'] = $user_id;
		}

		$db_result = $GLOBALS['wpdb']->insert( $table_name_nonce, $nonce_row );
		if($db_result)
		{
			return $nonce_row['nonce'];
		}
		else
		{
			return $db_result;
		}
	}

	function digiid_get_callback_url($nonce = NULL, $nonce_action = NULL)
	{
		if(!$nonce AND $nonce_action)
		{
			$nonce = digiid_get_nonce($nonce_action);
		}

		if(!$nonce)
		{
			return FALSE;
		}

		$url = home_url("digiid/callback?x=" . $nonce);

		if(substr($url, 0, 8) == 'https://')
		{
			return 'digiid://' . substr($url, 8);
		}
		else
		{
			return 'digiid://' . substr($url, 7) . "&u=1";
		}
	}

	function digiid_login_header($messages)
	{
		$url = digiid_get_callback_url(NULL, 'login');

		if(!$url)
		{
			return $messages;
		}

		$title = _x("Digi-ID login", 'qr_image_label', 'Digi-ID-Authentication');
		$alt_text = htmlentities(_x("QR-code for Digi-ID", 'qr_alt_text', 'Digi-ID-Authentication'), ENT_QUOTES);

		$url_encoded_url = urlencode($url);
		$messages .= <<<HTML_BLOCK
<div id='digiid'>
	<p>
		<span>{$title}:</span>
		<a href='{$url}'>
			<img src='https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl={$url_encoded_url}' alt='{$alt_text}' title='{$alt_text}' />
		</a>
	</p>
</div>
HTML_BLOCK;

		return $messages;
	}

	function digiid_login_script()
	{
		$ajax_url = admin_url('admin-ajax.php?action=digiid');

		$js = <<<JS_BLOCK
var digiid_interval_resource;
digiid_interval_resource = setInterval(
	function()
	{
		var ajax = new XMLHttpRequest();
		ajax.open("GET", "{$ajax_url}", true);
		ajax.onreadystatechange =
			function ()
			{
				if(ajax.readyState != 4 || ajax.status != 200)
				{
					return;
				}
				else if(ajax.responseText > '')
				{
					var json = JSON.parse(ajax.responseText);

					if(json.html > '')
					{
						document.getElementById('digiid').innerHTML = json.html;
					}

					if(json.stop > 0)
					{
						window.clearInterval(digiid_interval_resource);
					}

					if(json.reload > 0)
					{
						var redirect = document.getElementsByName("redirect_to");
						if(redirect && redirect[0].value > '')
						{
							window.location.href = redirect[0].value;
						}
						else
						{
							window.location.href = "wp-admin/";
						}
					}
				}
			};
		ajax.send();
	},
	3000
);
JS_BLOCK;

		echo "<script type=\"text/javascript\">\n{$js}\n</script>";
	}

	function digiid_exit()
	{
		$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";

		$session_id = session_id();

		if(!$session_id)
		{
			session_start();
			$session_id = session_id();
		}

		if(!$session_id)
		{
			return FALSE;
		}

		$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_nonce} WHERE session_id = %s", $session_id);
		$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
		if($nonce_row)
		{
			$GLOBALS['wpdb']->delete($table_name_nonce, array('session_id' => $session_id));
		}
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
		return  <<<HTML_BLOCK
	<div class='{$class}'>
		<p>{$text}</p>
	</div>

HTML_BLOCK;
	}
