=== Carousel Cutter ===
Contributors: ayodejiagboola
Tags: instagram, carousel, social media, tool
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later

Turn a block of text into ready-to-post Instagram carousel slides (1080x1350).

== Usage ==
Add the shortcode to any page (in Elementor, use the Shortcode widget on a full-width section):

    [carousel_cutter]

Optional attributes:
* credit_url="https://ayodejiagboola.com/services/"  Where the "Built by" footer links (default: /services/ on this site).
* credit_name="Ayodeji Agboola"
* subscribe="no"  Hide the email opt-in. By default it appears only when the MarkSui Signup plugin is active and configured; signups are sent to MarkSui tagged "carousel-cutter" and "tool".

== Notes ==
* Everything runs in the visitor's browser; their text is never sent to the server. Only an email they choose to submit is.
* The tool runs in an iframe so the theme's styles and the tool's styles can't interfere.
* Bundles JSZip 3.10.1 (MIT or GPLv3).
* If you use a caching or security plugin that blocks the REST API for logged-out visitors, allow /wp-json/carousel-cutter/v1/subscribe.
