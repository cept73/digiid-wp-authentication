=== DigiID Authentication ===
Contributors: digicontributer
Tags: Authentication
Requires at least: 3.0.1
Tested up to: 4.1
Stable tag: 1.0.0-20151004
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== Description ==
= DigiID Authentication =
extends wordpress default authentication with the digiid-protocol

== Installation ==
1. check if the server has the "GMP PHP extension", if not you or a server admin must install it.
2. Upload it to the `/wp-content/plugins/digiid-wp-authentication/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==
= What is digiid? =
DigiID is an authentication protocol, where the secret is tied to your existing DigiByte Wallet.

= How do i use digiid? =
Install a Digiid compatable wallet (currently DigiByteGo and DigiByte-Wallet)

== reference ==
This project is built ontop of code made in other projects:

* digiid-protocol @ https://github.com/LaurentMT/bitiid (forked from https://github.com/bitiid/bitiid )
* digiid-php @ https://github.com/conejoninja/bitiid-php
* phpeec @ https://github.com/mdanter/phpecc
