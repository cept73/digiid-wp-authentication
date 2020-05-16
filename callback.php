<?php
	namespace DigiIdAuthentication;

	if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
	if ( ! defined( 'DIGIID_AUTHENTICATION_PLUGIN_VERSION') ) exit;

	require_once("required_classes.php");
	function digiid_import_data($input)
	{
		$result = [];

		$variables = ['address'=>'string', 'signature'=>'string', 'uri'=>'string'];
		foreach($variables as $key => $type) {
			if (isset($input[$key])) {
				$raw_val = $input[$key];
				$sanitized_val = '';
				switch ($type) {
					case 'string': 	$sanitized_val = sanitize_text_field($raw_val); break;
					case 'url': 	$sanitized_val = esc_url_raw($raw_val); 		break;
				}
				$result[$key] = $sanitized_val;
			}
			else {
				$result[$key] = null;
			}
		}
		return $result;
	}


	$raw_post_data = file_get_contents('php://input');

	$json	= null;
	$uri 	= null;
	$nonce 	= null;
	$GLOBALS['digiid_vars']['json'] 	= &$json;
	$GLOBALS['digiid_vars']['uri'] 		= &$uri;
	$GLOBALS['digiid_vars']['nonce'] 	= &$nonce;

	$session_id = session_id();
	if (!$session_id) {
		session_start();
		$session_id = session_id();
	}

	$current_user_id = get_current_user_id();

	if (substr($raw_post_data, 0, 1) == "{") {
		$json = json_decode($raw_post_data, true);
		$post_data = digiid_import_data($json);
	}
	else {
		$json = FALSE;
		$post_data = digiid_import_data ($_POST);
	}

	if (!empty($post_data['digiid_addr'])) {
		$_SESSION['digiid_addr'] = $post_data['digiid_addr'];
	}

	if (!array_filter($post_data)) {
		DigiID::http_error(20, 'No data received');
	}

	if (isset($post_data['getaddr'])) {
		DigiID::http_ok($post_data['address']);
	}

    $post_data_uri = $post_data['uri'];
    $amp_pos = strpos($post_data_uri, '&');
    if ($amp_pos > 0) {
        $post_data_uri = substr($post_data_uri, 0, $amp_pos);
    }

	$nonce = DigiID::extractNonce($post_data_uri);
	if (!$nonce || strlen($nonce) != 32) {
		DigiID::http_error(40, 'Bad nonce' . json_encode($post_data));
	}

	$uri = digiid_get_callback_url($nonce);

	if ($uri != $post_data_uri) {
		error_log('Not match ' . $uri . ' != ' . $post_data_uri);
		DigiID::http_error(10, 'Bad URI', NULL, NULL, ['expected' => $uri, 'sent_uri' => $post_data['uri']]);
	}

	$wpdb = $GLOBALS['wpdb'];

	$table_name_nonce = "{$wpdb->prefix}digiid_nonce";
	$table_name_userlink = "{$wpdb->prefix}digiid_userlink";
	$query = $wpdb->prepare("SELECT * FROM {$table_name_nonce} WHERE nonce = %s", $nonce);
	$nonce_row = $wpdb->get_row($query, ARRAY_A);

	if (!$nonce_row) {
		error_log('No such nonce ' . $nonce);
		DigiID::http_error(41, 'Bad or expired nonce');
	}

	// For registration
	if ($nonce_row && $nonce_row['nonce_action'] != 'login'
		&& $nonce_row['address'] && $nonce_row['address'] != $post_data['address']) {
		error_log('Different nonce: ' . $nonce_row['address'] . '!=' . $post_data['address']);
		DigiID::http_error(41, 'Bad or expired nonce');
	}

	$digiid = new DigiID();

	$signValid = $digiid->isMessageSignatureValidSafe(
		$post_data['address'], 
		$post_data['signature'], 
		$post_data['uri'], 
		FALSE);

	if (!$signValid) {
		error_log('bad signature ' . json_encode($post_data));
		DigiID::http_error(30, 'Bad signature', $post_data['address'], $post_data['signature'], $post_data['uri']);
	}

	if (!$nonce_row['address']) {
		$nonce_row['address'] = $post_data['address'];
		switch ($nonce_row['nonce_action']) {
			case 'register':
				// No duplicates allowed
				$query = $wpdb->prepare(
					"SELECT * FROM {$table_name_userlink} WHERE address = %s AND nonce_action = %s", 
					[$nonce_row['address'], $nonce_row['nonce_action']]
				);
				$result = $wpdb->get_row($query, ARRAY_A);
				if ($result) {
					DigiID::http_error(42, 'Already registered');
				}

			case 'login':
			case 'wc-login':
			case 'wc-myaccount':

				$db_result = $wpdb->update(
					$table_name_nonce, 
					['address' => $post_data['address']], 
					['nonce' => $nonce, 'nonce_action' => $nonce_row['nonce_action']]
				);

				if (!$db_result) {
					error_log('database fail');
					DigiID::http_error(50, 'Database fail', 502, 'Internal Server Error');
				}

				break;

			case 'add':
				$current_user_id = $nonce_row['user_id'];
				if ($current_user_id) {
					$wpdb->update(
						$table_name_nonce, 
						['address' => $post_data['address']],
						['nonce' => $nonce, 'nonce_action' => $nonce_row['nonce_action']]
					);
				}
				else {
					error_log("Can't add Digi-ID to a userless session");
					DigiID::http_error(51, "Can't add Digi-ID to a userless session", 501, 'Internal Server Error');
				}	
		}
	}

	DigiID::http_ok($post_data['address'], $nonce);
