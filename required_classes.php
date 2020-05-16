<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( ! defined( 'DIGIID_AUTHENTICATION_PLUGIN_VERSION') ) exit;

require_once('classes/CurveFpInterface.php');
require_once('classes/CurveFp.php');
require_once('classes/PointInterface.php');
require_once('classes/Point.php');
require_once('classes/gmp_Utils.php');
require_once('classes/SignatureInterface.php');
require_once('classes/Signature.php');
require_once('classes/NumberTheory.php');
require_once('classes/PublicKeyInterface.php');
require_once('classes/PublicKey.php');
require_once('classes/digiid.php');

