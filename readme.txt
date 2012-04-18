=== BuddyPress Reply By Email  ===
Contributors: r-a-y, cuny-academic-commons
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=V9AUZCMECZEQJ
Tags: buddypress, email, basecamp
Requires at least: WordPress 3.2, BuddyPress 1.5
Tested up to: WordPress 3.3.1, BuddyPress 1.5.5
Stable tag: trunk
 
Reply to BuddyPress items from the comfort of your email inbox.

== Description ==

Brings Basecamp-like reply via email functionality to your BuddyPress site.

You can reply to the following items from your inbox:

* @mentions
* Activity replies
* Private messages
* Group forum topics / posts (requires [BP Group Email Subscription Plugin](http://wordpress.org/extend/plugins/buddypress-group-email-subscription/))

You can also create new forum topics from your email inbox as well.

**NOTE**
* Currently, you will need to setup a dedicated IMAP email account that supports [address tags](http://en.wikipedia.org/wiki/Email_address#Address_tags).  Free options include [GMail](http://www.gmail.com) (or [Google Apps Mail](http://www.google.com/apps/intl/en/group/index.html)) and [FastMail.fm](http://www.fastmail.fm/?STKI=6098221) *(referral link)*.
* Your webhost will need to enable the IMAP extension for PHP (if it isn't enabled already).

== Installation ==

**IMPORTANT**
* You will need to setup a dedicated IMAP email account that supports [address tags](http://en.wikipedia.org/wiki/Email_address#Address_tags).  Free options include [GMail](http://www.gmail.com) (or [Google Apps Mail](http://www.google.com/apps/intl/en/group/index.html)) and [FastMail.fm](http://www.fastmail.fm/?STKI=6098221) *(referral link)*.
* Your host will need to enable the IMAP extension for PHP (if it isn't enabled already).

1. Create a new IMAP email account. (GMail is good!)  Do *not* use an existing email account!
1. Install and activate the plugin.
1. Navigate to "BuddyPress > Reply By Email" in the WP admin dashboard and fill in the settings.  If you see a section called "Webhost Warnings", please resolve any issues that appear.
1. Make sure at least the Activity component is enabled in BuddyPress.

== Frequently Asked Questions ==

#### I've filled in my GMail account info in the plugin's settings, but the plugin isn't working! ####

Make sure you've enabled IMAP in your GMail account:
http://mail.google.com/support/bin/answer.py?answer=77695

Also make sure your username and password is correct!


#### How do I reply to group forum topics / posts via email? ####

This feature requires installing the [BP Group Email Subscription Plugin](http://wordpress.org/extend/plugins/buddypress-group-email-subscription/).

*NOTE* You can only reply to group forum topics and posts via email if you've setup your group to receive either "All Mail" or "New Topics" in BP Group Email Subscription.


#### WordPress' pseudo-cron and workaround ####

BuddyPress Reply By Email hooks into Wordpress' scheduling functions and hooks.  (Good!)
However, the way Wordpress works is these schedules are only fired when a user visits your site. (Not-so-good!)

For example, let's say I schedule BP Reply By Email to run every five minutes.  Six minutes pass by and I expect my task to run again, however if no user visits my site, the task will not run. (Tear runs down face!)

This isn't so bad if you have a site that generates decent traffic, but what about smaller-scale sites?

A potential solution is to use an external service to hit your website.  (Basically a cron job for our cron!)

Here are a couple of free options that allow you to do just that!

* [Pingdom](http://www.pingdom.com/#freemodal) - Free plan offers monitoring one website.  Intervals are configurable from 1, 5, 15, 30 or 60 minutes.
* [Alertfox](http://alertfox.com/free-website-monitoring) - Free plan offers to check your site every five minutes.
* [Was It Up?](http://wasitup.com) - Checks your website every five minutes.
* [UptimeRobot](http://www.uptimerobot.com/) - Checks your website every five minutes.

If you know of any others, let me know and I'll list them here!


== Roadmap ==

* Admin settings are currently site-aware.  Make settings network-aware.
* Test with other IMAP providers like Fastmail.FM, etc.  If they work, add prebuilt configuration for these providers.
* Add support for subdomain addressing in a future release.  Fastmail.fm's paid accounts support this (eg. anythinghere@USERNAME.fastmail.fm).


== Screenshots ==

1. An incoming email manipulated by BP Reply By Email.
2. Instructions to post a new forum topic via email.  Hooked to the new topic form.
3. An activity reply made via email posted on BuddyPress.


== Changelog ==

= 1.0-beta-20120418 =
* Make sure we're only connected to the inbox once per session.
* Code cleanup.

= 1.0-beta-20120404 =
* Requires at least BP 1.5.
* When email settings are saved, check to see if the connection is valid.
* Add a debug log. By default, debug log is created at /wp-content/bp-rbe-debug.txt
* Fix posting new forum topics.
* Fix various bugs and notices.

= 1.0-beta =
* Initial private release
* Warning: If this isn't on the WP plugin depository, this means there are still some bugs to work out and is still a work in progress!
