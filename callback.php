<?php

	$raw_post_data = file_get_contents('php://input');

	$variables = array('address', 'signature', 'uri');

	$post_data = array();

	$json = NULL;
	$uri = NULL;
	$nonce = NULL;

	$GLOBALS['digiid_vars']['json'] = &$json;
	$GLOBALS['digiid_vars']['uri'] = &$uri;
	$GLOBALS['digiid_vars']['nonce'] = &$nonce;

	if(substr($raw_post_data, 0, 1) == "{")
	{
		$json = json_decode($raw_post_data, TRUE);
		foreach($variables as $key)
		{
			if(isset($json[$key]))
			{
				$post_data[$key] = (string) $json[$key];
			}
			else
			{
				$post_data[$key] = NULL;
			}
		}
	}
	else
	{
		$json = FALSE;
		foreach($variables as $key)
		{
			if(isset($_POST[$key]))
			{
				$post_data[$key] = (string) $_POST[$key];
			}
			else
			{
				$post_data[$key] = NULL;
			}
		}
	}

	require_once("digiid.php");

	if(!array_filter($post_data))
	{
		DigiID::http_error(20, 'No data recived');
		die();
	}

	$nonce = DigiID::extractNonce($post_data['uri']);

	if(!$nonce OR strlen($nonce) != 32)
	{
		DigiID::http_error(40, 'Bad nonce');
		die();
	}

	$uri = digiid_get_callback_url($nonce);

	if($uri != $post_data['uri'])
	{
		DigiID::http_error(10, 'Bad URI', NULL, NULL, array('expected' => $uri, 'sent_uri' => $post_data['uri']));
		die();
	}

	$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";
	$table_name_userlink = "{$GLOBALS['wpdb']->prefix}digiid_userlink";
	$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_nonce} WHERE nonce = %s", $nonce);
	$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);

	if(!$nonce_row)
	{
		DigiID::http_error(41, 'Bad or expired nonce');
		die();
	}

	if($nonce_row AND $nonce_row['address'] AND $nonce_row['address'] != $post_data['address'])
	{
		DigiID::http_error(41, 'Bad or expired nonce');
		die();
	}

	$digiid = new DigiID();

	$signValid = $digiid->isMessageSignatureValidSafe($post_data['address'], $post_data['signature'], $post_data['uri'], FALSE);

	if(!$signValid)
	{
		DigiID::http_error(30, 'Bad signature', $post_data['address'], $post_data['signature'], $post_data['uri']);
		die();
	}

	if(!$nonce_row['address'])
	{
		$nonce_row['address'] = $post_data['address'];

		switch($nonce_row['nonce_action'])
		{
			case 'login':
			{
				$db_result = $GLOBALS['wpdb']->update( $table_name_nonce, array('address' => $post_data['address']), array('nonce' => $nonce));
				if(!$db_result)
				{
					DigiID::http_error(50, 'Database failer', 500, 'Internal Server Error');
					die();
				}
				// rest is done in ajax
				break;
			}

			case 'add':
			{
				if($nonce_row['user_id'])
				{
					$query = $GLOBALS['wpdb']->prepare("INSERT INTO {$table_name_userlink} SET user_id = %d, address = %s, birth = NOW()", $nonce_row['user_id'], $post_data['address']);
					$GLOBALS['wpdb']->query($query);
				}
				else
				{
					DigiID::http_error(51, "Can't add Digi-ID to a userless session", 500, 'Internal Server Error');
					die();
				}
			}
		}
	}

	DigiID::http_ok($post_data['address'], $nonce);
	die();
