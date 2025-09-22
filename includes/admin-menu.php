<?php
if (!defined('ABSPATH')) exit;

function osct_add_admin_menu() {
    add_menu_page('OS Content Translator','Content Translator','manage_options','osct-dashboard','osct_render_dashboard','dashicons-translation',58);
    add_submenu_page('osct-dashboard','Dashboard','Dashboard','manage_options','osct-dashboard','osct_render_dashboard');
    add_submenu_page('osct-dashboard','Einstellungen','Einstellungen','manage_options','osct-settings','osct_render_settings');
    add_submenu_page('osct-dashboard','Trockenlauf','Trockenlauf','manage_options','osct-dry-run','osct_render_dry_run');
}
add_action('admin_menu','osct_add_admin_menu');
