=== BuddyPress Reply By Email  ===
Contributors: r-a-y, cuny-academic-commons
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=V9AUZCMECZEQJ
Tags: buddypress, email, basecamp
Requires at least: WordPress 3.4.x, BuddyPress 1.5.6
Tested up to: WordPress 3.4.x, BuddyPress 1.6.1
Stable tag: trunk
 
Reply to BuddyPress items from the comfort of your email inbox.

== Description ==

BuddyPress Reply By Email is a plugin for [BuddyPress](http://buddypress.org) that allows you to reply to various email notifications from the comfort of your email inbox.

You can reply to the following items from your inbox:

* @mentions
* Activity replies
* Private messages
* Group forum topics / posts (requires [BP Group Email Subscription Plugin](http://wordpress.org/extend/plugins/buddypress-group-email-subscription/))

You can also create new group forum topics from your email inbox as well.

**Minimum Requirements**
* WordPress 3.4.1, BuddyPress 1.5.6
* [IMAP module enabled in PHP](https://github.com/r-a-y/bp-reply-by-email/wiki/Quick-Setup-with-GMail#wiki-server)
* An email address that supports IMAP and [address tags](https://en.wikipedia.org/wiki/Email_address#Address_tags)
* A VPS or dedicated server (recommended) ([see notes about shared hosts here](https://github.com/r-a-y/bp-reply-by-email/wiki/Quick-Setup-with-GMail#wiki-server))

**Wiki**
Check out the [BP Reply By Email wiki](https://github.com/r-a-y/bp-reply-by-email/wiki) for more information!


== Installation ==

**Quick Setup with Gmail**
[Check out the guide here.](https://github.com/r-a-y/bp-reply-by-email/wiki/Quick-Setup-with-GMail)

**Upgrading manually**

If you already have the plugin activated, but you choose to upgrade the plugin manually via FTP, you must deactivate the plugin and reactivate it again.


== Frequently Asked Questions ==

[Check out the FAQ here.](https://github.com/r-a-y/bp-reply-by-email/wiki/Frequently-Asked-Questions)

Also check out the [BP Reply By Email wiki](https://github.com/r-a-y/bp-reply-by-email/wiki) for more information!


== Special Thanks ==

* Jim Wigginton - for his `Crypt_AES` class from the [PHP Secure Communications Library](http://phpseclib.sourceforge.net/). Licensed under the [MIT License](http://www.opensource.org/licenses/mit-license.html).
* Jevon Wright - for his [html2text](https://code.google.com/p/iaml/source/browse/trunk/org.openiaml.model.runtime/src/include/html2text/html2text.php) functions from the [IAML Modelling Platform](http://openiaml.org/).  Licensed under the [Eclipse Public License v1.0](http://www.eclipse.org/legal/epl-v10.html).
* Dan Roscoe - for his [PHP-Tail](https://github.com/ruscoe/PHP-Tail) library. Licensed under the [MIT License](http://www.opensource.org/licenses/mit-license.html).


== Screenshots ==

1. An incoming email manipulated by BP Reply By Email.
2. Instructions to post a new forum topic via email.  Hooked to the new topic form.
3. An activity reply made via email posted on BuddyPress.


== Changelog ==

= 1.0-RC1 =
* Feature: BuddyPress Docs support with BuddyPress groups (requires [BP Group Email Subscription Plugin](http://wordpress.org/extend/plugins/buddypress-group-email-subscription/))
* Feature: Preliminary bbPress 2.2 support - can reply to group forum emails [BP Group Email Subscription Plugin](http://wordpress.org/extend/plugins/buddypress-group-email-subscription/)) and posting new topics via email.  Does not support replying to general bbPress forum emails (those not attached to BuddyPress) as there are some bbPress bugs that need to be fixed.
* Feature: Send failure message to sender if RBE cannot post their item
* Fix: Better parsing support for multipart emails / better encoding support
* Fix: Allow email servers other than GMail to save their settings
* Fix: Do not show 'Post New Topics via Email' block if not a group member
* Enhancement: Add an extension API
* Enhancement: Add failsafe if RBE is stuck during inbox checks
* Enhancement: Update 'last_activity' meta when posting via email for user / group

= 1.0-beta =
* Initial public release
