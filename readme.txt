=== Taka Virtual Gallery ===
Contributors: taka
Tags: gallery, masonry, virtualization, elementor, nas
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private NAS-backed galleries with an Elementor widget, incremental indexing,
review workflow, signed derivative URLs, and a virtualized masonry frontend.

== Installation ==

This plugin requires private original and derivative directories plus Apache
mod_xsendfile. Configure both absolute paths in Taka Gallery settings before
scanning media. Host-specific deployment configuration is not included.

== Security boundary ==

Original files, original names, EXIF data, and permanent media URLs are not
sent to public browsers. Derivative URLs are bound to an HttpOnly browser
session and direct document navigation is rejected. Any pixels displayed by a
browser can still be saved through developer tools or screenshots. This plugin
is not DRM.

== Changelog ==

= 0.1.5 =
* Fix numeric filename cursor ordering so background scans do not skip images after a batch boundary.
* Add persistent background NAS scan progress in the gallery manager.
* Add published-photo withdrawal and an excluded-photo tab with restore-to-review actions.

= 0.1.4 =
* Fix proportional Imagick thumbnail generation by disabling invalid fill mode.
* Report process results and clarify queued thumbnail placeholders.
* Count only processed pending-review assets in the admin summary.

= 0.1.3 =
* Fail closed unless Apache confirms that mod_xsendfile delivery is configured.
* Remove pending review counts from the anonymous galleries response.

= 0.1.2 =
* Reduce the virtual masonry window to a half-viewport buffer above and below.
* Bind signed derivative URLs to a browser session and reject direct navigation.
