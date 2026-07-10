=== Taka Virtual Gallery ===
Contributors: taka
Tags: gallery, masonry, virtualization, elementor, nas
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.1.2
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

= 0.1.2 =
* Reduce the virtual masonry window to a half-viewport buffer above and below.
* Bind signed derivative URLs to a browser session and reject direct navigation.
