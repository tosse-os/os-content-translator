<div class="wrap osct-scan">
  <h1>Scan-Ergebnis</h1>
  <?php
  $types = OSCT_Content_Scanner::scan_all();
  echo '<ul>';
  foreach ($types as $slug => $pt) {
    echo '<li>' . esc_html($pt->label) . '</li>';
  }
  echo '</ul>';
  ?>
</div>
