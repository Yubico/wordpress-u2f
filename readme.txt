=== U2F for Wordpress ===
Contributors: yubico
Tags: authentication, login
Requires at least: 4.0
Tested up to: 4.1
Stable tag: trunk
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allows users to login using U2F devices.

== Description ==

This plugin adds support for the two factor authentication standard U2F.

The functionality is similar to the U2F (Security Key) support available for Google accounts:

* Users registers U2F devices themselves.
* Users are not required to register devices.
* A user can have multiple devices registered.
* Currently, only Google Chrome is supported.
 

== Installation ==

This section describes how to install the plugin and get it working.

e.g.

1. Upload `u2f.php` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to _Settings_ -> _U2F_.
1. Set https://developers.yubico.com/U2F/App_ID.html[App ID] to the the base URL of your website, for example _https://mysite.wordpress.com_.

== Changelog ==

= 0.2 =
* Initial release.

== Upgrade Notice ==

= 0.2 =
None
