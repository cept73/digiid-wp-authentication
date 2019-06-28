<?php
/**
 * Runing from wp_ajax_nopriv_digiid action
 * 
 * Status:
 * 		-1: error
 * 		1: success, what we expect
 * 		2: alternative way
 *
 * Reload:
 * 		0: not needed
 * 		1: is flag to refresh page
 * 
 * Stop:
 * 		
 */

namespace DigiIdAuthentication;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( ! defined( 'DIGIID_AUTHENTICATION_PLUGIN_VERSION') ) exit;

	$session_id = session_id();
	if(!$session_id)
	{
		session_start();
		$session_id = session_id();
	}

	$current_user_id = get_current_user_id();

	$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";
	$table_name_userlink = "{$GLOBALS['wpdb']->prefix}digiid_userlink";
	$action = isset($_REQUEST['type']) ? $_REQUEST['type'] : ''; 

	function digiid_login($user)
	{
		$user_id = $user->ID;
		$user = get_user_by( 'id', $user_id ); 
		if( $user ) {
			wp_set_current_user( $user_id, $user->user_login );
			wp_set_auth_cookie( $user_id );
			do_action( 'wp_login', $user->user_login );
		}

/*		wp_set_current_user($user->ID, $user->data->user_login);
		wp_set_auth_cookie($user->ID);
		do_action('wp_login', $user->data->user_login, $user);
return [$user->ID, $user->data->user_login];*/
	}
