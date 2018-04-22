<?php

	$session_id = session_id();

	if(!$session_id)
	{
		session_start();
		$session_id = session_id();
	}

	$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";
	$table_name_userlink = "{$GLOBALS['wpdb']->prefix}digiid_userlink";
	$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_nonce} WHERE session_id = %s", $session_id);
	$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);

	$data = array();

	if(!$nonce_row)
	{
		$data['status'] = -1;
		$data['html'] = "<p>" . __("Error: The current session doesn't have a Digi-ID-nonce.", 'Digi-ID-Authentication') . "</p>";
	}
	else
	{
		switch($nonce_row['nonce_action'])
		{
			case 'login':
			{
				if($nonce_row['address'])
				{
					$data['status'] = 1;
					$data['adress'] = $nonce_row['address'];

					$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_userlink} WHERE address = %s", $data['adress']);
					$user_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
					if($user_row)
					{
						$query = $GLOBALS['wpdb']->delete($table_name_nonce, array('session_id' => $session_id));

						if(is_user_logged_in())
						{
							$data['html'] = "<p>" . __("Allredy logged in", 'Digi-ID-Authentication') . "</p>";
							$data['reload'] = 1;
						}
						else
						{
							$user = get_user_by( 'id', $user_row['user_id'] );
							if($user)
							{
								wp_set_current_user($user->ID, $user->user_login);
								wp_set_auth_cookie($user->ID);
								do_action('wp_login', $user->user_login, $user);

								$data['html'] = "<p>" . sprintf(__("Sucess, loged in as '%s'", 'Digi-ID-Authentication'), $user->user_login) . "</p>";
								$data['reload'] = 1;

								$update_query = $GLOBALS['wpdb']->prepare("UPDATE {$table_name_userlink} SET pulse = NOW() WHERE address = %s", $data['adress']);
								$GLOBALS['wpdb']->query($update_query);
							}
							else
							{
								$data['html'] = "<p>" . sprintf(__("Digi-ID verification Sucess, but no useraccount connected to '%s'", 'Digi-ID-Authentication'), $data['adress']) . "</p>";
							}
						}
					}
					else
					{
						$data['html'] = "<p>" . sprintf(__("Digi-ID verification Sucess, but no useraccount connected to '%s'", 'Digi-ID-Authentication'), $data['adress']) . "</p>";
					}
				}
				else
				{
					$data['status'] = 0;
				}

				break;
			}

			default:
			{
				$data['status'] = -1;
				$data['html'] = "<p>" . __("Unknown action: ", 'Digi-ID-Authentication') . $user_row['nonce_action'] . "</p>";
				break;
			}
		}
	}

	echo json_encode($data) . PHP_EOL;
	die();
?>
