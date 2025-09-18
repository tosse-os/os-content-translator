<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap osct-dashboard">
  <h1>Übersetzungs-Dashboard</h1>

  <?php if (!function_exists('pll_get_languages')): ?>
    <div class="notice notice-error"><p>Polylang nicht gefunden. Bitte installieren/aktivieren.</p></div>
  <?php endif; ?>

  <?php if (isset($_GET['updated'])): ?>
    <div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert.</p></div>
  <?php endif; ?>

  <div class="card" style="padding:16px;margin-top:12px;">
    <h2>Inhaltsübersicht</h2>
    <table class="widefat striped">
      <thead>
        <tr>
          <th>Inhaltstyp</th>
          <th>Anzahl veröffentlicht</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stats['by_type'] as $pt => $count): ?>
          <tr>
            <td><?php echo esc_html($pt); ?></td>
            <td><?php echo esc_html($count); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th>Gesamt</th>
          <th><?php echo esc_html($stats['total']); ?></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
    <input type="hidden" name="action" value="osct_save_dashboard">
    <?php wp_nonce_field('osct_save_dashboard', 'osct_nonce'); ?>

    <div class="card" style="padding:16px;">
      <h2>Sprachen</h2>
      <table class="widefat striped">
        <thead>
          <tr>
            <th>Sprache</th>
            <th>Code</th>
            <th>Flagge</th>
            <th>Aktiv</th>
            <th>Provider</th>
            <th>Gesetz</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($languages)): ?>
            <?php foreach ($languages as $slug => $lang): ?>
              <?php
                $row = isset($lang_settings[$slug]) ? $lang_settings[$slug] : [];
                $active = isset($row['active']) ? (int)$row['active'] : 0;
                $prov = isset($row['provider']) ? $row['provider'] : $default_provider;
                $law = isset($row['law']) ? (int)$row['law'] : 0;
                $flag = isset($lang->flag_url) ? $lang->flag_url : '';
                $code = isset($lang->locale) && $lang->locale ? $lang->locale : (isset($lang->slug) ? $lang->slug : '');
                $name = isset($lang->name) ? $lang->name : $slug;
              ?>
              <tr>
                <td><?php echo esc_html($name); ?></td>
                <td><?php echo esc_html($code); ?></td>
                <td><?php if ($flag): ?><img src="<?php echo esc_url($flag); ?>" alt="" style="height:14px;"><?php endif; ?></td>
                <td>
                  <label>
                    <input type="hidden" name="lang_active[<?php echo esc_attr($slug); ?>]" value="0">
                    <input type="checkbox" name="lang_active[<?php echo esc_attr($slug); ?>]" value="1" <?php checked($active, 1); ?>>
                  </label>
                </td>
                <td>
                  <select name="lang_provider[<?php echo esc_attr($slug); ?>]">
                    <?php foreach ($providers as $key => $label): ?>
                      <option value="<?php echo esc_attr($key); ?>" <?php selected($prov, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <label>
                    <input type="hidden" name="lang_law[<?php echo esc_attr($slug); ?>]" value="0">
                    <input type="checkbox" name="lang_law[<?php echo esc_attr($slug); ?>]" value="1" <?php checked($law, 1); ?>>
                  </label>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6">Keine Sprachen gefunden.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <p>
      <button class="button button-primary">Speichern</button>
      <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'osct-config'], admin_url('admin.php'))); ?>">Zu den Einstellungen</a>
    </p>
  </form>
</div>