/*		wp_set_current_user(1, 'cept');
		wp_set_auth_cookie(1);
		do_action('wp_login', 'cept', null);*/

	$data = array();

	// Some special queries running without nonce
	if (isset($_REQUEST['type']) && $_REQUEST['type'] == 'del')
	{
		$GLOBALS['wpdb']->delete($table_name_userlink, array('address' => $_REQUEST['digiid_addr'], 'user_id' => $current_user_id));
		$data['reload'] = 1;
		print json_encode($data);
		die();
	}

	// Есть запись?
	$query = $GLOBALS['wpdb']->prepare($ask = "SELECT * FROM {$table_name_nonce} WHERE session_id = %s AND nonce_action = %s", array($session_id, $action));
	$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
	if (!$nonce_row)
	{
		$data['status'] = -1;
		$data['reload'] = 0;
		//$data['sql'] = $ask; // $nonce_row['address']
		//$data['sql-params'] = json_encode(array($session_id, $action));
		$data['wanted'] = $action;

		print json_encode($data);
		die();
	}

	// Есть запись но адрес уже вписан?
	$query = $GLOBALS['wpdb']->prepare($ask = "SELECT * FROM {$table_name_nonce} WHERE session_id = %s AND nonce_action = %s AND address is not null", array($session_id, $action));
	$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
	// Не нашли такую
	if (!$nonce_row)
	{
		$data['status'] = -1;
		$data['reload'] = 0;
		/*$data['sql'] = $ask;
		$data['sql-params'] = json_encode(array($session_id, $action));
		$data['row'] = json_encode($nonce_row);*/
		//$data['html'] = __("Error: The current session doesn't have a Digi-ID-nonce.", 'Digi-ID-Authentication');
	}
	else
	{
		switch ($nonce_row['nonce_action'])
		{
			case 'login':
			case 'wc-login':
			case 'wc-myaccount':
				if ($nonce_row['address'])
				{
					$data['address'] = $nonce_row['address'];

					$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_userlink} WHERE address = %s", $data['address']);
					$user_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
					$digiid_success_but_not_connected = false;
					if ($user_row)
					{
						if (is_user_logged_in())
						{
							$data['status'] = 1;

							if ($nonce_row['nonce_action'] == 'login')
							{
								$data['reload'] = 1;
								$data['stop'] = 1;
								$data['redirect_url'] = home_url('wp-admin');
							}
							else
							{
								$data['reload'] = 1;
								$data['stop'] = 1;
								$data['redirect_url'] = get_permalink(get_option('woocommerce_myaccount_page_id'));
								$data['html'] = __("Already logged in", 'Digi-ID-Authentication');
							}

							$GLOBALS['wpdb']->delete($table_name_nonce, array('address' => $data['address']));
						}
						else
						{
							$user = get_user_by ('id', $user_row['user_id']);
							if ($user)
							{
								digiid_login($user);

								$data['status'] = 1;
								$data['reload'] = 1;
								$data['html'] = sprintf(__("Success, loged in as", 'Digi-ID-Authentication') . " <strong>%s</strong>", $user->user_login);

								$update_query = $GLOBALS['wpdb']->prepare("UPDATE {$table_name_userlink} SET pulse = NOW() WHERE address = %s", $data['address']);
								$GLOBALS['wpdb']->query($update_query);
								$GLOBALS['wpdb']->delete($table_name_nonce, array('address' => $data['address']));
							}
							else
							{
								$data['status'] = 2;
								$digiid_success_but_not_connected = true;
							}
						}
					}
					else
					{
						$digiid_success_but_not_connected = true;
						$data['status'] = 2;
						$data['stop'] = 1;
						/*if ($nonce_row['nonce_action'] == 'wc-login')
						{
							$data['reload'] = 1;
							$data['redirect_url'] = get_permalink(get_option('woocommerce_myaccount_page_id'));
						}*/
					}
					
					if ($digiid_success_but_not_connected)
					{
						$data['html'] = sprintf(
							__("Digi-ID verification success, but no active user account connected to address:", 'Digi-ID-Authentication')
							. " <a onclick='javascript:digiid_copyToClipboard(\"%s\");alert(\""
							. __("Address copied to clipboard", "Digi-ID-Authentication") . "\")' title='"
							. __("Press for copy to clipboard", "Digi-ID-Authentication") . "'>"
							. "<strong>%s</strong></a>"
							. "<p style='margin-top:10px'>"
							. __("If you are already registered, you might add this address to <b>Users - Digi-ID</b>", 'Digi-ID-Authentication')
							. '</p>',
							$data['address'],
							$data['address']
						);

						// Help to solve problem
						if ($nonce_row['nonce_action'] == 'login')
							// wp-admin/
							$register_url = esc_url (home_url('wp-login.php?action=register'));
						else
							// other sources
							$register_url = get_permalink(get_option('woocommerce_myaccount_page_id')) . '?digiid_addr=' . $data['address'];

						$data['html'] .= 
							'<p style="margin-top:10px">'
							. '<a class="button button-small" href="javascript: digiid_clear_qr()">Scan QR from other device</a> ';

						if ( get_option( 'users_can_register' ) ) 
							$data['html'] .= '<a class="button button-small" href="' . $register_url . '&nonce=' . $nonce_row['nonce'] . '">Register user</a>';
						
						$data['html'] .= '</p>';

						$_SESSION['digiid_addr'] = $data['address'];
						$update_query = $GLOBALS['wpdb']->prepare("UPDATE {$table_name_nonce} SET address = null WHERE address = %s", $data['address']);
						$GLOBALS['wpdb']->query($update_query);
						//$GLOBALS['wpdb']->delete($table_name_nonce, array('address' => $data['address'])); //, 'action' => $nonce_row['nonce_action']
					}
				}
				else
				{
					$data['status'] = -1;
				}

				break;

			case 'register':
				if ($nonce_row['address'])
				{
					$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_userlink} "
						. "WHERE address = %s AND action = %s", array($nonce_row['address'], $nonce_row['nonce_action']));
					$result = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
					if ($result) {
						$data['status'] = 2;
						$data['html'] = 
							__("Already registered. ", 'Digi-ID-Authentication') . 
							'<a href="' . esc_url('wp-admin') . '">' .
								__("Please login.", 'Digi-ID-Authentication') .
							'</a>';
						break;
					}
	
					// Got it!
					$data['status'] = 1;
					$data['address'] = $nonce_row['address'];

					// Remove all old records
					$update_query = $GLOBALS['wpdb']->prepare("UPDATE {$table_name_nonce} SET address = null WHERE address = %s", $nonce_row['address']);
					$GLOBALS['wpdb']->query($update_query);
				}
				else
					$data['status'] = -1;

				break;

			case 'add':
				if (!empty($nonce_row['address']))
				{
					$data['status'] = 1;
					$data['reload'] = 1;
					$data['stop'] = 1;

					// Add user address
					$userlink_row = array();
					$userlink_row['user_id'] = $current_user_id;
					$userlink_row['address'] = $nonce_row['address'];
					$userlink_row['birth'] = current_time('mysql');
					$userlink_row['pulse'] = 'null';

					// Run query and listen errors
					ob_start();
					$GLOBALS['wpdb']->show_errors();
					$result = $GLOBALS['wpdb']->insert($table_name_userlink, $userlink_row);
					$error = ob_get_contents();
					ob_end_clean();
					if (false === $result)
					{
						$msg = $error;
						if ($code_pos = strpos($msg, '<code>')) $msg = substr($msg, 0, $code_pos);
						$msg = strip_tags($msg);
						$data['message'] = $msg;
						$data['message_class'] = 'digiid_error';
					}

					// Delete nonce
					$GLOBALS['wpdb']->delete($table_name_nonce, array('address' => $nonce_row['address'], 'nonce_action' => $nonce_row['nonce_action']));
				}
				else
				{
					$data['status'] = 0;
				}
				break;

			default:
			{
				$data['status'] = -1;
				$data['html'] = __("Unknown action: ", 'Digi-ID-Authentication') . $nonce_row['nonce_action'];
				break;
			}
		}
	}

	print json_encode($data);
	die();
