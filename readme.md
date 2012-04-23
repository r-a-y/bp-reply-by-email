# BuddyPress Reply By Email #

This plugin is **not** quite ready for public consumption yet and is a private release.

Do **not** run this on production environments!

If you feel adventurous, view **readme.txt** for full details about the plugin and installation process.

---

## Testing notes ##

* If you're replying to items via email, make sure that you're replying from the email address you registered with on WordPress. Why? Because RBE checks your email address to see if you're a valid user.
* Check the debug log (wp-content/bp-rbe-debug.txt) if you run into errors and post issues about them.
* If you're upgrading from an older release, you must deactivate the plugin and reactivate it again.
* To stop inbox checks, right now, you'll have to deactivate the plugin.  Still working on making this better.

---

## Todo ##

* Manually allow admins to disable inbox checks in the settings area without deactivating the plugin.
* Look into why the Group Email Subscription plugin sends group forum replies to the same poster.  It should omit posts from the author.

---

## Dev changelog ##

### 20120418 ###

* Make sure we're only connected to the inbox once per session.
* Fix cron scheduling issues.
* Code cleanup.

### 20120404 ###

* Requires at least BP 1.5.
* When email settings are saved, check to see if the credentials are valid.
* Add a debug log. By default, debug log is created at /wp-content/bp-rbe-debug.txt
* Fix posting new forum topics.
* Fix various bugs and notices.