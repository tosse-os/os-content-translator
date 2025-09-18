<div class="wrap osct-config">
  <h1>Einstellungen</h1>
  <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
    <input type="hidden" name="action" value="osct_save_config">
    <!-- Checkboxen zu Inhaltstypen, API-Key, Provider -->
    <?php submit_button(); ?>
  </form>
</div>
