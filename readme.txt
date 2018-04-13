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
1. check if the server has the "GMP PHP extension", if not see if you (or the server admins) can install it.
2. Upload it to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==
= What is digiid? =
DigiID is a authentication protocol, where the secret never levave the user.
This is done by the server sending a task to the client, and the client mathematicly prove that it has the secret.
Read more at https://github.com/digibyte/digiid

= How do i use digiid? =
You install a bitcoin in your phone, for exemple mycelium or schildbach.
(There are at the current time no clients in the android market, they are both in a testing phase)

== reference ==
This project is built ontop of code made in other projects:

* digiid-protocol @ https://github.com/LaurentMT/bitiid (forked from https://github.com/bitiid/bitiid )
* digiid-php @ https://github.com/conejoninja/bitiid-php
* phpeec @ https://github.com/mdanter/phpecc
