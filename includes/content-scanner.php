<?php
if (! defined('ABSPATH')) exit;

class OSCT_Content_Scanner
{
  public static function scan_all()
  {
    $post_types = get_post_types(['public' => true], 'objects');
    // detect ACF and blocks
    return $post_types;
  }
}
