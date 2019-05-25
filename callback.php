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
				$result[$key] = null;
		}
		return $result;
	}


	$json = null;
	$uri = null;
	$nonce = null;
	
	$GLOBALS['digiid_vars']['json'] = &$json;
	$GLOBALS['digiid_vars']['uri'] = &$uri;
	$GLOBALS['digiid_vars']['nonce'] = &$nonce;
	
	$session_id = session_id();
	if(!$session_id)
	{
		session_start();
		$session_id = session_id();
	}

	if(substr($raw_post_data, 0, 1) == "{")
	{
		$json = json_decode($raw_post_data, true);
		$post_data = digiid_import_data ($json);
	}
	else
	{
		$json = FALSE;
		$post_data = digiid_import_data ($_POST);
	}

	if (!empty($post_data['digiid_addr']))
	{
		$_SESSION['digiid_addr'] = $post_data['digiid_addr'];
	}

	//TEST
	$fp = fopen('test2.log', 'at');
	fwrite($fp, "\r\n");
	fwrite($fp, json_encode(array($post_data)));
	fclose($fp);

	if(!array_filter($post_data)) {
		$fp = fopen('test2.log', 'at');
		fwrite($fp, "72--. ");
		fclose($fp);

		DigiID::http_error(20, 'No data received');
	}

	if(isset($post_data['getaddr'])) {
		$fp = fopen('test2.log', 'at');
		fwrite($fp, "81--. ");
		fclose($fp);

		DigiID::http_ok($post_data['address']);
	}

	$nonce = DigiID::extractNonce($post_data['uri']);

	if(!$nonce OR strlen($nonce) != 32)
	{
		$fp = fopen('test2.log', 'at');
		fwrite($fp, "92--- " . strlen($nonce));
		fclose($fp);

		DigiID::http_error(40, 'Bad nonce' . json_encode($post_data));
	}

	$uri = digiid_get_callback_url($nonce);

	if($uri != $post_data['uri']) {
		$fp = fopen('test2.log', 'at');
		fwrite($fp, "104--- " . json_encode(array('expected' => $uri, 'sent_uri' => $post_data['uri'])));
		fclose($fp);

		DigiID::http_error(10, 'Bad URI', NULL, NULL, array('expected' => $uri, 'sent_uri' => $post_data['uri']));
	}

	$table_name_nonce = "{$GLOBALS['wpdb']->prefix}digiid_nonce";
	$table_name_userlink = "{$GLOBALS['wpdb']->prefix}digiid_userlink";
	$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_nonce} WHERE nonce = %s", $nonce);
	$nonce_row = $GLOBALS['wpdb']->get_row($query, ARRAY_A);

	if(!$nonce_row)
	{
		$fp = fopen('test2.log', 'at');
		fwrite($fp, "114--- " . json_encode($nonce_row));
		fclose($fp);

		DigiID::http_error(41, 'Bad or expired nonce');
	}

	// For registration
	if($nonce_row && $nonce_row['nonce_action'] != 'login' 
		&& $nonce_row['address'] && $nonce_row['address'] != $post_data['address'])
	{
		$fp = fopen('test2.log', 'at');
		fwrite($fp, "123--- " . json_encode(array($nonce_row['address'], $post_data['address'])));
		fclose($fp);

		DigiID::http_error(41, 'Bad or expired nonce');// . $nonce_row['address'] . '!=' . $post_data['address']);
	}

	$digiid = new DigiID();
	
	$signValid = $digiid->isMessageSignatureValidSafe($post_data['address'], $post_data['signature'], $post_data['uri'], FALSE);

	if(!$signValid) {
		$fp = fopen('test2.log', 'at');
		fwrite($fp, "136--- ");
		fclose($fp);

		DigiID::http_error(30, 'Bad signature', $post_data['address'], $post_data['signature'], $post_data['uri']);
	}

	if(!$nonce_row['address'])
	{
		$nonce_row['address'] = $post_data['address'];

		$fp = fopen('test2.log', 'at');
		fwrite($fp, "\r\n");
		fwrite($fp, json_encode(array('all right!', $nonce_row['nonce_action'])));
		fclose($fp);

		switch($nonce_row['nonce_action'])
		{
			case 'register':
				// No duplicates allowed
				$query = $GLOBALS['wpdb']->prepare("SELECT * FROM {$table_name_userlink} WHERE address = %s", $nonce_row['address']);
				$result = $GLOBALS['wpdb']->get_row($query, ARRAY_A);
				if ($result) {
					$fp = fopen('test2.log', 'at');
					fwrite($fp, "158--- ");
					fclose($fp);
			
					DigiID::http_error(42, 'Already registered');
				}

				// Store to session
				//$_SESSION['digiid_scanned_addr'] = $post_data['address'];

				$fp = fopen('test2.log', 'at');
				fwrite($fp, "\r\n");
				fwrite($fp, json_encode(array('GOT IT!', $result)));
				fclose($fp);
				
				//DigiID::http_ok($post_data['address'], $nonce);
				//break;

			case 'login':

				$fp = fopen('test3.log', 'at');
				fwrite($fp, "\r\n" . $nonce_row['nonce_action']);
				fwrite($fp, json_encode(array('all right!', json_encode(array('address' => $post_data['address'], 'nonce' => $nonce)))));
				fclose($fp);

				$db_result = $GLOBALS['wpdb']->update( $table_name_nonce, array('address' => $post_data['address']), array('nonce' => $nonce));
				if(!$db_result)
					DigiID::http_error(50, 'Database failer', 500, 'Internal Server Error');

				// rest is done in ajax
				break;

/*			case 'register':
			{
				// Store to session
				session_start();
				$_SESSION['digiid_scanned_addr'] = $post_data['address'];

				// rest is done in ajax
				break;
			}*/

			case 'add':
			{
				if($nonce_row['user_id'])
				{
					$query = $GLOBALS['wpdb']->prepare("INSERT INTO {$table_name_userlink} SET user_id = %d, address = %s, birth = NOW()", $nonce_row['user_id'], $post_data['address']);
					$GLOBALS['wpdb']->query($query);
				}
				else
					DigiID::http_error(51, "Can't add Digi-ID to a userless session", 500, 'Internal Server Error');
			}
		}
	}

	$fp = fopen('test2.log', 'at');
	fwrite($fp, "215--- " . json_encode(array($post_data, $nonce)));
	fclose($fp);

	DigiID::http_ok($post_data['address'], $nonce);
	
