<?php

namespace DigiIdAuthentication;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( ! defined( 'DIGIID_AUTHENTICATION_PLUGIN_VERSION') ) exit;

	$raw_post_data = file_get_contents('php://input');
	require_once("required_classes.php");

	function digiid_import_data ($input)
	{
		$result = array ();

		$variables = array('address'=>'string', 'signature'=>'string', 'uri'=>'string');
		foreach($variables as $key => $type)
		{
			if(isset($input[$key]))
			{
				$raw_val = $input[$key];
				$sanitized_val = '';
				switch ($type) 
				{
					case 'string': 	$sanitized_val = sanitize_text_field($raw_val); break;
					case 'url': 	$sanitized_val = esc_url_raw($raw_val); break;
				}
				$result[$key] = $sanitized_val;
			}
			else
			{
				$result[$key] = NULL;
			}
		}
		return $result;
	}


	$json = NULL;
	$uri = NULL;
	$nonce = NULL;

	$GLOBALS['digiid_vars']['json'] = &$json;
	$GLOBALS['digiid_vars']['uri'] = &$uri;
	$GLOBALS['digiid_vars']['nonce'] = &$nonce;

	if(substr($raw_post_data, 0, 1) == "{")
	{
		$json = json_decode($raw_post_data, TRUE);
		$post_data = digiid_import_data ($json);
	}
	else
	{
		$json = FALSE;
		$post_data = digiid_import_data ($_POST);
	}

	if(!array_filter($post_data))
	{
		DigiID::http_error(20, 'No data recived');
		die();
	}

	$nonce = DigiID::extractNonce($post_data['uri']);

	if(!$nonce OR strlen($nonce) != 32)
	{
		DigiID::http_error(40, 'Bad nonce' . json_encode($post_data));
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
		DigiID::http_error(41, 'Bad or expired nonce' . $nonce_row['address'] . '!=' . $post_data['address']);
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
