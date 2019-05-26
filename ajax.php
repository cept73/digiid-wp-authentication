<?php
/**
 * Runing from wp_ajax_nopriv_digiid action
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

	$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";
	$table_name_userlink = "{$GLOBALS['wpdb']->prefix}digiid_userlink";

	function digiid_login($user)
	{
		wp_set_current_user($user->ID, $user->user_login);
		wp_set_auth_cookie($user->ID);
		do_action('wp_login', $user->user_login, $user);
	}

	$data = array();

	// Есть запись?
	$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_nonce} WHERE session_id = %s", $session_id);
	$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
	if (!$nonce_row)
	{
		$data['status'] = -1;
		$data['reload'] = 1;

		print json_encode($data);
		die();
	}

	// Есть запись но адрес уже вписан?
	$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_nonce} WHERE session_id = %s AND address is not null", $session_id);
	$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);

	if (!$nonce_row)
	{
		$data['status'] = 0;
		$data['reload'] = 0;
		//$data['html'] = __("Error: The current session doesn't have a Digi-ID-nonce.", 'Digi-ID-Authentication');
	}
	else
	{
		switch ($nonce_row['nonce_action'])
		{
			case 'login':
				if ($nonce_row['address'])
				{
					$data['status'] = 1;
					$data['address'] = $nonce_row['address'];

					$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_userlink} WHERE address = %s", $data['address']);
					$user_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
					$digiid_success_but_not_connected = false;
					if($user_row)
					{
						// Remove all old records
						//$GLOBALS['wpdb']->delete($table_name_nonce, array('nonce' => $nonce_row['nonce']));

						if(is_user_logged_in())
						{
							$data['html'] = __("Already logged in", 'Digi-ID-Authentication');
							//$data['reload'] = 1;
						}
						else
						{
							$user = get_user_by ('id', $user_row['user_id']);
							if($user)
							{
								digiid_login($user);

								$data['html'] = sprintf(__("Success, loged in as", 'Digi-ID-Authentication') . " <strong>%s</strong>", $user->user_login);
								$data['reload'] = 1;

								$update_query = $GLOBALS['wpdb']->prepare("UPDATE {$table_name_userlink} SET pulse = NOW() WHERE address = %s", $data['address']);
								$GLOBALS['wpdb']->query($update_query);
							}
							else
							{
								$digiid_success_but_not_connected = true;
							}
						}
					}
					else
					{
						$digiid_success_but_not_connected = true;
						$data['stop'] = 1;
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
						$register_url = esc_url (home_url('wp-login.php?action=register'));
						$data['html'] .= 
							'<p style="margin-top:10px">'
							. '<a class="button button-small" href="javascript: digiid_clear_qr()">Scan QR from other device</a> '
							. '<a class="button button-small" href="' . $register_url . '">Register user</a>'
							. '</p>';

						$GLOBALS['wpdb']->delete($table_name_nonce, array('address' => $data['address']));
					}
				}
				else
				{
					$data['status'] = 0;
				}

				break;

			case 'register':
				if($nonce_row['address'])
				{
					$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_userlink} WHERE address = %s", $nonce_row['address']);
					$result = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
					if ($result) {
						$data['status'] = 0;
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
					$GLOBALS['wpdb']->delete($table_name_nonce, array('address' => $nonce_row['address']));
				}
				else
					$data['status'] = 0;

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
