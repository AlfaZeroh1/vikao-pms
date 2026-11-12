<?php
/** Every ecommerce page needs the marketplace DB. */
if (!ecom_ok()) { echo '<div class="vk-panel">' . ui_empty('fa-plug-circle-xmark', 'Marketplace not connected', 'Set ecom_db (host, name, user, password, site_url, uploads_dir) in config/config.php so the PMS can manage vikao.io.') . '</div>'; return false; }
return true;
