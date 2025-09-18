<?php
if (! defined('ABSPATH')) exit;

function osct_config_page()
{
  include OSCT_PATH . 'views/config.php';
}

function osct_save_config()
{
  if (!current_user_can('manage_options')) return;
  // save $_POST data, sanitize, update_option
}
add_action('admin_post_osct_save_config', 'osct_save_config');
