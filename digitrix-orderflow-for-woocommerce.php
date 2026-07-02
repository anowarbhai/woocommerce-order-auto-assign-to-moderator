<?php
/**
 * Plugin Name: Digitrix OrderFlow for WooCommerce
 * Plugin URI: https://digitrixlabs.io
 * Description: Manage WooCommerce order assignment, moderator workflows, and remote order imports for operations teams.
 * Version: 1.0.0
 * Author: Digitrix Labs
 * Author URI: https://github.com/anowarbhai
 * Company: Digitrix Labs
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: digitrix-orderflow-for-woocommerce
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * Requires Plugins: woocommerce
 */

// Prevent direct access
if (!defined('ABSPATH')) {
 exit;
}

define('AOAM_VERSION', '1.0.0');
define('AOAM_PLUGIN_FILE', __FILE__);
define('AOAM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AOAM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AOAM_PLUGIN_BASENAME', plugin_basename(__FILE__));

function aoam_is_post_request() {
 $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
 return 'POST' === $request_method;
}

function aoam_is_remote_import_running() {
 global $aoam_doing_import_depth;
 return !empty($aoam_doing_import_depth);
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
 add_action('admin_notices', 'aoam_woocommerce_missing_notice');
 return;
}

function aoam_woocommerce_missing_notice() {
 ?>
 <div class="error">
 <p><?php esc_html_e('Digitrix OrderFlow for WooCommerce requires WooCommerce to be installed and active.', 'digitrix-orderflow-for-woocommerce'); ?></p>
 </div>
 <?php
}

function aoam_load_admin_page_files() {
 $page_files = array(
 'dashboard.php',
 'recent-assignments.php',
 'sequence-status.php',
 'product-assignments.php',
 'plugin-settings.php',
 'remote-import.php',
 'reassign-orders.php',
 );

 foreach ($page_files as $page_file) {
 require_once AOAM_PLUGIN_DIR . 'includes/pages/' . $page_file;
 }
}
aoam_load_admin_page_files();

function aoam_is_plugin_admin_screen() {
 $screen = function_exists('get_current_screen') ? get_current_screen() : null;
 if (!$screen || empty($screen->id)) {
 return false;
 }

 return strpos($screen->id, 'moderator') !== false || strpos($screen->id, 'order-management') !== false;
}

add_action('admin_enqueue_scripts', 'aoam_enqueue_admin_assets', 20);
function aoam_enqueue_admin_assets() {
 if (!aoam_is_plugin_admin_screen()) {
 return;
 }

 $screen = get_current_screen();
 wp_enqueue_style('dashicons');
 wp_enqueue_style('aoam-admin', AOAM_PLUGIN_URL . 'assets/css/admin.css', array(), AOAM_VERSION);
 wp_enqueue_script('jquery');

 if (strpos($screen->id, 'moderator-recent-assignments') !== false) {
 wp_enqueue_style('aoam-recent-assignments', AOAM_PLUGIN_URL . 'assets/css/recent-assignments.css', array('aoam-admin'), AOAM_VERSION);
 wp_enqueue_script('aoam-recent-assignments', AOAM_PLUGIN_URL . 'assets/js/recent-assignments.js', array('jquery'), AOAM_VERSION, true);
 }

 if (strpos($screen->id, 'digitrix-orderflow-my-orders') !== false) {
 wp_enqueue_style('aoam-moderator-orders', AOAM_PLUGIN_URL . 'assets/css/moderator-orders.css', array('aoam-admin'), AOAM_VERSION);
 wp_enqueue_script('aoam-moderator-orders', AOAM_PLUGIN_URL . 'assets/js/moderator-orders.js', array('jquery'), AOAM_VERSION, true);
 }

 wp_enqueue_script('aoam-admin-core', AOAM_PLUGIN_URL . 'assets/js/admin-core.js', array('jquery'), AOAM_VERSION, true);
 wp_localize_script('aoam-admin-core', 'aoamAdmin', array(
 'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php')),
 'version' => AOAM_VERSION,
 'screenId' => $screen->id,
 ));
}

add_filter('plugin_action_links_' . AOAM_PLUGIN_BASENAME, 'aoam_plugin_action_links');
function aoam_plugin_action_links($links) {
 $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=moderator-settings')) . '">' . esc_html__('Dashboard', 'digitrix-orderflow-for-woocommerce') . '</a>';
 $docs_link = '<a href="' . esc_url(admin_url('admin.php?page=moderator-plugin-settings')) . '">' . esc_html__('Settings', 'digitrix-orderflow-for-woocommerce') . '</a>';
 array_unshift($links, $settings_link, $docs_link);
 return $links;
}


// Get assigned roles dynamically
function aoam_get_assigned_roles() {
 return get_option('aoam_assigned_roles', array('moderator'));
}

// ====================================================
// SHIFT SETTINGS FUNCTIONS
// ====================================================

// Get shift settings
function aoam_get_shift_settings() {
 $default_shifts = array(
 'shift_1' => array(
 'name' => '1st Shift',
 'start' => '09:01',
 'end' => '22:00',
 'color' => '#4CAF50'
 ),
 'shift_2' => array(
 'name' => '2nd Shift',
 'start' => '22:01',
 'end' => '02:00',
 'color' => '#2196F3'
 ),
 'shift_3' => array(
 'name' => '3rd Shift',
 'start' => '02:01',
 'end' => '09:00',
 'color' => '#FF9800'
 )
 );
 
 return get_option('aoam_shift_settings', $default_shifts);
}

// Update shift settings
function aoam_update_shift_settings($shifts) {
 update_option('aoam_shift_settings', $shifts);
}

// Plugin Settings Page with Shift Settings
function aoam_plugin_settings_page() {
 if (!current_user_can('manage_options')) {
 wp_die(esc_html__('You do not have permission to access this page.', 'digitrix-orderflow-for-woocommerce'));
 }
 
 // Handle form submissions
 if (aoam_is_post_request()) {
 if (isset($_POST['update_aoam_settings'], $_POST['aoam_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['aoam_settings_nonce'])), 'aoam_settings')) {
 // Update assigned roles
 $selected_roles = isset($_POST['assigned_roles']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['assigned_roles'])) : array();
 update_option('aoam_assigned_roles', $selected_roles);
 
 echo '<div class="notice notice-success"><p>Settings updated successfully!</p></div>';
 }
 
 // Handle shift settings update
 if (isset($_POST['update_shift_settings'], $_POST['shift_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shift_settings_nonce'])), 'shift_settings')) {
 $shift_settings = array();
 
 for ($i = 1; $i <= 3; $i++) {
 $shift_key = 'shift_' . $i;
 $shift_settings[$shift_key] = array(
 'name' => isset($_POST['shift_name_' . $i]) ? sanitize_text_field(wp_unslash($_POST['shift_name_' . $i])) : '',
 'start' => isset($_POST['shift_start_' . $i]) ? sanitize_text_field(wp_unslash($_POST['shift_start_' . $i])) : '',
 'end' => isset($_POST['shift_end_' . $i]) ? sanitize_text_field(wp_unslash($_POST['shift_end_' . $i])) : '',
 'color' => isset($_POST['shift_color_' . $i]) ? sanitize_hex_color(wp_unslash($_POST['shift_color_' . $i])) : '#0073aa'
 );
 }
 
 aoam_update_shift_settings($shift_settings);
 echo '<div class="notice notice-success"><p>Shift settings updated successfully!</p></div>';
 }
 }
 
 $current_roles = get_option('aoam_assigned_roles', array('moderator'));
 $all_roles = wp_roles()->get_names();
 $shift_settings = aoam_get_shift_settings();
 ?>
 <div class="wrap">
        <h1>Plugin Settings</h1>
 <div class="nav-tab-wrapper">
 <a href="<?php echo admin_url('admin.php?page=moderator-settings'); ?>" class="nav-tab">Dashboard</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-recent-assignments'); ?>" class="nav-tab">Recent Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-sequence-status'); ?>" class="nav-tab">Sequence & Status</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-product-assignments'); ?>" class="nav-tab">Product Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-reassign-orders'); ?>" class="nav-tab">Reassign</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-plugin-settings'); ?>" class="nav-tab nav-tab-active">Plugin Settings</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-remote-import'); ?>" class="nav-tab">Remote Import</a>
 </div>

 <!-- Role Assignment Settings -->
 <div class="card aoam-role-settings-card">
 <div class="aoam-settings-card-header">
 <div>
 <h2>User Role Assignment Settings</h2>
 <p>Select which WordPress roles can receive assigned orders.</p>
 </div>
 <span class="aoam-role-count"><?php echo esc_html(count($current_roles)); ?> selected</span>
 </div>
 <form method="post">
 <?php wp_nonce_field('aoam_settings', 'aoam_settings_nonce'); ?>
 <div class="aoam-role-grid">
 <?php foreach ($all_roles as $role_key => $role_name): 
 if ($role_key === 'administrator') continue;
 $is_checked = in_array($role_key, $current_roles, true);
 ?>
 <label class="aoam-role-option <?php echo $is_checked ? 'is-selected' : ''; ?>">
 <input type="checkbox" name="assigned_roles[]" value="<?php echo esc_attr($role_key); ?>" <?php checked($is_checked); ?>>
 <span class="aoam-role-check"></span>
 <span class="aoam-role-copy">
 <strong><?php echo esc_html($role_name); ?></strong>
 <code><?php echo esc_html($role_key); ?></code>
 </span>
 </label>
 <?php endforeach; ?>
 </div>
 <div class="aoam-role-actions">
 <input type="submit" name="update_aoam_settings" class="button button-primary" value="Save Role Settings">
 </div>
 </form>
 </div>
 <style>
 .aoam-role-settings-card {
 max-width: 980px;
 padding: 24px;
 border: 1px solid #dcdcde;
 border-radius: 8px;
 box-shadow: 0 1px 2px rgba(0,0,0,0.04);
 }
 .aoam-settings-card-header {
 display: flex;
 align-items: flex-start;
 justify-content: space-between;
 gap: 20px;
 margin-bottom: 18px;
 }
 .aoam-settings-card-header h2 {
 margin: 0 0 6px;
 font-size: 18px;
 }
 .aoam-settings-card-header p {
 margin: 0;
 color: #646970;
 }
 .aoam-role-count {
 flex: 0 0 auto;
 padding: 6px 10px;
 border-radius: 999px;
 background: #edf4ff;
 color: #0b5cab;
 font-weight: 700;
 font-size: 12px;
 }
 .aoam-role-grid {
 display: grid;
 grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
 gap: 12px;
 margin: 18px 0 20px;
 }
 .aoam-role-option {
 display: flex;
 align-items: center;
 gap: 12px;
 min-height: 58px;
 padding: 12px 14px;
 border: 1px solid #dcdcde;
 border-radius: 8px;
 background: #fff;
 cursor: pointer;
 transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
 }
 .aoam-role-option:hover {
 border-color: #72aee6;
 box-shadow: 0 1px 3px rgba(0,0,0,0.06);
 }
 .aoam-role-option.is-selected {
 border-color: #2271b1;
 background: #f0f6fc;
 box-shadow: inset 3px 0 0 #2271b1;
 }
 .aoam-role-option input {
 position: absolute;
 opacity: 0;
 pointer-events: none;
 }
 .aoam-role-check {
 width: 20px;
 height: 20px;
 border: 2px solid #8c8f94;
 border-radius: 5px;
 background: #fff;
 box-sizing: border-box;
 position: relative;
 flex: 0 0 auto;
 }
 .aoam-role-option.is-selected .aoam-role-check {
 border-color: #2271b1;
 background: #2271b1;
 }
 .aoam-role-option.is-selected .aoam-role-check:after {
 content: "";
 position: absolute;
 left: 5px;
 top: 2px;
 width: 5px;
 height: 10px;
 border: solid #fff;
 border-width: 0 2px 2px 0;
 transform: rotate(45deg);
 }
 .aoam-role-copy {
 display: flex;
 flex-direction: column;
 gap: 4px;
 min-width: 0;
 }
 .aoam-role-copy strong {
 font-size: 14px;
 color: #1d2327;
 }
 .aoam-role-copy code {
 width: fit-content;
 font-size: 11px;
 color: #50575e;
 background: #f6f7f7;
 border-radius: 4px;
 padding: 2px 6px;
 }
 .aoam-role-actions {
 display: flex;
 justify-content: flex-end;
 padding-top: 4px;
 }
 </style>
 <script>
 jQuery(function($) {
 $('.aoam-role-option input').on('change', function() {
 var $option = $(this).closest('.aoam-role-option');
 $option.toggleClass('is-selected', this.checked);
 var selectedCount = $('.aoam-role-option input:checked').length;
 $('.aoam-role-count').text(selectedCount + ' selected');
 });
 });
 </script>

 <!-- Shift Settings -->
 <div class="card" style="max-width:100%;">
 <h2> Shift Settings</h2>
 <p>Configure the three shifts for order assignment. Users can be assigned to one or multiple shifts.</p>
 
 <form method="post">
 <?php wp_nonce_field('shift_settings', 'shift_settings_nonce'); ?>
 
 <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0;">
 <?php for ($i = 1; $i <= 3; $i++): 
 $shift_key = 'shift_' . $i;
 $shift = isset($shift_settings[$shift_key]) ? $shift_settings[$shift_key] : array();
 ?>
 <div style="padding: 20px; border: 1px solid #ddd; border-radius: 8px; background: <?php echo isset($shift['color']) ? $shift['color'] . '10' : '#f8f9fa'; ?>;">
 <h3 style="margin-top: 0; color: <?php echo isset($shift['color']) ? $shift['color'] : '#0073aa'; ?>;">
 Shift <?php echo $i; ?> Settings
 </h3>
 
 <div style="margin-bottom: 15px;">
 <label style="display: block; margin-bottom: 5px; font-weight: bold;">Shift Name:</label>
 <input type="text" name="shift_name_<?php echo $i; ?>" 
 value="<?php echo isset($shift['name']) ? esc_attr($shift['name']) : 'Shift ' . $i; ?>" 
 style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
 </div>
 
 <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
 <div>
 <label style="display: block; margin-bottom: 5px; font-weight: bold;">Start Time:</label>
 <input type="time" name="shift_start_<?php echo $i; ?>" 
 value="<?php echo isset($shift['start']) ? esc_attr($shift['start']) : '00:00'; ?>" 
 style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
 </div>
 <div>
 <label style="display: block; margin-bottom: 5px; font-weight: bold;">End Time:</label>
 <input type="time" name="shift_end_<?php echo $i; ?>" 
 value="<?php echo isset($shift['end']) ? esc_attr($shift['end']) : '00:00'; ?>" 
 style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
 </div>
 </div>
 
 <div style="margin-bottom: 15px;">
 <label style="display: block; margin-bottom: 5px; font-weight: bold;">Color:</label>
 <input type="color" name="shift_color_<?php echo $i; ?>" 
 value="<?php echo isset($shift['color']) ? esc_attr($shift['color']) : '#0073aa'; ?>" 
 style="width: 100%; height: 40px; border: 1px solid #ddd; border-radius: 4px;">
 </div>
 
 <div style="padding: 10px; background: white; border-radius: 4px; border-left: 4px solid <?php echo isset($shift['color']) ? $shift['color'] : '#0073aa'; ?>;">
 <strong>Current Time:</strong> 
 <?php echo isset($shift['start']) ? $shift['start'] : '00:00'; ?> 
 to 
 <?php echo isset($shift['end']) ? $shift['end'] : '00:00'; ?>
 </div>
 </div>
 <?php endfor; ?>
 </div>
 
 <div style="background: #f0f6ff; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
 <h4 style="margin-top: 0;"> Shift Configuration Tips:</h4>
 <ul style="margin: 10px 0; padding-left: 20px;">
 <?php
 $shift_settings = aoam_get_shift_settings();
 
 foreach ($shift_settings as $shift_key => $shift) {
 $shift_name = esc_html($shift['name']);
 $shift_start = esc_html($shift['start']);
 $shift_end = esc_html($shift['end']);
 
 // Convert 24-hour format to 12-hour format with AM/PM
 $start_time_12hr = gmdate("g:i A", strtotime($shift_start));
 $end_time_12hr = gmdate("g:i A", strtotime($shift_end));
 
 // Determine shift type based on time
 $shift_type = "";
 $start_hour = (int)gmdate('H', strtotime($shift_start));
 $end_hour = (int)gmdate('H', strtotime($shift_end));
 
 if ($shift_key === 'shift_1') {
 $shift_type = "(Day Shift)";
 } elseif ($shift_key === 'shift_2') {
 $shift_type = "(Night Shift 1)";
 } elseif ($shift_key === 'shift_3') {
 $shift_type = "(Night Shift 2)";
 } else {
 // For custom shifts, determine type based on hours
 if ($start_hour >= 6 && $end_hour <= 18) {
 $shift_type = "(Day Shift)";
 } elseif ($start_hour >= 18 || $end_hour <= 6) {
 if ($start_hour >= 22 || $end_hour <= 2) {
 $shift_type = "(Late Night Shift)";
 } else {
 $shift_type = "(Night Shift)";
 }
 } else {
 $shift_type = "(Mixed Shift)";
 }
 }
 
 echo "<li><strong>{$shift_name}:</strong> {$start_time_12hr} to {$end_time_12hr} {$shift_type}</li>";
 }
 ?>
 <li>Each user can be assigned to one or multiple shifts</li>
 <li>Only active users in their assigned shifts will receive orders</li>
 <li>Users with no shifts assigned will receive orders anytime (Always Active)</li>
 <li><strong>Current Server Time:</strong> <?php echo current_time('F j, Y, g:i A'); ?></li>
 </ul>
 
 <!-- Show shift color indicators -->
 <div style="display: flex; gap: 15px; margin-top: 10px; flex-wrap: wrap; align-items: center;">
 <div style="font-weight: bold;">Shift Colors:</div>
 <?php foreach ($shift_settings as $shift_key => $shift): ?>
 <div style="display: flex; align-items: center; gap: 5px;">
 <div style="width: 12px; height: 12px; background: <?php echo $shift['color']; ?>; border-radius: 50%;"></div>
 <span style="font-size: 12px;"><?php echo $shift['name']; ?></span>
 </div>
 <?php endforeach; ?>
 </div>
 </div>
 
 <input type="submit" name="update_shift_settings" class="button button-primary" value=" Save Shift Settings">
 </form>
 </div>
 </div>
 <?php
}

function aoam_get_remote_import_settings() {
 $settings = wp_parse_args(get_option('aoam_remote_import_settings', array()), array(
 'site_url' => '',
 'consumer_key' => '',
 'consumer_secret' => '',
 'statuses' => 'processing,pending,on-hold',
 'per_page' => 20,
 'enabled' => 'yes',
 'sources' => array(),
 ));
 if (empty($settings['sources']) && !empty($settings['site_url'])) {
 $settings['sources'] = array(array(
 'site_url' => $settings['site_url'],
 'consumer_key' => $settings['consumer_key'],
 'consumer_secret' => $settings['consumer_secret'],
 'statuses' => $settings['statuses'],
 'per_page' => $settings['per_page'],
 'enabled' => 'yes',
 ));
 }
 return $settings;
}

add_filter('cron_schedules', 'aoam_remote_import_cron_schedules');
function aoam_remote_import_cron_schedules($schedules) {
 $schedules['aoam_every_five_minutes'] = array(
 'interval' => 300,
 'display' => 'Every 5 minutes',
 );
 return $schedules;
}

add_action('init', 'aoam_schedule_remote_order_import');
function aoam_schedule_remote_order_import() {
 $next_run = wp_next_scheduled('aoam_remote_order_import_cron');
 if ($next_run && wp_get_schedule('aoam_remote_order_import_cron') !== 'aoam_every_five_minutes') {
 wp_clear_scheduled_hook('aoam_remote_order_import_cron');
 $next_run = false;
 }
 if (!$next_run) {
 wp_schedule_event(time() + 300, 'aoam_every_five_minutes', 'aoam_remote_order_import_cron');
 }
 if (!wp_next_scheduled('aoam_delayed_assignment_fallback_cron')) {
 wp_schedule_event(time() + 300, 'aoam_every_five_minutes', 'aoam_delayed_assignment_fallback_cron');
 }
}

add_action('aoam_remote_order_import_cron', 'aoam_import_remote_orders_cron');
function aoam_import_remote_orders_cron() {
 $settings = aoam_get_remote_import_settings();
 if (($settings['enabled'] ?? 'yes') !== 'yes') {
  return;
 }
 $result = aoam_import_remote_orders();
 update_option('aoam_remote_import_last_run', array(
 'timestamp' => time(),
 'time' => current_time('mysql'),
 'result' => is_wp_error($result) ? $result->get_error_message() : $result,
 ));
}

add_action('admin_init', 'aoam_maybe_run_remote_order_import_fallback');
function aoam_maybe_run_remote_order_import_fallback() {
 if (wp_doing_ajax() || wp_doing_cron()) {
  return;
 }
 if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
  return;
 }
 $settings = aoam_get_remote_import_settings();
 if (($settings['enabled'] ?? 'yes') !== 'yes') {
  return;
 }
 if (get_transient('aoam_remote_order_import_fallback_lock')) {
  return;
 }
 $last_run = get_option('aoam_remote_import_last_run', array());
 $last_timestamp = absint($last_run['timestamp'] ?? 0);
 if ($last_timestamp && (time() - $last_timestamp) < 300) {
  return;
 }
 set_transient('aoam_remote_order_import_fallback_lock', 1, 300);
 aoam_import_remote_orders_cron();
}

function aoam_sanitize_remote_import_sources($raw_sources) {
 $sources = array();
 if (!is_array($raw_sources)) {
 return $sources;
 }
 foreach ($raw_sources as $source) {
 $site_url = esc_url_raw(trim($source['site_url'] ?? ''));
 $consumer_key = sanitize_text_field($source['consumer_key'] ?? '');
 $consumer_secret = sanitize_text_field($source['consumer_secret'] ?? '');
 if (empty($site_url) || empty($consumer_key) || empty($consumer_secret)) {
 continue;
 }
 $sources[] = array(
 'site_url' => $site_url,
 'consumer_key' => $consumer_key,
 'consumer_secret' => $consumer_secret,
 'statuses' => sanitize_text_field($source['statuses'] ?? 'processing,pending,on-hold'),
 'per_page' => max(1, min(100, absint($source['per_page'] ?? 20))),
 'enabled' => !empty($source['enabled']) ? 'yes' : 'no',
 );
 }
 return $sources;
}

add_action('wp_ajax_aoam_run_remote_import_ajax', 'aoam_run_remote_import_ajax_handler');
function aoam_run_remote_import_ajax_handler() {
  if (!current_user_can('manage_options')) {
    wp_send_json_error('You do not have permission to perform this action.');
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
  if (!$nonce || !wp_verify_nonce($nonce, 'aoam_remote_import_run_nonce')) {
    wp_send_json_error('Security check failed.');
  }
  $result = aoam_import_remote_orders();
  if (is_wp_error($result)) {
    wp_send_json_error($result->get_error_message());
  }
  wp_send_json_success($result);
}

function aoam_remote_order_import_page() {
 if (!current_user_can('manage_options')) {
 wp_die('You do not have permission to access this page.');
 }

 $settings = aoam_get_remote_import_settings();
 $message = '';

 if (aoam_is_post_request() && isset($_POST['aoam_save_remote_import'])) {
 check_admin_referer('aoam_remote_import_settings', 'aoam_remote_import_nonce');
 $settings['enabled'] = !empty($_POST['enabled']) ? 'yes' : 'no';
 $raw_sources = isset($_POST['sources']) ? wp_unslash($_POST['sources']) : array();
 $settings['sources'] = aoam_sanitize_remote_import_sources($raw_sources);
 update_option('aoam_remote_import_settings', $settings);
 $message = 'Remote import settings saved.';
 }
 ?>
 <div class="wrap">
 <h1>Remote Order Import</h1>
 <div class="nav-tab-wrapper">
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-settings')); ?>" class="nav-tab">Dashboard</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-recent-assignments')); ?>" class="nav-tab">Recent Assignments</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-sequence-status')); ?>" class="nav-tab">Sequence & Status</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-product-assignments')); ?>" class="nav-tab">Product Assignments</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-reassign-orders')); ?>" class="nav-tab">Reassign</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-plugin-settings')); ?>" class="nav-tab">Plugin Settings</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-remote-import')); ?>" class="nav-tab nav-tab-active">Remote Import</a>
 </div>

 <?php if ($message): ?>
 <div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
 <?php endif; ?>

 <div id="aoam-import-result-container" style="display: none;"></div>


 <div class="card" style="max-width: 1100px;">
 <h2>Remote WooCommerce API Sources</h2>
 <form method="post">
 <?php wp_nonce_field('aoam_remote_import_settings', 'aoam_remote_import_nonce'); ?>
 <p><label><input type="checkbox" name="enabled" value="1" <?php checked(($settings['enabled'] ?? 'yes'), 'yes'); ?>> Enable automatic import every 5 minutes</label></p>
 <?php
 $sources = $settings['sources'];
 if (empty($sources)) {
 $sources[] = array('site_url' => '', 'consumer_key' => '', 'consumer_secret' => '', 'statuses' => 'processing,pending,on-hold', 'per_page' => 20, 'enabled' => 'yes');
 }
 ?>
 <style>
 #aoam-remote-sources-table {
 table-layout: fixed;
 width: 100%;
 }
 #aoam-remote-sources-table th,
 #aoam-remote-sources-table td {
 padding: 8px 10px;
 vertical-align: middle;
 }
 #aoam-remote-sources-table th:nth-child(1),
 #aoam-remote-sources-table td:nth-child(1) {
 width: 34px;
 text-align: center;
 }
 #aoam-remote-sources-table th:nth-child(2),
 #aoam-remote-sources-table td:nth-child(2) {
 width: 23%;
 }
 #aoam-remote-sources-table th:nth-child(3),
 #aoam-remote-sources-table td:nth-child(3),
 #aoam-remote-sources-table th:nth-child(4),
 #aoam-remote-sources-table td:nth-child(4) {
 width: 18%;
 }
 #aoam-remote-sources-table th:nth-child(5),
 #aoam-remote-sources-table td:nth-child(5) {
 width: 18%;
 }
 #aoam-remote-sources-table th:nth-child(6),
 #aoam-remote-sources-table td:nth-child(6) {
 width: 70px;
 }
 #aoam-remote-sources-table th:nth-child(7),
 #aoam-remote-sources-table td:nth-child(7) {
 width: 78px;
 }
 #aoam-remote-sources-table input[type="url"],
 #aoam-remote-sources-table input[type="text"],
 #aoam-remote-sources-table input[type="password"],
 #aoam-remote-sources-table input[type="number"] {
 width: 100% !important;
 max-width: 100%;
 box-sizing: border-box;
 }
 #aoam-remote-sources-table .aoam-remove-source-row {
 width: 100%;
 min-height: 32px;
 padding: 0 8px;
 }
 </style>
 <div style="overflow-x: hidden;">
 <table class="widefat striped" id="aoam-remote-sources-table">
 <thead>
 <tr>
 <th>On</th>
 <th>Source Site URL</th>
 <th>Consumer Key</th>
 <th>Consumer Secret</th>
 <th>Statuses</th>
 <th>Limit</th>
 <th>Action</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($sources as $index => $source): ?>
 <tr>
 <td><input type="checkbox" name="sources[<?php echo esc_attr($index); ?>][enabled]" value="1" <?php checked(($source['enabled'] ?? 'yes'), 'yes'); ?>></td>
 <td><input type="url" name="sources[<?php echo esc_attr($index); ?>][site_url]" value="<?php echo esc_attr($source['site_url'] ?? ''); ?>" placeholder="https://example.com" style="width: 220px;"></td>
 <td><input type="text" name="sources[<?php echo esc_attr($index); ?>][consumer_key]" value="<?php echo esc_attr($source['consumer_key'] ?? ''); ?>" style="width: 180px;"></td>
 <td><input type="password" name="sources[<?php echo esc_attr($index); ?>][consumer_secret]" value="<?php echo esc_attr($source['consumer_secret'] ?? ''); ?>" style="width: 180px;"></td>
 <td><input type="text" name="sources[<?php echo esc_attr($index); ?>][statuses]" value="<?php echo esc_attr($source['statuses'] ?? 'processing,pending,on-hold'); ?>" style="width: 180px;"></td>
 <td><input type="number" min="1" max="100" name="sources[<?php echo esc_attr($index); ?>][per_page]" value="<?php echo esc_attr($source['per_page'] ?? 20); ?>" style="width: 70px;"></td>
 <td><button type="button" class="button aoam-remove-source-row">Remove</button></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <p class="description">Add one source per row. Imported orders are duplicate-protected per source.</p>
 <p>
 <button type="button" id="aoam-add-source-row" class="button">Add New Source</button>
 <button type="submit" name="aoam_save_remote_import" class="button button-primary">Save Settings</button>
 </p>
 </form>
 </div>

 <div class="card" style="max-width: 900px;">
 <h2>Manual Import</h2>
 <p>Imports remote orders into this WooCommerce site. Imported orders are assigned by this plugin after creation.</p>
 <button type="button" id="aoam_run_remote_import_btn" class="button button-primary" data-nonce="<?php echo esc_attr(wp_create_nonce('aoam_remote_import_run_nonce')); ?>" style="display: inline-flex; align-items: center; gap: 8px;">
 <span class="aoam-btn-text">Run Import Now</span>
 <span class="spinner aoam-spinner" style="float: none; margin: 0 0 0 5px;"></span>
 </button>
 </div>
 </div>
 <script>
 jQuery(function($) {
 var sourceIndex = <?php echo esc_js(count($sources)); ?>;
 var defaultStatuses = 'processing,pending,on-hold';
 var rowTemplate = function(index) {
 return '<tr>' +
 '<td><input type="checkbox" name="sources[' + index + '][enabled]" value="1" checked></td>' +
 '<td><input type="url" name="sources[' + index + '][site_url]" value="" placeholder="https://example.com" style="width: 220px;"></td>' +
 '<td><input type="text" name="sources[' + index + '][consumer_key]" value="" style="width: 180px;"></td>' +
 '<td><input type="password" name="sources[' + index + '][consumer_secret]" value="" style="width: 180px;"></td>' +
 '<td><input type="text" name="sources[' + index + '][statuses]" value="' + defaultStatuses + '" style="width: 180px;"></td>' +
 '<td><input type="number" min="1" max="100" name="sources[' + index + '][per_page]" value="20" style="width: 70px;"></td>' +
 '<td><button type="button" class="button aoam-remove-source-row">Remove</button></td>' +
 '</tr>';
 };
 
 $('#aoam-add-source-row').on('click', function() {
 $('#aoam-remote-sources-table tbody').append(rowTemplate(sourceIndex));
 sourceIndex++;
 });
 
 $(document).on('click', '.aoam-remove-source-row', function() {
 var $row = $(this).closest('tr');
 var sourceUrl = $row.find('input[name$="[site_url]"]').val() || 'this source';
 var confirmMessage = 'Remove remote import source "' + sourceUrl + '"?\n\nThis source and its saved API credentials will be removed after you click Save Settings.';
 if (!window.confirm(confirmMessage)) {
 return;
 }
 
 var $tbody = $('#aoam-remote-sources-table tbody');
 if ($tbody.find('tr').length <= 1) {
 $row.find('input[type="url"], input[type="text"], input[type="password"]').val('');
 $row.find('input[name$="[statuses]"]').val(defaultStatuses);
 $row.find('input[type="number"]').val('20');
 $row.find('input[type="checkbox"]').prop('checked', true);
 return;
 }
 $(this).closest('tr').remove();
 });
 });
 </script>
 <?php
}

function aoam_import_remote_orders($source_settings = null) {
  global $aoam_doing_import_depth, $wpdb;
  if (!isset($aoam_doing_import_depth)) {
    $aoam_doing_import_depth = 0;
  }
  $is_root_import = ($source_settings === null);
  if ($is_root_import) {
    if (get_transient('aoam_remote_order_import_lock')) {
      return new WP_Error('aoam_remote_import_locked', 'Remote order import is already running.');
    }
    set_transient('aoam_remote_order_import_lock', time(), 10 * MINUTE_IN_SECONDS);
  }
  $aoam_doing_import_depth++;

  try {
    $settings = $source_settings ?: aoam_get_remote_import_settings();
    if ($source_settings === null && !empty($settings['sources'])) {
      $total = array('imported' => 0, 'skipped' => 0, 'failed' => 0);
      foreach ($settings['sources'] as $source) {
        if (($source['enabled'] ?? 'yes') !== 'yes') {
          continue;
        }
        $source_result = aoam_import_remote_orders($source);
        if (is_wp_error($source_result)) {
          $total['failed']++;
          continue;
        }
        $total['imported'] += (int) $source_result['imported'];
        $total['skipped'] += (int) $source_result['skipped'];
        $total['failed'] += (int) $source_result['failed'];
      }
      return $total;
    }
    if (empty($settings['site_url']) || empty($settings['consumer_key']) || empty($settings['consumer_secret'])) {
      return new WP_Error('aoam_missing_remote_settings', 'Remote site URL, consumer key, and consumer secret are required.');
    }

    $statuses = array_filter(array_map('sanitize_key', explode(',', $settings['statuses'])));
    if (empty($statuses)) {
      $statuses = array('processing');
    }

    $endpoint = trailingslashit($settings['site_url']) . 'wp-json/wc/v3/orders';
    
    $result = array('imported' => 0, 'skipped' => 0, 'failed' => 0);
    $page = 1;
    $per_run_limit = max(1, min(100, absint($settings['per_page'] ?? 20)));
    $per_page = $per_run_limit;
    $max_pages = 1;
    $keep_fetching = true;
    $processed_total = 0;

    while ($keep_fetching && $page <= $max_pages && $processed_total < $per_run_limit) {
      if (php_sapi_name() === 'cli') {
        echo "  -> Fetching Page {$page}... URL: " . esc_url($endpoint) . " (Limit: {$per_page})\n";
      }
      $url = add_query_arg(array(
        'per_page' => $per_page,
        'page'     => $page,
        'orderby'  => 'date',
        'order'    => 'desc',
        'status'   => implode(',', $statuses),
      ), $endpoint);

      $response = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array(
          'Authorization' => 'Basic ' . base64_encode($settings['consumer_key'] . ':' . $settings['consumer_secret']),
        ),
      ));

      if (is_wp_error($response)) {
        if (php_sapi_name() === 'cli') {
          echo "     WP_Error during fetch: " . $response->get_error_message() . "\n";
        }
        if ($page === 1) {
          return $response;
        }
        break;
      }

      $code = wp_remote_retrieve_response_code($response);
      if (php_sapi_name() === 'cli') {
        echo "     HTTP Code: {$code}\n";
      }
      if ($code < 200 || $code >= 300) {
        if ($page === 1) {
          return new WP_Error('aoam_remote_import_http_error', 'Remote API returned HTTP ' . $code . '.');
        }
        break;
      }

      $orders = json_decode(wp_remote_retrieve_body($response), true);
      if (!is_array($orders) || empty($orders)) {
        if (php_sapi_name() === 'cli') {
          echo "     No orders found or invalid JSON on Page {$page}.\n";
        }
        break;
      }

      if (php_sapi_name() === 'cli') {
        echo "     Found " . count($orders) . " orders on Page {$page}. Processing...\n";
      }

      $new_imported_in_page = 0;
      $skipped_in_page = 0;

      foreach ($orders as $remote_order) {
        if ($processed_total >= $per_run_limit) {
          break;
        }
        $processed_total++;
        if (empty($remote_order['id'])) {
          $result['failed']++;
          continue;
        }
        if (php_sapi_name() === 'cli') {
          echo "       - Checking remote ID: {$remote_order['id']}...\n";
        }
        $created = aoam_create_order_from_remote_order($remote_order, $settings['site_url']);
        if (is_wp_error($created)) {
          if ($created->get_error_code() === 'aoam_remote_order_exists') {
            $result['skipped']++;
            $skipped_in_page++;
          } else {
            $result['failed']++;
            if (php_sapi_name() === 'cli') {
              echo "     Failed to import remote order ID: " . $remote_order['id'] . " - " . $created->get_error_message() . "\n";
            }
          }
          continue;
        }
        $result['imported']++;
        $new_imported_in_page++;
      }

      if (php_sapi_name() === 'cli') {
        echo "     Page {$page} Done. Imported: {$new_imported_in_page}, Skipped: {$skipped_in_page}, Failed: " . ($result['failed']) . "\n";
      }

      $page++;
    }

    return $result;
  } finally {
    $aoam_doing_import_depth--;
    if ($is_root_import) {
      delete_transient('aoam_remote_order_import_lock');
    }
    if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'check_connection')) {
      $wpdb->check_connection(false);
    }
  }
}

function aoam_create_order_from_remote_order($remote_order, $source_url) {
 $source_key = md5(untrailingslashit($source_url));
 $remote_id = absint($remote_order['id']);
 $existing = wc_get_orders(array(
 'limit' => 1,
 'return' => 'ids',
 'meta_key' => '_aoam_remote_order_key',
 'meta_value' => $source_key . ':' . $remote_id,
 ));
 if (!empty($existing)) {
 return new WP_Error('aoam_remote_order_exists', 'Remote order already imported.');
 }

 try {
 $order = wc_create_order();
 if (!$order) {
 return new WP_Error('aoam_order_create_failed', 'Could not create local order.');
 }

 $billing = $remote_order['billing'] ?? array();
 $shipping = $remote_order['shipping'] ?? array();
 foreach (array('first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone') as $field) {
 if (isset($billing[$field]) && method_exists($order, 'set_billing_' . $field)) {
 $order->{'set_billing_' . $field}($billing[$field]);
 }
 }
 foreach (array('first_name','last_name','company','address_1','address_2','city','state','postcode','country','phone') as $field) {
 if (isset($shipping[$field]) && method_exists($order, 'set_shipping_' . $field)) {
 $order->{'set_shipping_' . $field}($shipping[$field]);
 }
 }

 if (!empty($remote_order['line_items']) && is_array($remote_order['line_items'])) {
 foreach ($remote_order['line_items'] as $line_item) {
 aoam_add_remote_line_item_to_order($order, $line_item);
 }
 }

 if (!empty($remote_order['shipping_lines']) && is_array($remote_order['shipping_lines'])) {
 foreach ($remote_order['shipping_lines'] as $shipping_line) {
 $item = new WC_Order_Item_Shipping();
 $item->set_method_title($shipping_line['method_title'] ?? 'Shipping');
 $item->set_total((float) ($shipping_line['total'] ?? 0));
 $order->add_item($item);
 }
 }

 $order->set_currency($remote_order['currency'] ?? get_woocommerce_currency());
 $order->set_payment_method($remote_order['payment_method'] ?? '');
 $order->set_payment_method_title($remote_order['payment_method_title'] ?? '');
 if (!empty($remote_order['customer_note'])) {
 $order->set_customer_note($remote_order['customer_note']);
 }

 $order->calculate_totals();
 $status = sanitize_key($remote_order['status'] ?? 'processing');
 $order->set_status($status);
 $order->update_meta_data('_aoam_remote_order_id', $remote_id);
 $order->update_meta_data('_aoam_remote_order_source', esc_url_raw($source_url));
 $order->update_meta_data('_aoam_remote_order_key', $source_key . ':' . $remote_id);
 $order->save();
 $order->add_order_note('Imported from remote WooCommerce order #' . $remote_id . '.');

 assign_order_to_specific_moderator($order->get_id(), $order, false);
 return $order->get_id();
 } catch (Exception $e) {
 return new WP_Error('aoam_remote_order_exception', $e->getMessage());
 }
}

function aoam_add_remote_line_item_to_order($order, $line_item) {
 $quantity = max(1, absint($line_item['quantity'] ?? 1));
 $sku = sanitize_text_field($line_item['sku'] ?? '');
 $product = $sku ? wc_get_product_id_by_sku($sku) : 0;
 $product = $product ? wc_get_product($product) : false;

 if ($product) {
 $order->add_product($product, $quantity, array(
 'subtotal' => (float) ($line_item['subtotal'] ?? $line_item['total'] ?? 0),
 'total' => (float) ($line_item['total'] ?? 0),
 ));
 return;
 }

 $item = new WC_Order_Item_Product();
 $item->set_name(sanitize_text_field($line_item['name'] ?? 'Remote Product'));
 $item->set_quantity($quantity);
 $item->set_subtotal((float) ($line_item['subtotal'] ?? $line_item['total'] ?? 0));
 $item->set_total((float) ($line_item['total'] ?? 0));
 if ($sku) {
 $item->add_meta_data('Remote SKU', $sku, true);
 }
 if (!empty($line_item['product_id'])) {
 $item->add_meta_data('Remote Product ID', absint($line_item['product_id']), true);
 }
 $order->add_item($item);
}

add_action('woocommerce_order_status_changed', 'aoam_sync_imported_order_status_to_remote', 20, 4);
function aoam_sync_imported_order_status_to_remote($order_id, $from_status, $to_status, $order) {
 if (aoam_is_remote_import_running()) {
 return;
 }
 if (!$order || !is_a($order, 'WC_Order')) {
 return;
 }

 $remote_id = $order->get_meta('_aoam_remote_order_id');
 $source_url = $order->get_meta('_aoam_remote_order_source');
 if (empty($remote_id) || empty($source_url)) {
 $remote_id = get_post_meta($order_id, '_aoam_remote_order_id', true);
 $source_url = get_post_meta($order_id, '_aoam_remote_order_source', true);
 }
 if (empty($remote_id) || empty($source_url)) {
 return;
 }

 $source = aoam_find_remote_import_source($source_url);
 if (!$source) {
 $order->add_order_note('Remote status sync skipped: source API credentials not found.');
 return;
 }

 $endpoint = trailingslashit($source['site_url']) . 'wp-json/wc/v3/orders/' . absint($remote_id);
 $response = wp_remote_request($endpoint, array(
 'method' => 'PUT',
 'timeout' => 30,
 'headers' => array(
 'Authorization' => 'Basic ' . base64_encode($source['consumer_key'] . ':' . $source['consumer_secret']),
 'Content-Type' => 'application/json',
 ),
 'body' => wp_json_encode(array('status' => sanitize_key($to_status))),
 ));

 if (is_wp_error($response)) {
 $order->add_order_note('Remote status sync failed: ' . $response->get_error_message());
 return;
 }

 $code = wp_remote_retrieve_response_code($response);
 if ($code < 200 || $code >= 300) {
 $order->add_order_note('Remote status sync failed with HTTP ' . $code . '.');
 return;
 }

 $order->add_order_note('Remote order #' . absint($remote_id) . ' status synced to ' . sanitize_key($to_status) . '.');
}

function aoam_find_remote_import_source($source_url) {
 $settings = aoam_get_remote_import_settings();
 $source_url_normalized = untrailingslashit(esc_url_raw($source_url));
 foreach (($settings['sources'] ?? array()) as $source) {
 if (empty($source['site_url']) || empty($source['consumer_key']) || empty($source['consumer_secret'])) {
 continue;
 }
 if (untrailingslashit(esc_url_raw($source['site_url'])) === $source_url_normalized) {
 return $source;
 }
 }
 if (!empty($settings['site_url']) && untrailingslashit(esc_url_raw($settings['site_url'])) === $source_url_normalized) {
 return $settings;
 }
 return false;
}

// Create moderator role if it doesn't exist - ENHANCED VERSION
function create_moderator_role_if_not_exists() {
 if (!get_role('moderator')) {
 // Copy capabilities from subscriber role as base
 $subscriber_caps = get_role('subscriber')->capabilities;
 
 // Enhanced WooCommerce and WordPress capabilities for moderators
 $moderator_caps = array_merge($subscriber_caps, [
 // WordPress core capabilities
 'read' => true,
 'upload_files' => true,
 'edit_posts' => true,
 
 // WooCommerce order capabilities
 'read_private_shop_orders' => true,
 'edit_shop_orders' => true,
 'edit_others_shop_orders' => true,
 'edit_private_shop_orders' => true,
 'edit_published_shop_orders' => true,
 'read_shop_orders' => true,
 'publish_shop_orders' => true,
 'delete_shop_orders' => true,
 'delete_private_shop_orders' => true,
 'delete_published_shop_orders' => true,
 'delete_others_shop_orders' => true,
 
 // WooCommerce product capabilities (read-only)
 'read_private_products' => true,
 'read_products' => true,
 
 // User capabilities
 'edit_users' => true,
 'list_users' => true,
 
 // Custom capabilities for our system
 'manage_moderator_orders' => true,
 'view_assigned_orders' => true,
 ]);
 
 // Create the moderator role
 add_role('moderator', 'Moderator', $moderator_caps);
 
 } else {
 // Update existing moderator role with missing capabilities.
 $moderator_role = get_role('moderator');
 
 // Add missing capabilities to existing role
 $missing_caps = [
 'edit_shop_orders' => true,
 'edit_others_shop_orders' => true,
 'edit_private_shop_orders' => true,
 'edit_published_shop_orders' => true,
 'publish_shop_orders' => true,
 'delete_shop_orders' => true,
 'delete_private_shop_orders' => true,
 'delete_published_shop_orders' => true,
 'delete_others_shop_orders' => true,
 'edit_users' => true,
 'list_users' => true,
 ];
 
 foreach ($missing_caps as $cap => $grant) {
 if (!$moderator_role->has_cap($cap)) {
 $moderator_role->add_cap($cap, $grant);
 }
 }
 
 }
}

// Run on plugin activation and admin init
add_action('admin_init', 'create_moderator_role_if_not_exists');
add_action('wp_loaded', 'create_moderator_role_if_not_exists');

register_activation_hook(__FILE__, 'aoam_activate_plugin');
function aoam_activate_plugin() {
 create_moderator_role_if_not_exists();
 flush_rewrite_rules();
}

// Fix moderator admin access
function fix_moderator_admin_access() {
 $current_user = wp_get_current_user();
 
 $assigned_roles = aoam_get_assigned_roles();
 $user_has_role = !empty(array_intersect($current_user->roles, $assigned_roles));
 
 if ($user_has_role && !current_user_can('manage_options')) {
 if (!current_user_can('edit_posts') && !current_user_can('read_shop_orders')) {
 $current_user->add_cap('read');
 $current_user->add_cap('read_shop_orders');
 $current_user->add_cap('edit_shop_orders');
 }
 }
}
add_action('admin_init', 'fix_moderator_admin_access', 1);

// Fix admin menu visibility for moderators
function fix_moderator_admin_menu() {
 $current_user = wp_get_current_user();
 $assigned_roles = aoam_get_assigned_roles();
 $user_has_role = !empty(array_intersect($current_user->roles, $assigned_roles));
 
 if ($user_has_role && !current_user_can('manage_options')) {
 if (!current_user_can('edit_posts')) {
 $current_user->add_cap('read');
 }
 }
}
add_action('wp_loaded', 'fix_moderator_admin_menu');

// ====================================================
// FIXED: Enhanced assignment with 5-minute delay for partial orders
// ====================================================

// FIXED: Enhanced assignment with 5-minute delay for partial orders
add_action('woocommerce_new_order', 'schedule_order_assignment', 10, 2);

function aoam_get_delayed_assignment_statuses() {
 return array('partial', 'pending', 'on-hold', 'failed');
}

function aoam_get_delayed_assignment_seconds() {
 return 600;
}

function aoam_get_order_local_timestamp($order) {
 if (!$order || !is_a($order, 'WC_Order')) {
 return false;
 }

 $date_created = $order->get_date_created();
 if (!$date_created) {
 return false;
 }

 return $date_created->getTimestamp();
}

function aoam_get_display_timezone() {
 return new DateTimeZone('Asia/Dhaka');
}

function aoam_format_display_date($format, $timestamp = null) {
 return wp_date($format, $timestamp ?: time(), aoam_get_display_timezone());
}

function aoam_format_order_local_date($order, $format) {
 $timestamp = aoam_get_order_local_timestamp($order);
 if (!$timestamp) {
 return '';
 }

 return aoam_format_display_date($format, $timestamp);
}

function aoam_order_has_moderator_assignment($order_id) {
 $order_id = absint($order_id);
 if (!$order_id) {
 return false;
 }

 $assigned_moderator_id = get_post_meta($order_id, '_assigned_moderator_id', true);
 if (!empty($assigned_moderator_id)) {
 return true;
 }

 $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
 if ($order && is_a($order, 'WC_Order')) {
 return !empty($order->get_meta('_assigned_moderator_id', true));
 }

 return false;
}

function aoam_schedule_delayed_assignment($order_id, $order = null, $reason = '') {
 $order_id = absint($order_id);
 if (!$order_id) {
 return false;
 }

 if (aoam_order_has_moderator_assignment($order_id)) {
 return false;
 }

 wp_clear_scheduled_hook('assign_order_to_specific_moderator_delayed', array($order_id));
 wp_schedule_single_event(time() + aoam_get_delayed_assignment_seconds(), 'assign_order_to_specific_moderator_delayed', array($order_id));

 if ($order && is_a($order, 'WC_Order')) {
 $order->add_order_note('Order assignment delayed for 10 minutes' . ($reason ? ': ' . $reason : '') . '.');
 }

 return true;
}

function schedule_order_assignment($order_id, $order) {
 // Get order status
 $order_status = $order->get_status();
  
 // Define which statuses should be delayed for 10 minutes
 $partial_statuses = aoam_get_delayed_assignment_statuses();
 $immediate_statuses = array('processing', 'completed', 'refunded'); // Statuses that trigger immediate assignment
  
 if (in_array($order_status, $partial_statuses)) {
 aoam_schedule_delayed_assignment($order_id, $order, 'status ' . $order_status);
 } elseif (in_array($order_status, $immediate_statuses)) {
 // Assign immediately for complete statuses
 assign_order_to_specific_moderator($order_id, $order);
 }
}

// ====================================================
// SHIFT TIME CHECK FUNCTIONS
// ====================================================

// Check if user is in any of their assigned shifts.
function is_user_in_any_shift($user_id) {
 $status = get_user_meta($user_id, 'moderator_status', true);
 
 // If status is inactive, return false immediately
 if ($status === 'inactive') {
 return false;
 }
 
 // Get user's assigned shifts
 $assigned_shifts = get_user_meta($user_id, 'moderator_assigned_shifts', true);
 
 // If no shifts assigned, user is always available
 if (empty($assigned_shifts) || !is_array($assigned_shifts)) {
 return array(
 'in_shift' => true,
 'shift_key' => 'always_active',
 'shift_name' => 'Always Active',
 'shift_color' => '#46b450'
 );
 }
 
 // Get current time
 $current_time = current_time('H:i');
 $current_hour = (int)gmdate('H', strtotime($current_time));
 $current_minute = (int)gmdate('i', strtotime($current_time));
 $current_decimal = $current_hour + ($current_minute / 60);
 
 // Get shift settings
 $shift_settings = aoam_get_shift_settings();
 
 // Check each assigned shift
 foreach ($assigned_shifts as $shift_key) {
 if (isset($shift_settings[$shift_key])) {
 $shift = $shift_settings[$shift_key];
 
 // Parse start time
 $start_time = $shift['start'];
 list($start_hour, $start_minute) = explode(':', $start_time);
 $start_hour = (int)$start_hour;
 $start_minute = (int)$start_minute;
 $start_decimal = $start_hour + ($start_minute / 60);
 
 // Parse end time
 $end_time = $shift['end'];
 list($end_hour, $end_minute) = explode(':', $end_time);
 $end_hour = (int)$end_hour;
 $end_minute = (int)$end_minute;
 $end_decimal = $end_hour + ($end_minute / 60);
 
 // Handle overnight shifts (end time is earlier than start time)
 if ($end_decimal < $start_decimal) {
 // Shift crosses midnight (e.g., 22:01 to 02:00)
 // Current time is either >= start time OR <= end time
 if ($current_decimal >= $start_decimal || $current_decimal <= $end_decimal) {
 return array(
 'in_shift' => true,
 'shift_key' => $shift_key,
 'shift_name' => $shift['name'],
 'shift_color' => $shift['color']
 );
 }
 } else {
 // Normal shift within same day
 if ($current_decimal >= $start_decimal && $current_decimal <= $end_decimal) {
 return array(
 'in_shift' => true,
 'shift_key' => $shift_key,
 'shift_name' => $shift['name'],
 'shift_color' => $shift['color']
 );
 }
 }
 }
 }
 
 return false;
}


// Get user's assigned shifts
function get_user_assigned_shifts($user_id) {
 $assigned_shifts = get_user_meta($user_id, 'moderator_assigned_shifts', true);
 if (empty($assigned_shifts) || !is_array($assigned_shifts)) {
 return array();
 }
 
 $shift_settings = aoam_get_shift_settings();
 $user_shifts = array();
 
 foreach ($assigned_shifts as $shift_key) {
 if (isset($shift_settings[$shift_key])) {
 $user_shifts[$shift_key] = $shift_settings[$shift_key];
 }
 }
 
 return $user_shifts;
}


// Hook for the delayed assignment
add_action('assign_order_to_specific_moderator_delayed', 'assign_order_to_specific_moderator_delayed_handler');

function assign_order_to_specific_moderator_delayed_handler($order_id) {
 $order = wc_get_order($order_id);
 
 // Check if order still exists
 if (!$order) {
 return;
 }
 
 // Check if order is already assigned
 $already_assigned = get_post_meta($order_id, '_assigned_moderator_id', true);
 if ($already_assigned) {
 return;
 }
 
 // Check if order is still in a partial status
 $order_status = $order->get_status();
 $partial_statuses = aoam_get_delayed_assignment_statuses();
 
 if (in_array($order_status, $partial_statuses)) {
 assign_order_to_specific_moderator($order_id, $order, true);
 } else {
 // If status changed to complete, assign immediately
 assign_order_to_specific_moderator($order_id, $order, false);
 }
}

add_action('aoam_delayed_assignment_fallback_cron', 'aoam_run_delayed_assignment_fallback');
add_action('init', 'aoam_maybe_run_delayed_assignment_fallback', 30);

function aoam_maybe_run_delayed_assignment_fallback() {
 if (get_transient('aoam_delayed_assignment_fallback_lock')) {
 return;
 }

 set_transient('aoam_delayed_assignment_fallback_lock', 1, 240);
 aoam_run_delayed_assignment_fallback();
 aoam_cleanup_stale_delayed_assignment_events();
}

function aoam_run_delayed_assignment_fallback() {
 global $wpdb;

 if (!function_exists('wc_get_order')) {
 return;
 }

 $order_ids = array();
 $delayed_statuses = aoam_get_delayed_assignment_statuses();
 $cutoff_gmt = gmdate('Y-m-d H:i:s', time() - aoam_get_delayed_assignment_seconds());
 $orders_table = $wpdb->prefix . 'wc_orders';
 $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
 $orders_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_table));
 $orders_meta_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_meta_table));

 if ($orders_table_exists === $orders_table) {
 $wc_statuses = array_map(function($status) {
 return 'wc-' . $status;
 }, $delayed_statuses);
 $placeholders = implode(',', array_fill(0, count($wc_statuses), '%s'));
 $orders_meta_join = '';
 $orders_meta_where = '';
 if ($orders_meta_table_exists === $orders_meta_table) {
 $orders_meta_join = "
 LEFT JOIN {$orders_meta_table} assigned_om
 ON assigned_om.order_id = o.id
 AND assigned_om.meta_key = '_assigned_moderator_id'
 ";
 $orders_meta_where = 'AND assigned_om.id IS NULL';
 }
 $sql = "
 SELECT o.id
 FROM {$orders_table} o
 LEFT JOIN {$wpdb->postmeta} assigned_pm
 ON assigned_pm.post_id = o.id
 AND assigned_pm.meta_key = '_assigned_moderator_id'
 {$orders_meta_join}
 WHERE o.type = 'shop_order'
 AND o.status IN ({$placeholders})
 AND (o.date_created_gmt <= %s OR o.date_created_gmt IS NULL)
 AND assigned_pm.meta_id IS NULL
 {$orders_meta_where}
 ORDER BY o.date_created_gmt DESC
 LIMIT 100
 ";
 $order_ids = $wpdb->get_col($wpdb->prepare($sql, array_merge($wc_statuses, array($cutoff_gmt))));
 } else {
 $orders = wc_get_orders(array(
 'status' => $delayed_statuses,
 'limit' => 100,
 'orderby' => 'date',
 'order' => 'DESC',
 'date_created' => '<=' . (time() - aoam_get_delayed_assignment_seconds()),
 'meta_query' => array(
 array(
 'key' => '_assigned_moderator_id',
 'compare' => 'NOT EXISTS',
 ),
 ),
 ));
 foreach ($orders as $order) {
 $order_ids[] = $order->get_id();
 }
 }

 foreach ($order_ids as $order_id) {
 $order = wc_get_order($order_id);
 if (!$order || aoam_order_has_moderator_assignment($order_id)) {
 continue;
 }
 if (in_array($order->get_status(), $delayed_statuses)) {
 assign_order_to_specific_moderator($order_id, $order, true);
 $order->add_order_note('Fallback delayed assignment ran after the scheduled event was missed.');
 }
 }
}

function aoam_cleanup_stale_delayed_assignment_events() {
 if (!function_exists('_get_cron_array') || !function_exists('_set_cron_array')) {
 return;
 }

 $cron = _get_cron_array();
 if (!is_array($cron)) {
 return;
 }

 $hook = 'assign_order_to_specific_moderator_delayed';
 $cutoff = time() - 60;
 $changed = false;

 foreach ($cron as $timestamp => $hooks) {
 if ((int) $timestamp > $cutoff || empty($hooks[$hook])) {
 continue;
 }

 unset($cron[$timestamp][$hook]);
 if (empty($cron[$timestamp])) {
 unset($cron[$timestamp]);
 }
 $changed = true;
 }

 if ($changed) {
 _set_cron_array($cron);
 }
}

// Also run assignment immediately when order status changes to complete statuses
add_action('woocommerce_order_status_changed', 'handle_order_status_change_assignment', 10, 4);
add_action('woocommerce_order_status_changed', 'aoam_track_terminal_status_transition', 8, 4);

function aoam_track_terminal_status_transition($order_id, $from_status, $to_status, $order) {
 $tracked_from_statuses = array('processing', 'partial');
 $tracked_to_statuses = array('completed', 'cancelled');
 $from_status = sanitize_key($from_status);
 $to_status = sanitize_key($to_status);

 if (!in_array($from_status, $tracked_from_statuses, true) || !in_array($to_status, $tracked_to_statuses, true)) {
 return;
 }

 update_post_meta($order_id, '_aoam_terminal_transition_from', $from_status);
 update_post_meta($order_id, '_aoam_terminal_transition_to', $to_status);
 update_post_meta($order_id, '_aoam_terminal_transition_at_gmt', gmdate('Y-m-d H:i:s'));
}

function handle_order_status_change_assignment($order_id, $from_status, $to_status, $order) {
 // Define statuses that should trigger immediate assignment
 $immediate_statuses = array('processing', 'completed');
 $delayed_statuses = aoam_get_delayed_assignment_statuses();
 $already_assigned = aoam_order_has_moderator_assignment($order_id);
 
 if (in_array($to_status, $immediate_statuses)) {
 wp_clear_scheduled_hook('assign_order_to_specific_moderator_delayed', array($order_id));
 if (!$already_assigned) {
 assign_order_to_specific_moderator($order_id, $order, false);
 }
 } elseif (in_array($to_status, $delayed_statuses) && !$already_assigned) {
 aoam_schedule_delayed_assignment($order_id, $order, 'status changed to ' . $to_status);
 }
}

// Cleanup function for scheduled events
function cleanup_moderator_assignment_schedules($order_id) {
 // Clear any scheduled assignments for this order
 wp_clear_scheduled_hook('assign_order_to_specific_moderator_delayed', array($order_id));
}

// Add cleanup when order is completed or cancelled
add_action('woocommerce_order_status_completed', 'cleanup_moderator_assignment_schedules');
add_action('woocommerce_order_status_cancelled', 'cleanup_moderator_assignment_schedules');
add_action('woocommerce_order_status_refunded', 'cleanup_moderator_assignment_schedules');

// ====================================================
// NEW: SHIFT TIME CHECK FUNCTION
// ====================================================

function is_user_in_shift_time($user_id) {
 $status = get_user_meta($user_id, 'moderator_status', true);
 
 // If status is inactive, return false immediately
 if ($status === 'inactive') {
 return false;
 }
 
 // If status is active but no shift time is set, consider always active
 $shift_start = get_user_meta($user_id, 'moderator_shift_start', true);
 $shift_end = get_user_meta($user_id, 'moderator_shift_end', true);
 
 // If no shift time is set, user is always available
 if (empty($shift_start) || empty($shift_end)) {
 return true;
 }
 
 // Get current time
 $current_time = current_time('H:i');
 
 // Convert times to comparable format
 $current_timestamp = strtotime($current_time);
 $start_timestamp = strtotime($shift_start);
 $end_timestamp = strtotime($shift_end);
 
 // Handle overnight shifts (e.g., 22:00 to 06:00)
 if ($end_timestamp < $start_timestamp) {
 // Shift crosses midnight
 if ($current_timestamp >= $start_timestamp || $current_timestamp <= $end_timestamp) {
 return true;
 }
 } else {
 // Normal shift within same day
 if ($current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp) {
 return true;
 }
 }
 
 return false;
}

// ====================================================
// NEW FUNCTION: GET SEQUENCE BASED ON ORDER STATUS
// ====================================================

function get_sequence_for_order_status($order_status) {
 // Define which statuses have their own sequences
 $status_sequences = array(
 'partial' => 'last_assigned_partial_moderator_sequence',
 'processing' => 'last_assigned_processing_moderator_sequence',
 // Add more statuses here if needed
 );
 
 return isset($status_sequences[$order_status]) ? $status_sequences[$order_status] : 'last_assigned_general_moderator_sequence';
}

// ====================================================
// NEW FUNCTION: RESET ALL SEQUENCES (Admin Tool)
// ====================================================

function reset_all_sequences_tool() {
 if (isset($_POST['reset_all_sequences']) && current_user_can('manage_options')) {
 $reset_nonce = isset($_POST['reset_sequences_nonce']) ? sanitize_text_field(wp_unslash($_POST['reset_sequences_nonce'])) : '';
 if (!$reset_nonce || !wp_verify_nonce($reset_nonce, 'reset_sequences')) {
 return;
 }
 // Reset all sequence options
 update_option('last_assigned_general_moderator_sequence', 0);
 update_option('last_assigned_partial_moderator_sequence', 0);
 update_option('last_assigned_processing_moderator_sequence', 0);
 
 // Also reset all product-specific sequences
 global $wpdb;
 $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'last_assigned_moderator_sequence_products_%'");
 
 echo '<div class="notice notice-success"><p>All assignment sequences have been reset!</p></div>';
 }
}

// Add this to your admin page
add_action('admin_init', 'reset_all_sequences_tool');

// ====================================================
// UPDATED ASSIGNMENT FUNCTION WITH SEPARATE SEQUENCES FOR PARTIAL & PROCESSING
// ====================================================

function assign_order_to_specific_moderator($order_id, $order, $delayed = false) {
 // Get order items to determine products
 $items = $order->get_items();
 $product_ids = array();
 
 foreach ($items as $item) {
 $product_ids[] = $item->get_product_id();
 }
 
 // Sort product IDs to maintain consistent key
 sort($product_ids);
 
 // Get order status
 $order_status = $order->get_status();
 
 // Get all active users with assigned roles ordered by SEQUENCE
 $assigned_roles = aoam_get_assigned_roles();
 
 $user_args = array(
 'role__in' => $assigned_roles,
 'meta_query' => array(
 'relation' => 'AND',
 array(
 'key' => 'moderator_sequence',
 'type' => 'NUMERIC',
 'compare' => 'EXISTS'
 ),
 array(
 'key' => 'moderator_status',
 'value' => 'active',
 'compare' => '='
 )
 ),
 'orderby' => 'meta_value_num',
 'meta_key' => 'moderator_sequence',
 'order' => 'ASC',
 'number' => 50
 );
 
 $users = get_users($user_args);
 
 // If no active users with sequence, try without status filter
 if (empty($users)) {
 $user_args['meta_query'] = array(
 array(
 'key' => 'moderator_sequence',
 'type' => 'NUMERIC',
 'compare' => 'EXISTS'
 )
 );
 $users = get_users($user_args);
 }
 
 // If still no users, try by registration date
 if (empty($users)) {
 $user_args = array(
 'role__in' => $assigned_roles,
 'orderby' => 'user_registered',
 'order' => 'ASC',
 'number' => 50
 );
 
 $users = get_users($user_args);
 
 // Add sequence numbers based on registration order
 $sequence = 1;
 foreach ($users as $user) {
 update_user_meta($user->ID, 'moderator_sequence', $sequence);
 $sequence++;
 }
 }
 
 if (empty($users)) {
 $order->add_order_note('No eligible users found for assignment.');
 return;
 }
 
 // Separate users into two groups:
 $specialized_users = array(); // Users with product assignments
 $general_users = array(); // Users WITHOUT product assignments
 
 foreach ($users as $user) {
 $status = get_user_meta($user->ID, 'moderator_status', true);
 // Check if user is in any of their assigned shifts
 $shift_check = is_user_in_any_shift($user->ID);
 
 if ($status === 'active' && $shift_check !== false) {
 $assigned_products = get_user_meta($user->ID, 'moderator_assigned_products', true);
 
 if (!empty($assigned_products)) {
 $specialized_users[] = array(
 'user' => $user,
 'shift_info' => $shift_check
 );
 } else {
 $general_users[] = array(
 'user' => $user,
 'shift_info' => $shift_check
 );
 }
 }
 }
 
 // Sort both groups by sequence
 usort($specialized_users, function($a, $b) {
 $seq_a = get_user_meta($a['user']->ID, 'moderator_sequence', true);
 $seq_b = get_user_meta($b['user']->ID, 'moderator_sequence', true);
 return $seq_a - $seq_b;
 });
 
 usort($general_users, function($a, $b) {
 $seq_a = get_user_meta($a['user']->ID, 'moderator_sequence', true);
 $seq_b = get_user_meta($b['user']->ID, 'moderator_sequence', true);
 return $seq_a - $seq_b;
 });
 
 // STEP 1: Try to find specialized users for these specific products
 $eligible_specialized_users = array();
 
 foreach ($specialized_users as $user_data) {
 $user = $user_data['user'];
 $assigned_products = get_user_meta($user->ID, 'moderator_assigned_products', true);
 
 // Check if any product in this order matches user's assigned products
 $common_products = array_intersect($product_ids, $assigned_products);
 if (!empty($common_products)) {
 $eligible_specialized_users[] = array(
 'user' => $user,
 'common_products' => $common_products,
 'match_count' => count($common_products),
 'shift_info' => $user_data['shift_info']
 );
 }
 }
 
 $assigned_user = null;
 $assignment_type = '';
 $next_sequence = 0;
 $shift_info = null;
 
 // If we found specialized users for these products
 if (!empty($eligible_specialized_users)) {
 $assignment_type = 'product_specific';
 
 // Sort by match count (users with more matching products get priority)
 usort($eligible_specialized_users, function($a, $b) {
 return $b['match_count'] - $a['match_count'];
 });
 
 // Get product-specific sequence key
 $product_specific_sequence_key = 'last_assigned_moderator_sequence_products_' . implode('_', $product_ids);
 $last_assigned_sequence = get_option($product_specific_sequence_key, 0);
 
 // Find the next user based on SEQUENCE from eligible specialized users
 $next_sequence = $last_assigned_sequence + 1;
 
 // If next sequence exceeds max sequence, reset to 1
 $max_sequence = 0;
 foreach ($eligible_specialized_users as $user_data) {
 $current_sequence = get_user_meta($user_data['user']->ID, 'moderator_sequence', true);
 if ($current_sequence > $max_sequence) {
 $max_sequence = $current_sequence;
 }
 }
 
 if ($next_sequence > $max_sequence) {
 $next_sequence = 1;
 }
 
 // Find user with the next sequence
 foreach ($eligible_specialized_users as $user_data) {
 $current_sequence = get_user_meta($user_data['user']->ID, 'moderator_sequence', true);
 if ($current_sequence == $next_sequence) {
 $assigned_user = $user_data['user'];
 $shift_info = $user_data['shift_info'];
 break;
 }
 }
 
 // If no user found with exact sequence, get the next available one
 if (!$assigned_user) {
 // Sort eligible users by sequence
 usort($eligible_specialized_users, function($a, $b) {
 $seq_a = get_user_meta($a['user']->ID, 'moderator_sequence', true);
 $seq_b = get_user_meta($b['user']->ID, 'moderator_sequence', true);
 return $seq_a - $seq_b;
 });
 
 // Find the first user with sequence >= next_sequence
 foreach ($eligible_specialized_users as $user_data) {
 $current_sequence = get_user_meta($user_data['user']->ID, 'moderator_sequence', true);
 if ($current_sequence >= $next_sequence) {
 $assigned_user = $user_data['user'];
 $shift_info = $user_data['shift_info'];
 $next_sequence = $current_sequence;
 break;
 }
 }
 
 // If still no user, get the first one
 if (!$assigned_user) {
 $assigned_user = $eligible_specialized_users[0]['user'];
 $shift_info = $eligible_specialized_users[0]['shift_info'];
 $next_sequence = get_user_meta($assigned_user->ID, 'moderator_sequence', true);
 }
 }
 
 // Update the product-specific sequence
 update_option($product_specific_sequence_key, $next_sequence);
 
 } 
 // STEP 2: If no specialized users found, use general users (no product assignments)
 elseif (!empty($general_users)) {
 $assignment_type = 'general';
 
 // Determine which sequence to use based on order status
 if ($order_status === 'partial') {
 // Use partial-specific sequence
 $sequence_option_key = 'last_assigned_partial_moderator_sequence';
 $sequence_type = 'partial';
 } elseif ($order_status === 'processing') {
 // Use processing-specific sequence
 $sequence_option_key = 'last_assigned_processing_moderator_sequence';
 $sequence_type = 'processing';
 } else {
 // Use general sequence for other statuses
 $sequence_option_key = 'last_assigned_general_moderator_sequence';
 $sequence_type = 'general';
 }
 
 // Get the appropriate sequence
 $last_assigned_sequence = get_option($sequence_option_key, 0);
 $next_sequence = $last_assigned_sequence + 1;
 
 // If next sequence exceeds max sequence, reset to 1
 $max_sequence = 0;
 foreach ($general_users as $user_data) {
 $current_sequence = get_user_meta($user_data['user']->ID, 'moderator_sequence', true);
 if ($current_sequence > $max_sequence) {
 $max_sequence = $current_sequence;
 }
 }
 
 if ($next_sequence > $max_sequence) {
 $next_sequence = 1;
 }
 
 // Find user with the next sequence
 foreach ($general_users as $user_data) {
 $current_sequence = get_user_meta($user_data['user']->ID, 'moderator_sequence', true);
 if ($current_sequence == $next_sequence) {
 $assigned_user = $user_data['user'];
 $shift_info = $user_data['shift_info'];
 break;
 }
 }
 
 // If no user found with exact sequence, get the next available one
 if (!$assigned_user) {
 // Find the first user with sequence >= next_sequence
 foreach ($general_users as $user_data) {
 $current_sequence = get_user_meta($user_data['user']->ID, 'moderator_sequence', true);
 if ($current_sequence >= $next_sequence) {
 $assigned_user = $user_data['user'];
 $shift_info = $user_data['shift_info'];
 $next_sequence = $current_sequence;
 break;
 }
 }
 
 // If still no user, get the first one
 if (!$assigned_user) {
 $assigned_user = $general_users[0]['user'];
 $shift_info = $general_users[0]['shift_info'];
 $next_sequence = get_user_meta($assigned_user->ID, 'moderator_sequence', true);
 }
 }
 
 // Update the appropriate sequence based on order status
 update_option($sequence_option_key, $next_sequence);
 
 // Store sequence type in meta for tracking
 update_post_meta($order_id, '_sequence_type', $sequence_type);
 }
 
 // If no users found at all
 if (!$assigned_user) {
 $order->add_order_note('No active users available for assignment (check shift timings).');
 return;
 }
 
 // Update order with user information
 update_post_meta($order_id, '_assigned_moderator_id', $assigned_user->ID);
 update_post_meta($order_id, '_assigned_moderator_name', $assigned_user->display_name);
 update_post_meta($order_id, '_assigned_moderator_sequence', $next_sequence);
 update_post_meta($order_id, '_assignment_type', $assignment_type);
 
 // Add shift info to order meta
 if ($shift_info && isset($shift_info['shift_name'])) {
 update_post_meta($order_id, '_assigned_shift_name', $shift_info['shift_name']);
 update_post_meta($order_id, '_assigned_shift_key', $shift_info['shift_key']);
 }
 
 // Add order note with detailed information
 $assigned_products = get_user_meta($assigned_user->ID, 'moderator_assigned_products', true);
 $product_note = '';
 
 if (!empty($assigned_products) && $assignment_type === 'product_specific') {
 $product_names = array();
 foreach ($assigned_products as $product_id) {
 $product = wc_get_product($product_id);
 if ($product) {
 $product_names[] = $product->get_name();
 }
 }
 $product_note = ' (Specialized in: ' . implode(', ', $product_names) . ')';
 }
 
 $order_products_info = array();
 foreach ($product_ids as $product_id) {
 $product = wc_get_product($product_id);
 if ($product) {
 $order_products_info[] = $product->get_name();
 }
 }
 
 // Add shift info to note
 $shift_note = '';
 if ($shift_info && isset($shift_info['shift_name'])) {
 $shift_note = ' [' . $shift_info['shift_name'] . ']';
 }
 
 // Add delay notice if assignment was delayed
 $delay_note = $delayed ? ' - Assigned after 5-minute delay for partial order status' : '';
 
 // Add sequence type info for partial/processing orders
 $sequence_type_note = '';
 if (isset($sequence_type) && in_array($sequence_type, ['partial', 'processing'])) {
 $sequence_type_note = ' [' . ucfirst($sequence_type) . ' Sequence]';
 }
 
 if ($assignment_type === 'product_specific') {
 $order->add_order_note(sprintf(
 'Order automatically assigned to User %d: %s%s%s - Product-specific assignment for: %s%s%s',
 $next_sequence,
 $assigned_user->display_name,
 $product_note,
 $shift_note,
 implode(', ', $order_products_info),
 $sequence_type_note,
 $delay_note
 ));
 } else {
 $order->add_order_note(sprintf(
 'Order automatically assigned to User %d: %s%s - %s assignment for: %s%s%s',
 $next_sequence,
 $assigned_user->display_name,
 $shift_note,
 $order_status === 'partial' ? 'Partial' : ($order_status === 'processing' ? 'Processing' : 'General'),
 implode(', ', $order_products_info),
 $sequence_type_note,
 $delay_note
 ));
 }
 
 // Optional: Send email to user
 send_moderator_notification($assigned_user, $order, $shift_info, $delayed);
}

// Updated notification function with shift info
function send_moderator_notification($moderator, $order, $shift_info = null, $delayed = false) {
  global $aoam_doing_import_depth;
  if (!empty($aoam_doing_import_depth) && $aoam_doing_import_depth > 0) {
    return; // Skip email notifications during remote order import to prevent execution timeouts
  }
  $to = $moderator->user_email;
 $subject = 'New Order Assigned to You - Moderator ' . get_user_meta($moderator->ID, 'moderator_sequence', true);
 
 $delay_info = $delayed ? "\n\n Note: This assignment was delayed for 5 minutes due to partial order status." : "";
 
 // Get shift info
 $shift_info_text = '';
 if ($shift_info && isset($shift_info['shift_name'])) {
 $shift_info_text = "\nShift: " . $shift_info['shift_name'];
 }
 
 $message = "
 Hello " . $moderator->display_name . ",

 A new order has been assigned to you!
 " . $shift_info_text . "

 Order Details:
 Order #: " . $order->get_id() . "
 Customer: " . $order->get_formatted_billing_full_name() . "
 Total: " . $order->get_formatted_order_total() . "
Date: " . aoam_format_order_local_date($order, 'F j, Y g:i A') . "
 
 " . $delay_info . "

 Please process this order promptly.

 Login to your dashboard to view details: " . admin_url('edit.php?post_type=shop_order') . "

 Thank you!
 ";

 wp_mail($to, $subject, $message);
 
 // Update last active timestamp
 update_user_meta($moderator->ID, 'moderator_last_active', current_time('mysql'));
}


// Admin menu for separate pages
add_action('admin_menu', 'moderator_sequence_settings_menu');

function aoam_get_admin_page_registry() {
 return array(
 'dashboard' => array(
 'page_title' => 'Order Management',
 'menu_title' => 'Order Management',
 'capability' => 'manage_options',
 'menu_slug' => 'moderator-settings',
 'callback' => 'aoam_dashboard_page',
 'icon' => 'dashicons-sort',
 'position' => 56,
 ),
 'recent_assignments' => array(
 'page_title' => 'Recent Assignments',
 'menu_title' => 'Recent Assignments',
 'capability' => 'manage_options',
 'menu_slug' => 'moderator-recent-assignments',
 'callback' => 'aoam_recent_assignments_page',
 ),
 'sequence_status' => array(
 'page_title' => 'Moderator Sequence & Status',
 'menu_title' => 'Sequence & Status',
 'capability' => 'manage_options',
 'menu_slug' => 'moderator-sequence-status',
 'callback' => 'aoam_sequence_status_page',
 ),
 'product_assignments' => array(
 'page_title' => 'Assign Products to Moderators',
 'menu_title' => 'Product Assignments',
 'capability' => 'manage_options',
 'menu_slug' => 'moderator-product-assignments',
 'callback' => 'aoam_product_assignments_page',
 ),
 'plugin_settings' => array(
 'page_title' => 'Plugin Settings',
 'menu_title' => 'Plugin Settings',
 'capability' => 'manage_options',
 'menu_slug' => 'moderator-plugin-settings',
 'callback' => 'aoam_settings_page',
 ),
 'reassign_orders' => array(
 'page_title' => 'Reassign Orders',
 'menu_title' => 'Reassign',
 'capability' => 'manage_options',
 'menu_slug' => 'moderator-reassign-orders',
 'callback' => 'aoam_reassign_orders_page',
 ),
 'remote_import' => array(
 'page_title' => 'Remote Order Import',
 'menu_title' => 'Remote Import',
 'capability' => 'manage_options',
 'menu_slug' => 'moderator-remote-import',
 'callback' => 'aoam_remote_import_page',
 ),
 );
}

function moderator_sequence_settings_menu() {
 $pages = aoam_get_admin_page_registry();
 $dashboard = $pages['dashboard'];
 add_menu_page(
 $dashboard['page_title'],
 $dashboard['menu_title'],
 $dashboard['capability'],
 $dashboard['menu_slug'],
 $dashboard['callback'],
 $dashboard['icon'],
 $dashboard['position']
 );

 foreach ($pages as $page_key => $page) {
 if ($page_key === 'dashboard') {
 continue;
 }
 add_submenu_page(
 $dashboard['menu_slug'],
 $page['page_title'],
 $page['menu_title'],
 $page['capability'],
 $page['menu_slug'],
 $page['callback']
 );
 }
}

function moderator_settings_main_page() {
 // Get assigned roles dynamically
 $assigned_roles = aoam_get_assigned_roles();
 
 // Get users with assigned roles
 $all_users = get_users(array(
 'role__in' => $assigned_roles,
 'number' => -1
 ));
 
 // Calculate stats based on all users with assigned roles
 $total_users = count($all_users);
 
 $active_users = array_filter($all_users, function($user) {
 $status = get_user_meta($user->ID, 'moderator_status', true);
 return $status !== 'inactive';
 });
 
 $users_with_products = array_filter($all_users, function($user) {
 $products = get_user_meta($user->ID, 'moderator_assigned_products', true);
 return !empty($products);
 });
 
 // Count users in shift
 $users_in_shift = array_filter($all_users, function($user) {
 $status = get_user_meta($user->ID, 'moderator_status', true);
 if ($status !== 'active') return false;
 return is_user_in_shift_time($user->ID);
 });
 
 // Get today's assignment stats
 global $wpdb;
 $today = aoam_format_display_date('Y-m-d');
 $today_assignments = $wpdb->get_var($wpdb->prepare("
 SELECT COUNT(DISTINCT pm.post_id) 
 FROM {$wpdb->postmeta} pm 
 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
 WHERE pm.meta_key = '_assigned_moderator_id' 
 AND DATE(p.post_date) = %s
 ", $today));
 
 ?>
 <div class="wrap">
 <h1> User Management Dashboard</h1>
 
 <div class="card" style="text-align: center; padding: 40px;">
 <h2> User Management Dashboard</h2>
 <p>Choose a section to manage your users and order assignments:</p>
 
 <!-- Quick Stats with better design -->
 <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; border-radius: 12px; margin-top: 30px; color: white;">
 <h3 style="color: white; margin-top: 0;"> Live Statistics</h3>
 <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px;">
 <div style="text-align: center; padding: 20px; background: rgba(255,255,255,0.2); border-radius: 10px; backdrop-filter: blur(10px);">
 <div style="margin-bottom: 5px;font-size: 32px; font-weight: bold; color: #fff;"><?php echo $total_users; ?></div>
 <div style="font-size: 14px; opacity: 0.9;">Total Users</div>
 </div>
 <div style="text-align: center; padding: 20px; background: rgba(255,255,255,0.2); border-radius: 10px; backdrop-filter: blur(10px);">
 <div style="margin-bottom: 5px;font-size: 32px; font-weight: bold; color: #4CAF50;"><?php echo count($active_users); ?></div>
 <div style="font-size: 14px; opacity: 0.9;">Active Users</div>
 </div>
 <div style="text-align: center; padding: 20px; background: rgba(255,255,255,0.2); border-radius: 10px; backdrop-filter: blur(10px);">
 <div style="margin-bottom: 5px;font-size: 32px; font-weight: bold; color: #FF9800;"><?php echo count($users_in_shift); ?></div>
 <div style="font-size: 14px; opacity: 0.9;">In Shift Now</div>
 </div>
 <div style="text-align: center; padding: 20px; background: rgba(255,255,255,0.2); border-radius: 10px; backdrop-filter: blur(10px);">
 <div style="margin-bottom: 5px;font-size: 32px; font-weight: bold; color: #2196F3;"><?php echo $today_assignments; ?></div>
 <div style="font-size: 14px; opacity: 0.9;">Today's Assignments</div>
 </div>
 </div>
 
 <!-- Progress bars for better visualization -->
 <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-top: 25px;">
 <div style="text-align: left;">
 <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
 <span>Active Users</span>
 <span><?php echo count($active_users); ?>/<?php echo $total_users; ?></span>
 </div>
 <div style="background: rgba(255,255,255,0.3); height: 8px; border-radius: 4px; overflow: hidden;">
 <div style="background: #4CAF50; height: 100%; width: <?php echo $total_users > 0 ? (count($active_users) / $total_users * 100) : 0; ?>%;"></div>
 </div>
 </div>
 <div style="text-align: left;">
 <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
 <span>In Shift Now</span>
 <span><?php echo count($users_in_shift); ?>/<?php echo count($active_users); ?></span>
 </div>
 <div style="background: rgba(255,255,255,0.3); height: 8px; border-radius: 4px; overflow: hidden;">
 <div style="background: #FF9800; height: 100%; width: <?php echo count($active_users) > 0 ? (count($users_in_shift) / count($active_users) * 100) : 0; ?>%;"></div>
 </div>
 </div>
 </div>
 </div>

 <!-- Dashboard cards with better styling -->
 <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; margin: 40px 0;">
 
 <div class="card" style="text-align: center; padding: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
 <div style="font-size: 48px; margin-bottom: 25px;"></div>
 <h3 style="color: white; margin-top: 8px; margin-bottom:10px;">Recent Assignments</h3>
 <p style="opacity: 0.9;">View recent order assignments and history</p>
 <a href="<?php echo admin_url('admin.php?page=moderator-recent-assignments'); ?>" class="button button-primary" style="background: white; color: #667eea; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; margin-top: 15px;">
 View Assignments
 </a>
 </div>

 <div class="card" style="text-align: center; padding: 30px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
 <div style="font-size: 48px; margin-bottom: 25px;"></div>
 <h3 style="color: white; margin-top: 8px; margin-bottom:10px;">Sequence & Status</h3>
 <p style="opacity: 0.9;">Set user sequence, status and shift timings</p>
 <a href="<?php echo admin_url('admin.php?page=moderator-sequence-status'); ?>" class="button button-primary" style="background: white; color: #f5576c; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; margin-top: 15px;">
 Manage Sequence & Status
 </a>
 </div>

 <div class="card" style="text-align: center; padding: 30px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border: none; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
 <div style="font-size: 48px; margin-bottom: 25px;"></div>
 <h3 style="color: white; margin-top: 8px; margin-bottom:10px;">Product Assignments</h3>
 <p style="opacity: 0.9;">Assign specific products to users</p>
 <a href="<?php echo admin_url('admin.php?page=moderator-product-assignments'); ?>" class="button button-primary" style="background: white; color: #4facfe; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; margin-top: 15px;">
 Manage Product Assignments
 </a>
 </div>

 <div class="card" style="text-align: center; padding: 30px; background: linear-gradient(135deg, #b34ffe 0%, #d7fe00 100%); color: white; border: none; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
 <div style="font-size: 48px; margin-bottom: 25px;"></div>
 <h3 style="color: white; margin-top: 8px; margin-bottom:10px;">Reassign</h3>
 <p style="opacity: 0.9;">Inactive user order reassign</p>
 <a href="<?php echo admin_url('admin.php?page=moderator-reassign-orders'); ?>" class="button button-primary" style="background: white; color: #4facfe; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; margin-top: 15px;">
 Reassign
 </a>
 </div>

 <div class="card" style="text-align: center; padding: 30px; background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; border: none; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
 <div style="font-size: 48px; margin-bottom: 25px;"></div>
 <h3 style="color: white; margin-top: 8px; margin-bottom:10px;">Plugin Settings</h3>
 <p style="opacity: 0.9;">Select Multiple Role Assign</p>
 <a href="<?php echo admin_url('admin.php?page=moderator-plugin-settings'); ?>" class="button button-primary" style="background: white; color: #43e97b; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; margin-top: 15px;">
 Manage Role
 </a>
 </div>
 </div>
 </div>
 </div>
 
 <style>
 .card { 
 margin: 20px 0; 
 padding: 20px; 
 background: #fff; 
 border: 1px solid #ccd0d4; 
 border-radius: 8px;
 box-shadow: 0 2px 4px rgba(0,0,0,0.1);
 max-width: 100%;
 }
 
 /* Hover effects for dashboard cards */
 .card:hover {
 transform: translateY(-2px);
 box-shadow: 0 8px 25px rgba(0,0,0,0.15);
 transition: all 0.3s ease;
 }
 
 /* Button hover effects */
 .button.button-primary:hover {
 transform: translateY(-1px);
 box-shadow: 0 4px 12px rgba(0,0,0,0.2);
 }
 </style>
 <?php
}

 // ====================================================
 // Sequence & Status page with shift assignment.
 // ====================================================

function moderator_sequence_status_page() {
 if (!current_user_can('manage_options')) {
 wp_die(esc_html__('You do not have permission to access this page.', 'digitrix-orderflow-for-woocommerce'));
 }
 
 // Get assigned roles dynamically
 $assigned_roles = aoam_get_assigned_roles();
 
 // Handle form submissions for this page only
 if (aoam_is_post_request()) {
 
 // Handle status update
 if (isset($_POST['update_status'], $_POST['moderator_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['moderator_nonce'])), 'moderator_settings')) {
 if (isset($_POST['moderator_status'])) {
 foreach (wp_unslash((array) $_POST['moderator_status']) as $user_id => $status) {
 $user_id = intval($user_id);
 $status = sanitize_text_field($status);
 update_user_meta($user_id, 'moderator_status', $status);
 
 if ($status === 'active') {
 update_user_meta($user_id, 'moderator_last_active', current_time('mysql'));
 }
 }
 echo '<div class="notice notice-success"><p>User status updated successfully!</p></div>';
 }
 }
 
 // Handle sequence update
 if (isset($_POST['update_sequence'], $_POST['moderator_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['moderator_nonce'])), 'moderator_settings')) {
 if (isset($_POST['moderator_sequence'])) {
 foreach (wp_unslash((array) $_POST['moderator_sequence']) as $user_id => $sequence) {
 $user_id = intval($user_id);
 $sequence = intval($sequence);
 update_user_meta($user_id, 'moderator_sequence', $sequence);
 update_user_meta($user_id, 'moderator_last_active', current_time('mysql'));
 }
 echo '<div class="notice notice-success"><p>User sequence updated successfully!</p></div>';
 }
 }
 
 // Handle shift assignment update
 if (isset($_POST['update_shifts'], $_POST['moderator_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['moderator_nonce'])), 'moderator_settings')) {
 if (isset($_POST['moderator_shifts'])) {
 foreach (wp_unslash((array) $_POST['moderator_shifts']) as $user_id => $shifts) {
 $user_id = intval($user_id);
 $shifts = is_array($shifts) ? array_map('sanitize_text_field', $shifts) : array();
 update_user_meta($user_id, 'moderator_assigned_shifts', $shifts);
 }
 echo '<div class="notice notice-success"><p>Shift assignments updated successfully!</p></div>';
 }
 }
 
 // Handle shift removal for specific user
 if (isset($_POST['clear_user_shifts'], $_POST['moderator_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['moderator_nonce'])), 'moderator_settings')) {
 $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
 if ($user_id) {
 delete_user_meta($user_id, 'moderator_assigned_shifts');
 echo '<div class="notice notice-success"><p>Shift assignments cleared for user!</p></div>';
 }
 }
 
 // Handle bulk actions
 if (isset($_POST['bulk_update'], $_POST['moderator_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['moderator_nonce'])), 'moderator_settings')) {
 $bulk_action = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
 
 // Get users with assigned roles
 $users = get_users(array('role__in' => $assigned_roles));
 
 if ($bulk_action === 'activate_all') {
 foreach ($users as $user) {
 update_user_meta($user->ID, 'moderator_status', 'active');
 update_user_meta($user->ID, 'moderator_last_active', current_time('mysql'));
 }
 echo '<div class="notice notice-success"><p>All users activated!</p></div>';
 } elseif ($bulk_action === 'deactivate_all') {
 foreach ($users as $user) {
 update_user_meta($user->ID, 'moderator_status', 'inactive');
 }
 echo '<div class="notice notice-success"><p>All users deactivated!</p></div>';
 } elseif ($bulk_action === 'reset_all_sequences') {
 global $wpdb;
 $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'last_assigned_moderator_sequence_products_%'");
 update_option('last_assigned_moderator_sequence', 0);
 echo '<div class="notice notice-success"><p>All assignment sequences reset!</p></div>';
 } elseif ($bulk_action === 'clear_all_shifts') {
 foreach ($users as $user) {
 delete_user_meta($user->ID, 'moderator_assigned_shifts');
 }
 echo '<div class="notice notice-success"><p>All shift assignments cleared!</p></div>';
 }
 }
 }
 
 // Get filter parameters
 $search_term = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
 $status_filter = isset($_GET['status_filter']) ? sanitize_key(wp_unslash($_GET['status_filter'])) : 'all';
 $sequence_filter = isset($_GET['sequence_filter']) ? sanitize_key(wp_unslash($_GET['sequence_filter'])) : 'all';
 $shift_filter = isset($_GET['shift_filter']) ? sanitize_key(wp_unslash($_GET['shift_filter'])) : 'all';
 
 // Get shift settings
 $shift_settings = aoam_get_shift_settings();
 
 // Base query for users with assigned roles
 $user_args = array(
 'role__in' => $assigned_roles,
 'orderby' => 'ID',
 'order' => 'ASC',
 'number' => -1
 );
 
 // Apply search filter
 if (!empty($search_term)) {
 $user_args['search'] = '*' . $search_term . '*';
 $user_args['search_columns'] = array('user_login', 'user_nicename', 'user_email', 'display_name');
 }
 
 $all_users = get_users($user_args);
 
 // Apply status filter
 $users = array();
 foreach ($all_users as $user) {
 $current_status = get_user_meta($user->ID, 'moderator_status', true) ?: 'active';
 $assigned_shifts = get_user_meta($user->ID, 'moderator_assigned_shifts', true);
 $in_shift = is_user_in_any_shift($user->ID);
 
 // Apply status filter
 $status_match = ($status_filter === 'all' || $current_status === $status_filter);
 
 // Apply shift filter
 $shift_match = false;
 if ($shift_filter === 'all') {
 $shift_match = true;
 } elseif ($shift_filter === 'with_shifts' && !empty($assigned_shifts)) {
 $shift_match = true;
 } elseif ($shift_filter === 'without_shifts' && empty($assigned_shifts)) {
 $shift_match = true;
 } elseif ($shift_filter === 'in_shift' && $in_shift !== false) {
 $shift_match = true;
 } elseif ($shift_filter === 'out_shift' && $current_status === 'active' && !empty($assigned_shifts) && $in_shift === false) {
 $shift_match = true;
 } elseif (strpos($shift_filter, 'shift_') === 0) {
 // Specific shift filter
 if (!empty($assigned_shifts) && in_array($shift_filter, $assigned_shifts)) {
 $shift_match = true;
 }
 }
 
 if ($status_match && $shift_match) {
 // Apply sequence filter if set
 if ($sequence_filter === 'all') {
 $users[] = $user;
 } else {
 $current_sequence = get_user_meta($user->ID, 'moderator_sequence', true);
 if ($sequence_filter === 'with_sequence' && $current_sequence) {
 $users[] = $user;
 } elseif ($sequence_filter === 'without_sequence' && !$current_sequence) {
 $users[] = $user;
 }
 }
 }
 }
 
 $active_users = array_filter($users, function($user) {
 $status = get_user_meta($user->ID, 'moderator_status', true);
 return $status !== 'inactive';
 });
 
 $in_shift_users = array_filter($active_users, function($user) {
 return is_user_in_any_shift($user->ID) !== false;
 });
 ?>

 <div class="wrap">
 
 <div class="card">
 <h2> Sequence Statistics</h2>
 <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
 <div style="text-align: center; padding: 15px; background: #fff8e1; border-radius: 6px;">
 <div style="font-size: 20px; font-weight: bold; color: #ffb900;">
 <?php echo get_option('last_assigned_partial_moderator_sequence', 0); ?>
 </div>
 <div>Partial Sequence</div>
 </div>
 <div style="text-align: center; padding: 15px; background: #e5f7e5; border-radius: 6px;">
 <div style="font-size: 20px; font-weight: bold; color: #46b450;">
 <?php echo get_option('last_assigned_processing_moderator_sequence', 0); ?>
 </div>
 <div>Processing Sequence</div>
 </div>
 </div>
 
 <!-- Reset Sequences Form -->
 <form method="post" style="margin-top: 20px;">
 <?php wp_nonce_field('reset_sequences', 'reset_sequences_nonce'); ?>
 <input type="submit" name="reset_all_sequences" class="button button-secondary" 
 value=" Reset All Sequences" 
 onclick="return confirm('Are you sure you want to reset ALL assignment sequences? This will restart counting from User 1.')">
 <small style="color: #666; margin-left: 10px;">This will reset all assignment sequences to start from User 1</small>
 </form>
 </div>
 
 <h1> User Sequence, Status & Shift Assignment</h1>
 
 <!-- Navigation Tabs -->
 <div class="nav-tab-wrapper">
 <a href="<?php echo admin_url('admin.php?page=moderator-settings'); ?>" class="nav-tab">Dashboard</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-recent-assignments'); ?>" class="nav-tab">Recent Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-sequence-status'); ?>" class="nav-tab nav-tab-active">Sequence & Status</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-product-assignments'); ?>" class="nav-tab">Product Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-reassign-orders'); ?>" class="nav-tab">Reassign</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-plugin-settings'); ?>" class="nav-tab">Plugin Settings</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-remote-import'); ?>" class="nav-tab">Remote Import</a>
 </div>

 <!-- ... [existing cards and stats code remains the same] ... -->

 <?php if (empty($users)): ?>
 <div class="card">
 <!-- ... [no users found message] ... -->
 </div>
 <?php else: ?>
 
 <form method="post" id="moderator-sequence-form">
 <?php wp_nonce_field('moderator_settings', 'moderator_nonce'); ?>
 
 <table class="wp-list-table widefat fixed striped" id="moderator-sequence-table">
 <thead>
 <tr>
 <th style="width: 80px;">Sequence</th>
 <th style="width: 150px;">User Name</th>
 <th style="width: 100px;">Status</th>
 <th style="width: 250px;">Assigned Shifts</th>
 <th style="width: 200px;">Current Shift Status</th>
 <th style="width: 100px;">Assigned Products</th>
 <th style="width: 100px;">Assigned Orders</th>
 <th style="width: 120px;">Last Active</th>
 </tr>
 </thead>
 <tbody>
 <?php
 $sequence = 1;
 foreach ($users as $user) {
 $current_sequence = get_user_meta($user->ID, 'moderator_sequence', true) ?: $sequence;
 $current_status = get_user_meta($user->ID, 'moderator_status', true);
 if (empty($current_status)) {
 $current_status = 'active';
 update_user_meta($user->ID, 'moderator_status', 'active');
 }
 
 $assigned_shifts = get_user_meta($user->ID, 'moderator_assigned_shifts', true);
 $user_shifts = get_user_assigned_shifts($user->ID);
 $shift_check = is_user_in_any_shift($user->ID);
 $last_active = get_user_meta($user->ID, 'moderator_last_active', true);
 $assigned_products = get_user_meta($user->ID, 'moderator_assigned_products', true) ?: array();

 global $wpdb;
 $order_ids = $wpdb->get_col($wpdb->prepare("
 SELECT post_id 
 FROM {$wpdb->postmeta} 
 WHERE meta_key = '_assigned_moderator_id' 
 AND meta_value = %d
 ", $user->ID));
 
 $orders = array();
 foreach ($order_ids as $order_id) {
 $order = wc_get_order($order_id);
 if ($order) {
 $orders[] = $order;
 }
 }
 
 // Get all user's assigned shifts information
 $assigned_shift_names = array();
 $assigned_shift_keys = array();
 
 if (!empty($user_shifts)) {
 foreach ($user_shifts as $shift_key => $shift_data) {
 $assigned_shift_names[] = $shift_data['name'];
 $assigned_shift_keys[] = $shift_key;
 }
 }
 
 // Check which specific shifts user is currently in
 $current_in_shifts = array();
 $current_not_in_shifts = array();
 
 if ($current_status === 'active') {
 if (!empty($assigned_shifts)) {
 $shift_settings = aoam_get_shift_settings();
 $current_time = current_time('H:i');
 $current_hour = (int)gmdate('H', strtotime($current_time));
 $current_minute = (int)gmdate('i', strtotime($current_time));
 $current_decimal = $current_hour + ($current_minute / 60);
 
 foreach ($assigned_shifts as $shift_key) {
 if (isset($shift_settings[$shift_key])) {
 $shift = $shift_settings[$shift_key];
 
 // Parse start time
 $start_time = $shift['start'];
 list($start_hour, $start_minute) = explode(':', $start_time);
 $start_hour = (int)$start_hour;
 $start_minute = (int)$start_minute;
 $start_decimal = $start_hour + ($start_minute / 60);
 
 // Parse end time
 $end_time = $shift['end'];
 list($end_hour, $end_minute) = explode(':', $end_time);
 $end_hour = (int)$end_hour;
 $end_minute = (int)$end_minute;
 $end_decimal = $end_hour + ($end_minute / 60);
 
 // Check if in this specific shift
 $in_this_shift = false;
 if ($end_decimal < $start_decimal) {
 // Overnight shift
 $in_this_shift = ($current_decimal >= $start_decimal || $current_decimal <= $end_decimal);
 } else {
 // Normal shift
 $in_this_shift = ($current_decimal >= $start_decimal && $current_decimal <= $end_decimal);
 }
 
 if ($in_this_shift) {
 $current_in_shifts[] = $shift['name'];
 } else {
 $current_not_in_shifts[] = $shift['name'];
 }
 }
 }
 }
 }
 
 ?>
 <tr class="user-<?php echo $current_status; ?>" data-user-id="<?php echo $user->ID; ?>">
 <td>
 <input style="width:60px;" type="text" name="moderator_sequence[<?php echo $user->ID; ?>]" 
 value="<?php echo $current_sequence; ?>" class="sequence-input">
 </td>
 <td>
 <strong><?php echo esc_html($user->display_name); ?></strong>
 <div class="sequence-number">#<?php echo $current_sequence; ?></div>
 <?php if ($current_status == 'inactive'): ?>
 <span class="dashicons dashicons-hidden" style="color:#ff0000; margin-left:5px;" title="Inactive"> </span>
 <?php endif; ?>
 </td>
 <td>
 <select name="moderator_status[<?php echo $user->ID; ?>]" class="moderator-status">
 <option value="active" <?php selected($current_status, 'active'); ?>>Active</option>
 <option value="inactive" <?php selected($current_status, 'inactive'); ?>>Inactive</option>
 </select>
 </td>
 <td>
 <div class="shift-assignment">
 <div style="display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 5px;">
 <?php foreach ($shift_settings as $shift_key => $shift): ?>
 <label style="display: flex; align-items: center; gap: 3px; padding: 4px 8px; background: <?php echo $shift['color'] . '20'; ?>; border-radius: 4px; border: 1px solid <?php echo $shift['color'] . '40'; ?>;">
 <input type="checkbox" 
 name="moderator_shifts[<?php echo $user->ID; ?>][]" 
 value="<?php echo $shift_key; ?>"
 <?php checked(is_array($assigned_shifts) && in_array($shift_key, $assigned_shifts)); ?>>
 <span style="font-size: 11px; font-weight: bold; color: <?php echo $shift['color']; ?>;">
 <?php echo $shift['name']; ?>
 </span>
 </label>
 <?php endforeach; ?>
 </div>
 <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 5px;">
 <small style="color: #666; font-size: 11px;">
 <?php if (!empty($assigned_shift_names)): ?>
 Assigned: 
 <?php echo implode(', ', $assigned_shift_names); ?>
 <?php else: ?>
 No shifts assigned
 <?php endif; ?>
 </small>
 <button type="button" class="button button-small clear-shifts-btn" 
 data-user-id="<?php echo $user->ID; ?>" 
 data-user-name="<?php echo esc_attr($user->display_name); ?>"
 style="font-size: 10px; padding: 2px 6px;">
 Clear All
 </button>
 </div>
 </div>
 </td>
 <td>
 <div class="shift-status">
 <?php 
 if ($current_status === 'active') {
 if (!empty($assigned_shifts)) {
 // User has assigned shifts
 if (!empty($current_in_shifts)) {
 // Currently in one or more shifts
 ?>
 <span class="shift-status-text" style="color: #46b450; font-weight: bold;">
 In Shift
 </span>
 <br>
 <small style="color: #46b450;">
 Will receive orders (<?php echo implode(', ', $current_in_shifts); ?>)
 </small>
 <?php
 } else {
 // Not currently in any assigned shift
 ?>
 <span class="shift-status-text" style="color: #666; font-weight: bold;">
 Currently Out of Shift
 </span>
 <br>
 <small style="color: #666;">
 Assigned shifts: <?php echo implode(', ', $assigned_shift_names); ?>
 <br>Will receive orders when in shift
 <br>Current time: <?php echo current_time('H:i'); ?>
 </small>
 <?php
 }
 } else {
 // No shifts assigned - always active
 ?>
 <span class="shift-status-text" style="color: #0073aa; font-weight: bold;">
 Always Active
 </span>
 <br>
 <small style="color: #666;">
 No shift restrictions<br>
 Will receive orders anytime
 </small>
 <?php
 }
 } else {
 // User is inactive
 ?>
 <span class="shift-status-text" style="color: #cc1818; font-weight: bold;">
 Inactive User
 </span>
 <br>
 <small style="color: #666;">
 Won't receive orders<br>
 Change status to "Active" to receive orders
 </small>
 <?php
 }
 ?>
 </div>
 </td>
 <td>
 <?php 
 if (empty($assigned_products)) {
 echo '<div class="no-products-assigned">';
 echo '<span class="dashicons dashicons-warning" style="color:#cc1818;"></span>';
 echo '<span style="color:#cc1818; font-weight:bold;">No Products</span>';
 echo '<br><small style="color:#666;">Will receive general orders</small>';
 echo '</div>';
 } else {
 echo '<div class="products-assigned">';
 echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>';
 echo '<span style="color:#46b450; font-weight:bold;">' . count($assigned_products) . ' Products</span>';
 
 echo '<div class="product-list">';
 $display_count = 0;
 foreach ($assigned_products as $product_id) {
 if ($display_count >= 2) break;
 $product = wc_get_product($product_id);
 if ($product) {
 echo '<div class="product-item">';
 echo '<span class="product-name">' . esc_html($product->get_name()) . '</span>';
 echo '</div>';
 $display_count++;
 }
 }
 
 if (count($assigned_products) > 2) {
 echo '<div class="more-products">';
 echo '+ ' . (count($assigned_products) - 2) . ' more';
 echo '</div>';
 }
 echo '</div>';
 echo '</div>';
 }
 ?>
 </td>
 <td>
 <div class="order-count">
 <strong><?php echo count($orders); ?></strong>
 <br>
 <small>orders</small>
 </div>
 </td>
 <td>
 <div class="last-active">
 <?php 
 if ($last_active) {
 $last_active_timestamp = strtotime($last_active);
 echo '<span class="date">' . esc_html(aoam_format_display_date('M j, Y', $last_active_timestamp)) . '</span>';
 echo '<br><small class="time">' . esc_html(aoam_format_display_date('g:i A', $last_active_timestamp)) . '</small>';
 } else {
 echo '<span style="color:#666;">Never</span>';
 }
 ?>
 </div>
 </td>
 </tr>
 <?php
 $sequence++;
 }
 ?>
 </tbody>
 </table>
 
 <p style="margin-top: 15px;">
 <input type="submit" name="update_sequence" class="button button-primary" value="Update Sequence">
 <input type="submit" name="update_status" class="button button-secondary" value="Update Status Only">
 <input type="submit" name="update_shifts" class="button button-secondary" value="Update Shifts Only">
 </p>
 </form>
 <?php endif; ?>
 </div>

 <!-- Quick Actions -->
 <div class="card">
 <h2>Quick Actions</h2>
 <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
 <div>
 <h4 style="margin-top: 0;">Bulk Actions</h4>
 <form method="post" style="display: flex; gap: 10px; align-items: center;">
 <?php wp_nonce_field('moderator_settings', 'moderator_nonce'); ?>
 <select name="bulk_action" id="bulk_action" style="flex: 1;">
 <option value="">Select Action</option>
 <option value="activate_all">Activate All Users</option>
 <option value="deactivate_all">Deactivate All Users</option>
 <option value="clear_all_shifts">Clear All Shift Assignments</option>
 <option value="reset_all_sequences">Reset All Assignment Sequences</option>
 </select>
 <input type="submit" name="bulk_update" class="button" value="Apply" onclick="return confirm('Are you sure?')">
 </form>
 </div>
 
 <div>
 <h4 style="margin-top: 0;">Quick Filters</h4>
 <div style="display: flex; gap: 5px; flex-wrap: wrap;">
 <a href="?page=moderator-sequence-status&shift_filter=in_shift" 
 class="button button-small <?php echo $shift_filter === 'in_shift' ? 'button-primary' : 'button-secondary'; ?>">
 In Shift Now
 </a>
 <a href="?page=moderator-sequence-status&shift_filter=out_shift" 
 class="button button-small <?php echo $shift_filter === 'out_shift' ? 'button-primary' : 'button-secondary'; ?>">
 Currently Out of Shift
 </a>
 <a href="?page=moderator-sequence-status&shift_filter=without_shifts" 
 class="button button-small <?php echo $shift_filter === 'without_shifts' ? 'button-primary' : 'button-secondary'; ?>">
 No Shifts (Always Active)
 </a>
 <a href="?page=moderator-sequence-status&status_filter=inactive" 
 class="button button-small <?php echo $status_filter === 'inactive' ? 'button-primary' : 'button-secondary'; ?>">
 Inactive Users
 </a>
 </div>
 </div>
 </div>
 </div>
 </div>
 <div class="clear"></div>
 <style>
 .card { margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4;max-width:100%; }
 .sequence-number { 
 display: inline-block; 
 width: 30px; 
 height: 30px; 
 background: #0073aa; 
 color: white; 
 text-align: center; 
 line-height: 30px; 
 border-radius: 50%; 
 font-weight: bold;
 margin-top: 5px;
 }
 #wpfooter {
 position: static !important;
 }
 .user-inactive { 
 background-color: #fff8f8; 
 opacity: 0.7;
 }
 .no-products-assigned {
 padding: 8px;
 background: #fff5f5;
 border: 1px solid #ffd6d6;
 border-radius: 4px;
 text-align: center;
 }
 .products-assigned {
 padding: 8px;
 background: #f8fff8;
 border: 1px solid #d6ffd6;
 border-radius: 4px;
 }
 .product-list {
 margin-top: 8px;
 max-height: 80px;
 overflow-y: auto;
 padding: 5px;
 background: white;
 border-radius: 3px;
 border: 1px solid #f0f0f0;
 }
 .product-item {
 padding: 3px 5px;
 margin: 2px 0;
 background: #f9f9f9;
 border-radius: 3px;
 border-left: 3px solid #0073aa;
 font-size: 11px;
 line-height: 1.3;
 }
 .more-products {
 padding: 5px;
 text-align: center;
 background: #f0f6fc;
 color: #0073aa;
 font-size: 10px;
 font-weight: bold;
 border-radius: 3px;
 margin-top: 5px;
 }
 .order-count {
 text-align: center;
 padding: 5px;
 }
 .order-count strong {
 font-size: 18px;
 color: #0073aa;
 display: block;
 }
 .last-active {
 text-align: center;
 padding: 5px;
 }
 .nav-tab-wrapper { margin: 20px 0; }
 .nav-tab { 
 text-decoration: none; 
 padding: 10px 15px; 
 margin: 0 5px 0 0; 
 border: 1px solid #ccd0d4;
 border-bottom: none;
 background: #f0f0f0;
 }
 .nav-tab-active { 
 background: #0073aa; 
 color: white; 
 border-bottom: 1px solid #0073aa;
 }
 .filter-group {
 margin-bottom: 0;
 }
 .shift-assignment label {
 cursor: pointer;
 transition: all 0.3s ease;
 }
 .shift-assignment label:hover {
 transform: translateY(-2px);
 box-shadow: 0 2px 5px rgba(0,0,0,0.1);
 }
 .shift-assignment input[type="checkbox"]:checked + span {
 font-weight: bold;
 }
 .shift-status {
 padding: 8px;
 border-radius: 4px;
 background: #f8f9fa;
 border: 1px solid #e0e0e0;
 min-height: 60px;
 text-align: center;
 }
 .clear-shifts-btn {
 background: #ff6b6b;
 color: white;
 border: none;
 cursor: pointer;
 }
 .clear-shifts-btn:hover {
 background: #ff5252;
 }
 </style>
 
 <script>
 jQuery(document).ready(function($) {
 // Make table sortable
 $('#moderator-sequence-table tbody').sortable({
 update: function(event, ui) {
 $('#moderator-sequence-table tbody tr').each(function(index) {
 var sequence = index + 1;
 $(this).find('.sequence-number').text('#' + sequence);
 $(this).find('.sequence-input').val(sequence);
 });
 }
 });
 
 // Update row appearance when status changes
 $('.moderator-status').change(function() {
 var row = $(this).closest('tr');
 if ($(this).val() === 'inactive') {
 row.addClass('user-inactive');
 } else {
 row.removeClass('user-inactive');
 }
 });
 
 // Initialize row colors based on current status
 $('.moderator-status').each(function() {
 var row = $(this).closest('tr');
 if ($(this).val() === 'inactive') {
 row.addClass('user-inactive');
 }
 });
 
 // Clear shifts button functionality
 $('.clear-shifts-btn').click(function(e) {
 e.preventDefault();
 
 var userId = $(this).data('user-id');
 var userName = $(this).data('user-name');
 var $button = $(this);
 var $row = $button.closest('tr');
 
 if (confirm('Are you sure you want to remove all shift assignments for ' + userName + '?\n\nThis will make the user "Always Active" and able to receive orders anytime.')) {
 // Uncheck all checkboxes
 $row.find('input[type="checkbox"]').prop('checked', false);
 
 // Show loading state
 $button.text('Clearing...').prop('disabled', true);
 
 // Submit the form to save changes
 setTimeout(function() {
 $row.find('input[name^="moderator_shifts"]').val([]);
 $('#moderator-sequence-form').append('<input type="hidden" name="clear_user_shifts" value="1">');
 $('#moderator-sequence-form').append('<input type="hidden" name="user_id" value="' + userId + '">');
 $('input[name="update_shifts"]').click();
 }, 500);
 }
 });
 
 // Focus on search field when page loads if there's a search term
 <?php if (!empty($search_term)): ?>
 $('#search').focus();
 <?php endif; ?>
 });
 </script>
 <?php
}



// The remaining functions (moderator_product_assignments_page, moderator_recent_assignments_page, etc.)
// remain mostly the same as your original code, with minor adjustments for shift timing display
function moderator_product_assignments_page() {
 if (!current_user_can('manage_options')) {
 wp_die(esc_html__('You do not have permission to access this page.', 'digitrix-orderflow-for-woocommerce'));
 }
 
 // Get assigned roles dynamically
 $assigned_roles = aoam_get_assigned_roles();
 
 // Handle form submissions for product assignments
 if (aoam_is_post_request()) {
 
 // Handle product assignment update
 if (isset($_POST['update_products'], $_POST['moderator_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['moderator_nonce'])), 'moderator_settings')) {
 $updated_count = 0;
 
 // Get all users with assigned roles
 $all_users = get_users(array(
 'role__in' => $assigned_roles,
 'fields' => 'ID'
 ));
 
 foreach ($all_users as $user_id) {
 if (isset($_POST['moderator_products'][$user_id])) {
 // Products were selected for this user
 $raw_product_ids = wp_unslash($_POST['moderator_products'][$user_id]);
 if (!is_array($raw_product_ids)) {
 $raw_product_ids = array($raw_product_ids);
 }
 $product_ids = array_map('absint', $raw_product_ids);
 $product_ids = array_filter($product_ids); // Remove empty values
 update_user_meta($user_id, 'moderator_assigned_products', $product_ids);
 $updated_count++;
 } else {
 // No products selected - clear the assignment
 update_user_meta($user_id, 'moderator_assigned_products', array());
 $updated_count++;
 }
 }
 
 if ($updated_count > 0) {
 echo '<div class="notice notice-success"><p>Product assignments updated successfully! ' . $updated_count . ' users processed.</p></div>';
 }
 }
 
 // Handle bulk actions for product assignments
 if (isset($_POST['bulk_product_action'], $_POST['moderator_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['moderator_nonce'])), 'moderator_settings')) {
 $bulk_action = isset($_POST['bulk_product_action']) ? sanitize_key(wp_unslash($_POST['bulk_product_action'])) : '';
 $raw_user_ids = isset($_POST['user_ids']) ? wp_unslash($_POST['user_ids']) : array();
 if (is_string($raw_user_ids)) {
 $raw_user_ids = array_filter(array_map('trim', explode(',', $raw_user_ids)));
 }
 if (!is_array($raw_user_ids)) {
 $raw_user_ids = array();
 }
 $user_ids = array_values(array_filter(array_map('absint', $raw_user_ids)));
 
 if ($bulk_action === 'clear_all_products') {
 // Clear all users
 $users = get_users(array('role__in' => $assigned_roles));
 foreach ($users as $user) {
 update_user_meta($user->ID, 'moderator_assigned_products', array());
 }
 echo '<div class="notice notice-success"><p>All product assignments cleared for all users!</p></div>';
 } elseif ($bulk_action === 'clear_filtered_products' && !empty($user_ids)) {
 // Clear only filtered users
 foreach ($user_ids as $user_id) {
 update_user_meta($user_id, 'moderator_assigned_products', array());
 }
 echo '<div class="notice notice-success"><p>Product assignments cleared for ' . count($user_ids) . ' users!</p></div>';
 }
 }
 }
 
 // Get filter parameters
 $search_term = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
 $status_filter = isset($_GET['status_filter']) ? sanitize_key(wp_unslash($_GET['status_filter'])) : 'all';
 $assignment_filter = isset($_GET['assignment_filter']) ? sanitize_key(wp_unslash($_GET['assignment_filter'])) : 'all';
 $shift_filter = isset($_GET['shift_filter']) ? sanitize_key(wp_unslash($_GET['shift_filter'])) : 'all';
 
 // Base query for users with assigned roles
 $user_args = array(
 'role__in' => $assigned_roles,
 'orderby' => 'meta_value_num',
 'meta_key' => 'moderator_sequence',
 'order' => 'ASC',
 'number' => -1
 );
 
 // Apply search filter
 if (!empty($search_term)) {
 $user_args['search'] = '*' . $search_term . '*';
 $user_args['search_columns'] = array('user_login', 'user_nicename', 'user_email', 'display_name');
 }
 
 $all_users = get_users($user_args);
 
 // Apply filters
 $users = array();
 $stats = array(
 'total' => 0,
 'active' => 0,
 'inactive' => 0,
 'in_shift' => 0,
 'with_products' => 0,
 'without_products' => 0
 );
 
 foreach ($all_users as $user) {
 $current_status = get_user_meta($user->ID, 'moderator_status', true) ?: 'active';
 $assigned_products = get_user_meta($user->ID, 'moderator_assigned_products', true) ?: array();
 $has_products = !empty($assigned_products);
 $in_shift = ($current_status === 'active') ? is_user_in_shift_time($user->ID) : false;
 
 // Update stats
 $stats['total']++;
 if ($current_status === 'active') $stats['active']++;
 if ($current_status === 'inactive') $stats['inactive']++;
 if ($in_shift) $stats['in_shift']++;
 if ($has_products) $stats['with_products']++;
 if (!$has_products) $stats['without_products']++;
 
 // Apply status filter
 $status_match = ($status_filter === 'all' || $current_status === $status_filter);
 
 // Apply assignment filter
 $assignment_match = false;
 if ($assignment_filter === 'all') {
 $assignment_match = true;
 } elseif ($assignment_filter === 'with_products' && $has_products) {
 $assignment_match = true;
 } elseif ($assignment_filter === 'without_products' && !$has_products) {
 $assignment_match = true;
 }
 
 // Apply shift filter
 $shift_match = false;
 if ($shift_filter === 'all') {
 $shift_match = true;
 } elseif ($shift_filter === 'in_shift' && $in_shift) {
 $shift_match = true;
 } elseif ($shift_filter === 'out_shift' && !$in_shift && $current_status === 'active') {
 $shift_match = true;
 }
 
 if ($status_match && $assignment_match && $shift_match) {
 $users[] = $user;
 }
 }
 
 // Get all products for product assignment
 $products = wc_get_products(array(
 'status' => 'publish',
 'limit' => -1,
 ));
 ?>

 <div class="wrap">
 <h1> Assign Products to Users</h1>
 
 <!-- Navigation Tabs -->
 <div class="nav-tab-wrapper">
 <a href="<?php echo admin_url('admin.php?page=moderator-settings'); ?>" class="nav-tab">Dashboard</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-recent-assignments'); ?>" class="nav-tab">Recent Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-sequence-status'); ?>" class="nav-tab">Sequence & Status</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-product-assignments'); ?>" class="nav-tab nav-tab-active">Product Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-reassign-orders'); ?>" class="nav-tab">Reassign</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-plugin-settings'); ?>" class="nav-tab">Plugin Settings</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-remote-import'); ?>" class="nav-tab">Remote Import</a>
 </div>

 <!-- Search and Filters Card -->
 <div class="card">
 <h2>Product Assignment Management</h2>
 <p style="color: #cc1818; font-weight: bold;"> Only active users within their shift time will receive orders for their assigned products.</p>
 <p>Select specific products for each user. Users without product assignments will receive general orders.</p>
 <h2> Search & Filter Users</h2>
 
 <form method="get" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
 <input type="hidden" name="page" value="moderator-product-assignments">
 
 <!-- Search Field -->
 <div class="filter-group" style="width:100%;">
 <label for="search" style="display: block; margin-bottom: 5px; font-weight: bold;">Search Users:</label>
 <input type="text" name="search" id="search" value="<?php echo esc_attr($search_term); ?>" 
 placeholder="Search by name or email..." style="width: 100%; padding: 8px;">
 <small style="color: #666;position: absolute;bottom: -4px;">Search by name, username, or email</small>
 </div>
 
 <!-- Status Filter -->
 <div class="filter-group" style="width:100%;">
 <label for="status_filter" style="display: block; margin-bottom: 5px; font-weight: bold;">Status Filter:</label>
 <select name="status_filter" id="status_filter" style="width: 100%; padding: 8px;">
 <option value="all" <?php selected($status_filter, 'all'); ?>>All Statuses</option>
 <option value="active" <?php selected($status_filter, 'active'); ?>>Active Only</option>
 <option value="inactive" <?php selected($status_filter, 'inactive'); ?>>Inactive Only</option>
 </select>
 </div>
 
 <!-- Shift Filter -->
 <div class="filter-group" style="width:100%;">
 <label for="shift_filter" style="display: block; margin-bottom: 5px; font-weight: bold;">Shift Status:</label>
 <select name="shift_filter" id="shift_filter" style="width: 100%; padding: 8px;">
 <option value="all" <?php selected($shift_filter, 'all'); ?>>All Shifts</option>
 <option value="in_shift" <?php selected($shift_filter, 'in_shift'); ?>>In Shift Now</option>
 <option value="out_shift" <?php selected($shift_filter, 'out_shift'); ?>>Out of Shift</option>
 </select>
 </div>
 
 <!-- Action Buttons -->
 <div class="filter-group" style="width:100%;display: flex; gap: 10px; align-items: center;">
 <button type="submit" class="button button-primary" style="padding: 8px 20px;">
 <span class="dashicons dashicons-search"></span> Apply Filters
 </button>
 <a href="?page=moderator-product-assignments" class="button button-secondary" style="padding: 8px 20px;">
 <span class="dashicons dashicons-update"></span> Reset
 </a>
 </div>
 </form>
 
 <!-- Results Summary -->
 <div style="margin-top: 20px; padding: 15px; background: #f0f6ff; border-radius: 6px;">
 <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; text-align: center;">
 <div>
 <div style="font-size: 20px; font-weight: bold; color: #0073aa;"><?php echo count($users); ?></div>
 <div style="font-size: 12px;">Showing</div>
 </div>
 <div>
 <div style="font-size: 20px; font-weight: bold; color: #46b450;"><?php echo $stats['active']; ?></div>
 <div style="font-size: 12px;">Active</div>
 </div>
 <div>
 <div style="font-size: 20px; font-weight: bold; color: #FF9800;"><?php echo $stats['in_shift']; ?></div>
 <div style="font-size: 12px;">In Shift Now</div>
 </div>
 <div>
 <div style="font-size: 20px; font-weight: bold; color: #cc1818;"><?php echo $stats['inactive']; ?></div>
 <div style="font-size: 12px;">Inactive</div>
 </div>
 <div>
 <div style="font-size: 20px; font-weight: bold; color: #ffb900;"><?php echo $stats['with_products']; ?></div>
 <div style="font-size: 12px;">With Products</div>
 </div>
 <div>
 <div style="font-size: 20px; font-weight: bold; color: #dc3232;"><?php echo $stats['without_products']; ?></div>
 <div style="font-size: 12px;">Without Products</div>
 </div>
 </div>
 
 <?php if (!empty($search_term)): ?>
 <div style="margin-top: 10px; text-align: center;">
 <strong>Search results for:</strong> "<?php echo esc_html($search_term); ?>"
 </div>
 <?php endif; ?>
 
 <?php if ($status_filter !== 'all' || $shift_filter !== 'all'): ?>
 <div style="margin-top: 5px; text-align: center; font-size: 12px; color: #666;">
 <strong>Filters:</strong> 
 <?php 
 $active_filters = array();
 if ($status_filter !== 'all') $active_filters[] = 'Status: ' . ucfirst($status_filter);
 if ($shift_filter !== 'all') $active_filters[] = 'Shift: ' . str_replace('_', ' ', ucfirst($shift_filter));
 echo implode(' ', $active_filters);
 ?>
 </div>
 <?php endif; ?>
 </div>
 
 <?php if (empty($users)): ?>
 <div style="text-align: center; padding: 40px;">
 <div style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></div>
 <h3>No users found</h3>
 <p>No users match your current search and filter criteria.</p>
 <a href="?page=moderator-product-assignments" class="button button-primary">Show All Users</a>
 </div>
 <?php else: ?>
 
 <form method="post" id="moderator-products-form">
 <?php wp_nonce_field('moderator_settings', 'moderator_nonce'); ?>
 
 <table class="wp-list-table widefat fixed striped" id="moderator-products-table">
 <thead>
 <tr>
 <th style="width: 80px;">Sequence</th>
 <th style="width: 150px;">User Name</th>
 <th style="width: 100px;">Status</th>
 <th style="width: 100px;">Shift Status</th>
 <th style="width: 430px;">Assigned Products</th>
 <th style="width: 120px;">Actions</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($users as $user): ?>
 <?php 
 $current_sequence = get_user_meta($user->ID, 'moderator_sequence', true) ?: 0;
 $assigned_products = get_user_meta($user->ID, 'moderator_assigned_products', true) ?: array();
 $current_status = get_user_meta($user->ID, 'moderator_status', true) ?: 'active';
 $in_shift = ($current_status === 'active') ? is_user_in_shift_time($user->ID) : false;
 
 // Get product details for display
 $assigned_product_details = array();
 foreach ($assigned_products as $product_id) {
 $product = wc_get_product($product_id);
 if ($product) {
 $assigned_product_details[] = array(
 'id' => $product_id,
 'name' => $product->get_name(),
 'price' => $product->get_price(),
 'type' => $product->get_type()
 );
 }
 }
 ?>
 <tr class="user-<?php echo $current_status; ?>">
 <td>
 <div class="sequence-badge"><?php echo $current_sequence; ?></div>
 </td>
 <td>
 <div class="user-info">
 <strong><?php echo esc_html($user->display_name); ?></strong>
 <div class="user-email"><?php echo esc_html($user->user_email); ?></div>
 </div>
 </td>
 <td>
 <span class="status-badge status-<?php echo $current_status; ?>">
 <span class="dashicons dashicons-<?php echo $current_status === 'active' ? 'yes' : 'no'; ?>"></span>
 <?php echo ucfirst($current_status); ?>
 </span>
 </td>
 <td>
 <?php if ($current_status === 'active'): ?>
 <span class="shift-badge shift-<?php echo $in_shift ? 'in' : 'out'; ?>">
 <span class="dashicons dashicons-<?php echo $in_shift ? 'clock' : 'no-alt'; ?>"></span>
 <?php echo $in_shift ? 'In Shift' : 'Out of Shift'; ?>
 </span>
 <?php else: ?>
 <span class="shift-badge shift-inactive">
 <span class="dashicons dashicons-no"></span>
 Inactive
 </span>
 <?php endif; ?>
 </td>
 <td>
 <div class="assigned-products-container">
 <div class="aoam-product-picker">
 <div class="aoam-product-picker-toolbar">
 <input type="search" class="aoam-product-search" placeholder="Search products..." aria-label="Search assigned products">
 <span class="aoam-product-selected-count"><?php echo esc_html(count($assigned_products)); ?> selected</span>
 </div>
 <select name="moderator_products[<?php echo esc_attr($user->ID); ?>][]" multiple="multiple" class="moderator-products-select aoam-hidden-product-select" aria-hidden="true" tabindex="-1">
 <?php foreach ($products as $product): ?>
 <option value="<?php echo esc_attr($product->get_id()); ?>" 
 <?php selected(in_array($product->get_id(), $assigned_products, true)); ?>>
 <?php echo esc_html($product->get_name()); ?> (ID: <?php echo $product->get_id(); ?>)
 </option>
 <?php endforeach; ?>
 </select>
 <div class="aoam-product-options" role="listbox" aria-label="Assigned products">
 <div class="aoam-product-match-count"></div>
 <?php foreach ($products as $product): ?>
 <?php $is_selected_product = in_array($product->get_id(), $assigned_products, true); ?>
 <button type="button" class="aoam-product-option <?php echo $is_selected_product ? 'is-selected' : ''; ?>" data-product-id="<?php echo esc_attr($product->get_id()); ?>" data-product-name="<?php echo esc_attr(strtolower($product->get_name())); ?>" data-product-search="<?php echo esc_attr(strtolower($product->get_name() . ' ' . $product->get_id())); ?>" aria-selected="<?php echo $is_selected_product ? 'true' : 'false'; ?>">
 <span><?php echo esc_html($product->get_name()); ?> (ID: <?php echo esc_html($product->get_id()); ?>)</span>
 <span class="dashicons dashicons-yes"></span>
 </button>
 <?php endforeach; ?>
 <div class="aoam-product-no-results">No products found</div>
 </div>
 <div class="aoam-selected-products" aria-live="polite"></div>
 </div>
 
 <!-- Current Assignment Summary -->
 <div class="assignment-summary">
 <?php if (empty($assigned_products)): ?>
 <div class="no-assignment">
 <span class="dashicons dashicons-warning"></span>
 <div class="warning-text">
 <strong>No Products Assigned</strong>
 <span>This user will receive general orders only</span>
 </div>
 </div>
 <?php else: ?>
 <div class="has-assignment">
 <div class="assignment-header">
 <span class="dashicons dashicons-yes-alt"></span>
 <div class="assignment-stats">
 <strong><?php echo count($assigned_products); ?> Product<?php echo count($assigned_products) > 1 ? 's' : ''; ?> Assigned</strong>
 <span>Will receive orders for these products</span>
 </div>
 </div>
 </div>
 <?php endif; ?>
 </div>
 </div>
 </td>
 <td>
 <div class="action-buttons">
 <button type="button" class="button button-small clear-products" data-user-id="<?php echo $user->ID; ?>">
 <span class="dashicons dashicons-trash"></span>
 Clear All
 </button>
 </div>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 
 <p style="margin-top: 15px;">
 <input type="submit" name="update_products" class="button button-primary" value=" Update All Product Assignments">
 </p>
 </form>
 <?php endif; ?>
 </div>

 <!-- Bulk Actions -->
 <div class="card">
 <h2>Quick Product Management</h2>
 <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
 <div>
 <h4 style="margin-top: 0;">Bulk Actions</h4>
 <form method="post" style="display: flex; gap: 10px; align-items: center;">
 <?php wp_nonce_field('moderator_settings', 'moderator_nonce'); ?>
 <input type="hidden" name="user_ids" value="<?php echo implode(',', array_map(function($u) { return $u->ID; }, $users)); ?>">
 <select name="bulk_product_action" id="bulk_product_action" style="flex: 1;">
 <option value="">Select Action</option>
 <option value="clear_all_products">Clear All Product Assignments</option>
 <option value="clear_filtered_products">Clear Filtered Assignments</option>
 </select>
 <input type="submit" name="bulk_product_update" class="button" value="Apply" 
 onclick="return confirm('Are you sure? This will remove product assignments.')">
 </form>
 </div>
 
 <div>
 <h4 style="margin-top: 0;">Quick Filters</h4>
 <div style="display: flex; gap: 5px; flex-wrap: wrap;">
 <a href="?page=moderator-product-assignments&assignment_filter=without_products" 
 class="button button-small <?php echo $assignment_filter === 'without_products' ? 'button-primary' : 'button-secondary'; ?>">
 Without Products
 </a>
 <a href="?page=moderator-product-assignments&shift_filter=in_shift" 
 class="button button-small <?php echo $shift_filter === 'in_shift' ? 'button-primary' : 'button-secondary'; ?>">
 In Shift
 </a>
 <a href="?page=moderator-product-assignments&status_filter=inactive" 
 class="button button-small <?php echo $status_filter === 'inactive' ? 'button-primary' : 'button-secondary'; ?>">
 Inactive
 </a>
 </div>
 </div>
 </div>
 </div>
 </div>

 <!-- Native Product Assignment Script -->
 <script type="text/javascript">
 jQuery(document).ready(function($) {
 function aoamGetNoAssignmentHtml() {
 return '<div class="no-assignment">' +
 '<span class="dashicons dashicons-warning"></span>' +
 '<div class="warning-text">' +
 '<strong>No Products Assigned</strong>' +
 '<span>This user will receive general orders only</span>' +
 '</div>' +
 '</div>';
 }

 function aoamGetAssignmentHtml(count) {
 var label = count > 1 ? 'Products Assigned' : 'Product Assigned';
 return '<div class="has-assignment">' +
 '<div class="assignment-header">' +
 '<span class="dashicons dashicons-yes-alt"></span>' +
 '<div class="assignment-stats">' +
 '<strong>' + count + ' ' + label + '</strong>' +
 '<span>Will receive orders for these products</span>' +
 '</div>' +
 '</div>' +
 '</div>';
 }

 function aoamUpdateProductPicker($container) {
 var $select = $container.find('.moderator-products-select');
 var selectedOptions = $select.find('option:selected').toArray();
 var selectedCount = selectedOptions.length;
 var $chips = $container.find('.aoam-selected-products');
 var selectedValues = selectedOptions.map(function(option) {
 return String(option.value);
 });

 $container.find('.aoam-product-selected-count').text(selectedCount + ' selected');
 $container.find('.assignment-summary').html(selectedCount ? aoamGetAssignmentHtml(selectedCount) : aoamGetNoAssignmentHtml());
 $container.find('.aoam-product-option').each(function() {
 var isSelected = selectedValues.indexOf(String($(this).data('product-id'))) !== -1;
 $(this).toggleClass('is-selected', isSelected).attr('aria-selected', isSelected ? 'true' : 'false');
 });

 $chips.empty();
 if (!selectedCount) {
 $chips.append($('<span>', { 'class': 'aoam-product-empty-chip', text: 'No products selected' }));
 return;
 }

 selectedOptions.slice(0, 5).forEach(function(option) {
 $chips.append($('<span>', { 'class': 'aoam-product-chip', text: $(option).text() }));
 });

 if (selectedCount > 5) {
 $chips.append($('<span>', { 'class': 'aoam-product-more-chip', text: '+ ' + (selectedCount - 5) + ' more' }));
 }
 }

 function aoamOpenProductPicker($container) {
 $('.aoam-product-picker.is-open').not($container.find('.aoam-product-picker')).removeClass('is-open');
 $container.find('.aoam-product-picker').addClass('is-open');
 }

 function aoamFilterProductOptions($container) {
 var term = $container.find('.aoam-product-search').val().toLowerCase().trim();
 var terms = term ? term.split(/\s+/) : [];
 var visibleCount = 0;
 var $options = $container.find('.aoam-product-option');
 var $exactMatches = $();

 if (term) {
 $exactMatches = $options.filter(function() {
 var productName = String($(this).data('product-name') || '');
 var productId = String($(this).data('product-id') || '');
 return productName === term || productId === term;
 });
 }

 $options.removeClass('is-active').each(function() {
 var optionText = String($(this).data('product-search') || $(this).text()).toLowerCase();
 var matches = terms.every(function(part) {
 return optionText.indexOf(part) !== -1;
 });
 if ($exactMatches.length) {
 matches = $exactMatches.is(this);
 }
 $(this).toggle(matches);
 if (matches) {
 visibleCount++;
 }
 });

 $container.find('.aoam-product-no-results').toggle(visibleCount === 0);
 $container.find('.aoam-product-option:visible').first().addClass('is-active');
 $container.find('.aoam-product-match-count').text(visibleCount ? visibleCount + ' match' + (visibleCount > 1 ? 'es' : '') + ' found' : 'No matches');
 return visibleCount;
 }

 $('.assigned-products-container').each(function() {
 aoamUpdateProductPicker($(this));
 });

 $('.moderator-products-select').on('change', function() {
 var $container = $(this).closest('.assigned-products-container');
 aoamUpdateProductPicker($container);
 aoamFilterProductOptions($container);
 });

 $('.aoam-product-search').on('input', function() {
 var $container = $(this).closest('.assigned-products-container');
 aoamOpenProductPicker($container);
 aoamFilterProductOptions($container);
 });

 $('.aoam-product-search').on('keydown', function(e) {
 var $container = $(this).closest('.assigned-products-container');
 var $visibleOptions = $container.find('.aoam-product-option:visible');
 var $active = $visibleOptions.filter('.is-active');

 if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
 e.preventDefault();
 if (!$visibleOptions.length) {
 return;
 }
 var index = $visibleOptions.index($active);
 index = e.key === 'ArrowDown' ? index + 1 : index - 1;
 if (index < 0) {
 index = $visibleOptions.length - 1;
 }
 if (index >= $visibleOptions.length) {
 index = 0;
 }
 $visibleOptions.removeClass('is-active').eq(index).addClass('is-active').get(0).scrollIntoView({ block: 'nearest' });
 }

 if (e.key === 'Enter') {
 e.preventDefault();
 ($active.length ? $active : $visibleOptions.first()).trigger('click');
 }

 if (e.key === 'Escape') {
 $container.find('.aoam-product-picker').removeClass('is-open');
 }
 });

 $('.aoam-product-search, .aoam-product-selected-count, .aoam-selected-products').on('focus click', function() {
 aoamOpenProductPicker($(this).closest('.assigned-products-container'));
 });

 $('.aoam-product-option').on('click', function() {
 var $button = $(this);
 var productId = String($button.data('product-id'));
 var $container = $button.closest('.assigned-products-container');
 var $select = $container.find('.moderator-products-select');
 var option = $select.find('option[value="' + productId.replace(/"/g, '\\"') + '"]').get(0);

 if (!option) {
 return;
 }

 option.selected = !option.selected;
 $select.trigger('change');
 $container.find('.aoam-product-search').val('').focus();
 aoamFilterProductOptions($container);
 });

 $(document).on('click', function(e) {
 if (!$(e.target).closest('.aoam-product-picker').length) {
 $('.aoam-product-picker').removeClass('is-open');
 }
 });

 // Clear products for specific user
 $('.clear-products').on('click', function(e) {
 e.preventDefault();
 
 var $button = $(this);
 var userName = $button.closest('tr').find('.user-info strong').text();
 var $container = $button.closest('tr').find('.assigned-products-container');
 var $select = $container.find('.moderator-products-select');
 
 if (confirm('Are you sure you want to clear ALL product assignments for ' + userName + '?')) {
 $select.val(null).trigger('change');
 $container.find('.aoam-product-search').val('').trigger('input');
 aoamUpdateProductPicker($container);
 
 // Show success feedback
 $button.html('<span class="dashicons dashicons-yes"></span> Cleared!').prop('disabled', true);
 setTimeout(function() {
 $button.html('<span class="dashicons dashicons-trash"></span> Clear All').prop('disabled', false);
 }, 2000);
 }
 });

 // Focus on search field when page loads if there's a search term
 <?php if (!empty($search_term)): ?>
 $('#search').focus();
 <?php endif; ?>
 });
 </script>

 <style>
 .card{max-width: 100%;}
 .assigned-products-container {
 max-width: 100%;
 }
 .aoam-product-picker {
 position: relative;
 border: 1px solid #dcdcde;
 border-radius: 8px;
 background: #fff;
 padding: 8px;
 }
 .aoam-product-picker-toolbar {
 display: flex;
 gap: 10px;
 align-items: center;
 }
 .aoam-product-search {
 flex: 1 1 auto;
 min-height: 34px;
 border-color: #c3c4c7;
 border-radius: 6px;
 }
 .aoam-product-selected-count {
 flex: 0 0 auto;
 padding: 6px 9px;
 border-radius: 999px;
 background: #f0f6ff;
 color: #2271b1;
 font-size: 12px;
 font-weight: 700;
 }
 .aoam-hidden-product-select {
 position: absolute !important;
 width: 1px !important;
 height: 1px !important;
 overflow: hidden !important;
 clip: rect(1px, 1px, 1px, 1px) !important;
 white-space: nowrap !important;
 }
 .aoam-product-options {
 display: none;
 position: absolute;
 left: 8px;
 right: 8px;
 top: 48px;
 z-index: 100;
 max-height: 190px;
 overflow-y: auto;
 border: 1px solid #c3c4c7;
 border-radius: 6px;
 background: #fff;
 padding: 6px;
 box-shadow: 0 8px 20px rgba(0,0,0,0.14);
 }
 .aoam-product-picker.is-open .aoam-product-options {
 display: block;
 }
 .aoam-product-option {
 display: flex;
 align-items: center;
 justify-content: space-between;
 gap: 10px;
 width: 100%;
 min-height: 34px;
 margin: 2px 0;
 padding: 7px 9px;
 border: 0;
 border-radius: 5px;
 background: transparent;
 color: #1d2327;
 cursor: pointer;
 text-align: left;
 }
 .aoam-product-option:hover {
 background: #f0f6ff;
 }
 .aoam-product-option.is-active {
 outline: 2px solid #3858e9;
 outline-offset: -2px;
 background: #f0f6ff;
 }
 .aoam-product-option.is-selected {
 background: #2271b1;
 color: #fff;
 }
 .aoam-product-option.is-selected.is-active {
 background: #135e96;
 }
 .aoam-product-option .dashicons {
 display: none;
 flex: 0 0 auto;
 font-size: 16px;
 height: 16px;
 width: 16px;
 }
 .aoam-product-option.is-selected .dashicons {
 display: inline-block;
 }
 .aoam-product-no-results {
 display: none;
 padding: 10px;
 color: #646970;
 font-weight: 700;
 text-align: center;
 }
 .aoam-product-match-count {
 padding: 4px 8px 8px;
 color: #646970;
 font-size: 12px;
 font-weight: 700;
 }
 .aoam-selected-products {
 display: flex;
 flex-wrap: wrap;
 gap: 6px;
 margin-top: 7px;
 }
 .aoam-product-chip,
 .aoam-product-more-chip,
 .aoam-product-empty-chip {
 display: inline-flex;
 align-items: center;
 max-width: 100%;
 padding: 5px 8px;
 border-radius: 999px;
 font-size: 12px;
 font-weight: 700;
 line-height: 1.2;
 }
 .aoam-product-chip {
 background: #e7f3ff;
 color: #1d4f7a;
 }
 .aoam-product-more-chip {
 background: #f0f0f1;
 color: #50575e;
 }
 .aoam-product-empty-chip {
 background: #fff8e5;
 color: #8a6d3b;
 }
 .sequence-badge {
 display: inline-block;
 width: 30px;
 height: 30px;
 background: #0073aa;
 color: white;
 text-align: center;
 line-height: 30px;
 border-radius: 50%;
 font-weight: bold;
 }
 .status-badge {
 padding: 4px 8px;
 border-radius: 3px;
 font-size: 12px;
 font-weight: bold;
 }
 .status-active { background: #46b450; color: white; }
 .status-inactive { background: #cc1818; color: white; }
 .shift-badge {
 padding: 4px 8px;
 border-radius: 3px;
 font-size: 12px;
 font-weight: bold;
 display: inline-block;
 }
 .shift-in { background: #e5f7e5; color: #46b450; border: 1px solid #46b450; }
 .shift-out { background: #fff8e1; color: #ffb900; border: 1px solid #ffb900; }
 .shift-inactive { background: #ffe5e5; color: #cc1818; border: 1px solid #cc1818; }
 .no-assignment {
 display: flex;
 align-items: center;
 gap: 10px;
 padding: 10px;
 background: #fff5f5;
 border: 1px solid #ffd6d6;
 border-radius: 4px;
 margin-top: 10px;
 }
 .no-assignment .dashicons { color: #cc1818; }
 .has-assignment {
 padding: 10px;
 background: #f8fff8;
 border: 1px solid #d6ffd6;
 border-radius: 4px;
 margin-top: 10px;
 }
 .assignment-header {
 display: flex;
 align-items: center;
 gap: 10px;
 margin-bottom: 10px;
 }
 .assignment-header .dashicons { color: #46b450; }
 .action-buttons .button {
 display: flex;
 align-items: center;
 gap: 5px;
 justify-content: center;
 text-align: center;
 padding: 6px 10px;
 font-size: 12px;
 line-height: 1.3;
 }
 .clear-products {
 background: #dc3232;
 color: white;
 border-color: #dc3232;
 }
 .clear-products:hover {
 background: #a00;
 border-color: #a00;
 color: white;
 }
 .nav-tab-wrapper { margin: 20px 0; }
 .nav-tab { 
 text-decoration: none; 
 padding: 10px 15px; 
 margin: 0 5px 0 0; 
 border: 1px solid #ccd0d4;
 border-bottom: none;
 background: #f0f0f0;
 }
 .nav-tab-active { 
 background: #0073aa; 
 color: white; 
 border-bottom: 1px solid #0073aa;
 }
 .filter-group {
 margin-bottom: 0;
 }
 </style>
 <?php
}


function moderator_recent_assignments_page() {
 ?>
 <div class="wrap">
 <h1>Recent Assignments</h1>
 <div class="nav-tab-wrapper">
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-settings')); ?>" class="nav-tab">Dashboard</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-recent-assignments')); ?>" class="nav-tab nav-tab-active">Recent Assignments</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-sequence-status')); ?>" class="nav-tab">Sequence & Status</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-product-assignments')); ?>" class="nav-tab">Product Assignments</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-reassign-orders')); ?>" class="nav-tab">Reassign</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-plugin-settings')); ?>" class="nav-tab">Plugin Settings</a>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-remote-import')); ?>" class="nav-tab">Remote Import</a>
 </div>
 <div id="aoam-recent-assignments-app" class="aoam-ajax-shell">
 <div class="aoam-ajax-loading"><span class="spinner is-active"></span><p>Loading assignments...</p></div>
 </div>
 </div>
 <script>
 jQuery(function($) {
 var $app = $('#aoam-recent-assignments-app');
 var request = null;
 var modalOrderId = null;
 var recentSearchTimer = null;

 function toggleRecentCustomDates($scope) {
 var $root = $scope || $app;
 var showCustom = $root.find('#assignment_date_filter').val() === 'custom';
 $root.find('.aoam-custom-date-field').toggle(showCustom);
 }

 function collectParamsFromUrl(url) {
 var target = new URL(url || window.location.href, window.location.href);
 return target.searchParams;
 }

 function collectParamsFromForm($form) {
 var params = new URLSearchParams($form.serialize());
 params.set('page', 'moderator-recent-assignments');
 params.set('paged', '1');
 return params;
 }

 function loadRecentAssignments(params, pushUrl) {
 if (request) {
 request.abort();
 }

 window.aoamRefreshRecentAssignments = function() {
 loadRecentAssignments(collectParamsFromUrl(), false);
 };

 window.viewOrderDetails = function(orderId) {
 modalOrderId = orderId;
 $('.modal-backdrop').remove();
 $('#modal-order-id').text(orderId);
 $('#order-details-content').html("<div style='text-align: center; padding: 40px;'><div class='spinner is-active' style='float: none;'></div><p>Loading order details...</p></div>");
 $('body').append('<div class="modal-backdrop"></div>');
 $('#order-details-modal').show();
 $('body').addClass('aoam-modal-open');

 $.ajax({
 url: ajaxurl,
 type: 'POST',
 dataType: 'json',
 data: {
 action: 'aoam_get_moderator_order_details',
 order_id: orderId,
 nonce: '<?php echo esc_js(wp_create_nonce('moderator_order_details')); ?>'
 }
 }).done(function(response) {
 if (response && response.success) {
 $('#order-details-content').html(response.data);
 } else {
 $('#order-details-content').html("<div style='text-align: center; padding: 40px; color: #cc1818;'><p>Error loading order details.</p></div>");
 }
 }).fail(function() {
 $('#order-details-content').html("<div style='text-align: center; padding: 40px; color: #cc1818;'><p>Error loading order details.</p></div>");
 });
 };

 function aoamForceCloseModal() {
 modalOrderId = null;
 $('#order-details-modal, .aoam-order-modal').hide();
 $('.modal-backdrop').remove();
 $('body').removeClass('aoam-modal-open');
 }

 window.closeOrderModal = function() {
 aoamForceCloseModal();
 setTimeout(aoamForceCloseModal, 50);
 setTimeout(aoamForceCloseModal, 250);
 };

 var searchParams = params || collectParamsFromUrl();
 searchParams.set('page', 'moderator-recent-assignments');
 $app.addClass('is-loading');
 if (!$app.children().length || $app.find('.aoam-ajax-loading').length) {
 $app.html('<div class="aoam-ajax-loading"><span class="spinner is-active"></span><p>Loading assignments...</p></div>');
 }

 request = $.ajax({
 url: ajaxurl,
 type: 'POST',
 dataType: 'json',
 data: {
 action: 'aoam_recent_assignments_ajax',
 nonce: '<?php echo esc_js(wp_create_nonce('aoam_recent_assignments_ajax')); ?>',
 moderator_filter: searchParams.get('moderator_filter') || '0',
 source_filter: searchParams.get('source_filter') || 'all',
 status_filter: searchParams.get('status_filter') || 'all',
 order_search: searchParams.get('order_search') || '',
 assignment_date_filter: searchParams.get('assignment_date_filter') || 'all',
 custom_start_date: searchParams.get('custom_start_date') || '',
 custom_end_date: searchParams.get('custom_end_date') || '',
 per_page: searchParams.get('per_page') || '20',
 paged: searchParams.get('paged') || '1'
 }
 }).done(function(response) {
 if (response && response.success && response.data && response.data.html) {
 $app.html(response.data.html);
 toggleRecentCustomDates($app);
 if (pushUrl) {
 window.history.pushState({}, '', '?' + searchParams.toString());
 }
 } else {
 $app.html('<div class="notice notice-error"><p>Could not load assignments.</p></div>');
 }
 }).fail(function(xhr, status) {
 if (status !== 'abort') {
 $app.html('<div class="notice notice-error"><p>Server took too long to load assignments. Please try again.</p></div>');
 }
 }).always(function() {
 $app.removeClass('is-loading');
 request = null;
 });
 }

 $app.on('submit', '.aoam-filter-form', function(e) {
 e.preventDefault();
 e.stopImmediatePropagation();
 loadRecentAssignments(collectParamsFromForm($(this)), true);
 });

 $app.on('change', '.aoam-filter-form select', function(e) {
 e.preventDefault();
 e.stopImmediatePropagation();
 toggleRecentCustomDates($(this).closest('form'));
 loadRecentAssignments(collectParamsFromForm($(this).closest('form')), true);
 });

 $app.on('change', '.aoam-filter-form input[type="date"]', function(e) {
 e.preventDefault();
 e.stopImmediatePropagation();
 var $form = $(this).closest('form');
 if ($('#assignment_date_filter').val() === 'custom' && $('#custom_start_date').val() && $('#custom_end_date').val()) {
 loadRecentAssignments(collectParamsFromForm($form), true);
 }
 });

 $app.on('search', '.aoam-filter-form input[type="search"]', function(e) {
 e.preventDefault();
 loadRecentAssignments(collectParamsFromForm($(this).closest('form')), true);
 });

 $app.on('input', '.aoam-filter-form input[type="search"]', function() {
 var $field = $(this);
 clearTimeout(recentSearchTimer);
 recentSearchTimer = setTimeout(function() {
 loadRecentAssignments(collectParamsFromForm($field.closest('form')), true);
 }, 450);
 });

 $app.on('click', '.aoam-clear-search', function(e) {
 e.preventDefault();
 var $form = $(this).closest('form');
 $form.find('input[name="order_search"]').val('');
 loadRecentAssignments(collectParamsFromForm($form), true);
 });

 $app.on('click', '.tablenav-pages a, .aoam-soft-button, .aoam-reset-filters', function(e) {
 var href = $(this).attr('href');
 if (!href) {
 return;
 }
 e.preventDefault();
 loadRecentAssignments(collectParamsFromUrl(href), true);
 });

 $app.on('click', '.view-order-details', function(e) {
 e.preventDefault();
 viewOrderDetails($(this).data('order-id'));
 });

 $(document).on('click', '.modal-backdrop', function() {
 closeOrderModal();
 });

 $(document).on('click', '[onclick*="closeOrderModal"], .aoam-modal-close', function() {
 setTimeout(aoamForceCloseModal, 0);
 setTimeout(aoamForceCloseModal, 100);
 });

 $(document).on('keyup', function(e) {
 if (e.keyCode === 27) {
 closeOrderModal();
 }
 });

 $(document).on('submit', '#update_status_form', function(e) {
 e.preventDefault();
 var $form = $(this);
 var $button = $('#update_status_btn');
 var $message = $('#status_update_message');
 if (!$('#order_status_select').val()) {
 $message.html('<div style="color: #cc1818; padding: 8px; background: #ffe5e5; border-radius: 4px;">Please select a status</div>').show();
 return;
 }
 $button.text('Updating...').prop('disabled', true);
 $message.hide();

 $.ajax({
 url: ajaxurl,
 type: 'POST',
 dataType: 'json',
 data: $form.serialize() + '&action=update_order_status_ajax'
 }).done(function(response) {
 if (response && response.success) {
 $message.html('<div style="color: #1f8f3a; padding: 8px; background: #e5f7e5; border-radius: 4px;">' + response.data.message + '</div>').show();
 $('#current_status_display').text(response.data.new_status_label);
 aoamRefreshRecentAssignments();
 closeOrderModal();
 } else {
 $message.html('<div style="color: #cc1818; padding: 8px; background: #ffe5e5; border-radius: 4px;">' + (response.data || 'Update failed') + '</div>').show();
 }
 }).fail(function() {
 $message.html('<div style="color: #cc1818; padding: 8px; background: #ffe5e5; border-radius: 4px;">Network error. Please try again.</div>').show();
 }).always(function() {
 $button.text('Update Status').prop('disabled', false);
 });
 });

 $(document).on('submit', '#change_moderator_form', function(e) {
 e.preventDefault();
 var $form = $(this);
 var $button = $form.find('button[type="submit"]');
 var $message = $('#moderator_change_message');
 if (!$form.find('select[name="new_moderator_id"]').val()) {
 $message.html('<div style="color: #cc1818; padding: 8px; background: #ffe5e5; border-radius: 4px;">Please select a user</div>').show();
 return;
 }
 $button.text('Changing...').prop('disabled', true);
 $message.hide();

 $.ajax({
 url: ajaxurl,
 type: 'POST',
 dataType: 'json',
 data: $form.serialize() + '&action=change_order_moderator_ajax'
 }).done(function(response) {
 if (response && response.success) {
 var message = response.data && response.data.message ? response.data.message : 'User changed successfully.';
 $message.html('<div style="color: #1f8f3a; padding: 8px; background: #e5f7e5; border-radius: 4px;">' + message + '</div>').show();
 aoamRefreshRecentAssignments();
 closeOrderModal();
 } else {
 $message.html('<div style="color: #cc1818; padding: 8px; background: #ffe5e5; border-radius: 4px;">' + (response.data || 'User change failed') + '</div>').show();
 }
 }).fail(function() {
 $message.html('<div style="color: #cc1818; padding: 8px; background: #ffe5e5; border-radius: 4px;">Network error. Please try again.</div>').show();
 }).always(function() {
 $button.text('Change User').prop('disabled', false);
 });
 });

 window.addEventListener('popstate', function() {
 loadRecentAssignments(collectParamsFromUrl(), false);
 });

 document.addEventListener('submit', function(event) {
 if (event.target && event.target.classList && event.target.classList.contains('aoam-filter-form')) {
 event.preventDefault();
 }
 }, true);

 loadRecentAssignments(collectParamsFromUrl(), false);
 });
 </script>
 <style>
 .aoam-ajax-shell {
 min-height: 240px;
 position: relative;
 }
 .aoam-ajax-shell.is-loading {
 opacity: .68;
 pointer-events: none;
 }
 .aoam-ajax-loading {
 background: #fff;
 border: 1px solid #dcdcde;
 border-radius: 8px;
 margin: 20px 0;
 padding: 48px;
 text-align: center;
 }
 .aoam-ajax-loading .spinner {
 float: none;
 margin: 0 0 10px;
 }
 </style>
 <?php
}

add_action('wp_ajax_aoam_recent_assignments_ajax', 'aoam_recent_assignments_ajax');
function aoam_recent_assignments_ajax() {
 check_ajax_referer('aoam_recent_assignments_ajax', 'nonce');
 if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
 wp_send_json_error(array('message' => 'Permission denied'), 403);
 }

 $_GET['moderator_filter'] = isset($_POST['moderator_filter']) ? absint($_POST['moderator_filter']) : 0;
 $_GET['source_filter'] = isset($_POST['source_filter']) ? sanitize_key(wp_unslash($_POST['source_filter'])) : 'all';
 $_GET['status_filter'] = isset($_POST['status_filter']) ? sanitize_key(wp_unslash($_POST['status_filter'])) : 'all';
 $_GET['order_search'] = isset($_POST['order_search']) ? sanitize_text_field(wp_unslash($_POST['order_search'])) : '';
 $_GET['assignment_date_filter'] = isset($_POST['assignment_date_filter']) ? sanitize_key(wp_unslash($_POST['assignment_date_filter'])) : 'all';
 $_GET['custom_start_date'] = isset($_POST['custom_start_date']) ? sanitize_text_field(wp_unslash($_POST['custom_start_date'])) : '';
 $_GET['custom_end_date'] = isset($_POST['custom_end_date']) ? sanitize_text_field(wp_unslash($_POST['custom_end_date'])) : '';
 $_GET['per_page'] = isset($_POST['per_page']) ? absint($_POST['per_page']) : 20;
 $_GET['paged'] = isset($_POST['paged']) ? absint($_POST['paged']) : 1;

 ob_start();
 aoam_render_recent_assignments_page_content(true);
 wp_send_json_success(array('html' => ob_get_clean()));
}

function aoam_get_recent_assignments_date_range($date_filter, $custom_start_date = '', $custom_end_date = '') {
 $timezone = function_exists('aoam_get_display_timezone') ? aoam_get_display_timezone() : new DateTimeZone('Asia/Dhaka');
 $now = new DateTimeImmutable('now', $timezone);
 $range = array('start_gmt' => '', 'end_gmt' => '');

 if ($date_filter === 'today') {
 $end = $now->setTime(22, 0, 0);
 if ($now > $end) {
 $end = $end->modify('+1 day');
 }
 $start = $end->modify('-1 day');
 } elseif ($date_filter === 'yesterday') {
 $today_end = $now->setTime(22, 0, 0);
 if ($now > $today_end) {
 $today_end = $today_end->modify('+1 day');
 }
 $end = $today_end->modify('-1 day');
 $start = $end->modify('-1 day');
 } elseif ($date_filter === 'this_month') {
 $start = $now->modify('first day of this month')->setTime(0, 0, 0);
 $end = $now->modify('last day of this month')->setTime(23, 59, 59);
 } elseif ($date_filter === 'last_month') {
 $last_month = $now->modify('first day of last month');
 $start = $last_month->setTime(0, 0, 0);
 $end = $last_month->modify('last day of this month')->setTime(23, 59, 59);
 } elseif ($date_filter === 'custom' && $custom_start_date && $custom_end_date) {
 $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $custom_start_date . ' 00:00:00', $timezone);
 $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $custom_end_date . ' 23:59:59', $timezone);
 if (!$start || !$end || $start > $end) {
 return $range;
 }
 } else {
 return $range;
 }

 $range['start_gmt'] = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
 $range['end_gmt'] = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
 return $range;
}

function aoam_get_recent_assignment_flow_counts($base_where_sql, $base_params, $orders_meta_table, $orders_meta_table_exists, $date_range = array()) {
 global $wpdb;

 $orders_table = $wpdb->prefix . 'wc_orders';
 $flow_counts = array(
 'processing' => array('completed' => 0, 'cancelled' => 0),
 'partial' => array('completed' => 0, 'cancelled' => 0),
 );

 foreach ($flow_counts as $from_status => $to_statuses) {
 foreach ($to_statuses as $to_status => $unused_count) {
 $flow_where = array($base_where_sql, 'o.status = %s');
 $flow_params = $base_params;
 $flow_params[] = 'wc-' . $to_status;

 if (!empty($date_range['start_gmt']) && !empty($date_range['end_gmt'])) {
 $no_transition_date_exists = "NOT EXISTS (
 SELECT 1 FROM {$wpdb->postmeta} existing_transition_date_pm
 WHERE existing_transition_date_pm.post_id = pm.post_id
 AND existing_transition_date_pm.meta_key = '_aoam_terminal_transition_at_gmt'
 )";
 if ($orders_meta_table_exists) {
 $no_transition_date_exists .= " AND NOT EXISTS (
 SELECT 1 FROM {$orders_meta_table} existing_transition_date_om
 WHERE existing_transition_date_om.order_id = pm.post_id
 AND existing_transition_date_om.meta_key = '_aoam_terminal_transition_at_gmt'
 )";
 }
 $origin_meta_where = array(
 "(EXISTS (
 SELECT 1 FROM {$wpdb->postmeta} origin_pm
 WHERE origin_pm.post_id = pm.post_id
 AND origin_pm.meta_key = '_aoam_terminal_transition_from'
 AND origin_pm.meta_value = %s
 )
 AND EXISTS (
 SELECT 1 FROM {$wpdb->postmeta} transition_date_pm
 WHERE transition_date_pm.post_id = pm.post_id
 AND transition_date_pm.meta_key = '_aoam_terminal_transition_at_gmt'
 AND transition_date_pm.meta_value >= %s
 AND transition_date_pm.meta_value <= %s
 ))",
 "(EXISTS (
 SELECT 1 FROM {$wpdb->postmeta} legacy_origin_pm
 WHERE legacy_origin_pm.post_id = pm.post_id
 AND legacy_origin_pm.meta_key = '_sequence_type'
 AND legacy_origin_pm.meta_value = %s
 )
 AND {$no_transition_date_exists}
 AND o.date_updated_gmt >= %s
 AND o.date_updated_gmt <= %s)"
 );
 $flow_params[] = $from_status;
 $flow_params[] = $date_range['start_gmt'];
 $flow_params[] = $date_range['end_gmt'];
 $flow_params[] = $from_status;
 $flow_params[] = $date_range['start_gmt'];
 $flow_params[] = $date_range['end_gmt'];

 if ($orders_meta_table_exists) {
 $origin_meta_where[] = "(EXISTS (
 SELECT 1 FROM {$orders_meta_table} origin_om
 WHERE origin_om.order_id = pm.post_id
 AND origin_om.meta_key = '_aoam_terminal_transition_from'
 AND origin_om.meta_value = %s
 )
 AND EXISTS (
 SELECT 1 FROM {$orders_meta_table} transition_date_om
 WHERE transition_date_om.order_id = pm.post_id
 AND transition_date_om.meta_key = '_aoam_terminal_transition_at_gmt'
 AND transition_date_om.meta_value >= %s
 AND transition_date_om.meta_value <= %s
 ))";
 $flow_params[] = $from_status;
 $flow_params[] = $date_range['start_gmt'];
 $flow_params[] = $date_range['end_gmt'];
 $origin_meta_where[] = "(EXISTS (
 SELECT 1 FROM {$orders_meta_table} legacy_origin_om
 WHERE legacy_origin_om.order_id = pm.post_id
 AND legacy_origin_om.meta_key = '_sequence_type'
 AND legacy_origin_om.meta_value = %s
 )
 AND {$no_transition_date_exists}
 AND o.date_updated_gmt >= %s
 AND o.date_updated_gmt <= %s)";
 $flow_params[] = $from_status;
 $flow_params[] = $date_range['start_gmt'];
 $flow_params[] = $date_range['end_gmt'];
 }
 } else {
 $origin_meta_where = array(
 "EXISTS (
 SELECT 1 FROM {$wpdb->postmeta} origin_pm
 WHERE origin_pm.post_id = pm.post_id
 AND origin_pm.meta_key IN ('_aoam_terminal_transition_from', '_sequence_type')
 AND origin_pm.meta_value = %s
 )"
 );
 $flow_params[] = $from_status;

 if ($orders_meta_table_exists) {
 $origin_meta_where[] = "EXISTS (
 SELECT 1 FROM {$orders_meta_table} origin_om
 WHERE origin_om.order_id = pm.post_id
 AND origin_om.meta_key IN ('_aoam_terminal_transition_from', '_sequence_type')
 AND origin_om.meta_value = %s
 )";
 $flow_params[] = $from_status;
 }
 }

 $flow_where[] = '(' . implode(' OR ', $origin_meta_where) . ')';
 // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE fragments above are static SQL clauses; values are passed through prepare placeholders.
 $flow_counts[$from_status][$to_status] = (int) $wpdb->get_var($wpdb->prepare("
 SELECT COUNT(DISTINCT pm.post_id)
 FROM {$wpdb->postmeta} pm
 INNER JOIN {$orders_table} o ON o.id = pm.post_id
 WHERE " . implode(' AND ', $flow_where) . "
 ", $flow_params));
 }
 }

 return $flow_counts;
}

function aoam_render_recent_assignments_page_content($ajax_request = false) {
 // Get assigned roles dynamically.
 $assigned_roles = aoam_get_assigned_roles();
 
 // Get filter parameters
 $moderator_filter = isset($_GET['moderator_filter']) ? absint($_GET['moderator_filter']) : 0;
 $allowed_statuses = array('pending', 'partial', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed');
 $status_filter = isset($_GET['status_filter']) ? sanitize_key($_GET['status_filter']) : 'all';
 if ($status_filter !== 'all' && !in_array($status_filter, $allowed_statuses, true)) {
 $status_filter = 'all';
 }
 $remote_settings = aoam_get_remote_import_settings();
 $remote_source_options = array();
 foreach (($remote_settings['sources'] ?? array()) as $remote_source) {
 if (!empty($remote_source['site_url'])) {
 $source_url = esc_url_raw($remote_source['site_url']);
 $remote_source_options[md5(untrailingslashit($source_url))] = $source_url;
 }
 }
 $source_filter = isset($_GET['source_filter']) ? sanitize_key($_GET['source_filter']) : 'all';
 if ($source_filter !== 'all' && $source_filter !== 'current' && !isset($remote_source_options[$source_filter])) {
 $source_filter = 'all';
 }
 $allowed_date_filters = array('all', 'today', 'yesterday', 'this_month', 'last_month', 'custom');
 $assignment_date_filter = isset($_GET['assignment_date_filter']) ? sanitize_key($_GET['assignment_date_filter']) : 'all';
 if (!in_array($assignment_date_filter, $allowed_date_filters, true)) {
 $assignment_date_filter = 'all';
 }
 $custom_start_date = isset($_GET['custom_start_date']) ? sanitize_text_field(wp_unslash($_GET['custom_start_date'])) : '';
 $custom_end_date = isset($_GET['custom_end_date']) ? sanitize_text_field(wp_unslash($_GET['custom_end_date'])) : '';
 $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
 $order_search = isset($_GET['order_search']) ? sanitize_text_field(wp_unslash($_GET['order_search'])) : '';
 
 // Get per_page from GET parameter.
 $per_page = isset($_GET['per_page']) ? absint($_GET['per_page']) : 20;
 if (!in_array($per_page, array(10, 20, 50, 100), true)) {
 $per_page = 20;
 }
 
 // Get users with assigned roles.
 $moderators = get_users(array(
 'role__in' => $assigned_roles,
 'orderby' => 'display_name'
 ));

 $today_date_range = aoam_get_recent_assignments_date_range('today');

 ?>
 <?php if (!$ajax_request): ?>
 <div class="wrap">
        <h1>Recent Assignments</h1>
 
 <!-- Navigation Tabs -->
 <div class="nav-tab-wrapper">
 <a href="<?php echo admin_url('admin.php?page=moderator-settings'); ?>" class="nav-tab">Dashboard</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-recent-assignments'); ?>" class="nav-tab nav-tab-active">Recent Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-sequence-status'); ?>" class="nav-tab">Sequence & Status</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-product-assignments'); ?>" class="nav-tab">Product Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-reassign-orders'); ?>" class="nav-tab">Reassign</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-plugin-settings'); ?>" class="nav-tab">Plugin Settings</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-remote-import'); ?>" class="nav-tab">Remote Import</a>
 </div>
 <?php endif; ?>

 

 <?php
 // Query assigned orders from HPOS order data while keeping pagination in SQL.
 $status_counts = array(
 'all' => 0,
 'pending' => 0,
 'partial' => 0,
 'processing' => 0,
 'on-hold' => 0,
 'completed' => 0,
 'cancelled' => 0,
 'refunded' => 0,
 'failed' => 0
 );

 $orders = array();
 $total_orders = 0;
 $total_pages = 0;
 $today_orders_count = 0;

 global $wpdb;
 $orders_table = $wpdb->prefix . 'wc_orders';
 $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
 $order_addresses_table = $wpdb->prefix . 'wc_order_addresses';
 $orders_meta_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_meta_table)) === $orders_meta_table;
 $order_addresses_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $order_addresses_table)) === $order_addresses_table;
 $base_where = array(
 'pm.meta_key = %s',
 'o.type = %s',
 );
 $base_params = array('_assigned_moderator_id', 'shop_order');
 if ($moderator_filter > 0) {
 $base_where[] = 'pm.meta_value = %s';
 $base_params[] = (string) $moderator_filter;
 }
 $selected_date_range = aoam_get_recent_assignments_date_range($assignment_date_filter, $custom_start_date, $custom_end_date);
 if (!empty($selected_date_range['start_gmt']) && !empty($selected_date_range['end_gmt'])) {
 $base_where[] = 'o.date_created_gmt >= %s';
 $base_where[] = 'o.date_created_gmt <= %s';
 $base_params[] = $selected_date_range['start_gmt'];
 $base_params[] = $selected_date_range['end_gmt'];
 }
 if ($source_filter === 'current') {
 $base_where[] = "NOT EXISTS (
 SELECT 1 FROM {$wpdb->postmeta} source_pm
 WHERE source_pm.post_id = pm.post_id
 AND source_pm.meta_key IN ('_aoam_remote_order_source', '_aoam_remote_order_key')
 )";
 if ($orders_meta_table_exists) {
 $base_where[] = "NOT EXISTS (
 SELECT 1 FROM {$orders_meta_table} source_om
 WHERE source_om.order_id = pm.post_id
 AND source_om.meta_key IN ('_aoam_remote_order_source', '_aoam_remote_order_key')
 )";
 }
 } elseif (isset($remote_source_options[$source_filter])) {
 $remote_source_where = array(
 "EXISTS (
 SELECT 1 FROM {$wpdb->postmeta} source_pm
 WHERE source_pm.post_id = pm.post_id
 AND (
 (source_pm.meta_key = '_aoam_remote_order_source' AND source_pm.meta_value = %s)
 OR (source_pm.meta_key = '_aoam_remote_order_key' AND source_pm.meta_value LIKE %s)
 )
 )"
 );
 $base_params[] = $remote_source_options[$source_filter];
 $base_params[] = $source_filter . ':%';
 if ($orders_meta_table_exists) {
 $remote_source_where[] = "EXISTS (
 SELECT 1 FROM {$orders_meta_table} source_om
 WHERE source_om.order_id = pm.post_id
 AND (
 (source_om.meta_key = '_aoam_remote_order_source' AND source_om.meta_value = %s)
 OR (source_om.meta_key = '_aoam_remote_order_key' AND source_om.meta_value LIKE %s)
 )
 )";
 $base_params[] = $remote_source_options[$source_filter];
 $base_params[] = $source_filter . ':%';
 }
 $base_where[] = '(' . implode(' OR ', $remote_source_where) . ')';
 }

 if ($order_search !== '') {
 $search_like = '%' . $wpdb->esc_like($order_search) . '%';
 $search_where = array('CAST(o.id AS CHAR) LIKE %s');
 $base_params[] = $search_like;

 if ($order_addresses_table_exists) {
 $search_where[] = "EXISTS (
 SELECT 1 FROM {$order_addresses_table} billing_address
 WHERE billing_address.order_id = o.id
 AND billing_address.address_type = 'billing'
 AND (
 billing_address.first_name LIKE %s
 OR billing_address.last_name LIKE %s
 OR CONCAT_WS(' ', billing_address.first_name, billing_address.last_name) LIKE %s
 OR billing_address.phone LIKE %s
 )
 )";
 $base_params[] = $search_like;
 $base_params[] = $search_like;
 $base_params[] = $search_like;
 $base_params[] = $search_like;
 }

 $search_where[] = "EXISTS (
 SELECT 1 FROM {$wpdb->postmeta} billing_pm
 WHERE billing_pm.post_id = pm.post_id
 AND billing_pm.meta_key IN ('_billing_first_name', '_billing_last_name', '_billing_phone')
 AND billing_pm.meta_value LIKE %s
 )";
 $base_params[] = $search_like;

 $base_where[] = '(' . implode(' OR ', $search_where) . ')';
 }

 $flow_base_where = $base_where;
 $flow_base_params = $base_params;
 if (!empty($selected_date_range['start_gmt']) && !empty($selected_date_range['end_gmt'])) {
 $removed_date_clauses = 0;
 foreach ($flow_base_where as $where_index => $where_clause) {
 if ($removed_date_clauses < 2 && in_array($where_clause, array('o.date_created_gmt >= %s', 'o.date_created_gmt <= %s'), true)) {
 unset($flow_base_where[$where_index]);
 $removed_date_clauses++;
 }
 }
 $flow_base_where = array_values($flow_base_where);
 $date_param_offset = 2 + ($moderator_filter > 0 ? 1 : 0);
 array_splice($flow_base_params, $date_param_offset, 2);
 }

 $base_where_sql = implode(' AND ', $base_where);
 $flow_base_where_sql = implode(' AND ', $flow_base_where);
 $status_rows = $wpdb->get_results($wpdb->prepare("
 SELECT o.status, COUNT(DISTINCT pm.post_id) AS total
 FROM {$wpdb->postmeta} pm
 INNER JOIN {$orders_table} o ON o.id = pm.post_id
 WHERE {$base_where_sql}
 GROUP BY o.status
 ", $base_params));
 foreach ($status_rows as $status_row) {
 $status_key = preg_replace('/^wc-/', '', (string) $status_row->status);
 $count = (int) $status_row->total;
 $status_counts['all'] += $count;
 if (isset($status_counts[$status_key])) {
 $status_counts[$status_key] = $count;
 }
 }
 $total_orders = $status_counts['all'];

 if ($status_filter !== 'all') {
 $total_orders = $status_counts[$status_filter] ?? 0;
 }

 $today_where = $base_where;
 $today_where[] = 'o.date_created_gmt >= %s';
 $today_where[] = 'o.date_created_gmt <= %s';
 $today_params = array_merge($base_params, array($today_date_range['start_gmt'], $today_date_range['end_gmt']));
 // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE fragments above are static SQL clauses; values are passed through prepare placeholders.
 $today_orders_count = (int) $wpdb->get_var($wpdb->prepare("
 SELECT COUNT(DISTINCT pm.post_id)
 FROM {$wpdb->postmeta} pm
 INNER JOIN {$orders_table} o ON o.id = pm.post_id
 WHERE " . implode(' AND ', $today_where) . "
 ", $today_params));
 $flow_counts = aoam_get_recent_assignment_flow_counts($flow_base_where_sql, $flow_base_params, $orders_meta_table, $orders_meta_table_exists, $selected_date_range);
 $processing_flow_total = array_sum($flow_counts['processing']);
 $partial_flow_total = array_sum($flow_counts['partial']);
 $flow_total = $processing_flow_total + $partial_flow_total;
 $completed_flow_total = $flow_counts['processing']['completed'] + $flow_counts['partial']['completed'];
 $cancelled_flow_total = $flow_counts['processing']['cancelled'] + $flow_counts['partial']['cancelled'];
 $flow_max_value = max(1, $flow_counts['processing']['completed'], $flow_counts['processing']['cancelled'], $flow_counts['partial']['completed'], $flow_counts['partial']['cancelled']);
 $flow_percent = function($value, $total) {
 return $total > 0 ? round(((int) $value / (int) $total) * 100) : 0;
 };

 $query_where = $base_where;
 $query_params = $base_params;
 if ($status_filter !== 'all') {
 $query_where[] = 'o.status = %s';
 $query_params[] = 'wc-' . $status_filter;
 }
 $total_pages = $total_orders > 0 ? (int) ceil($total_orders / $per_page) : 0;
 $paged = $total_pages > 0 ? min($paged, $total_pages) : 1;
 $offset = ($paged - 1) * $per_page;
 $query_params[] = $per_page;
 $query_params[] = $offset;
 // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE fragments above are static SQL clauses; values are passed through prepare placeholders.
 $order_ids = $wpdb->get_col($wpdb->prepare("
 SELECT DISTINCT pm.post_id
 FROM {$wpdb->postmeta} pm
 INNER JOIN {$orders_table} o ON o.id = pm.post_id
 WHERE " . implode(' AND ', $query_where) . "
 ORDER BY o.date_created_gmt DESC, pm.meta_id DESC
 LIMIT %d OFFSET %d
 ", $query_params));
 $orders = array_filter(array_map('wc_get_order', array_map('absint', $order_ids)));
 ?>
 
 <!-- Results Summary -->
 <div class="aoam-report-panel">
 <div class="aoam-section-heading">
 <div>
 <h2>Assignment Results</h2>
 <p>Overview of assigned orders with the current filters applied.</p>
 </div>
 <a href="<?php echo esc_url(admin_url('admin.php?page=moderator-recent-assignments')); ?>" class="button aoam-soft-button">Refresh</a>
 </div>
 <div class="aoam-stats-grid">
 <div class="aoam-stat-tile aoam-stat-total">
 <span class="dashicons dashicons-clipboard"></span>
 <div>
 <strong><?php echo esc_html(number_format_i18n($total_orders)); ?></strong>
 <small>Total Orders</small>
 </div>
 </div>
 <div class="aoam-stat-tile aoam-stat-today">
 <span class="dashicons dashicons-calendar-alt"></span>
 <div>
 <strong><?php echo esc_html(number_format_i18n($today_orders_count)); ?></strong>
 <small>Today Orders</small>
 </div>
 </div>
 <div class="aoam-stat-tile aoam-stat-showing">
 <span class="dashicons dashicons-visibility"></span>
 <div>
 <strong><?php echo esc_html(number_format_i18n(count($orders))); ?></strong>
 <small>Showing Now</small>
 </div>
 </div>
 <div class="aoam-stat-tile aoam-stat-pages">
 <span class="dashicons dashicons-media-spreadsheet"></span>
 <div>
 <strong><?php echo esc_html(number_format_i18n($total_pages)); ?></strong>
 <small>Total Pages</small>
 </div>
 </div>
 <div class="aoam-stat-tile aoam-stat-users">
 <span class="dashicons dashicons-groups"></span>
 <div>
 <strong><?php echo esc_html(number_format_i18n(count($moderators))); ?></strong>
 <small>Total Users</small>
 </div>
 </div>
 </div>

 <div class="aoam-flow-panel">
 <div class="aoam-section-heading aoam-section-heading-small">
 <div>
 <h3>Completion & Cancellation Flow</h3>
 <p>Tracks orders that moved from Processing or Partial into Completed or Cancelled.</p>
 </div>
 <div class="aoam-flow-total">
 <span><?php echo esc_html(number_format_i18n($flow_total)); ?></span>
 <small>Tracked changes</small>
 </div>
 </div>
 <div class="aoam-flow-grid">
 <div class="aoam-flow-card aoam-flow-processing">
 <span class="dashicons dashicons-update"></span>
 <div>
 <strong><?php echo esc_html(number_format_i18n($processing_flow_total)); ?></strong>
 <small>From Processing</small>
 <em><?php echo esc_html($flow_percent($processing_flow_total, $flow_total)); ?>% of tracked</em>
 </div>
 </div>
 <div class="aoam-flow-card aoam-flow-completed">
 <span class="dashicons dashicons-yes-alt"></span>
 <div>
 <strong><?php echo esc_html(number_format_i18n($completed_flow_total)); ?></strong>
 <small>Total Completed</small>
 <em><?php echo esc_html($flow_percent($completed_flow_total, $flow_total)); ?>% of tracked</em>
 </div>
 </div>
 <div class="aoam-flow-card aoam-flow-cancelled">
 <span class="dashicons dashicons-dismiss"></span>
 <div>
 <strong><?php echo esc_html(number_format_i18n($cancelled_flow_total)); ?></strong>
 <small>Total Cancelled</small>
 <em><?php echo esc_html($flow_percent($cancelled_flow_total, $flow_total)); ?>% of tracked</em>
 </div>
 </div>
 <div class="aoam-flow-card aoam-flow-partial">
 <span class="dashicons dashicons-clock"></span>
 <div>
 <strong><?php echo esc_html(number_format_i18n($partial_flow_total)); ?></strong>
 <small>From Partial</small>
 <em><?php echo esc_html($flow_percent($partial_flow_total, $flow_total)); ?>% of tracked</em>
 </div>
 </div>
 </div>
 <div class="aoam-flow-bars">
 <?php
 $flow_progress_rows = array(
 array('label' => 'Processing', 'completed' => $flow_counts['processing']['completed'], 'cancelled' => $flow_counts['processing']['cancelled'], 'total' => $processing_flow_total),
 array('label' => 'Partial', 'completed' => $flow_counts['partial']['completed'], 'cancelled' => $flow_counts['partial']['cancelled'], 'total' => $partial_flow_total),
 );
 foreach ($flow_progress_rows as $flow_row):
 $completed_percent = $flow_percent($flow_row['completed'], $flow_row['total']);
 $cancelled_percent = $flow_percent($flow_row['cancelled'], $flow_row['total']);
 $completed_width = $flow_row['completed'] > 0 ? max(3, $completed_percent) : 0;
 $cancelled_width = $flow_row['cancelled'] > 0 ? max(3, $cancelled_percent) : 0;
 ?>
 <div class="aoam-flow-progress-row">
 <div class="aoam-flow-progress-head">
 <strong><?php echo esc_html($flow_row['label']); ?> Flow</strong>
 <span><?php echo esc_html(number_format_i18n($flow_row['total'])); ?> total</span>
 </div>
 <div class="aoam-flow-progress-meta">
 <span class="aoam-flow-complete-label"><?php echo esc_html($flow_row['label']); ?> to Completed: <strong><?php echo esc_html(number_format_i18n($flow_row['completed'])); ?></strong> <em><?php echo esc_html($completed_percent); ?>%</em></span>
 <span class="aoam-flow-cancel-label"><?php echo esc_html($flow_row['label']); ?> to Cancelled: <strong><?php echo esc_html(number_format_i18n($flow_row['cancelled'])); ?></strong> <em><?php echo esc_html($cancelled_percent); ?>%</em></span>
 </div>
 <div class="aoam-flow-split-track">
 <div class="aoam-flow-split-fill aoam-flow-split-completed" style="width: <?php echo esc_attr($completed_width); ?>%;"></div>
 <div class="aoam-flow-split-fill aoam-flow-split-cancelled" style="width: <?php echo esc_attr($cancelled_width); ?>%;"></div>
 </div>
 </div>
 <?php endforeach; ?>
 </div>
 </div>
 
 <!-- Status Breakdown -->
 <div class="aoam-status-panel">
 <div class="aoam-section-heading aoam-section-heading-small">
 <div>
 <h3>Order Status Breakdown</h3>
 <p>Status counts are calculated from the selected source and user filter.</p>
 </div>
 </div>
 <div class="aoam-status-grid">
 <?php foreach ($status_counts as $status => $count): 
 if ($status === 'all') continue;
 if ($count > 0):
 $status_class = 'status-' . $status;
 $status_label = wc_get_order_status_name($status);
 ?>
 <div class="status-count-badge <?php echo esc_attr($status_class); ?>">
 <span class="status-label"><?php echo esc_html($status_label); ?></span>
 <span class="status-count"><?php echo esc_html($count); ?></span>
 </div>
 <?php endif; endforeach; ?>
 </div>
 </div>
 
 <?php if ($moderator_filter > 0): ?>
 <?php 
 $moderator = get_userdata($moderator_filter);
 $moderator_name = $moderator ? $moderator->display_name : 'Unknown';
 $moderator_sequence = get_user_meta($moderator_filter, 'moderator_sequence', true);
 ?>
 <div class="aoam-active-filter-note">
 <strong>Currently viewing orders for:</strong> 
 <?php echo esc_html($moderator_name); ?>
 <?php if ($moderator_sequence): ?>
 (User <?php echo $moderator_sequence; ?>)
 <?php endif; ?>
 <?php if ($status_filter !== 'all'): ?>
 | Status: <strong><?php echo esc_html(ucfirst($status_filter)); ?></strong>
 <?php endif; ?>
 <?php if ($source_filter !== 'all'): ?>
| Source: <strong><?php echo esc_html($source_filter === 'current' ? 'Current Site' : wp_parse_url($remote_source_options[$source_filter] ?? '', PHP_URL_HOST)); ?></strong>
 <?php endif; ?>
 <?php if ($assignment_date_filter !== 'all'): ?>
 | Date: <strong><?php echo esc_html(ucwords(str_replace('_', ' ', $assignment_date_filter))); ?></strong>
 <?php endif; ?>
 </div>
 <?php endif; ?>
 </div>

 <!-- Filters -->
 <div class="aoam-report-panel aoam-filter-panel">
 <div class="aoam-section-heading">
 <div>
 <h2>Assignment Filters</h2>
 <p>Use these controls to narrow the table without loading every order.</p>
 </div>
 </div>
 <div class="assignment-filters">
 <form method="get" class="aoam-filter-form" action="<?php echo esc_url(admin_url('admin.php')); ?>">
 <input type="hidden" name="page" value="moderator-recent-assignments">
 <div class="filter-group aoam-search-field aoam-filter-row-primary">
 <label for="order_search">Search Orders</label>
 <div class="aoam-search-control">
 <span class="dashicons dashicons-search"></span>
 <input type="search" name="order_search" id="order_search" value="<?php echo esc_attr($order_search); ?>" placeholder="Order ID, customer name, phone">
 <?php if ($order_search !== ''): ?>
 <button type="button" class="aoam-clear-search" aria-label="Clear search">x</button>
 <?php endif; ?>
 </div>
 </div>
 
 <div class="filter-group">
 <label for="moderator_filter">Filter by User</label> 
 <select name="moderator_filter" id="moderator_filter">
 <option value="0">All Users</option>
 <?php foreach ($moderators as $mod): 
 $mod_sequence = get_user_meta($mod->ID, 'moderator_sequence', true);
 $display_name = $mod->display_name . ($mod_sequence ? ' (User ' . $mod_sequence . ')' : ''); 
 ?>
 <option value="<?php echo $mod->ID; ?>" <?php selected($moderator_filter, $mod->ID); ?>>
 <?php echo esc_html($display_name); ?>
 </option>
 <?php endforeach; ?>
 </select>
 </div>

 <div class="filter-group">
 <label for="source_filter">Order Source</label>
 <select name="source_filter" id="source_filter">
 <option value="all" <?php selected($source_filter, 'all'); ?>>All Sources</option>
 <option value="current" <?php selected($source_filter, 'current'); ?>>Current Site</option>
 <?php foreach ($remote_source_options as $source_key => $source_url): ?>
 <option value="<?php echo esc_attr($source_key); ?>" <?php selected($source_filter, $source_key); ?>>
 Remote: <?php echo esc_html(wp_parse_url($source_url, PHP_URL_HOST) ?: $source_url); ?>
 </option>
 <?php endforeach; ?>
 </select>
 </div>

 <div class="filter-group aoam-date-filter-field aoam-filter-row-primary">
 <label for="assignment_date_filter">Date</label>
 <select name="assignment_date_filter" id="assignment_date_filter">
 <option value="all" <?php selected($assignment_date_filter, 'all'); ?>>All Times</option>
 <option value="today" <?php selected($assignment_date_filter, 'today'); ?>>Today</option>
 <option value="yesterday" <?php selected($assignment_date_filter, 'yesterday'); ?>>Yesterday</option>
 <option value="this_month" <?php selected($assignment_date_filter, 'this_month'); ?>>This Month</option>
 <option value="last_month" <?php selected($assignment_date_filter, 'last_month'); ?>>Last Month</option>
 <option value="custom" <?php selected($assignment_date_filter, 'custom'); ?>>Custom</option>
 </select>
 </div>

 <div class="filter-group aoam-custom-date-field aoam-filter-row-primary" style="<?php echo $assignment_date_filter === 'custom' ? '' : 'display:none;'; ?>">
 <label for="custom_start_date">Start Date</label>
 <input type="date" name="custom_start_date" id="custom_start_date" value="<?php echo esc_attr($custom_start_date); ?>">
 </div>

 <div class="filter-group aoam-custom-date-field aoam-filter-row-primary" style="<?php echo $assignment_date_filter === 'custom' ? '' : 'display:none;'; ?>">
 <label for="custom_end_date">End Date</label>
 <input type="date" name="custom_end_date" id="custom_end_date" value="<?php echo esc_attr($custom_end_date); ?>">
 </div>
  
 <div class="filter-group">
 <label for="status_filter">Order Status</label>
 <select name="status_filter" id="status_filter">
 <option value="all" <?php selected($status_filter, 'all'); ?>>All Statuses</option>
 <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
 <option value="partial" <?php selected($status_filter, 'partial'); ?>>Partial</option>
 <option value="processing" <?php selected($status_filter, 'processing'); ?>>Processing</option>
 <option value="on-hold" <?php selected($status_filter, 'on-hold'); ?>>On Hold</option>
 <option value="completed" <?php selected($status_filter, 'completed'); ?>>Completed</option>
 <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>>Cancelled</option>
 <option value="refunded" <?php selected($status_filter, 'refunded'); ?>>Refunded</option>
 <option value="failed" <?php selected($status_filter, 'failed'); ?>>Failed</option>
 </select>
 </div>
 
 <div class="filter-group">
 <label for="per_page">Orders per page</label>
 <select name="per_page" id="per_page">
 <option value="10" <?php selected($per_page, 10); ?>>10</option>
 <option value="20" <?php selected($per_page, 20); ?>>20</option>
 <option value="50" <?php selected($per_page, 50); ?>>50</option>
 <option value="100" <?php selected($per_page, 100); ?>>100</option>
 </select>
 </div>
 </form>
 </div>
 </div>

 <!-- Orders Table -->
 <div class="card">
 <h2>Assigned Orders</h2>
 
 <?php if (!empty($orders)): ?>
 <div class="table-wrapper">
 <table class="wp-list-table widefat fixed striped fixed-table">
 <thead>
 <tr>
 <th style="width: 80px;">Order #</th>
 <th style="width: 120px;">Date</th>
 <th style="width: 150px;">Customer</th>
 <th style="width: 200px;">Products</th>
 <th style="width: 100px;">Total</th>
 <th style="width: 150px;">Assigned User</th>
 <th style="width: 80px;">Sequence</th>
 <th style="width: 100px;">Status</th>
 <th style="width: 120px;">Actions</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($orders as $order): ?>
 <?php
 $moderator_id = get_post_meta($order->get_id(), '_assigned_moderator_id', true);
 $moderator_sequence = get_post_meta($order->get_id(), '_assigned_moderator_sequence', true);
 if ($moderator_id):
 $moderator = get_userdata($moderator_id);
 $moderator_status = get_user_meta($moderator_id, 'moderator_status', true) ?: 'active';
 
 // Get order items
 $items = $order->get_items();
 $product_names = array();
 foreach ($items as $item) {
 $product_names[] = $item->get_name();
 }
 ?>
 <tr>
 <td>
 <strong>#<?php echo esc_html($order->get_id()); ?></strong>
 </td>
 <td>
 <?php echo esc_html(aoam_format_order_local_date($order, 'M j, Y')); ?><br>
 <small style="color: #666;"><?php echo esc_html(aoam_format_order_local_date($order, 'g:i A')); ?></small>
 </td>
 <td>
 <strong><?php echo esc_html($order->get_formatted_billing_full_name()); ?></strong><br>
 <small><?php echo esc_html($order->get_billing_email()); ?></small><br>
 <small style="color: #666;"><?php echo esc_html($order->get_billing_phone()); ?></small>
 </td>
 <td>
 <div class="order-products">
 <?php 
 $display_count = 0;
 foreach ($product_names as $product_name) {
 if ($display_count >= 2) break;
                                            echo '<div class="product-name">- ' . esc_html($product_name) . '</div>';
 $display_count++;
 }
 if (count($product_names) > 2): ?>
 <div class="more-products">
 + <?php echo esc_html(count($product_names) - 2); ?> more products
 </div>
 <?php endif; ?>
 </div>
 </td>
 <td>
 <strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
 </td>
 <td>
 <div class="moderator-info">
 <strong><?php echo esc_html($moderator ? $moderator->display_name : 'Unknown User'); ?></strong>
 <?php if ($moderator_status == 'inactive'): ?>
 <span class="moderator-inactive-badge">(Inactive)</span>
 <?php endif; ?>
 <br>
 <small><?php echo esc_html($moderator ? $moderator->user_email : ''); ?></small>
 </div>
 </td>
 <td>
 <?php if ($moderator_sequence): ?>
 <span class="sequence-badge-small"><?php echo esc_html($moderator_sequence); ?></span>
 <?php else: ?>
 <span style="color:#ccc; font-size: 12px;">N/A</span>
 <?php endif; ?>
 </td>
 <td>
 <?php 
 $order_status = $order->get_status();
 $status_class = 'status-' . $order_status;
 $status_label = wc_get_order_status_name($order_status);
 ?>
 <span class="order-status-badge <?php echo esc_attr($status_class); ?>">
 <?php echo esc_html($status_label); ?>
 </span>
 </td>
 <td>
 <div class="order-actions aoam-action-stack">
 <a href="<?php echo esc_url(get_edit_post_link($order->get_id())); ?>" class="button button-small aoam-action-button aoam-action-edit" target="_blank" title="Edit Order">
 <span class="dashicons dashicons-edit"></span>
 Edit
 </a>
 <button type="button" class="button button-small view-order-details aoam-action-button aoam-action-view" data-order-id="<?php echo esc_attr($order->get_id()); ?>" title="View Details">
 <span class="dashicons dashicons-visibility"></span>
 View
 </button>
 </div>
 </td>
 </tr>
 <?php endif; ?>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>

 
 <!-- Pagination -->
 <?php if ($total_pages > 1): ?>
 <div class="tablenav" style="margin-top: 20px;">
 <div class="tablenav-pages">
 <span class="displaying-num"><?php echo esc_html($total_orders); ?> items</span>
 <span class="pagination-links">
 <?php
 $base_url = add_query_arg(array(
 'page' => 'moderator-recent-assignments',
 'moderator_filter' => $moderator_filter,
 'status_filter' => $status_filter,
 'source_filter' => $source_filter,
 'order_search' => $order_search,
 'assignment_date_filter' => $assignment_date_filter,
 'custom_start_date' => $custom_start_date,
 'custom_end_date' => $custom_end_date,
 'per_page' => $per_page,
 ), admin_url('admin.php'));
 
 // Previous page
 if ($paged > 1) {
 echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $paged - 1, $base_url)) . '"> Previous</a>';
 }
 
 // Page numbers
 $start_page = max(1, $paged - 2);
 $end_page = min($total_pages, $paged + 2);
 
 for ($i = $start_page; $i <= $end_page; $i++) {
 if ($i == $paged) {
 echo '<span class="current-page button">' . esc_html($i) . '</span>';
 } else {
 echo '<a class="page-number button" href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '">' . esc_html($i) . '</a>';
 }
 }
 
 // Next page
 if ($paged < $total_pages) {
 echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $paged + 1, $base_url)) . '">Next </a>';
 }
 ?>
 </span>
 </div>
 </div>
 <?php endif; ?>
 
 <?php else: ?>
 <div style="text-align: center; padding: 40px;">
                    <h3>No orders found</h3>
 <p>No orders match the current filters.</p>
 <?php if ($status_counts['all'] > 0 && $status_filter !== 'all'): ?>
 <p style="color: #666;">
 There are <?php echo esc_html($status_counts['all']); ?> assigned orders, but none with status "<?php echo esc_html($status_filter); ?>".
 </p>
 <?php endif; ?>
 <a href="?page=moderator-recent-assignments" class="button button-primary aoam-reset-filters">Reset Filters</a>
 </div>
 <?php endif; ?>
 </div>

 <?php if (!$ajax_request): ?>
 </div>
 <?php endif; ?>
 
 <!-- Order Details Modal -->
 <div id="order-details-modal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border: 2px solid #0073aa; z-index: 10000; width: 90%; max-width: 800px; max-height: 80vh; overflow-y: auto; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
 <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #ddd;">
 <h3 style="margin: 0;">Order Details - #<span id="modal-order-id"></span></h3>
 <button type="button" class="button" onclick="closeOrderModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;"></button>
 </div>
 <div id="order-details-content"></div>
 </div>
 
 <style>
 .table-wrapper {
 width: 100%;
 overflow-x: auto; 
 position: relative;
 }

 /* Basic table design */
 .fixed-table {
 border-collapse: collapse;
 width: max-content;
 min-width: 100%;
 }

 .fixed-table th,
 .fixed-table td {
 border: 1px solid #ccc;
 padding: 10px;
 white-space: nowrap;
 background: #fff;
 }

 /* --- FIXED FIRST 2 COLUMNS --- */
 .fixed-table th:nth-child(1),
 .fixed-table td:nth-child(1) {
 position: sticky;
 left: 0;
 z-index: 5;
 background: #fff;
 }

 .fixed-table th:nth-child(2),
 .fixed-table td:nth-child(2) {
 position: sticky;
 left: 120px; /* 1st column width */
 z-index: 5;
 background: #fff;
 }

 /* --- FIXED LAST COLUMN --- */
 .fixed-table th:last-child,
 .fixed-table td:last-child {
 position: sticky;
 right: 0;
 z-index: 5;
 background: #fff;
 }
 .card { 
 margin: 20px 0; 
 padding: 20px; 
 background: #fff; 
 border: 1px solid #ccd0d4; 
 border-radius: 8px;
 box-shadow: 0 2px 4px rgba(0,0,0,0.1);
 max-width: 100%;
 }
 .aoam-report-panel {
 background: #fff;
 border: 1px solid #dcdcde;
 border-radius: 8px;
 box-shadow: 0 1px 2px rgba(0,0,0,0.04);
 margin: 20px 0;
 padding: 22px;
 }
 .aoam-section-heading {
 display: flex;
 justify-content: space-between;
 align-items: flex-start;
 gap: 16px;
 margin-bottom: 18px;
 }
 .aoam-section-heading h2,
 .aoam-section-heading h3 {
 margin: 0;
 color: #1d2327;
 }
 .aoam-section-heading p {
 margin: 5px 0 0;
 color: #646970;
 }
 .aoam-section-heading-small {
 margin-bottom: 12px;
 }
 .aoam-soft-button {
 border-color: #c3d9ff !important;
 color: #0073aa !important;
 background: #f0f6ff !important;
 }
 .aoam-stats-grid {
 display: grid;
 grid-template-columns: repeat(5, minmax(150px, 1fr));
 gap: 14px;
 }
 .aoam-stat-tile {
 display: flex;
 align-items: center;
 gap: 13px;
 min-height: 78px;
 padding: 16px;
 border: 1px solid #e6eaf0;
 border-radius: 8px;
 background: #f8fafc;
 }
 .aoam-stat-tile .dashicons {
 width: 38px;
 height: 38px;
 border-radius: 8px;
 display: inline-flex;
 align-items: center;
 justify-content: center;
 font-size: 20px;
 }
 .aoam-stat-tile strong {
 display: block;
 font-size: 25px;
 line-height: 1.1;
 color: #1d2327;
 }
 .aoam-stat-tile small {
 display: block;
 margin-top: 3px;
 color: #646970;
 font-weight: 600;
 }
 .aoam-stat-total .dashicons { background: #e7f3ff; color: #0073aa; }
 .aoam-stat-today .dashicons { background: #e5f7e5; color: #1f8f3a; }
 .aoam-stat-showing .dashicons { background: #f0f6ff; color: #3858e9; }
 .aoam-stat-pages .dashicons { background: #fff8e5; color: #996800; }
 .aoam-stat-users .dashicons { background: #ffecec; color: #cc1818; }
 .aoam-status-panel {
 margin-top: 22px;
 padding-top: 18px;
 border-top: 1px solid #edf0f2;
 }
 .aoam-flow-panel {
 margin-top: 22px;
 padding: 18px;
 border: 1px solid #d8e7ff;
 border-left: 4px solid #2271b1;
 border-radius: 8px;
 background: linear-gradient(180deg, #f8fbff 0%, #fff 100%);
 }
 .aoam-flow-total {
 text-align: right;
 color: #50575e;
 }
 .aoam-flow-total span {
 display: block;
 color: #1d2327;
 font-size: 24px;
 font-weight: 800;
 line-height: 1;
 }
 .aoam-flow-total small {
 display: block;
 margin-top: 4px;
 font-weight: 600;
 }
 .aoam-flow-grid {
 display: grid;
 grid-template-columns: repeat(4, minmax(160px, 1fr));
 gap: 12px;
 margin-bottom: 18px;
 }
 .aoam-flow-card {
 display: flex;
 align-items: center;
 gap: 12px;
 min-height: 86px;
 padding: 16px;
 border: 1px solid #e1e8f0;
 border-radius: 8px;
 background: #fff;
 }
 .aoam-flow-card .dashicons {
 width: 36px;
 height: 36px;
 border-radius: 10px;
 display: inline-flex;
 align-items: center;
 justify-content: center;
 font-size: 19px;
 }
 .aoam-flow-card strong {
 display: block;
 font-size: 28px;
 line-height: 1;
 color: #1d2327;
 }
 .aoam-flow-card small {
 display: block;
 margin-top: 5px;
 color: #646970;
 font-weight: 700;
 }
 .aoam-flow-card em {
 display: block;
 margin-top: 5px;
 color: #2271b1;
 font-size: 12px;
 font-style: normal;
 font-weight: 700;
 }
 .aoam-flow-processing .dashicons { background: #e7f3ff; color: #2271b1; }
 .aoam-flow-partial .dashicons { background: #f0f0f1; color: #50575e; }
 .aoam-flow-completed .dashicons { background: #e5f7e5; color: #1f8f3a; }
 .aoam-flow-cancelled .dashicons { background: #ffecec; color: #cc1818; }
 .aoam-flow-bars {
 display: grid;
 grid-template-columns: 1fr;
 gap: 18px;
 }
 .aoam-flow-progress-row {
 padding: 14px;
 border: 1px solid #e1e8f0;
 border-radius: 8px;
 background: #fff;
 }
 .aoam-flow-progress-head,
 .aoam-flow-progress-meta {
 display: flex;
 justify-content: space-between;
 align-items: center;
 gap: 10px;
 }
 .aoam-flow-progress-head {
 margin-bottom: 10px;
 }
 .aoam-flow-progress-head strong {
 color: #1d2327;
 font-size: 14px;
 }
 .aoam-flow-progress-head span {
 color: #50575e;
 font-weight: 700;
 }
 .aoam-flow-progress-meta {
 margin-bottom: 8px;
 color: #50575e;
 font-weight: 700;
 }
 .aoam-flow-progress-meta span {
 display: inline-flex;
 align-items: center;
 gap: 6px;
 }
 .aoam-flow-progress-meta strong {
 color: #1d2327;
 font-size: 15px;
 }
 .aoam-flow-progress-meta em {
 color: #646970;
 font-size: 12px;
 font-style: normal;
 font-weight: 700;
 }
 .aoam-flow-complete-label::before,
 .aoam-flow-cancel-label::before {
 content: "";
 display: inline-block;
 width: 9px;
 height: 9px;
 border-radius: 999px;
 }
 .aoam-flow-complete-label::before { background: #22c55e; }
 .aoam-flow-cancel-label::before { background: #ef4444; }
 .aoam-flow-split-track {
 display: flex;
 height: 14px;
 border-radius: 999px;
 background: #edf0f2;
 overflow: hidden;
 }
 .aoam-flow-split-fill {
 height: 100%;
 transition: width 180ms ease;
 }
 .aoam-flow-split-completed { background: #22c55e; }
 .aoam-flow-split-cancelled { background: #ef4444; margin-left: auto; }
 .aoam-status-grid {
 display: flex;
 flex-wrap: wrap;
 gap: 10px;
 }
 .aoam-active-filter-note {
 margin-top: 16px;
 padding: 12px 14px;
 border: 1px solid #c3d9ff;
 border-left: 4px solid #2271b1;
 background: #f0f6ff;
 border-radius: 6px;
 color: #1d2327;
 }
 .aoam-filter-panel {
 padding-bottom: 20px;
 }
 .assignment-filters {
 padding: 16px;
 background: #f6f7f7;
 border: 1px solid #edf0f2;
 border-radius: 8px;
 }
 .aoam-filter-form {
 display: grid;
 grid-template-columns: 1.35fr 1fr 1fr 1fr;
 gap: 14px;
 align-items: end;
 }
 .aoam-filter-form .filter-group {
 width: auto !important;
 display: flex;
 flex-direction: column;
 gap: 7px;
 }
 .aoam-filter-form .filter-group label {
 color: #1d2327;
 font-weight: 700;
 }
 .aoam-filter-form .filter-group select {
 width: 100%;
 max-width: none;
 min-height: 36px;
 border-color: #c3c4c7;
 border-radius: 6px;
 }
 .aoam-filter-form .filter-group input[type="search"] {
 width: 100%;
 max-width: none;
 min-height: 36px;
 border: 1px solid #c3c4c7;
 border-radius: 6px;
 padding: 0 10px;
 }
 .aoam-filter-form .filter-group input[type="date"] {
 width: 100%;
 max-width: none;
 min-height: 36px;
 border: 1px solid #c3c4c7;
 border-radius: 6px;
 padding: 0 10px;
 }
 .aoam-search-field {
 grid-column: span 1;
 }
 .aoam-filter-row-primary {
 grid-row: 1;
 }
 .aoam-date-filter-field,
 .aoam-custom-date-field {
 grid-column: span 1;
 }
 .aoam-search-control {
 display: flex;
 align-items: center;
 background: #fff;
 border: 1px solid #c3c4c7;
 border-radius: 6px;
 gap: 8px;
 min-height: 36px;
 padding: 0 8px;
 }
 .aoam-search-control .dashicons {
 color: #646970;
 font-size: 18px;
 height: 18px;
 width: 18px;
 }
 .aoam-search-control input {
 flex: 1 1 auto;
 border: 0 !important;
 box-shadow: none !important;
 min-height: 34px !important;
 padding: 0 !important;
 }
 .aoam-clear-search {
 align-items: center;
 background: #f0f0f1;
 border: 0;
 border-radius: 50%;
 color: #50575e;
 cursor: pointer;
 display: inline-flex;
 font-size: 14px;
 height: 22px;
 justify-content: center;
 line-height: 1;
 padding: 0;
 width: 22px;
 }
 .aoam-clear-search:hover {
 background: #dcdcde;
 color: #1d2327;
 }
 .aoam-search-clear .button {
 min-height: 36px;
 display: inline-flex;
 align-items: center;
 justify-content: center;
 }
 span.current-page.button {
 background-color: #ddd;
 }
 .filter-group {
 width: 23% !important;
 }
 .nav-tab-wrapper { margin: 20px 0; }
 .nav-tab { 
 text-decoration: none; 
 padding: 10px 15px; 
 margin: 0 5px 0 0; 
 border: 1px solid #ccd0d4;
 border-bottom: none;
 background: #f0f0f0;
 }
 .nav-tab-active { 
 background: #0073aa; 
 color: white; 
 border-bottom: 1px solid #0073aa;
 }
 .order-status-badge { 
 padding: 6px 12px; 
 border-radius: 4px; 
 font-size: 12px; 
 font-weight: bold;
 display: inline-block;
 text-align: center;
 min-width: 80px;
 }
 .status-partial { background: #777; color: #fff; }
 .status-pending { background: #ffb900; color: #000; }
 .status-processing { background: #00a0d2; color: #fff; }
 .status-on-hold { background: #cc1818; color: #fff; }
 .status-completed { background: #46b450; color: #fff; }
 .status-cancelled { background: #aaa; color: #fff; }
 .status-refunded { background: #aaa; color: #fff; }
 .status-failed { background: #cc1818; color: #fff; }
 .sequence-badge-small {
 display: inline-block;
 width: 25px;
 height: 25px;
 background: #0073aa;
 color: white;
 text-align: center;
 line-height: 25px;
 border-radius: 50%;
 font-weight: bold;
 font-size: 12px;
 }
 .moderator-inactive-badge {
 font-size: 10px;
 color: #cc1818;
 font-weight: bold;
 }
 .order-products {
 max-height: 80px;
 overflow-y: auto;
 }
 .product-name {
 font-size: 12px;
 margin-bottom: 3px;
 line-height: 1.3;
 }
 .more-products {
 font-size: 11px;
 color: #666;
 font-style: italic;
 }
 .order-actions {
 display: flex;
 flex-direction: column;
 gap: 6px;
 min-width: 120px;
 }
 .aoam-action-stack {
 align-items: stretch;
 }
 .aoam-action-button {
 align-items: center !important;
 background: #f8fbff !important;
 border: 1px solid #c8d8ea !important;
 border-radius: 7px !important;
 box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04) !important;
 color: #1f5f99 !important;
 display: inline-flex !important;
 gap: 6px !important;
 justify-content: center !important;
 min-height: 32px !important;
 padding: 5px 10px !important;
 text-decoration: none !important;
 transition: background 140ms ease, border-color 140ms ease, box-shadow 140ms ease;
 width: 100%;
 }
 .aoam-action-button:hover,
 .aoam-action-button:focus {
 background: #eef6ff !important;
 border-color: #2271b1 !important;
 box-shadow: 0 0 0 1px rgba(34, 113, 177, 0.12) !important;
 color: #0b4f82 !important;
 }
 .aoam-action-button .dashicons {
 font-size: 16px;
 height: 16px;
 line-height: 16px;
 width: 16px;
 }
 .aoam-action-edit {
 color: #2557c7 !important;
 }
 .aoam-action-view {
 color: #0f6e9f !important;
 }
 .button-small {
 padding: 4px 8px;
 font-size: 12px;
 line-height: 1.5;
 text-align: center;
 }
 .status-count-badge {
 display: flex;
 align-items: center;
 gap: 8px;
 padding: 8px 10px 8px 12px;
 border-radius: 999px;
 font-size: 12px;
 font-weight: bold;
 border: 1px solid transparent;
 }
 .status-count-badge.status-pending { background: #fff8e5; color: #8a6d3b; }
 .status-count-badge.status-partial { background: #777; color: #fff; }
 .status-count-badge.status-processing { background: #e7f3ff; color: #0073aa; }
 .status-count-badge.status-on-hold { background: #ffe5e5; color: #cc1818; }
 .status-count-badge.status-completed { background: #e5f7e5; color: #46b450; }
 .status-count-badge.status-cancelled { background: #f0f0f0; color: #666; }
 .status-count-badge.status-refunded { background: #f0f0f0; color: #666; }
 .status-count-badge.status-failed { background: #ffe5e5; color: #cc1818; }
 .status-count {
 background: white;
 padding: 2px 8px;
 border-radius: 10px;
 font-weight: bold;
 color: #777;
 }
 @media (max-width: 1200px) {
 .aoam-stats-grid,
 .aoam-flow-grid,
 .aoam-flow-bars,
 .aoam-filter-form {
 grid-template-columns: repeat(2, minmax(180px, 1fr));
 }
 }
 @media (max-width: 782px) {
 .aoam-report-panel {
 padding: 16px;
 }
 .aoam-section-heading {
 flex-direction: column;
 }
 .aoam-stats-grid,
 .aoam-flow-grid,
 .aoam-flow-bars,
 .aoam-filter-form {
 grid-template-columns: 1fr;
 }
 .aoam-flow-total {
 text-align: left;
 }
 .aoam-flow-progress-head,
 .aoam-flow-progress-meta {
 align-items: flex-start;
 flex-direction: column;
 }
 }
 .modal-backdrop {
 position: fixed;
 top: 0;
 left: 0;
 right: 0;
 bottom: 0;
 background: rgba(0,0,0,0.5);
 z-index: 9999;
 }
 #order-details-modal[style*="display: none"] ~ .modal-backdrop,
 body:not(.aoam-modal-open) .modal-backdrop {
 display: none !important;
 }
 </style>
 
 <script>
 jQuery(document).ready(function($) {
 // View order details
 $('.view-order-details').click(function() {
 var orderId = $(this).data('order-id');
 viewOrderDetails(orderId);
 });
 
 // Close modal with ESC key
 $(document).keyup(function(e) {
 if (e.keyCode === 27) {
 closeOrderModal();
 }
 });
 });
 
 function viewOrderDetails(orderId) {
 // Loading message.
 jQuery('.modal-backdrop').remove();
 document.getElementById("modal-order-id").textContent = orderId;
 document.getElementById("order-details-content").innerHTML = "<div style='text-align: center; padding: 40px;'><div class='spinner is-active' style='float: none;'></div><p>Loading order details...</p></div>";
 
 // Show modal and backdrop
 document.body.appendChild(document.createElement("div")).className = "modal-backdrop";
 document.getElementById("order-details-modal").style.display = "block";
 document.body.classList.add("aoam-modal-open");
 
 // Load details via AJAX
 jQuery.ajax({
 url: "<?php echo admin_url('admin-ajax.php'); ?>",
 type: "POST",
 data: {
 action: "aoam_get_moderator_order_details",
 order_id: orderId,
 nonce: "<?php echo wp_create_nonce('moderator_order_details'); ?>"
 },
 success: function(response) {
 if (response.success) {
 jQuery("#order-details-content").html(response.data);
 } else {
 jQuery("#order-details-content").html("<div style='text-align: center; padding: 40px; color: #cc1818;'><p>Error loading order details.</p></div>");
 }
 },
 error: function() {
 jQuery("#order-details-content").html("<div style='text-align: center; padding: 40px; color: #cc1818;'><p>Error loading order details.</p></div>");
 }
 });
 }
 
 function closeOrderModal() {
 var modal = document.getElementById("order-details-modal");
 if (modal) {
 modal.style.display = "none";
 }
 var backdrops = document.getElementsByClassName("modal-backdrop");
 while (backdrops.length > 0) {
 backdrops[0].remove();
 }
 document.body.classList.remove("aoam-modal-open");
 }
 
 // Close modal when clicking backdrop
 document.addEventListener("click", function(e) {
 if (e.target.classList.contains("modal-backdrop")) {
 closeOrderModal();
 }
 });
 
 </script>
 <?php
}


// Filter orders for users - they only see their own orders
add_action('pre_get_posts', 'filter_orders_for_moderators_by_assignment');

function filter_orders_for_moderators_by_assignment($query) {
 if (!is_admin() || !$query->is_main_query()) return;
 
 $current_user = wp_get_current_user();
 
 // Check if user has any assigned role
 $assigned_roles = aoam_get_assigned_roles();
 $user_has_role = !empty(array_intersect($current_user->roles, $assigned_roles));
 
 if ($user_has_role && 
 isset($query->query['post_type']) && 
 $query->query['post_type'] === 'shop_order') {
 
 $meta_query = array(
 array(
 'key' => '_assigned_moderator_id',
 'value' => $current_user->ID,
 'compare' => '='
 )
 );
 
 $query->set('meta_query', $meta_query);
 }
}

// Add an assigned moderator filter to the WooCommerce Orders list.
add_action('woocommerce_order_list_table_restrict_manage_orders', 'aoam_render_hpos_order_moderator_filter', 20, 2);
add_action('restrict_manage_posts', 'aoam_render_cpt_order_moderator_filter', 20, 2);
add_action('pre_get_posts', 'aoam_apply_cpt_order_moderator_filter', 20);
add_filter('woocommerce_shop_order_list_table_prepare_items_query_args', 'aoam_apply_hpos_order_moderator_filter');
add_filter('woocommerce_shop_order_list_table_order_count', 'aoam_filter_hpos_order_status_count_by_moderator', 20, 2);
add_filter('woocommerce_before_shop_order_list_table_view_links', 'aoam_render_hpos_moderator_filtered_status_views');
add_filter('views_edit-shop_order', 'aoam_render_cpt_moderator_filtered_status_views');

function aoam_get_order_moderator_filter_value() {
 if (!isset($_GET['aoam_moderator_filter'])) {
 return '';
 }

 $value = sanitize_text_field(wp_unslash($_GET['aoam_moderator_filter']));

 if ($value === 'unassigned') {
 return 'unassigned';
 }

 $user_id = absint($value);
 return $user_id > 0 ? (string) $user_id : '';
}

function aoam_render_hpos_order_moderator_filter($order_type, $which = 'top') {
 if ($order_type !== 'shop_order' || $which !== 'top') {
 return;
 }

 aoam_render_order_moderator_filter_select();
}

function aoam_render_cpt_order_moderator_filter($post_type, $which = 'top') {
 if ($post_type !== 'shop_order' || $which !== 'top') {
 return;
 }

 aoam_render_order_moderator_filter_select();
}

function aoam_render_order_moderator_filter_select() {
 $assigned_roles = aoam_get_assigned_roles();

 if (empty($assigned_roles)) {
 return;
 }

 $moderators = get_users(array(
 'role__in' => $assigned_roles,
 'orderby' => 'display_name',
 'order' => 'ASC',
 'fields' => array('ID', 'display_name', 'user_email'),
 ));

 $selected = aoam_get_order_moderator_filter_value();

 echo '<select name="aoam_moderator_filter" id="aoam_moderator_filter" style="min-width: 210px;">';
 echo '<option value="">' . esc_html__('All assigned moderators', 'digitrix-orderflow-for-woocommerce') . '</option>';
 echo '<option value="unassigned"' . selected($selected, 'unassigned', false) . '>' . esc_html__('No Assign', 'digitrix-orderflow-for-woocommerce') . '</option>';

 foreach ($moderators as $moderator) {
 $label = $moderator->display_name;
 if (!empty($moderator->user_email)) {
 $label .= ' (' . $moderator->user_email . ')';
 }

 echo '<option value="' . esc_attr($moderator->ID) . '"' . selected($selected, (string) $moderator->ID, false) . '>' . esc_html($label) . '</option>';
 }

 echo '</select>';
}

function aoam_get_order_moderator_filter_meta_query($selected = null) {
 if ($selected === null) {
 $selected = aoam_get_order_moderator_filter_value();
 }

 if ($selected === '') {
 return array();
 }

 if ($selected === 'unassigned') {
 return array(
 'relation' => 'OR',
 array(
 'key' => '_assigned_moderator_id',
 'compare' => 'NOT EXISTS',
 ),
 array(
 'key' => '_assigned_moderator_id',
 'value' => '',
 'compare' => '=',
 ),
 array(
 'key' => '_assigned_moderator_id',
 'value' => '0',
 'compare' => '=',
 ),
 );
 }

 return array(
 'key' => '_assigned_moderator_id',
 'value' => absint($selected),
 'compare' => '=',
 'type' => 'NUMERIC',
 );
}

function aoam_append_order_meta_query($existing_meta_query, $new_clause) {
 if (empty($new_clause)) {
 return $existing_meta_query;
 }

 if (empty($existing_meta_query) || !is_array($existing_meta_query)) {
 return array($new_clause);
 }

 if (isset($existing_meta_query['key'])) {
 return array(
 'relation' => 'AND',
 $existing_meta_query,
 $new_clause,
 );
 }

 $existing_meta_query[] = $new_clause;

 if (!isset($existing_meta_query['relation'])) {
 $existing_meta_query['relation'] = 'AND';
 }

 return $existing_meta_query;
}

function aoam_apply_cpt_order_moderator_filter($query) {
 if (!is_admin() || !$query->is_main_query()) {
 return;
 }

 $post_type = $query->get('post_type');
 if ($post_type !== 'shop_order') {
 return;
 }

 $filter_clause = aoam_get_order_moderator_filter_meta_query();
 if (empty($filter_clause)) {
 return;
 }

 $query->set('meta_query', aoam_append_order_meta_query($query->get('meta_query'), $filter_clause));
}

function aoam_apply_hpos_order_moderator_filter($query_args) {
 $selected = aoam_get_order_moderator_filter_value();
 if ($selected === '') {
 return $query_args;
 }

 $order_ids = aoam_get_order_ids_for_moderator_filter($selected);

 if (isset($query_args['id']) && is_array($query_args['id'])) {
 $order_ids = array_values(array_intersect(array_map('absint', $query_args['id']), $order_ids));
 }

 $query_args['id'] = !empty($order_ids) ? $order_ids : array(0);

 return $query_args;
}

function aoam_get_order_ids_for_moderator_filter($selected) {
 global $wpdb;

 $postmeta_table = $wpdb->postmeta;
 $posts_table = $wpdb->posts;
 $hpos_orders_table = $wpdb->prefix . 'wc_orders';
 $hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
 $has_hpos_tables = aoam_database_table_exists($hpos_orders_table) && aoam_database_table_exists($hpos_meta_table);

 if ($selected === 'unassigned') {
 $legacy_ids = $wpdb->get_col(
 "SELECT p.ID
 FROM {$posts_table} p
 WHERE p.post_type = 'shop_order'
 AND NOT EXISTS (
 SELECT 1
 FROM {$postmeta_table} pm
 WHERE pm.post_id = p.ID
 AND pm.meta_key = '_assigned_moderator_id'
 AND pm.meta_value NOT IN ('', '0')
 )"
 );

 $hpos_ids = array();
 if ($has_hpos_tables) {
 $hpos_ids = $wpdb->get_col(
 "SELECT o.id
 FROM {$hpos_orders_table} o
 WHERE o.type = 'shop_order'
 AND NOT EXISTS (
 SELECT 1
 FROM {$hpos_meta_table} om
 WHERE om.order_id = o.id
 AND om.meta_key = '_assigned_moderator_id'
 AND om.meta_value NOT IN ('', '0')
 )
 AND NOT EXISTS (
 SELECT 1
 FROM {$postmeta_table} pm
 WHERE pm.post_id = o.id
 AND pm.meta_key = '_assigned_moderator_id'
 AND pm.meta_value NOT IN ('', '0')
 )"
 );
 }

 return aoam_unique_absint_ids(array_merge($legacy_ids, $hpos_ids));
 }

 $moderator_id = absint($selected);
 if ($moderator_id < 1) {
 return array();
 }

 $legacy_ids = $wpdb->get_col(
 $wpdb->prepare(
 "SELECT DISTINCT post_id
 FROM {$postmeta_table}
 WHERE meta_key = '_assigned_moderator_id'
 AND meta_value = %d",
 $moderator_id
 )
 );

 $hpos_ids = array();
 if ($has_hpos_tables) {
 $hpos_ids = $wpdb->get_col(
 $wpdb->prepare(
 "SELECT DISTINCT order_id
 FROM {$hpos_meta_table}
 WHERE meta_key = '_assigned_moderator_id'
 AND meta_value = %d",
 $moderator_id
 )
 );
 }

 return aoam_unique_absint_ids(array_merge($legacy_ids, $hpos_ids));
}

function aoam_unique_absint_ids($ids) {
 $ids = array_map('absint', is_array($ids) ? $ids : array());
 $ids = array_filter($ids);
 return array_values(array_unique($ids));
}

function aoam_database_table_exists($table_name) {
 global $wpdb;

 return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
}

function aoam_filter_hpos_order_status_count_by_moderator($count, $statuses) {
 $selected = aoam_get_order_moderator_filter_value();
 if ($selected === '') {
 return $count;
 }

 $counts = aoam_get_moderator_filtered_order_status_counts($selected);
 $total = 0;

 foreach ((array) $statuses as $status) {
 $total += isset($counts[$status]) ? absint($counts[$status]) : 0;
 }

 return $total;
}

function aoam_get_moderator_filtered_order_status_counts($selected) {
 static $cache = array();

 if (isset($cache[$selected])) {
 return $cache[$selected];
 }

 global $wpdb;

 $order_ids = aoam_get_order_ids_for_moderator_filter($selected);
 $status_ids = array();

 if (!empty($order_ids)) {
 $id_placeholders = implode(',', array_fill(0, count($order_ids), '%d'));

 $legacy_rows = $wpdb->get_results(
 $wpdb->prepare(
 "SELECT ID, post_status
 FROM {$wpdb->posts}
 WHERE post_type = 'shop_order'
 AND ID IN ({$id_placeholders})",
 $order_ids
 )
 );

 foreach ($legacy_rows as $row) {
 $status_ids[$row->post_status][absint($row->ID)] = true;
 }

 $hpos_orders_table = $wpdb->prefix . 'wc_orders';
 if (aoam_database_table_exists($hpos_orders_table)) {
 $hpos_rows = $wpdb->get_results(
 $wpdb->prepare(
 "SELECT id, status
 FROM {$hpos_orders_table}
 WHERE type = 'shop_order'
 AND id IN ({$id_placeholders})",
 $order_ids
 )
 );

 foreach ($hpos_rows as $row) {
 $status_ids[$row->status][absint($row->id)] = true;
 }
 }
 }

 $counts = array();
 foreach ($status_ids as $status => $ids) {
 $counts[$status] = count($ids);
 }

 $cache[$selected] = $counts;
 return $counts;
}

function aoam_render_hpos_moderator_filtered_status_views($views) {
 $selected = aoam_get_order_moderator_filter_value();
 if ($selected === '') {
 return $views;
 }

 $base_url = admin_url('admin.php?page=wc-orders');
 return aoam_build_moderator_filtered_order_status_views($selected, $base_url, 'status');
}

function aoam_render_cpt_moderator_filtered_status_views($views) {
 $selected = aoam_get_order_moderator_filter_value();
 if ($selected === '') {
 return $views;
 }

 $base_url = admin_url('edit.php?post_type=shop_order');
 return aoam_build_moderator_filtered_order_status_views($selected, $base_url, 'post_status');
}

function aoam_build_moderator_filtered_order_status_views($selected, $base_url, $status_arg) {
 $view_links = array();
 $counts = aoam_get_moderator_filtered_order_status_counts($selected);
 $statuses = aoam_get_visible_order_statuses_for_filter();
 $current = isset($_GET[$status_arg]) ? sanitize_key(wp_unslash($_GET[$status_arg])) : 'all';
 $all_count = 0;

 foreach ($statuses as $slug => $label) {
 $status_object = get_post_status_object($slug);
 if ($status_object && $status_object->show_in_admin_all_list && $slug !== 'auto-draft') {
 $all_count += isset($counts[$slug]) ? absint($counts[$slug]) : 0;
 }
 }

 $view_links['all'] = aoam_build_order_status_view_link($base_url, $status_arg, 'all', __('All', 'digitrix-orderflow-for-woocommerce'), $all_count, $current === '' || $current === 'all');

 foreach ($statuses as $slug => $label) {
 $count = isset($counts[$slug]) ? absint($counts[$slug]) : 0;
 if ($count < 1) {
 continue;
 }

 $view_links[$slug] = aoam_build_order_status_view_link($base_url, $status_arg, $slug, $label, $count, $current === $slug);
 }

 return $view_links;
}

function aoam_get_visible_order_statuses_for_filter() {
 $extra_statuses = array();

 foreach (array('trash', 'draft', 'auto-draft') as $status) {
 $status_object = get_post_status_object($status);
 if ($status_object) {
 $extra_statuses[$status] = $status_object->label;
 }
 }

 return array_intersect_key(
 array_merge(wc_get_order_statuses(), $extra_statuses),
 array_flip(get_post_stati(array('show_in_admin_status_list' => true)))
 );
}

function aoam_build_order_status_view_link($base_url, $status_arg, $slug, $label, $count, $current) {
 $args = array(
 'aoam_moderator_filter' => aoam_get_order_moderator_filter_value(),
 );

 if ($slug !== 'all') {
 $args[$status_arg] = $slug;
 }

 $url = esc_url(add_query_arg($args, $base_url));
 $label = esc_html($label);
 $count = number_format_i18n($count);
 $class = $current ? ' class="current"' : '';

 return "<a href='{$url}'{$class}>{$label} <span class='count'>({$count})</span></a>";
}

// Display moderator sequence and status in orders list
add_filter('manage_edit-shop_order_columns', 'add_moderator_sequence_column');

function add_moderator_sequence_column($columns) {
 $new_columns = array();
 
 foreach ($columns as $key => $column) {
 $new_columns[$key] = $column;
 if ($key === 'order_status') {
 $new_columns['assigned_moderator_seq'] = 'Moderator #';
 $new_columns['moderator_status'] = 'Mod Status';
 }
 }
 
 return $new_columns;
}

add_action('manage_shop_order_posts_custom_column', 'show_moderator_sequence_in_column', 10, 2);

function show_moderator_sequence_in_column($column, $post_id) {
 if ($column === 'assigned_moderator_seq') {
 $sequence = get_post_meta($post_id, '_assigned_moderator_sequence', true);
 if ($sequence) {
 echo '<strong>Moderator ' . esc_html($sequence) . '</strong>';
 } else {
 echo '<span style="color:#ccc;">Not assigned</span>';
 }
 }
 
 if ($column === 'moderator_status') {
 $moderator_id = get_post_meta($post_id, '_assigned_moderator_id', true);
 if ($moderator_id) {
 $status = get_user_meta($moderator_id, 'moderator_status', true) ?: 'active';
 if ($status === 'active') {
 echo '<span style="color:green; font-weight:bold;"> Active</span>';
 } else {
 echo '<span style="color:red; font-weight:bold;"> Inactive</span>';
 }
 } else {
 echo '<span style="color:#ccc;">N/A</span>';
 }
 }
}

// Initialize default status for existing moderators
add_action('admin_init', 'initialize_moderator_status');

function initialize_moderator_status() {
 // Get assigned roles
 $assigned_roles = aoam_get_assigned_roles();
 
 // Get users with assigned roles
 $users = get_users(array('role__in' => $assigned_roles));
 
 foreach ($users as $user) {
 $current_status = get_user_meta($user->ID, 'moderator_status', true);
 if (empty($current_status)) {
 update_user_meta($user->ID, 'moderator_status', 'active');
 }
 }
}



// Moderator orders page.
add_action('admin_menu', 'aoam_moderator_orders_menu');

function aoam_moderator_orders_menu() {
 $current_user = wp_get_current_user();
 
 // Check if user has any assigned role
 $assigned_roles = aoam_get_assigned_roles();
 $user_has_role = !empty(array_intersect($current_user->roles, $assigned_roles));
 
 if ($user_has_role) {
 add_menu_page(
 'My Orders',
 'My Orders', 
 'read',
 'digitrix-orderflow-my-orders',
 'aoam_moderator_orders_page',
 'dashicons-clipboard',
 25
 );
 }
}

// Fix admin bar for moderators
function fix_moderator_admin_bar($wp_admin_bar) {
 $current_user = wp_get_current_user();
 $assigned_roles = aoam_get_assigned_roles();
 $user_has_role = !empty(array_intersect($current_user->roles, $assigned_roles));
 
 if ($user_has_role && !current_user_can('manage_options')) {
 // Ensure moderators can see the admin bar
 if (!current_user_can('edit_posts')) {
 show_admin_bar(true);
 }
 
 // Add our custom "My Orders" link
 $wp_admin_bar->add_node(array(
 'id' => 'moderator-orders',
            'title' => 'My Orders',
 'href' => admin_url('admin.php?page=digitrix-orderflow-my-orders'),
 'parent' => 'site-name'
 ));
 }
}
add_action('admin_bar_menu', 'fix_moderator_admin_bar', 1000);

// Hide WordPress admin bar for assigned order-management users.
add_filter('show_admin_bar', 'aoam_hide_admin_bar_for_assigned_roles', 99);
function aoam_hide_admin_bar_for_assigned_roles($show) {
 $current_user = wp_get_current_user();
 $assigned_roles = aoam_get_assigned_roles();
 $user_has_role = !empty(array_intersect((array) $current_user->roles, $assigned_roles));
 
 if ($user_has_role && !current_user_can('manage_options')) {
 return false;
 }
 
 return $show;
}

add_action('admin_head', 'aoam_hide_wp_admin_toolbar_on_moderator_orders');
function aoam_hide_wp_admin_toolbar_on_moderator_orders() {
 if (($_GET['page'] ?? '') !== 'digitrix-orderflow-my-orders') {
 return;
 }

 $current_user = wp_get_current_user();
 $assigned_roles = aoam_get_assigned_roles();
 $user_has_role = !empty(array_intersect((array) $current_user->roles, $assigned_roles));

 if (!$user_has_role || current_user_can('manage_options')) {
 return;
 }
 ?>
 <style>
 #wpadminbar {
 display: none !important;
 }
 html.wp-toolbar {
 padding-top: 0 !important;
 }
 body.admin-bar #wpwrap,
 body.admin-bar #wpcontent {
 padding-top: 0 !important;
 }
 #wpcontent,
 #wpbody,
 #wpbody-content {
 padding-top: 0 !important;
 }
 #wpcontent {
 margin-top: 0 !important;
 }
 #wpbody-content > .wrap:first-child,
 .wrap:first-child {
 margin-top: 10px !important;
 }
 @media (max-width: 782px) {
 html.wp-toolbar {
 padding-top: 0 !important;
 }
 #wpwrap {
 margin-top: 0 !important;
 }
 #wpcontent {
 padding-left: 8px !important;
 padding-right: 8px !important;
 box-sizing: border-box;
 }
 #wpbody-content > .wrap:first-child,
 .wrap:first-child {
 margin-left: 0 !important;
 margin-right: 0 !important;
 }
 #wpbody-content {
 padding-bottom: 0 !important;
 }
 #wpbody-content > .wrap:first-child,
 .wrap:first-child {
 margin-top: 6px !important;
 }
 }
 </style>
 <?php
}

function aoam_moderator_orders_page() {
 ?>
 <div class="wrap">
 <div id="aoam-moderator-orders-app" class="aoam-moderator-ajax-shell">
 <div class="aoam-moderator-loading"><span class="spinner is-active"></span><p>Loading orders...</p></div>
 </div>
 </div>
 <script>
 jQuery(function($) {
 var $app = $('#aoam-moderator-orders-app');
 var request = null;

 function collectParamsFromUrl(url) {
 var target = new URL(url || window.location.href, window.location.href);
 return target.searchParams;
 }

 function collectParamsFromForm($form) {
 var params = new URLSearchParams($form.serialize());
 params.set('page', 'digitrix-orderflow-my-orders');
 params.set('paged', '1');
 return params;
 }

 function loadModeratorOrders(params, pushUrl) {
 if (request) {
 request.abort();
 }
 var searchParams = params || collectParamsFromUrl();
 searchParams.set('page', 'digitrix-orderflow-my-orders');
 $app.addClass('is-loading');
 if (!$app.children().length || $app.find('.aoam-moderator-loading').length) {
 $app.html('<div class="aoam-moderator-loading"><span class="spinner is-active"></span><p>Loading orders...</p></div>');
 }
 request = $.ajax({
 url: ajaxurl,
 type: 'POST',
 dataType: 'json',
 data: {
 action: 'aoam_moderator_orders_ajax',
 nonce: '<?php echo esc_js(wp_create_nonce('aoam_moderator_orders_ajax')); ?>',
 status: searchParams.get('status') || 'all',
 date_filter: searchParams.get('date_filter') || 'all',
 phone_search: searchParams.get('phone_search') || '',
 paged: searchParams.get('paged') || '1'
 }
 }).done(function(response) {
 if (response && response.success && response.data && response.data.html) {
 $app.html(response.data.html);
 if (pushUrl) {
 window.history.pushState({}, '', '?' + searchParams.toString());
 }
 } else {
 $app.html('<div class="notice notice-error"><p>Could not load orders.</p></div>');
 }
 }).fail(function(xhr, status) {
 if (status !== 'abort') {
 $app.html('<div class="notice notice-error"><p>Server took too long to load orders. Please try again.</p></div>');
 }
 }).always(function() {
 $app.removeClass('is-loading');
 request = null;
 });
 }

 window.aoamRefreshModeratorOrders = function() {
 var params = collectParamsFromUrl();
 if ($('#status_filter_select').length) {
 params.set('status', $('#status_filter_select').val() || 'all');
 }
 if ($('#date_filter_select').length) {
 params.set('date_filter', $('#date_filter_select').val() || 'all');
 }
 if ($('#phone_search_main').length) {
 params.set('phone_search', $('#phone_search_main').val() || '');
 }
 params.set('page', 'digitrix-orderflow-my-orders');
 params.set('paged', '1');
 loadModeratorOrders(params, false);
 };

 window.aoamRefreshModeratorOrdersAfterUpdate = function() {
 window.aoamRefreshModeratorOrders();
 setTimeout(window.aoamRefreshModeratorOrders, 350);
 };

 window.aoamHideModeratorOrderIfFiltered = function(orderId, newStatus) {
 var currentStatus = $('#status_filter_select').val() || 'all';
 if (currentStatus !== 'all' && currentStatus !== newStatus) {
 $('[data-order-id="' + orderId + '"]').remove();
 if (!$('.mobile-order-card').length && !$('.fixed-table tbody tr').length && !$('.aoam-moderator-empty-state').length) {
 $('#orders-table-container').append('<div class="aoam-moderator-empty-state"><h3>No orders found</h3><p>No orders match the current filters.</p></div>');
 }
 }
 };

 $app.on('submit', '.orders-filter-toolbar, .phone-search-form', function(e) {
 e.preventDefault();
 e.stopImmediatePropagation();
 loadModeratorOrders(collectParamsFromForm($(this)), true);
 });

 $app.on('change', '.orders-filter-toolbar select', function(e) {
 e.preventDefault();
 e.stopImmediatePropagation();
 loadModeratorOrders(collectParamsFromForm($(this).closest('form')), true);
 });

 $app.on('change', '.mobile-status-form select[name="order_status"]', function(e) {
 e.preventDefault();
 e.stopImmediatePropagation();
 var $select = $(this);
 var $form = $select.closest('form');
 var orderId = $form.find('input[name="order_id"]').val();
 var newStatus = $select.val();
 if (!orderId || !newStatus) {
 return;
 }
 var $card = $select.closest('.mobile-order-card');
 $card.addClass('is-updating-status');
 $select.css('visibility', 'hidden');
 $.ajax({
 url: ajaxurl,
 type: 'POST',
 dataType: 'json',
 data: {
 action: 'update_order_status_ajax',
 status_update_nonce: '<?php echo esc_js(wp_create_nonce('update_order_status')); ?>',
 order_id: orderId,
 order_status: newStatus
 }
 }).done(function(response) {
 if (response && response.success) {
 if (window.aoamHideModeratorOrderIfFiltered) {
 window.aoamHideModeratorOrderIfFiltered(orderId, newStatus);
 }
 if (window.aoamRefreshModeratorOrdersAfterUpdate) {
 window.aoamRefreshModeratorOrdersAfterUpdate();
 }
 } else {
 alert(response && response.data ? response.data : 'Status update failed');
 $card.removeClass('is-updating-status');
 $select.css('visibility', '');
 }
 }).fail(function() {
 alert('Network error. Please try again.');
 $card.removeClass('is-updating-status');
 $select.css('visibility', '');
 });
 });

 $app.on('click', '.tablenav-pages a, .phone-search-form a, .search-stats a, .notice a[href*="digitrix-orderflow-my-orders"]', function(e) {
 var href = $(this).attr('href');
 if (!href) {
 return;
 }
 e.preventDefault();
 loadModeratorOrders(collectParamsFromUrl(href), true);
 });

 window.addEventListener('popstate', function() {
 loadModeratorOrders(collectParamsFromUrl(), false);
 });

 document.addEventListener('submit', function(event) {
 if (event.target && event.target.classList && (event.target.classList.contains('orders-filter-toolbar') || event.target.classList.contains('phone-search-form'))) {
 event.preventDefault();
 }
 }, true);

 loadModeratorOrders(collectParamsFromUrl(), false);
 });
 </script>
 <style>
 .aoam-moderator-ajax-shell {
 min-height: 240px;
 }
 .aoam-moderator-ajax-shell.is-loading {
 pointer-events: none;
 }
 .aoam-moderator-loading {
 background: #fff;
 border: 1px solid #dcdcde;
 border-radius: 8px;
 margin: 20px 0;
 padding: 48px;
 text-align: center;
 }
 .aoam-moderator-loading .spinner {
 float: none;
 margin: 0 0 10px;
 }
 </style>
 <?php
}

add_action('wp_ajax_aoam_moderator_orders_ajax', 'aoam_moderator_orders_ajax');
function aoam_moderator_orders_ajax() {
 check_ajax_referer('aoam_moderator_orders_ajax', 'nonce');
 $current_user = wp_get_current_user();
 $assigned_roles = aoam_get_assigned_roles();
 if (!$current_user || empty(array_intersect((array) $current_user->roles, $assigned_roles))) {
 wp_send_json_error(array('message' => 'Permission denied'), 403);
 }
 
 $_GET['page'] = 'digitrix-orderflow-my-orders';
 $_GET['status'] = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'all';
 $_GET['date_filter'] = isset($_POST['date_filter']) ? sanitize_key(wp_unslash($_POST['date_filter'])) : 'all';
 $_GET['phone_search'] = isset($_POST['phone_search']) ? sanitize_text_field(wp_unslash($_POST['phone_search'])) : '';
 $_GET['paged'] = isset($_POST['paged']) ? absint($_POST['paged']) : 1;

 ob_start();
 aoam_render_moderator_orders_page_content(true);
 wp_send_json_success(array('html' => ob_get_clean()));
}

function aoam_render_moderator_orders_page_content($ajax_request = false) {
 $current_user = wp_get_current_user();
 $user_id = $current_user->ID;
 
 // Check if user has any assigned role.
 $assigned_roles = aoam_get_assigned_roles();
 $user_has_role = !empty(array_intersect($current_user->roles, $assigned_roles));
 
 if (!$user_has_role) {
 wp_die('You do not have permission to access this page.');
 }
 
 // Handle order status update
 if (isset($_POST['update_order_status'], $_POST['moderator_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['moderator_nonce'])), 'update_order_status')) {
 $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
 $new_status = isset($_POST['order_status']) ? sanitize_key(wp_unslash($_POST['order_status'])) : '';
 
 if ($order_id && $new_status) {
 $order = wc_get_order($order_id);
 
 // Verify this order belongs to the current user
 $assigned_user_id = get_post_meta($order_id, '_assigned_moderator_id', true);
 
 if ($order && $assigned_user_id == $user_id) {
 $old_status = $order->get_status();
 $order->update_status($new_status);
 
 // Add order note
 $order_note = sprintf(
 'Order status changed from %s to %s by user: %s',
 $old_status,
 $new_status,
 $current_user->display_name
 );
 
 $order->add_order_note($order_note);
 
 ?>
 <div class="notice notice-success">
 <p> Order #<?php echo esc_html($order_id); ?> status updated to <?php echo esc_html(ucfirst($new_status)); ?> successfully!</p>
 </div>
 <?php
 } else {
 ?>
 <div class="notice notice-error aoam-moderator-empty-state">
 <p> You are not authorized to update this order or order not found.</p>
 </div>
 <?php
 }
 }
 }
 
 // Get filters from URL
 $status_filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
 $date_filter = isset($_GET['date_filter']) ? sanitize_key(wp_unslash($_GET['date_filter'])) : 'all';
 $phone_search = isset($_GET['phone_search']) ? sanitize_text_field(wp_unslash($_GET['phone_search'])) : '';
 $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
 $per_page = 50;
 
 ?>
 
 <div class="wrap">
 <!-- Header -->
 <div class="moderator-profile" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #ccd0d4;">
 <div>
 <h1 style="margin: 0;">My Orders - <?php echo esc_html($current_user->display_name); ?> 
 <?php $moderator_status = get_user_meta($current_user->ID, 'moderator_status', true); ?>
 <?php if ($moderator_status == 'active'): ?>
 <span class="moderator-active-badge">(Active)</span>
 <?php else: ?>
 <span class="moderator-inactive-badge">(Inactive)</span>
 <?php endif; ?>
 </h1>
 <p style="margin: 5px 0 0 0; color: #666;">
 <strong>Email:</strong> <?php echo esc_html($current_user->user_email); ?> | 
 <strong>Role:</strong> <?php echo implode(', ', $current_user->roles); ?>
 </p>
 </div>
 <div class="moderator-menu">
 <button type="button" class="moderator-menu-toggle" aria-label="Open user menu" onclick="this.parentNode.classList.toggle('is-open')">
 <span></span><span></span><span></span>
 </button>
 <div class="moderator-menu-panel">
 <a href="<?php echo esc_url(admin_url('profile.php')); ?>" class="button">Profile</a>
 <a href="<?php echo esc_url(wp_logout_url()); ?>" class="button moderator-logout-btn">Logout</a>
 </div>
 </div>
 </div>

 <?php
 // Get orders assigned to this user
 global $wpdb;
 
 $today_date = aoam_format_display_date('Y-m-d');
 
 // Base query for order IDs - SIMPLIFIED
 $query = $wpdb->prepare(
 "SELECT DISTINCT pm.post_id 
 FROM {$wpdb->postmeta} pm 
 WHERE pm.meta_key = '_assigned_moderator_id' 
 AND pm.meta_value = %d",
 $user_id
 );

 $query .= " ORDER BY pm.meta_id DESC";
 
 // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above, then only a static ORDER BY clause is appended.
 $order_ids = $wpdb->get_col($query);
 
 // Filter by status and prepare orders array
 $all_filtered_orders = array();
 $status_counts = array(
 'all' => 0,
 'pending' => 0,
 'partial' => 0,
 'processing' => 0,
 'on-hold' => 0,
 'completed' => 0,
 'cancelled' => 0,
 'refunded' => 0,
 'failed' => 0
 );
 
 // Count today's orders
 $today_orders_count = 0;
 $today_orders_by_status = array(
 'pending' => 0,
 'partial' => 0,
 'processing' => 0,
 'on-hold' => 0,
 'completed' => 0,
 'cancelled' => 0,
 'refunded' => 0,
 'failed' => 0
 );
 
 if (!empty($order_ids)) {
 foreach ($order_ids as $order_id) {
 try {
 $order = wc_get_order($order_id);
 
 if ($order && is_a($order, 'WC_Order')) {
 $order_date = aoam_format_order_local_date($order, 'Y-m-d');
 $order_status = $order->get_status();
 $order_phone = $order->get_billing_phone();
 
 // Check phone number match if searching
 $phone_match = true;
 if (!empty($phone_search)) {
 // Clean both phone numbers for comparison (remove non-numeric)
 $search_clean = preg_replace('/[^0-9]/', '', $phone_search);
 $order_phone_clean = preg_replace('/[^0-9]/', '', $order_phone);
 
 // Check if search appears in order phone (partial match)
 $phone_match = !empty($order_phone_clean) && 
 (stripos($order_phone, $phone_search) !== false || 
 stripos($order_phone_clean, $search_clean) !== false);
 }
 
 // Count today's orders
 if ($order_date === $today_date) {
 $today_orders_count++;
 if (isset($today_orders_by_status[$order_status])) {
 $today_orders_by_status[$order_status]++;
 }
 }
 
 $status_counts['all']++;
 if (isset($status_counts[$order_status])) {
 $status_counts[$order_status]++;
 }
 
 // Apply all filters: status, date, and phone
 $status_match = ($status_filter === 'all' || $order_status === $status_filter);
 $date_match = true;
 
 if ($date_filter === 'today') {
 $date_match = ($order_date === $today_date);
 }
 
 if ($status_match && $date_match && $phone_match) {
 $all_filtered_orders[] = $order;
 }
 }
 } catch (Exception $e) {
 continue;
 }
 }
 }
 
 // Pagination calculations
 $total_orders = count($all_filtered_orders);
 $total_pages = ceil($total_orders / $per_page);
 $offset = ($paged - 1) * $per_page;
 $orders = array_slice($all_filtered_orders, $offset, $per_page);
 $moderator_orders_base_url = admin_url('admin.php');
 $clear_phone_url = add_query_arg(array(
 'page' => 'digitrix-orderflow-my-orders',
 'status' => $status_filter,
 'date_filter' => $date_filter,
 ), $moderator_orders_base_url);
 $show_all_url = add_query_arg(array(
 'page' => 'digitrix-orderflow-my-orders',
 ), $moderator_orders_base_url);
 ?>
 
 <!-- Date and Status filters -->
 <div class="order-filters-container">
 <form method="get" class="orders-filter-toolbar">
 <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? 'digitrix-orderflow-my-orders'); ?>">
 <input type="hidden" name="phone_search" value="<?php echo esc_attr($phone_search); ?>">
 <input type="hidden" name="paged" value="1">
 <div class="orders-filter-field">
 <label for="date_filter_select">Date</label>
 <select id="date_filter_select" name="date_filter">
 <option value="all" <?php selected($date_filter, 'all'); ?>>All Orders (<?php echo esc_html($status_counts['all']); ?>)</option>
 <option value="today" <?php selected($date_filter, 'today'); ?>>Today's Orders (<?php echo esc_html($today_orders_count); ?>)</option>
 </select>
 </div>
 <div class="orders-filter-field orders-filter-field-right">
 <label for="status_filter_select">Status</label>
 <select id="status_filter_select" name="status">
 <option value="all" <?php selected($status_filter, 'all'); ?>>All (<?php echo esc_html($date_filter === 'today' ? $total_orders : $status_counts['all']); ?>)</option>
 <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending (<?php echo esc_html($date_filter === 'today' ? $today_orders_by_status['pending'] : $status_counts['pending']); ?>)</option>
 <option value="partial" <?php selected($status_filter, 'partial'); ?>>Partial (<?php echo esc_html($date_filter === 'today' ? $today_orders_by_status['partial'] : $status_counts['partial']); ?>)</option>
 <option value="processing" <?php selected($status_filter, 'processing'); ?>>Processing (<?php echo esc_html($date_filter === 'today' ? $today_orders_by_status['processing'] : $status_counts['processing']); ?>)</option>
 <option value="on-hold" <?php selected($status_filter, 'on-hold'); ?>>On Hold (<?php echo esc_html($date_filter === 'today' ? $today_orders_by_status['on-hold'] : $status_counts['on-hold']); ?>)</option>
 <option value="completed" <?php selected($status_filter, 'completed'); ?>>Completed (<?php echo esc_html($date_filter === 'today' ? $today_orders_by_status['completed'] : $status_counts['completed']); ?>)</option>
 <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>>Cancelled (<?php echo esc_html($date_filter === 'today' ? $today_orders_by_status['cancelled'] : $status_counts['cancelled']); ?>)</option>
 </select>
 </div>
 </form>
 <?php if ($date_filter === 'today'): ?>
 <div class="orders-filter-note">
 <strong>Showing orders from:</strong> <?php echo esc_html(aoam_format_display_date('F j, Y')); ?>
 </div>
 <?php endif; ?>
 </div>
 
 <!-- Phone Number Search Box -->
 <div class="phone-search-section" style="margin-bottom: 30px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
 <h3 style="margin-top: 0; margin-bottom: 15px;"> Search Orders by Phone Number</h3>
 
 <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="phone-search-form">
 <!-- Keep existing filters in URL -->
 <input type="hidden" name="page" value="digitrix-orderflow-my-orders">
 <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
 <input type="hidden" name="date_filter" value="<?php echo esc_attr($date_filter); ?>">
 
 <input type="text" 
 name="phone_search" 
 id="phone_search_main" 
 placeholder="Enter customer phone number (e.g., +1234567890 or 123-456-7890)" 
 value="<?php echo esc_attr($phone_search); ?>">
 
 <button type="submit" 
 class="button button-primary phone-search-button">
 Search
 </button>
 
 <?php if (!empty($phone_search)): ?>
 <a href="<?php echo esc_url($clear_phone_url); ?>" 
 class="button" 
 style="white-space: nowrap; padding: 10px 20px; background: #f0f0f0; border-color: #ccc;">
 Clear Search
 </a>
 <?php endif; ?>
 </form>
 
 <?php if (!empty($phone_search)): ?>
 <div style="margin-top: 10px; padding: 12px; background: #e5f7e5; border-radius: 4px; color: #46b450; border-left: 4px solid #46b450;">
 <strong> Showing orders for phone number:</strong> <?php echo esc_html($phone_search); ?>
 <span style="float: right;">
 <a href="<?php echo esc_url($clear_phone_url); ?>" style="color: #0073aa; text-decoration: none; font-weight: bold;">
 Clear filter
 </a>
 </span>
 </div>
 <?php endif; ?>
 </div>
 
 <?php if (!empty($phone_search)) : ?>
 <div class="search-stats" style="margin-bottom: 20px; padding: 15px; background: #f0f6ff; border-radius: 6px; border: 1px solid #c3d9ff;">
 <h4 style="margin-top: 0; color: #0073aa;"> Search Results</h4>
 <p style="margin: 5px 0;">
 <strong>Searching for:</strong> <code style="background: white; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($phone_search); ?></code>
 <strong>Filtered by:</strong> 
 <?php if ($date_filter === 'today'): ?>
 <span style="background: #e7f3ff; padding: 2px 8px; border-radius: 3px;">Today's orders</span>
 <?php else: ?>
 <span style="background: #f0f0f0; padding: 2px 8px; border-radius: 3px;">All dates</span>
 <?php endif; ?>
 
 <?php if ($status_filter !== 'all'): ?>
 <span style="background: #e7f3ff; padding: 2px 8px; border-radius: 3px;"><?php echo ucfirst($status_filter); ?> status</span>
 <?php endif; ?>
 </p>
 </div>
 <?php endif; ?>
 
 <?php if (empty($order_ids)) : ?>
 <div class="notice notice-error">
 <h3> No Orders Found</h3>
 <p>You don't have any orders assigned to you yet.</p>
 </div>
 <?php elseif (empty($all_filtered_orders)) : ?>
 <div class="notice notice-info aoam-moderator-empty-state">
 <p>
 <?php if (!empty($phone_search)): ?>
 <strong> No orders found for phone number:</strong> <?php echo esc_html($phone_search); ?>
 <?php if ($date_filter === 'today' && $status_filter !== 'all'): ?>
 with status "<?php echo esc_html($status_filter); ?>" for today.
 <?php elseif ($date_filter === 'today'): ?>
 for today.
 <?php elseif ($status_filter !== 'all'): ?>
 with status "<?php echo esc_html($status_filter); ?>".
 <?php else: ?>
 .
 <?php endif; ?>
 <?php elseif ($date_filter === 'today' && $status_filter !== 'all'): ?>
 No <?php echo esc_html($status_filter); ?> orders found for today (<?php echo esc_html(aoam_format_display_date('F j, Y')); ?>).
 <?php elseif ($date_filter === 'today'): ?>
 No orders found for today (<?php echo esc_html(aoam_format_display_date('F j, Y')); ?>).
 <?php elseif ($status_filter !== 'all'): ?>
 No orders found with status: <?php echo esc_html($status_filter); ?>.
 <?php else: ?>
 You have <?php echo esc_html($status_counts['all']); ?> total orders, but none match current filters.
 <?php endif; ?>
 </p>
 <p>
 <a href="<?php echo esc_url($show_all_url); ?>" class="button button-primary">Show All Orders</a>
 <?php if (!empty($phone_search)): ?>
 <a href="<?php echo esc_url($clear_phone_url); ?>" class="button" style="margin-left: 10px;">Clear Phone Search</a>
 <?php endif; ?>
 </p>
 </div>
 <?php else : ?>
 <!-- Display orders count with filter info -->
 <div class="card" style="background: #fff; padding: 15px; margin: 10px 0; border-left: 4px solid #46b450; max-width: 100%;">
 <h3>
 <?php 
 $filter_text = '';
 if ($date_filter === 'today') {
 $filter_text = 'Today\'s ';
 }
 
 $status_text = ($status_filter === 'all') ? 'All' : ucfirst($status_filter);
 
 if (!empty($phone_search)) {
 echo ' ' . $filter_text . $status_text . ' Orders for Phone: ' . esc_html($phone_search) . ' - ' . $total_orders . ' found';
 } else {
 echo ' ' . $filter_text . $status_text . ' Orders: ' . $total_orders;
 }
 
 if ($date_filter === 'today') {
 echo ' (From: ' . esc_html(aoam_format_display_date('F j, Y')) . ')';
 }
 ?>
 </h3>
 
 <!-- Quick Stats -->
 <div class="mobile-col3" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-top: 15px;">
 <div style="text-align: center; padding: 10px; background: #f0f6ff; border-radius: 6px;">
 <div style="font-size: 18px; font-weight: bold; color: #0073aa;"><?php echo count($orders); ?></div>
 <div style="font-size: 12px;">Showing</div>
 </div>
 <div style="text-align: center; padding: 10px; background: #f0f6ff; border-radius: 6px;">
 <div style="font-size: 18px; font-weight: bold; color: #46b450;"><?php echo esc_html($today_orders_count); ?></div>
 <div style="font-size: 12px;">Today's Total</div>
 </div>
 <div style="text-align: center; padding: 10px; background: #f0f6ff; border-radius: 6px;">
 <div style="font-size: 18px; font-weight: bold; color: #ffb900;"><?php echo esc_html($status_counts['all']); ?></div>
 <div style="font-size: 12px;">All Time Total</div>
 </div>
 </div>
 
 <?php if (!empty($phone_search)): ?>
 <div style="margin-top: 15px; padding: 10px; background: #f8fff8; border-radius: 6px; border: 1px solid #46b450;">
 <h4 style="margin-top: 0;"> Phone Search Active</h4>
 <p style="margin: 5px 0;">
 <strong>Searching for:</strong> <code style="background: white; padding: 2px 8px; border-radius: 3px; font-size: 14px;"><?php echo esc_html($phone_search); ?></code>
 <a href="<?php echo esc_url($clear_phone_url); ?>" style="margin-left: 10px; color: #0073aa; text-decoration: none;">
 (Clear search)
 </a>
 </p>
 <p style="margin: 5px 0; font-size: 13px; color: #666;">
 <small>Showing orders where phone number contains the search term (partial match).</small>
 </p>
 </div>
 <?php endif; ?>
 
 <!-- Today's Breakdown (if today filter is active) -->
 <?php if ($date_filter === 'today'): ?>
 <div style="margin-top: 15px; padding: 10px; background: #f8fff8; border-radius: 6px; border: 1px solid #ccd0d4;">
 <h4 style="margin-top: 0;"> Today's Order Breakdown:</h4>
 <div style="display: flex; gap: 10px; flex-wrap: wrap;">
 <?php foreach ($today_orders_by_status as $status => $count): 
 if ($count > 0): 
 $status_label = wc_get_order_status_name($status);
 ?>
 <div style="text-align: center; padding: 8px 12px; background: white; border-radius: 4px; border: 1px solid #ddd; min-width: 100px;">
 <div style="font-size: 16px; font-weight: bold; color: #0073aa;"><?php echo esc_html($count); ?></div>
 <div style="font-size: 11px; color: #666;"><?php echo esc_html($status_label); ?></div>
 </div>
 <?php endif; endforeach; ?>
 </div>
 </div>
 <?php endif; ?>
 </div>
 
 <!-- Orders Table Container -->
 <div id="orders-table-container">
 <!-- Display orders table -->
 <div class="table-wrapper">
 <table class="fixed-table">
 <thead>
 <tr>
 <th>Order #</th>
 <th>Date & Time</th>
 <th>Customer</th>
 <th>Items</th>
 <th>Total</th>
 <th>Status</th>
 <th>Actions</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($orders as $order) : 
 $order_status = $order->get_status();
 $status_class = 'status-' . $order_status;
 $status_label = wc_get_order_status_name($order_status);
 $order_date = $order->get_date_created();
 $order_date_formatted = aoam_format_order_local_date($order, 'Y-m-d');
 $is_today = $order_date_formatted === $today_date;
 
 // Get order items
 $items = $order->get_items();
 $product_names = array();
 $item_count = 0;
 
 foreach ($items as $item) {
 $product_names[] = $item->get_name();
 $item_count += $item->get_quantity();
 }
 ?>
 <tr data-order-id="<?php echo esc_attr($order->get_id()); ?>">
 <td>
 <strong>#<?php echo esc_html($order->get_id()); ?></strong>
 <?php if ($is_today): ?>
 <span style="color: #46b450; font-size: 10px; margin-left: 5px;"></span>
 <?php endif; ?>
 </td>
 <td>
 <?php echo esc_html(aoam_format_order_local_date($order, 'M j')); ?><br>
 <small style="color: #666;"><?php echo esc_html(aoam_format_order_local_date($order, 'g:i A')); ?></small>
 <?php if ($is_today): ?>
 <br><small style="color: #46b450; font-weight: bold;">Today</small>
 <?php endif; ?>
 </td>
 <td>
 <?php echo esc_html($order->get_formatted_billing_full_name()); ?>
 <?php if (!empty($order->get_billing_email())) {
 echo '<br><small>'.$order->get_billing_email().'</small>';
 } ?>
 
 <br><small><?php echo esc_html($order->get_billing_phone()); ?></small>
 </td>
 <td> 
 <div class="order-products">
 <?php 
 $display_count = 0;
 foreach ($product_names as $product_name) {
 if ($display_count >= 2) break;
 echo '<div class="product-name" style="display:inline">' . esc_html($product_name) . '</div>';
 $display_count++;
 }
 if (count($product_names) > 2): ?>
 <div class="more-products" style="display:inline">
 + <?php echo count($product_names) - 2; ?> more products
 </div>
 <?php endif; ?>
 (<?php echo esc_html($item_count); ?> items)
 </div>
 </td>
 <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
 <td>
 <span class="order-status-badge <?php echo esc_attr($status_class); ?>">
 <?php echo esc_html($status_label); ?>
 </span>
 </td>
 <td class="order-actions">
 <div class="aoam-table-actions">
 <!-- View Details Button -->
 <button type="button" class="button button-small aoam-action-button aoam-action-view" onclick="viewOrderDetails(<?php echo esc_js($order->get_id()); ?>)">
 <span class="dashicons dashicons-visibility"></span>
 View Details
 </button>
 
 <!-- Status Update Form -->
 <form method="post" class="aoam-inline-status-form">
 <?php wp_nonce_field('update_order_status', 'moderator_nonce'); ?>
 <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
 <input type="hidden" name="update_order_status" value="1">
 <select name="order_status" onchange="this.form.submit()" class="aoam-action-select">
 <option value="">Change Status</option>
 <option value="pending" <?php selected($order_status, 'pending'); ?>>Pending</option>
 <option value="partial" <?php selected($order_status, 'partial'); ?>>Partial</option>
 <option value="processing" <?php selected($order_status, 'processing'); ?>>Processing</option>
 <option value="on-hold" <?php selected($order_status, 'on-hold'); ?>>On Hold</option>
 <option value="completed" <?php selected($order_status, 'completed'); ?>>Completed</option>
 <option value="cancelled" <?php selected($order_status, 'cancelled'); ?>>Cancelled</option>
 </select>
 </form>
 </div>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 
 <div class="mobile-order-cards">
 <?php foreach ($orders as $order) :
 $order_status = $order->get_status();
 $status_class = 'status-' . $order_status;
 $status_label = wc_get_order_status_name($order_status);
 $items = $order->get_items();
 $item_count = 0;
 $product_names = array();
 foreach ($items as $item) {
 $item_count += $item->get_quantity();
 $product_names[] = $item->get_name();
 }
 $customer_name = $order->get_formatted_billing_full_name();
 $customer_phone = $order->get_billing_phone();
 if (empty($customer_phone)) {
 $customer_phone = $order->get_shipping_phone();
 }
 $order_date_timestamp = aoam_get_order_local_timestamp($order);
 $time_ago = $order_date_timestamp ? human_time_diff($order_date_timestamp, time()) . ' ago' : '';
 ?>
 <article class="mobile-order-card" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
 <div class="mobile-card-top">
 <div class="mobile-card-badges">
 <span class="mobile-status-pill <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
 <span class="mobile-item-pill"><span class="dashicons dashicons-products"></span><?php echo esc_html($item_count); ?> items</span>
 </div>
 <div class="mobile-order-meta">
 <span class="mobile-order-number">#<?php echo esc_html($order->get_id()); ?></span>
 <button type="button" class="mobile-view-link" onclick="viewOrderDetails(<?php echo esc_attr($order->get_id()); ?>)">
 <span class="dashicons dashicons-visibility"></span>
 View
 </button>
 </div>
 </div>

 <div class="mobile-card-body">
 <div class="mobile-customer-block">
 <h3><?php echo esc_html($customer_name ?: 'Guest Customer'); ?></h3>
 <p><?php echo esc_html(!empty($product_names) ? implode(', ', array_slice($product_names, 0, 2)) : 'No product name'); ?></p>
 </div>
 <div class="mobile-contact-block">
 <?php if ($customer_phone): ?>
 <a class="mobile-phone" href="tel:<?php echo esc_attr($customer_phone); ?>"><span class="dashicons dashicons-phone"></span><?php echo esc_html($customer_phone); ?></a>
 <?php else: ?>
 <span class="mobile-phone mobile-phone-empty"><span class="dashicons dashicons-phone"></span>No phone</span>
 <?php endif; ?>
 <div class="mobile-total"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></div>
 </div>
 </div>

 <div class="mobile-card-footer">
 <div class="mobile-owner-time">
 <strong><?php echo esc_html($current_user->display_name); ?></strong>
 <?php if ($time_ago): ?><span><?php echo esc_html($time_ago); ?></span><?php endif; ?>
 </div>
 <form method="post" class="mobile-status-form">
 <?php wp_nonce_field('update_order_status', 'moderator_nonce'); ?>
 <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
 <input type="hidden" name="update_order_status" value="1">
 <select name="order_status">
 <option value="pending" <?php selected($order_status, 'pending'); ?>>Pending</option>
 <option value="partial" <?php selected($order_status, 'partial'); ?>>Partial</option>
 <option value="processing" <?php selected($order_status, 'processing'); ?>>Processing</option>
 <option value="on-hold" <?php selected($order_status, 'on-hold'); ?>>On Hold</option>
 <option value="completed" <?php selected($order_status, 'completed'); ?>>Completed</option>
 <option value="cancelled" <?php selected($order_status, 'cancelled'); ?>>Cancelled</option>
 </select>
 </form>
 </div>
 </article>
 <?php endforeach; ?>
 </div>
 <!-- Pagination -->
 <?php if ($total_pages > 1): ?>
 <div class="tablenav">
 <div class="tablenav-pages">
 <span class="displaying-num">
 <?php echo sprintf(
 'Showing %d-%d of %d orders',
 $offset + 1,
 min($offset + $per_page, $total_orders),
 $total_orders
 ); ?>
 </span>
 
 <span class="pagination-links">
 <?php
 $base_url = add_query_arg(array(
 'status' => $status_filter,
 'date_filter' => $date_filter,
 'phone_search' => $phone_search
 ), $moderator_orders_base_url);
 
 // Previous page
 if ($paged > 1) {
 echo '<a class="prev-page button" href="' . add_query_arg('paged', $paged - 1, $base_url) . '">
 <span class="screen-reader-text">Previous page</span>
 <span aria-hidden="true"></span>
 </a> ';
 }
 
 // Page numbers
 $range = 2;
 $start = max(1, $paged - $range);
 $end = min($total_pages, $paged + $range);
 
 if ($start > 1) {
 echo '<a class="first-page button" href="' . add_query_arg('paged', 1, $base_url) . '">1</a>';
 if ($start > 2) echo '<span class="pagination-dots"></span>';
 }
 
 for ($i = $start; $i <= $end; $i++) {
 if ($i == $paged) {
 echo '<span class="current-page button">' . $i . '</span> ';
 } else {
 echo '<a class="button" href="' . add_query_arg('paged', $i, $base_url) . '">' . $i . '</a> ';
 }
 }
 
 if ($end < $total_pages) {
 if ($end < $total_pages - 1) echo '<span class="pagination-dots"></span>';
 echo '<a class="last-page button" href="' . add_query_arg('paged', $total_pages, $base_url) . '">' . $total_pages . '</a>';
 }
 
 // Next page
 if ($paged < $total_pages) {
 echo ' <a class="next-page button" href="' . add_query_arg('paged', $paged + 1, $base_url) . '">
 <span class="screen-reader-text">Next page</span>
 <span aria-hidden="true"></span>
 </a>';
 }
 ?>
 </span>
 </div>
 </div>
 <?php endif; ?>
 </div>
 <?php endif; ?>
 
 <!-- Order details modal -->
 <div id="order-details-modal" class="aoam-order-modal" style="display: none;">
 <div class="aoam-modal-header">
 <h3>Order Details - #<span id="modal-order-id"></span></h3>
 <button type="button" class="aoam-modal-close" onclick="closeOrderModal()" aria-label="Close order details">x</button>
 </div>
 <div id="order-details-content"></div>
 </div>
 
 <style>
 .mobile-order-cards {
 display: none;
 }
 .mobile-order-card {
 background: #fff;
 border: 1px solid #e7edf3;
 border-radius: 14px;
 box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06);
 margin: 14px 0;
 padding: 16px;
 }
 .mobile-card-top,
 .mobile-card-body,
 .mobile-card-footer {
 display: flex;
 justify-content: space-between;
 gap: 12px;
 }
 .mobile-card-top {
 align-items: center;
 margin-bottom: 14px;
 }
 .mobile-card-badges {
 display: flex;
 flex-wrap: wrap;
 gap: 8px;
 min-width: 0;
 }
 .mobile-status-pill,
 .mobile-item-pill {
 display: inline-flex;
 align-items: center;
 gap: 5px;
 border-radius: 999px;
 font-size: 13px;
 line-height: 1;
 padding: 8px 12px;
 white-space: nowrap;
 }
 .mobile-status-pill {
 background: #d8f7e8;
 border: 1px solid #99e4c0;
 color: #157347;
 font-weight: 600;
 }
 .mobile-status-pill.status-completed {
 background: #d8f7e8;
 border-color: #99e4c0;
 color: #157347;
 }
 .mobile-status-pill.status-pending,
 .mobile-status-pill.status-on-hold,
 .mobile-status-pill.status-partial {
 background: #fff3cd;
 border-color: #ffe08a;
 color: #8a5a00;
 }
 .mobile-status-pill.status-cancelled,
 .mobile-status-pill.status-failed {
 background: #fde2e1;
 border-color: #f5aaa6;
 color: #a52822;
 }
 .mobile-item-pill {
 background: #f3f5f8;
 border: 1px solid #e5e9ef;
 color: #667085;
 }
 .mobile-item-pill .dashicons,
 .mobile-phone .dashicons {
 width: 16px;
 height: 16px;
 font-size: 16px;
 line-height: 16px;
 }
 .mobile-order-meta {
 display: flex;
 align-items: center;
 gap: 12px;
 color: #6b7280;
 white-space: nowrap;
 }
 .mobile-order-number {
 font-size: 14px;
 }
 .mobile-view-link {
 align-items: center;
 background: #f8fafc;
 border: 1px solid #dbe3ea;
 border-radius: 999px;
 box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
 color: #334155;
 cursor: pointer;
 display: inline-flex;
 font-size: 15px;
 font-weight: 600;
 gap: 5px;
 line-height: 1;
 min-height: 34px;
 padding: 8px 12px;
 }
 .mobile-view-link .dashicons {
 width: 15px;
 height: 15px;
 font-size: 15px;
 line-height: 15px;
 }
 .mobile-view-link:hover,
 .mobile-view-link:focus {
 background: #eaf4ff;
 border-color: #91c7f3;
 color: #0a5f9e;
 }
 .mobile-card-body {
 align-items: flex-start;
 border-bottom: 1px solid #eef1f4;
 padding-bottom: 16px;
 }
 .mobile-customer-block {
 min-width: 0;
 flex: 1;
 }
 .mobile-customer-block h3 {
 color: #1f2937;
 font-size: 20px;
 line-height: 1.2;
 margin: 0 0 12px;
 word-break: break-word;
 }
 .mobile-customer-block p {
 color: #6b7280;
 font-size: 14px;
 line-height: 1.4;
 margin: 0;
 }
 .mobile-contact-block {
 flex: 0 0 auto;
 min-width: 132px;
 text-align: right;
 }
 .mobile-phone {
 align-items: center;
 color: #22a36f;
 display: inline-flex;
 font-size: 16px;
 gap: 5px;
 margin-bottom: 18px;
 text-decoration: none;
 white-space: nowrap;
 }
 .mobile-phone-empty {
 color: #9ca3af;
 }
 .mobile-total {
 color: #111827;
 font-size: 24px;
 font-weight: 800;
 line-height: 1;
 white-space: nowrap;
 }
 .mobile-card-footer {
 align-items: center;
 justify-content: space-between;
 gap: 12px;
 padding-top: 14px;
 }
 .mobile-owner-time {
 color: #6b7280;
 display: flex;
 align-items: center;
 flex: 1 1 auto;
 gap: 10px;
 min-width: 0;
 text-align: left;
 }
 .mobile-owner-time span {
 white-space: nowrap;
 }
 .mobile-owner-time strong {
 color: #374151;
 }
 .mobile-owner-time span {
 color: #9ca3af;
 }
 .mobile-status-form select {
 appearance: none;
 background-color: #fff;
 background-image: url("data:image/svg+xml,%3Csvg width='16' height='16' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M5.5 7.5l4.5 4.5 4.5-4.5' fill='none' stroke='%231f2937' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
 background-position: right 12px center;
 background-repeat: no-repeat;
 background-size: 16px 16px;
 border: 1px solid #dce2e8;
 border-radius: 10px;
 box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
 color: #4b5563;
 font-size: 14px;
 min-height: 40px;
 min-width: 152px;
 padding: 6px 34px 6px 14px;
 }
 .mobile-status-form {
 flex: 0 0 auto;
 margin: 0;
 text-align: right;
 }
 .aoam-moderator-empty-state {
 background: #fff;
 border: 1px solid #dce2e8;
 border-radius: 10px;
 box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
 margin: 16px 0;
 padding: 30px 18px;
 text-align: center;
 }
 .aoam-moderator-empty-state h3 {
 margin: 0 0 8px;
 }
 .aoam-moderator-empty-state p {
 color: #64748b;
 margin: 0 0 14px;
 }
 .mobile-order-card.is-updating-status .mobile-status-form {
 min-height: 40px;
 position: relative;
 }
 .mobile-order-card.is-updating-status .mobile-status-form:after {
 align-items: center;
 background: #f8fafc;
 border: 1px solid #dce2e8;
 border-radius: 10px;
 color: #64748b;
 content: "Updating...";
 display: flex;
 font-size: 14px;
 inset: 0;
 justify-content: center;
 position: absolute;
 }
 @media (max-width: 782px) {
 body.wp-admin {
 background: #f2f4f7;
 }
 .moderator-profile {
 align-items: flex-start !important;
 gap: 12px;
 }
 .date-filter-section,
 .phone-search-section,
 .status-filter-section,
 .card {
 border-radius: 12px !important;
 }
 #orders-table-container .table-wrapper {
 display: none;
 }
 .mobile-order-cards {
 display: block;
 }
 .mobile-col3 {
 grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
 }
 .tablenav .tablenav-pages {
 float: none;
 text-align: center;
 }
 }
 @media (max-width: 480px) {
 .mobile-order-card {
 border-radius: 12px;
 padding: 14px;
 }
 .mobile-card-body {
 gap: 10px;
 }
 .mobile-contact-block {
 min-width: 118px;
 }
 .mobile-phone {
 font-size: 14px;
 }
 .mobile-total {
 font-size: 21px;
 }
 .mobile-customer-block h3 {
 font-size: 18px;
 }
 .mobile-card-footer {
 align-items: center;
 flex-direction: row;
 }
 .mobile-status-form select {
 min-width: 138px;
 padding-right: 34px;
 }
 }
 .order-products .product-name {
 white-space: pre-line;
 }
 span.moderator-active-badge {
 color: green;
 }
 span.moderator-inactive-badge {
 color: #DC3232;
 }
 
 .phone-search-section {
 background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
 border: 1px solid #dee2e6 !important;
 }
 .moderator-menu {
 margin-left: auto;
 position: relative;
 }
 .moderator-menu-toggle {
 align-items: center;
 background: #fff;
 border: 1px solid #cfd8e3;
 border-radius: 8px;
 box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
 cursor: pointer;
 display: inline-flex;
 flex-direction: column;
 gap: 4px;
 height: 38px;
 justify-content: center;
 padding: 0;
 width: 42px;
 }
 .moderator-menu-toggle span {
 background: #1f2937;
 border-radius: 999px;
 display: block;
 height: 2px;
 width: 18px;
 }
 .moderator-menu-panel {
 background: #fff;
 border: 1px solid #dbe3ea;
 border-radius: 10px;
 box-shadow: 0 12px 30px rgba(15, 23, 42, 0.16);
 display: none;
 gap: 8px;
 min-width: 150px;
 padding: 10px;
 position: absolute;
 right: 0;
 top: calc(100% + 8px);
 z-index: 30;
 }
 .moderator-menu.is-open .moderator-menu-panel {
 display: grid;
 }
 .moderator-menu-panel .button {
 display: block;
 margin: 0;
 text-align: center;
 }
 .moderator-logout-btn {
 background: #dc3232 !important;
 border-color: #dc3232 !important;
 color: #fff !important;
 }
 .phone-search-form {
 align-items: stretch;
 display: flex;
 gap: 10px;
 margin-bottom: 10px;
 }
 #phone_search_main {
 border: 1px solid #cfd8e3;
 border-radius: 8px;
 flex: 1 1 auto;
 font-size: 14px;
 min-height: 44px;
 min-width: 0;
 padding: 10px 14px;
 width: auto;
 }
 .phone-search-button {
 border-radius: 8px !important;
 flex: 0 0 auto;
 min-height: 44px;
 min-width: 112px;
 padding: 0 20px !important;
 white-space: nowrap;
 }
 .orders-filter-toolbar {
 align-items: end;
 background: #fff;
 border: 1px solid #dbe3ea;
 border-radius: 12px;
 box-shadow: 0 1px 4px rgba(15, 23, 42, 0.05);
 display: grid;
 gap: 14px;
 grid-template-columns: 1fr 1fr;
 margin: 0 0 16px;
 padding: 16px;
 }
 .orders-filter-field {
 min-width: 0;
 }
 .orders-filter-field-right {
 justify-self: end;
 width: 100%;
 }
 .orders-filter-field label {
 color: #374151;
 display: block;
 font-size: 13px;
 font-weight: 700;
 margin-bottom: 7px;
 }
 .orders-filter-field select {
 appearance: none;
 background-color: #fff;
 background-image: url("data:image/svg+xml,%3Csvg width='16' height='16' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M5.5 7.5l4.5 4.5 4.5-4.5' fill='none' stroke='%231f2937' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
 background-position: right 14px center;
 background-repeat: no-repeat;
 background-size: 16px 16px;
 border: 1px solid #cfd8e3;
 border-radius: 8px;
 box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
 color: #1f2937;
 font-size: 14px;
 min-height: 42px;
 padding: 8px 34px 8px 12px;
 width: 100%;
 }
 .orders-filter-field select:focus {
 border-color: #2271b1;
 box-shadow: 0 0 0 1px #2271b1;
 outline: none;
 }
 .orders-filter-note {
 background: #eef6ff;
 border: 1px solid #cfe6ff;
 border-radius: 8px;
 color: #1d4f7a;
 margin: -6px 0 16px;
 padding: 10px 12px;
 }
 
 #phone_search_main:focus {
 border-color: #0073aa !important;
 box-shadow: 0 0 0 1px #0073aa !important;
 outline: none;
 }
 
 @media (max-width: 768px) {
 .mobile-col3{
 grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)) !important;
 }
 .moderator-profile{
 align-items: flex-start !important;
 display: flex !important;
 gap: 12px;
 }
 
 .phone-search-section {
 padding: 15px !important;
 }
 .orders-filter-toolbar {
 grid-template-columns: 1fr 1fr;
 gap: 10px;
 padding: 12px;
 }
 .orders-filter-field select {
 font-size: 13px;
 min-height: 40px;
 padding-left: 10px;
 padding-right: 34px;
 }
 
 #phone_search_main {
 margin-bottom: 0;
 }
 .phone-search-form {
 gap: 8px;
 }
 .phone-search-button {
 min-width: 86px;
 padding-left: 14px !important;
 padding-right: 14px !important;
 }
 .phone-search-section a.button {
 width: auto;
 margin-bottom: 0;
 }
 }
 
 .order-status-badge { 
 padding: 4px 8px; 
 border-radius: 3px; 
 font-size: 12px; 
 font-weight: bold;
 display: inline-block;
 }
 .status-pending { background: #ffb900; color: #000; }
 .status-partial { background: #777; color: #fff; }
 .status-processing { background: #00a0d2; color: #fff; }
 .status-on-hold { background: #cc1818; color: #fff; }
 .status-completed { background: #46b450; color: #fff; }
 .status-cancelled { background: #aaa; color: #fff; }
 .status-refunded { background: #aaa; color: #fff; }
 .order-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
 .aoam-table-actions {
 align-items: center;
 display: flex;
 gap: 10px;
 justify-content: center;
 width: 100%;
 }
 .aoam-inline-status-form {
 display: inline-flex;
 margin: 0;
 }
 .aoam-action-button {
 align-items: center !important;
 background: #f8fbff !important;
 border: 1px solid #c8d8ea !important;
 border-radius: 8px !important;
 box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04) !important;
 color: #1f5f99 !important;
 display: inline-flex !important;
 gap: 6px !important;
 justify-content: center !important;
 min-height: 38px !important;
 padding: 7px 12px !important;
 text-decoration: none !important;
 white-space: nowrap;
 }
 .aoam-action-button:hover,
 .aoam-action-button:focus {
 background: #eef6ff !important;
 border-color: #2271b1 !important;
 color: #0b4f82 !important;
 }
 .aoam-action-button .dashicons {
 font-size: 16px;
 height: 16px;
 line-height: 16px;
 width: 16px;
 }
 select.aoam-action-select {
 appearance: none !important;
 -webkit-appearance: none !important;
 background-color: #fff !important;
 background-image: url("data:image/svg+xml,%3Csvg width='16' height='16' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M5.5 7.5l4.5 4.5 4.5-4.5' fill='none' stroke='%231f2937' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
 background-position: right 14px center !important;
 background-repeat: no-repeat !important;
 background-size: 16px 16px !important;
 border: 1px solid #cfd8e3 !important;
 border-radius: 8px !important;
 box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04) !important;
 color: #1f2937 !important;
 font-size: 14px !important;
 line-height: 1.4 !important;
 min-height: 42px !important;
 min-width: 132px !important;
 padding: 8px 38px 8px 12px !important;
 }
 select.aoam-action-select:focus {
 border-color: #2271b1 !important;
 box-shadow: 0 0 0 1px #2271b1 !important;
 outline: none !important;
 }
 .modal-backdrop {
 position: fixed;
 top: 0;
 left: 0;
 right: 0;
 bottom: 0;
 background: rgba(0,0,0,0.5);
 z-index: 9999;
 }
 .order-details-section {
 margin-bottom: 18px;
 padding: 18px;
 background: #fff;
 border: 1px solid #e7edf3;
 border-radius: 12px;
 box-shadow: 0 1px 4px rgba(15, 23, 42, 0.04);
 }
 .order-details-section h4 {
 color: #1f2937;
 font-size: 15px;
 font-weight: 700;
 margin: 0 0 14px;
 border-bottom: 1px solid #eef1f4;
 padding-bottom: 10px;
 }
 .aoam-order-modal {
 position: fixed;
 top: 50%;
 left: 50%;
 transform: translate(-50%, -50%);
 background: #f8fafc;
 border: 1px solid #d7e6f2;
 border-radius: 16px;
 box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28);
 z-index: 10000;
 width: min(920px, calc(100vw - 28px));
 max-height: min(86vh, 920px);
 overflow-y: auto;
 padding: 20px;
 }
 .aoam-modal-header {
 align-items: center;
 background: #fff;
 border: 1px solid #e7edf3;
 border-radius: 12px;
 display: flex;
 justify-content: space-between;
 margin-bottom: 18px;
 padding: 16px 18px;
 position: sticky;
 top: 0;
 z-index: 2;
 }
 .aoam-modal-header h3 {
 color: #111827;
 font-size: 20px;
 line-height: 1.2;
 margin: 0;
 }
 .aoam-modal-close {
 align-items: center;
 background: #f1f5f9;
 border: 1px solid #dbe3ea;
 border-radius: 999px;
 color: #475569;
 cursor: pointer;
 display: inline-flex;
 font-size: 18px;
 font-weight: 700;
 height: 34px;
 justify-content: center;
 line-height: 1;
 width: 34px;
 }
 .aoam-modal-close:hover {
 background: #fee2e2;
 border-color: #fecaca;
 color: #991b1b;
 }
 .nav-tab-wrapper { margin: 20px 0; }
 .nav-tab { 
 text-decoration: none; 
 padding: 10px 15px; 
 margin: 0 5px 0 0; 
 border: 1px solid #ccc;
 background: #f0f0f0;
 }
 .nav-tab-active { 
 background: #0073aa; 
 color: white; 
 border-bottom: 3px solid #0073aa; 
 }
 .order-status-filters { 
 background: white; 
 padding: 10px; 
 border: 1px solid #ccd0d4; 
 margin-bottom: 20px;
 }
 .card {
 background: #fff;
 padding: 15px;
 margin: 10px 0;
 border-left: 4px solid #46b450;
 border-radius: 4px;
 box-shadow: 0 1px 3px rgba(0,0,0,0.1);
 }

 .table-wrapper {
 width: 100%;
 overflow-x: auto; 
 position: relative;
 }

 /* Basic table design */
 .fixed-table {
 border-collapse: collapse;
 width: auto;
 min-width: 100%;
 }

 .fixed-table th,
 .fixed-table td {
 border: 1px solid #ccc;
 padding: 10px;
 white-space: nowrap;
 background: #fff;
 }

 /* --- FIX LAST 2 COLUMNS --- */
 .fixed-table th:nth-last-child(2),
 .fixed-table td:nth-last-child(2) {
 position: sticky !important;
 right: 30px; /* last column width */
 z-index: 5;
 }

 .fixed-table th:last-child,
 .fixed-table td:last-child {
 position: sticky !important;
 right: 0;
 z-index: 5;
 }
 
 /* Pagination Styles */
 .tablenav {
 margin: 20px 0;
 padding: 15px;
 background: white;
 border: 1px solid #ccd0d4;
 border-radius: 4px;
 overflow: hidden;
 }
 .tablenav-pages {
 display: flex;
 align-items: center;
 flex-wrap: wrap;
 gap: 15px;
 }
 .displaying-num {
 margin-right: 15px;
 font-weight: bold;
 }
 .pagination-links {
 display: flex;
 gap: 5px;
 flex-wrap: wrap;
 }
 .pagination-links .button {
 min-width: 32px;
 height: 32px;
 display: inline-flex;
 align-items: center;
 justify-content: center;
 padding: 6px 8px !important;
 }
 .current-page {
 background: #0073aa;
 color: white;
 border-color: #0073aa;
 }
 .pagination-dots {
 padding: 0 8px;
 color: #666;
 }
 .tablenav-pages .pagination-links .current-page {
 padding: 6px 6px !important;
 }
 </style>
 
 <script>
 function viewOrderDetails(orderId) {
 jQuery('.modal-backdrop').remove();
 document.getElementById("modal-order-id").textContent = orderId;
 document.getElementById("order-details-content").innerHTML = "<p>Loading order details...</p>";
 
 document.body.appendChild(document.createElement("div")).className = "modal-backdrop";
 document.getElementById("order-details-modal").style.display = "block";
 document.body.classList.add("aoam-modal-open");
 
 jQuery.ajax({
 url: "<?php echo admin_url('admin-ajax.php'); ?>",
 type: "POST",
 data: {
 action: "aoam_get_moderator_order_details",
 order_id: orderId,
 nonce: "<?php echo wp_create_nonce('moderator_order_details'); ?>"
 },
 success: function(response) {
 if (response.success) {
 jQuery("#order-details-content").html(response.data);
 } else {
 jQuery("#order-details-content").html("<p>Error loading order details.</p>");
 }
 },
 error: function() {
 jQuery("#order-details-content").html("<p>Error loading order details.</p>");
 }
 });
 }
 
 function closeOrderModal() {
 var modal = document.getElementById("order-details-modal");
 if (modal) {
 modal.style.display = "none";
 }
 var backdrops = document.getElementsByClassName("modal-backdrop");
 while (backdrops.length > 0) {
 backdrops[0].remove();
 }
 document.body.classList.remove("aoam-modal-open");
 }
 
 document.addEventListener("click", function(e) {
 if (e.target.classList.contains("modal-backdrop")) {
 closeOrderModal();
 }
 });
 
 // // Auto-focus on search input when page loads
 // document.addEventListener('DOMContentLoaded', function() {
 // var searchInput = document.getElementById('phone_search_main');
 // if (searchInput && searchInput.value === '') {
 // searchInput.focus();
 // }
 // });
 </script>
 </div>
 <?php
}

// Handle AJAX order details.
add_action('wp_ajax_aoam_get_moderator_order_details', 'aoam_get_moderator_order_details');

function aoam_get_moderator_order_details() {
 // Check nonce first
 if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'moderator_order_details')) {
 wp_send_json_error('Security verification failed');
 return;
 }
 
 // Check if order_id is provided
 if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
 wp_send_json_error('Order ID is required');
 return;
 }
 
 $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
 $current_user = wp_get_current_user();
 
 // Get the order
 $order = wc_get_order($order_id);
 if (!$order) {
 wp_send_json_error('Order not found');
 return;
 }
 
 // Check permissions - allow admins and assigned moderators
 $assigned_moderator_id = get_post_meta($order_id, '_assigned_moderator_id', true);
 $is_admin = current_user_can('manage_options');
 $is_assigned_moderator = ($assigned_moderator_id == $current_user->ID); // FIXED: using $current_user instead of $user
 
 if (!$is_admin && !$is_assigned_moderator) {
 wp_send_json_error('Access denied - You are not assigned to this order');
 return;
 }
 
 // Start output buffering to capture the HTML
 ob_start();
 ?>
 
 <div class="order-details-container">
 
 <!-- Customer Information -->
 <div class="order-details-section">
 <h4> Customer Information</h4>
 <table style="width: 100%;">
 <tr>
 <td style="width: 30%;"><strong>Name:</strong></td>
 <td><?php echo esc_html($order->get_formatted_billing_full_name()); ?> 
 <button class="copy-btn" data-text="<?php echo esc_attr($order->get_formatted_billing_full_name()); ?>" title="Copy Name" aria-label="Copy Name">
 <span class="dashicons dashicons-admin-page"></span>
 </button></td>
 </tr>
 <tr>
 <td><strong>Phone:</strong></td>
 <td><a href="tel:<?php echo esc_attr($order->get_billing_phone()); ?>"><?php echo esc_html($order->get_billing_phone()); ?></a>
 <button class="copy-btn" data-text="<?php echo esc_attr($order->get_billing_phone()); ?>" title="Copy Phone" aria-label="Copy Phone">
 <span class="dashicons dashicons-admin-page"></span>
 </button>
 </td>
 </tr>
 <tr>
 <td><strong>Address:</strong></td>
 <td>
 <?php
 $address_parts = array_filter(array(
 $order->get_billing_address_1(),
 $order->get_billing_address_2(),
 trim($order->get_billing_city() . ', ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode(), ', '),
 WC()->countries->countries[$order->get_billing_country()] ?? $order->get_billing_country(),
 ));
 $full_address = implode(', ', $address_parts);
 echo esc_html($order->get_billing_address_1()) . '<br>';
 if ($order->get_billing_address_2()) {
 echo esc_html($order->get_billing_address_2()) . '<br>';
 }
 echo esc_html(trim($order->get_billing_city() . ', ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode(), ', '));
 ?><br>
 <?php echo esc_html(WC()->countries->countries[$order->get_billing_country()] ?? $order->get_billing_country()); ?>
 <button class="copy-btn" data-text="<?php echo esc_attr($full_address); ?>" title="Copy Address" aria-label="Copy Address">
 <span class="dashicons dashicons-admin-page"></span>
 </button>
 
 </td>
 </tr>
 </table>

 </div>
 
 <!-- Order Items -->
 <div class="order-details-section">
 <h4> Order Items</h4>
 <table style="width: 100%; border-collapse: collapse;">
 <thead>
 <tr style="background: #f1f1f1;">
 <th style="padding: 8px; text-align: left; border-bottom: 1px solid #ddd;">Product</th>
 <th style="padding: 8px; text-align: center; border-bottom: 1px solid #ddd;">Quantity</th>
 <th style="padding: 8px; text-align: right; border-bottom: 1px solid #ddd;">Price</th>
 <th style="padding: 8px; text-align: right; border-bottom: 1px solid #ddd;">Total</th>
 </tr>
 </thead>
 <tbody>
 <?php 
 foreach ($order->get_items() as $item_id => $item) : 
 $product = $item->get_product();
 ?>
 <tr>
 <td style="padding: 8px; border-bottom: 1px solid #eee;">
 <strong><?php echo esc_html($item->get_name()); ?></strong>
 <?php if ($product) : ?>
 <br><small>SKU: <?php echo esc_html($product && $product->get_sku() ? $product->get_sku() : 'N/A'); ?></small>
 <?php endif; ?>
 </td>
 <td style="padding: 8px; text-align: center; border-bottom: 1px solid #eee;">
 <?php echo esc_html($item->get_quantity()); ?>
 </td>
 <td style="padding: 8px; text-align: right; border-bottom: 1px solid #eee;">
 <?php echo wc_price($item->get_subtotal() / $item->get_quantity()); ?>
 </td>
 <td style="padding: 8px; text-align: right; border-bottom: 1px solid #eee;">
 <?php echo wc_price($item->get_total()); ?>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 <tfoot>
 <?php
 $subtotal = $order->get_subtotal();
 $shipping_total = $order->get_shipping_total();
 $tax_total = $order->get_total_tax();
 $order_total = $order->get_total();
 ?>
 <tr>
 <td colspan="3" style="padding: 8px; text-align: right; border-top: 2px solid #ddd;"><strong>Subtotal:</strong></td>
 <td style="padding: 8px; text-align: right; border-top: 2px solid #ddd;"><?php echo wc_price($subtotal); ?></td>
 </tr>
 <?php if ($shipping_total > 0) : ?>
 <tr>
 <td colspan="3" style="padding: 8px; text-align: right;"><strong>Shipping:</strong></td>
 <td style="padding: 8px; text-align: right;"><?php echo wc_price($shipping_total); ?></td>
 </tr>
 <?php endif; ?>
 <?php if ($tax_total > 0) : ?>
 <tr>
 <td colspan="3" style="padding: 8px; text-align: right;"><strong>Tax:</strong></td>
 <td style="padding: 8px; text-align: right;"><?php echo wc_price($tax_total); ?></td>
 </tr>
 <?php endif; ?>
 <tr>
 <td colspan="3" style="padding: 8px; text-align: right; font-weight: bold;"><strong>Total:</strong></td>
 <td style="padding: 8px; text-align: right; font-weight: bold;"><?php echo wc_price($order_total); ?></td>
 </tr>
 </tfoot>
 </table>
 </div>

 <!-- Quick Status Update & Moderator Change -->
 <div class="order-details-section">
 <h4> Quick Updates</h4>
 
 <!-- Status Update Form -->
 <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
 <h5 style="margin-top: 0;">Update Order Status</h5>
 <form method="post" id="update_status_form" style="display: flex; gap: 10px; align-items: center;">
 <?php wp_nonce_field('update_order_status', 'status_update_nonce'); ?>
 <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
 <input type="hidden" name="update_order_status" value="1">
 <select name="order_status" id="order_status_select" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
 <option value="">Select New Status</option>
 <option value="pending" <?php selected($order->get_status(), 'pending'); ?>>Pending</option>
 <option value="partial" <?php selected($order->get_status(), 'partial'); ?>>Partial</option>
 <option value="processing" <?php selected($order->get_status(), 'processing'); ?>>Processing</option>
 <option value="on-hold" <?php selected($order->get_status(), 'on-hold'); ?>>On Hold</option>
 <option value="completed" <?php selected($order->get_status(), 'completed'); ?>>Completed</option>
 <option value="cancelled" <?php selected($order->get_status(), 'cancelled'); ?>>Cancelled</option>
 </select>
 <button type="submit" class="button button-primary" id="update_status_btn">
 Update Status
 </button>
 </form>
 <p style="margin-top: 10px; font-size: 12px; color: #666;">
 Current status: <strong id="current_status_display"><?php echo wc_get_order_status_name($order->get_status()); ?></strong>
 </p>
 <div id="status_update_message" style="margin-top: 10px; display: none;"></div>
 </div>
 <?php
 $user = wp_get_current_user();
 if ( in_array( 'administrator', (array) $user->roles ) ) {
 ?>
 <!-- Moderator Change Form -->
 <div style="padding: 15px; background: #f0f6ff; border-radius: 5px;">
 <h5 style="margin-top: 0;">Change Assigned Moderator</h5>
 <?php
 // Get current moderator
 $current_moderator_id = get_post_meta($order_id, '_assigned_moderator_id', true);
 $current_moderator_name = get_post_meta($order_id, '_assigned_moderator_name', true);
 
 // Get only ACTIVE users with assigned roles
 $assigned_roles = aoam_get_assigned_roles();
 $moderators = get_users(array(
 'role__in' => $assigned_roles,
 'meta_query' => array(
 array(
 'key' => 'moderator_status',
 'value' => 'active',
 'compare' => '='
 )
 ),
 'orderby' => 'display_name'
 ));
 ?>
 
 <div style="margin-bottom: 10px;">
 <strong>Current Moderator:</strong>
 <?php if ($current_moderator_name): ?>
 <span style="color: #0073aa; font-weight: bold;"><?php echo esc_html($current_moderator_name); ?></span>
 <?php 
 $moderator_sequence = get_user_meta($current_moderator_id, 'moderator_sequence', true);
 if ($moderator_sequence) {
 echo ' <span style="color: #666;">(User ' . $moderator_sequence . ')</span>';
 }
 ?>
 <?php else: ?>
 <span style="color: #cc1818; font-style: italic;">Not assigned</span>
 <?php endif; ?>
 </div>
 
 <form method="post" id="change_moderator_form" style="display: flex; gap: 10px; align-items: center;">
 <?php wp_nonce_field('change_order_moderator', 'moderator_change_nonce'); ?>
 <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
 <input type="hidden" name="change_order_moderator" value="1">
 
 <select name="new_moderator_id" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; min-width: 200px;">
                    <option value="">Select New User</option>
 <?php foreach ($moderators as $moderator): 
 $moderator_sequence = get_user_meta($moderator->ID, 'moderator_sequence', true);
 $display_name = $moderator->display_name . ($moderator_sequence ? ' (User ' . $moderator_sequence . ')' : '');
 ?>
 <option value="<?php echo esc_attr($moderator->ID); ?>" <?php selected($current_moderator_id, $moderator->ID); ?>>
 <?php echo esc_html($display_name); ?>
 </option>
 <?php endforeach; ?>
 </select>
 
 <button type="submit" class="button" style="background: #0073aa; color: white; border-color: #0073aa;">
 Change User
 </button>
 </form>
 <div id="moderator_change_message" style="margin-top: 10px; display: none;"></div>
 </div>
 <?php } ?>
 </div>
 <script>
 jQuery(document).ready(function($) {
 // Initialize copy buttons for this modal
 function initCopyButtons() {
 $('.copy-btn').off('click').on('click', function(e) {
 e.preventDefault();
 e.stopPropagation();
 
 var $button = $(this);
 var textToCopy = $button.data('text');
 
 // Create temporary input for copying
 var $temp = $('<textarea>');
 $('body').append($temp);
 $temp.val(textToCopy).select();
 
 try {
 var successful = document.execCommand('copy');
 if (successful) {
 var originalHtml = $button.html();
 var originalColor = $button.css('color');
 
 $button.html('Copied');
 $button.css({
 'color': 'green',
 'border-color': 'green'
 });
 
 setTimeout(function() {
 $button.html(originalHtml);
 $button.css({
 'color': originalColor,
 'border-color': '#ddd'
 });
 }, 2000);
 } else {
 alert('');
 }
 } catch (err) {
 alert('');
 }
 
 $temp.remove();
 });
 }
 
 // Initialize copy buttons immediately
 initCopyButtons();
 });
 </script> 
 
 <style>
 .copy-btn {
 margin-left: 10px;
 background: #f8fafc;
 border: 1px solid #dbe3ea;
 border-radius: 8px;
 box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
 cursor: pointer;
 padding: 5px 7px;
 font-size: 14px;
 line-height: 1;
 vertical-align: middle;
 transition: all 0.3s ease;
 }
 .copy-btn .dashicons {
 width: 14px;
 height: 14px;
 font-size: 14px;
 line-height: 14px;
 }
 
 .copy-btn:hover {
 background-color: #eaf4ff;
 border-color: #91c7f3;
 color: #0a5f9e;
 }
 .order-details-container table {
 border-collapse: collapse;
 width: 100%;
 }
 .order-details-container td {
 padding: 7px 4px;
 vertical-align: top;
 }
 .order-details-container td:first-child {
 color: #374151;
 width: 120px;
 }
 .order-details-container thead tr {
 background: #f3f6f9 !important;
 }
 .order-details-container th {
 color: #374151;
 font-size: 13px;
 font-weight: 700;
 }
 .order-details-container tbody td {
 border-bottom: 1px solid #eef1f4 !important;
 }
 @media (max-width: 768px) {
 .aoam-order-modal {
 border-radius: 0;
 height: 100vh;
 max-height: 100vh;
 padding: 14px;
 width: 100vw;
 }
 .aoam-modal-header {
 margin-bottom: 14px;
 padding: 14px;
 }
 .aoam-modal-header h3 {
 font-size: 18px;
 }
 .order-details-section {
 border-radius: 10px;
 padding: 14px;
 }
 .order-details-container td:first-child {
 width: 82px;
 }
 .order-details-container th,
 .order-details-container td {
 font-size: 12px;
 }
 }
 
 
 @media (max-width: 768px) {
 .copy-btn {
 padding: 2px 6px;
 font-size: 12px;
 }
 }
 </style>
 <script>
 jQuery(document).ready(function($) {
 // Handle status update form submission
 $('#update_status_form').on('submit', function(e) {
 e.preventDefault();
 e.stopImmediatePropagation();
 
 var formData = $(this).serialize();
 var $button = $('#update_status_btn');
 var $message = $('#status_update_message');
 
 // Validate selection
 var selectedStatus = $('#order_status_select').val();
 if (!selectedStatus) {
 $message.html('<div style="color: #cc1818; padding: 8px; background: #ffe5e5; border-radius: 4px;">Please select a status</div>').show();
 return;
 }
 
 // Show loading state
 $button.text('Updating...').prop('disabled', true);
 $message.hide();
 
 $.ajax({
 url: '<?php echo admin_url('admin-ajax.php'); ?>',
 type: 'POST',
 data: formData + '&action=update_order_status_ajax',
 success: function(response) {
 if (response.success) {
 var updatedOrderId = $('#update_status_form input[name="order_id"]').val();
 var updatedStatus = $('#order_status_select').val();
 // Show success message
 $message.html('<div style="color: #46b450; padding: 8px; background: #e5f7e5; border-radius: 4px;"> ' + response.data.message + '</div>').show();
 
 // Update status display in MODAL ONLY
 $('#current_status_display').text(response.data.new_status_label);
 
 closeOrderModal();
 if (window.aoamHideModeratorOrderIfFiltered) {
 window.aoamHideModeratorOrderIfFiltered(updatedOrderId, updatedStatus);
 }
 if (window.aoamRefreshModeratorOrdersAfterUpdate) {
 window.aoamRefreshModeratorOrdersAfterUpdate();
 } else if (window.aoamRefreshModeratorOrders) {
 window.aoamRefreshModeratorOrders();
 } else if (window.aoamRefreshRecentAssignments) {
 window.aoamRefreshRecentAssignments();
 }
 
 } else {
 $message.html('<div style="color: #cc1818; padding: 8px; background: #ffe5e5; border-radius: 4px;"> ' + response.data + '</div>').show();
 $button.text('Update Status').prop('disabled', false);
 }
 },
 error: function(xhr, status, error) {
 $message.html('<div style="color: #cc1818; padding: 8px; background: #ffe5e5; border-radius: 4px;"> Network error. Please try again.</div>').show();
 $button.text('Update Status').prop('disabled', false);
 console.error('Status update error:', error);
 }
 });
 });
 
 // Handle moderator change form submission
 $('#change_moderator_form').on('submit', function(e) {
 e.preventDefault();
 e.stopImmediatePropagation();
 
 var formData = $(this).serialize();
 var $button = $(this).find('button[type="submit"]');
 
 // Validate selection
 var selectedModerator = $(this).find('select[name="new_moderator_id"]').val();
 if (!selectedModerator) {
 alert('Please select a user');
 return;
 }
 
 // Show loading state
 $button.text('Changing...').prop('disabled', true);
 
 $.ajax({
 url: '<?php echo admin_url('admin-ajax.php'); ?>',
 type: 'POST',
 data: formData + '&action=change_order_moderator_ajax',
 success: function(response) {
 if (response.success) {
 var message = response.data && response.data.message ? response.data.message : 'User changed successfully!';
 $('#moderator_change_message').html('<div style="color: #46b450; padding: 8px; background: #e5f7e5; border-radius: 4px;">' + message + '</div>').show();
 if (window.aoamRefreshModeratorOrdersAfterUpdate) {
 window.aoamRefreshModeratorOrdersAfterUpdate();
 } else if (window.aoamRefreshModeratorOrders) {
 window.aoamRefreshModeratorOrders();
 } else if (window.aoamRefreshRecentAssignments) {
 window.aoamRefreshRecentAssignments();
 }
 closeOrderModal();
 } else {
 alert('Error: ' + response.data);
 $button.text('Change User').prop('disabled', false);
 }
 },
 error: function() {
 alert('Network error. Please try again.');
 $button.text('Change User').prop('disabled', false);
 }
 });
 });
 });
 </script>
 </div>
 
 <style>
 .order-status-badge { 
 padding: 4px 8px; 
 border-radius: 3px; 
 font-size: 12px; 
 font-weight: bold;
 display: inline-block;
 }
 .status-pending { background: #ffb900; color: #000; }
 .status-partial { background: #777; color: #fff; }
 .status-processing { background: #00a0d2; color: #fff; }
 .status-on-hold { background: #cc1818; color: #fff; }
 .status-completed { background: #46b450; color: #fff; }
 .status-cancelled { background: #aaa; color: #fff; }
 </style>
 <?php
 
 $content = ob_get_clean();
 wp_send_json_success($content);
}


// AJAX handler for updating order status
add_action('wp_ajax_update_order_status_ajax', 'handle_update_order_status_ajax');

function handle_update_order_status_ajax() {
 // Check nonce
 if (!isset($_POST['status_update_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['status_update_nonce'])), 'update_order_status')) {
 wp_send_json_error('Security verification failed');
 return;
 }
 
 // Validate inputs
 if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
 wp_send_json_error('Order ID is required');
 return;
 }
 
 if (!isset($_POST['order_status']) || empty($_POST['order_status'])) {
 wp_send_json_error('Please select a status');
 return;
 }
 
 $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
 $new_status = isset($_POST['order_status']) ? sanitize_key(wp_unslash($_POST['order_status'])) : '';
 $current_user = wp_get_current_user();
 
 // Get the order
 $order = wc_get_order($order_id);
 if (!$order) {
 wp_send_json_error('Order not found');
 return;
 }
 
 // Check permissions
 $assigned_moderator_id = get_post_meta($order_id, '_assigned_moderator_id', true);
 $is_admin = current_user_can('manage_options');
 $is_assigned_moderator = ($assigned_moderator_id == $current_user->ID);
 
 if (!$is_admin && !$is_assigned_moderator) {
 wp_send_json_error('Access denied - You are not assigned to this order');
 return;
 }
 
 // Get current status before update
 $old_status = $order->get_status();
 
 // Valid statuses
 $valid_statuses = array('partial', 'pending', 'processing', 'on-hold', 'completed', 'cancelled');
 if (!in_array($new_status, $valid_statuses)) {
 wp_send_json_error('Invalid order status');
 return;
 }
 
 // Update order status
 $order->update_status($new_status);
 
 // Add order note
 $order_note = sprintf(
 'Order status changed from %s to %s by %s: %s',
 $old_status,
 $new_status,
 $is_admin ? 'admin' : 'moderator',
 $current_user->display_name
 );
 
 $order->add_order_note($order_note);
 
 // Return success with new status info
 $response = array(
 'message' => 'Status updated successfully!',
 'new_status' => $new_status,
 'new_status_label' => wc_get_order_status_name($new_status),
 'old_status' => $old_status
 );
 
 wp_send_json_success($response);
}

// AJAX handler for changing order moderator
add_action('wp_ajax_change_order_moderator_ajax', 'handle_change_order_moderator_ajax');

function handle_change_order_moderator_ajax() {
 // Check nonce
 if (!isset($_POST['moderator_change_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['moderator_change_nonce'])), 'change_order_moderator')) {
 wp_send_json_error('Security check failed');
 }
 
 // Check permissions
 if (!current_user_can('manage_options')) {
 wp_send_json_error('Insufficient permissions. Only administrators can change moderators.');
 }
 
 // Validate inputs
 if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
 wp_send_json_error('Order ID is required');
 }
 
 if (!isset($_POST['new_moderator_id']) || empty($_POST['new_moderator_id'])) {
 wp_send_json_error('Please select a moderator');
 }
 
 $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
 $new_moderator_id = isset($_POST['new_moderator_id']) ? absint($_POST['new_moderator_id']) : 0;
 
 // Get order and moderator
 $order = wc_get_order($order_id);
 $moderator = get_userdata($new_moderator_id);
 
 if (!$order) {
 wp_send_json_error('Order not found');
 }
 
 if (!$moderator) {
 wp_send_json_error('Moderator not found');
 }
 
 // Get current moderator info before change
 $old_moderator_id = get_post_meta($order_id, '_assigned_moderator_id', true);
 $old_moderator_name = get_post_meta($order_id, '_assigned_moderator_name', true);
 
 // Update moderator information
 update_post_meta($order_id, '_assigned_moderator_id', $new_moderator_id);
 update_post_meta($order_id, '_assigned_moderator_name', $moderator->display_name);
 
 // Get sequence info
 $new_moderator_sequence = get_user_meta($new_moderator_id, 'moderator_sequence', true);
 
 // Add order note
 $order_note = sprintf(
 'Assigned moderator changed from %s to %s (Moderator %s) by admin: %s',
 $old_moderator_name ?: 'None',
 $moderator->display_name,
 $new_moderator_sequence ?: 'N/A',
 wp_get_current_user()->display_name
 );
 
 $order->add_order_note($order_note);
 
 wp_send_json_success(array(
 'message' => 'User changed successfully.',
 'new_moderator_id' => $new_moderator_id,
 'new_moderator_name' => $moderator->display_name,
 'new_moderator_sequence' => $new_moderator_sequence,
 ));
}


// Remove WooCommerce menus for moderators
add_action('admin_menu', 'remove_woocommerce_menus_for_moderators', 999);

function remove_woocommerce_menus_for_moderators() {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 remove_menu_page('woocommerce');
 remove_menu_page('edit.php?post_type=shop_order');
 remove_menu_page('wc-admin');
 remove_menu_page('woocommerce-marketing');
 remove_menu_page('admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM');
 remove_menu_page('tools.php');
 remove_menu_page('edit-comments.php');
 remove_menu_page('edit.php');
 remove_menu_page('edit.php?post_type=post-wcode');
 remove_menu_page('admin.php?page=awcfe_admin_settings');
 remove_menu_page('admin.php?page=wpcf7');
 remove_menu_page('admin.php?page=yith_wcan_panel');
 }
}


// Alternative: Add moderator section after order details
add_action('woocommerce_admin_order_data_after_order_details', 'add_moderator_section_after_order_details');

function add_moderator_section_after_order_details($order) {
 $order_id = $order->get_id();
 $current_moderator_id = get_post_meta($order_id, '_assigned_moderator_id', true);
 $current_moderator_name = get_post_meta($order_id, '_assigned_moderator_name', true);
 
 ?>
 <div class="order_data_column" style="width: 100%; margin: 20px 0; padding: 20px; background: white; border: 1px solid #ccd0d4; border-radius: 4px;">
        <h3 style="margin-top: 0; color: #0073aa;">Assign Moderator</h3>
 
 <div style="margin-bottom: 15px;">
 <p><strong>Current Moderator:</strong></p>
 <?php if ($current_moderator_name): ?>
 <div style="padding: 10px; background: #f0f6fc; border-radius: 4px; border-left: 4px solid #0073aa;">
 <strong style="font-size: 16px; color: #0073aa;"><?php echo esc_html($current_moderator_name); ?></strong>
 <?php 
 $moderator_sequence = get_user_meta($current_moderator_id, 'moderator_sequence', true);
 if ($moderator_sequence) {
 echo ' <span style="color: #666;">(Moderator ' . $moderator_sequence . ')</span>';
 }
 ?>
 </div>
 <?php else: ?>
 <div style="padding: 10px; background: #fff8e1; border-radius: 4px; border-left: 4px solid #ffb900;">
 <span style="color: #cc1818; font-style: italic;">Not assigned to any moderator</span>
 </div>
 <?php endif; ?>
 </div>
 
 <?php
 // Get all active moderators
 $moderators = get_users(array(
 'role' => 'moderator',
 'meta_key' => 'moderator_status',
 'meta_value' => 'active',
 'orderby' => 'display_name'
 ));
 ?>
 
 <div class="form-field">
 <label for="assigned_moderator_direct"><strong>Change Moderator:</strong></label>
 <select name="assigned_moderator_direct" id="assigned_moderator_direct" style="width: 100%; margin-top: 5px;">
 <option value=""> Select New Moderator </option>
 <?php foreach ($moderators as $moderator): 
 $moderator_sequence = get_user_meta($moderator->ID, 'moderator_sequence', true);
 $display_name = $moderator->display_name . ($moderator_sequence ? ' (Moderator ' . $moderator_sequence . ')' : '');
 ?>
 <option value="<?php echo esc_attr($moderator->ID); ?>" <?php selected($current_moderator_id, $moderator->ID); ?>>
 <?php echo esc_html($display_name); ?>
 </option>
 <?php endforeach; ?>
 </select>
 </div>
 
 <p style="margin-top: 15px;">
 <button type="button" id="update_moderator_direct" class="button button-primary">
 Update Moderator
 </button>
 </p>
 </div>
 
 <script>
 jQuery(document).ready(function($) {
 $('#update_moderator_direct').click(function() {
 var newModeratorId = $('#assigned_moderator_direct').val();
 var orderId = <?php echo wp_json_encode(absint($order_id)); ?>;
 
 if (!newModeratorId) {
 alert(' Please select a moderator.');
 return;
 }
 
 if (confirm('Are you sure you want to change the assigned moderator?')) {
 var $button = $(this);
 $button.text('Updating...').prop('disabled', true);
 
 $.ajax({
 url: '<?php echo admin_url('admin-ajax.php'); ?>',
 type: 'POST',
 data: {
 action: 'update_order_moderator_direct',
 order_id: orderId,
 moderator_id: newModeratorId,
 nonce: '<?php echo wp_create_nonce('direct_update_moderator'); ?>'
 },
 success: function(response) {
 if (response.success) {
 alert(' Moderator updated successfully!');
 location.reload();
 } else {
 alert(' Error: ' + response.data);
 $button.text(' Update Moderator').prop('disabled', false);
 }
 },
 error: function() {
 alert(' Network error. Please try again.');
 $button.text(' Update Moderator').prop('disabled', false);
 }
 });
 }
 });
 });
 </script>
 <?php
 }
 
 // AJAX handler for direct update
 add_action('wp_ajax_update_order_moderator_direct', 'handle_update_order_moderator_direct');
 
 function handle_update_order_moderator_direct() {
 if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'direct_update_moderator')) {
 wp_send_json_error('Security check failed');
 }
 
 if (!current_user_can('manage_options')) {
 wp_send_json_error('Insufficient permissions');
 }
 
 $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
 $moderator_id = isset($_POST['moderator_id']) ? absint($_POST['moderator_id']) : 0;
 
 $order = wc_get_order($order_id);
 $moderator = get_userdata($moderator_id);
 
 if (!$order || !$moderator) {
 wp_send_json_error('Invalid order or moderator');
 }
 
 // Get current moderator before update
 $old_moderator_id = get_post_meta($order_id, '_assigned_moderator_id', true);
 $old_moderator_name = get_post_meta($order_id, '_assigned_moderator_name', true);
 
 // Update moderator information
 update_post_meta($order_id, '_assigned_moderator_id', $moderator_id);
 update_post_meta($order_id, '_assigned_moderator_name', $moderator->display_name);
 
 // Get moderator sequence for note
 $moderator_sequence = get_user_meta($moderator_id, 'moderator_sequence', true);
 
 // Add order note
 $order_note = sprintf(
 'Assigned moderator changed from %s to %s (Moderator %s) by admin: %s',
 $old_moderator_name ?: 'None',
 $moderator->display_name,
 $moderator_sequence ?: 'N/A',
 wp_get_current_user()->display_name
 );
 
 $order->add_order_note($order_note);
 
 wp_send_json_success('Moderator updated successfully');
 }
 
 // Remove all unnecessary admin menus for moderators
 add_action('admin_menu', 'remove_admin_menus_for_moderators', 9999);
 
 function remove_admin_menus_for_moderators() {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 // KEEP THESE MENUS:
 // - Dashboard (index.php) - Already kept by default
 // - My Orders (digitrix-orderflow-my-orders) - Our custom page
 // - Profile (profile.php) - User profile
 
 // REMOVE ALL OTHER MENUS:
 global $menu, $submenu;
 
 $allowed_menus = array(
 'index.php', // Dashboard
 'digitrix-orderflow-my-orders', // My Orders
 'profile.php', // Profile
 'separator1', // Separators (optional)
 'separator2',
 'separator-last'
 );
 
 // Remove top-level menus
 foreach ($menu as $menu_key => $menu_item) {
 $menu_slug = $menu_item[2];
 
 // Remove if not in allowed list
 if (!in_array($menu_slug, $allowed_menus)) {
 remove_menu_page($menu_slug);
 }
 }
 
 // Specifically remove common menus that might not be caught above
 $menus_to_remove = array(
 // WordPress Core
 'edit.php', // Posts
 'upload.php', // Media
 'edit.php?post_type=page', // Pages
 'edit-comments.php', // Comments
 'themes.php', // Appearance
 'plugins.php', // Plugins
 'users.php', // Users
 'tools.php', // Tools
 'options-general.php', // Settings
 
 // WooCommerce
 'woocommerce',
 'edit.php?post_type=shop_order',
 'wc-admin',
 'woocommerce-marketing',
 'wc-admin&path=/analytics/overview',
 'wc-reports',
 'wc-settings',
 'wc-status',
 'wc-addons',
 
 // Other common plugins
 'jetpack',
 'akismet-key-config',
 'wpseo_dashboard',
 
 // Our plugin's admin pages (except My Orders)
 'moderator-settings',
 'moderator-recent-assignments', 
 'moderator-sequence-status',
 'moderator-product-assignments'
 );
 
 foreach ($menus_to_remove as $menu_slug) {
 remove_menu_page($menu_slug);
 }
 }
 }
 
 // Remove admin bar items for moderators - UPDATED WITH LOGOUT
 add_action('wp_before_admin_bar_render', 'remove_admin_bar_items_for_moderators', 999);
 
 function remove_admin_bar_items_for_moderators() {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 global $wp_admin_bar;
 
 // Keep only these admin bar items
 $allowed_nodes = array(
 'site-name',
 'view-site', 
 'edit-profile',
 'user-actions',
 'user-info',
 'logout', // KEEP LOGOUT
 'my-account' // KEEP MY ACCOUNT
 );
 
 $nodes = $wp_admin_bar->get_nodes();
 
 foreach ($nodes as $node_id => $node) {
 if (!in_array($node_id, $allowed_nodes)) {
 $wp_admin_bar->remove_node($node_id);
 }
 }
 
 // Add custom "My Orders" link to admin bar
 $wp_admin_bar->add_node(array(
 'id' => 'moderator-orders',
 'title' => ' My Orders',
 'href' => admin_url('admin.php?page=digitrix-orderflow-my-orders'),
 'parent' => 'site-name'
 ));
 
 // Custom logout link in a better position.
 $wp_admin_bar->add_node(array(
 'id' => 'moderator-logout',
 'title' => ' Logout',
 'href' => wp_logout_url(),
 'parent' => 'top-secondary',
 'meta' => array(
 'title' => 'Logout from the system'
 )
 ));
 }
 }
 
 // Redirect moderators from restricted pages
 add_action('admin_init', 'redirect_moderators_from_restricted_pages');
 
 function redirect_moderators_from_restricted_pages() {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 $current_page = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
 
 // Allowed pages for moderators
 $allowed_pages = array(
 '/wp-admin/index.php', // Dashboard
 '/wp-admin/profile.php', // Profile
 '/wp-admin/admin.php?page=digitrix-orderflow-my-orders', // My Orders
 '/wp-admin/admin-ajax.php', // AJAX calls
 );
 
 // Check if current page is not allowed
 $is_allowed = false;
 foreach ($allowed_pages as $allowed_page) {
 if (strpos($current_page, $allowed_page) !== false) {
 $is_allowed = true;
 break;
 }
 }
 
 // If not allowed, redirect to My Orders page
 if (!$is_allowed && strpos($current_page, '/wp-admin/') !== false) {
 wp_safe_redirect(admin_url('admin.php?page=digitrix-orderflow-my-orders'));
 exit;
 }
 }
 }
 
 // Customize the admin dashboard for moderators - ENHANCED VERSION
 add_action('wp_dashboard_setup', 'customize_dashboard_for_moderators', 9999);
 
 function customize_dashboard_for_moderators() {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 global $wp_meta_boxes;
 
 // COMPLETELY CLEAR ALL DASHBOARD WIDGETS
 $wp_meta_boxes['dashboard'] = array();
 
 // Remove WooCommerce specific widgets
 remove_meta_box('woocommerce_dashboard_status', 'dashboard', 'normal');
 remove_meta_box('wc_admin_dashboard_setup', 'dashboard', 'normal');
 remove_meta_box('dashboard_woocommerce_setup', 'dashboard', 'normal');
 remove_meta_box('ecommerce_dashboard_widget', 'dashboard', 'normal');
 
 // Remove other common widgets
 remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
 remove_meta_box('dashboard_activity', 'dashboard', 'normal');
 remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
 remove_meta_box('dashboard_primary', 'dashboard', 'side');
 remove_meta_box('dashboard_site_health', 'dashboard', 'normal');
 remove_meta_box('dashboard_php_nag', 'dashboard', 'normal');
 
 // Remove any remaining WooCommerce widgets
 remove_action('wp_dashboard_setup', array('WC_Admin', 'init_dashboard'));
 
 // Add custom moderator dashboard widgets
 wp_add_dashboard_widget(
 'moderator_orders_widget',
 ' My Order Statistics',
 'moderator_orders_dashboard_widget'
 );
 
 wp_add_dashboard_widget(
 'moderator_quick_actions',
 ' Quick Actions',
 'moderator_quick_actions_widget'
 );
 
 wp_add_dashboard_widget(
 'moderator_recent_orders',
 ' Recent Orders',
 'moderator_recent_orders_widget'
 );
 }
 }
// Recent orders widget for moderator dashboard
function moderator_recent_orders_widget() {
 $current_user = wp_get_current_user();
 $moderator_id = $current_user->ID;
 
 // Get recent orders
 global $wpdb;
 $recent_orders = $wpdb->get_results($wpdb->prepare("
 SELECT p.ID, p.post_date, p.post_status 
 FROM {$wpdb->postmeta} pm 
 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
 WHERE pm.meta_key = '_assigned_moderator_id' 
 AND pm.meta_value = %d
 ORDER BY p.post_date DESC 
 LIMIT 5
 ", $moderator_id));
 
 if (empty($recent_orders)) {
 echo '<p>No orders assigned yet.</p>';
 return;
 }
 
 echo '<div style="max-height: 300px; overflow-y: auto;">';
 echo '<table style="width: 100%; border-collapse: collapse;">';
 
 foreach ($recent_orders as $order_data) {
 $order = wc_get_order($order_data->ID);
 if (!$order) continue;
 
 $order_status = $order->get_status();
 $status_class = 'status-' . $order_status;
 $status_label = wc_get_order_status_name($order_status);
 $order_timestamp = strtotime($order_data->post_date);
 $order_date = aoam_format_display_date('M j, g:i A', $order_timestamp);
 $is_today = aoam_format_display_date('Y-m-d', $order_timestamp) === aoam_format_display_date('Y-m-d');
 
 echo '<tr style="border-bottom: 1px solid #f0f0f0;">';
 echo '<td style="padding: 8px 0;">';
 echo '<strong>#' . $order->get_id() . '</strong>';
 if ($is_today) {
 echo ' <span style="color: #46b450; font-size: 10px;"></span>';
 }
 echo '<br><small style="color: #666;">' . $order_date . '</small>';
 echo '</td>';
 echo '<td style="padding: 8px 0; text-align: right;">';
 echo '<span class="order-status-badge-small ' . $status_class . '">' . $status_label . '</span>';
 echo '</td>';
 echo '</tr>';
 }
 
 echo '</table>';
 echo '</div>';
 
 echo '<div style="margin-top: 10px; text-align: center;">';
 echo '<a href="' . admin_url('admin.php?page=digitrix-orderflow-my-orders') . '" class="button button-small">View All Orders</a>';
 echo '</div>';
 
 ?>
 <style>
 .order-status-badge-small {
 padding: 2px 6px;
 border-radius: 3px;
 font-size: 10px;
 font-weight: bold;
 display: inline-block;
 }
 .status-pending { background: #ffb900; color: #000; }
 .status-partial { background: #777; color: #fff; }
 .status-processing { background: #00a0d2; color: #fff; }
 .status-on-hold { background: #cc1818; color: #fff; }
 .status-completed { background: #46b450; color: #fff; }
 .status-cancelled { background: #aaa; color: #fff; }
 </style>
 <?php
}
// Remove WooCommerce admin bar menu for moderators
add_action('wp_before_admin_bar_render', 'remove_woocommerce_admin_bar', 999);

function remove_woocommerce_admin_bar() {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 global $wp_admin_bar;
 $wp_admin_bar->remove_node('woocommerce');
 $wp_admin_bar->remove_node('wc-admin');
 }
}

// Remove WooCommerce admin footer for moderators
add_filter('admin_footer_text', 'remove_woocommerce_admin_footer', 999);

function remove_woocommerce_admin_footer($text) {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 // Remove WooCommerce footer text
 $text = 'Thank you for using WordPress as a Moderator.';
 }
 
 return $text;
}

// Disable WooCommerce admin for moderators
add_action('admin_init', 'disable_woocommerce_admin_for_moderators');

function disable_woocommerce_admin_for_moderators() {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 // Disable WooCommerce admin
 add_filter('woocommerce_admin_disabled', '__return_true');
 add_filter('woocommerce_marketing_menu_items', '__return_empty_array');
 add_filter('woocommerce_admin_features', '__return_empty_array');
 }
}

// Custom dashboard widget showing order statistics
function moderator_orders_dashboard_widget() {
 $current_user = wp_get_current_user();
 $moderator_id = $current_user->ID;
 
 // Get today's orders count
 global $wpdb;
 $today = aoam_format_display_date('Y-m-d');
 
 $today_orders = $wpdb->get_var($wpdb->prepare("
 SELECT COUNT(pm.post_id) 
 FROM {$wpdb->postmeta} pm 
 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
 WHERE pm.meta_key = '_assigned_moderator_id' 
 AND pm.meta_value = %d
 AND DATE(p.post_date) = %s
 ", $moderator_id, $today));
 
 // Get total pending orders
 $pending_orders = $wpdb->get_var($wpdb->prepare("
 SELECT COUNT(pm.post_id) 
 FROM {$wpdb->postmeta} pm 
 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
 WHERE pm.meta_key = '_assigned_moderator_id' 
 AND pm.meta_value = %d
 AND p.post_status = 'wc-pending'
 ", $moderator_id));
 
 // Get total partial orders
 $partial_orders = $wpdb->get_var($wpdb->prepare("
 SELECT COUNT(pm.post_id) 
 FROM {$wpdb->postmeta} pm 
 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
 WHERE pm.meta_key = '_assigned_moderator_id' 
 AND pm.meta_value = %d
 AND p.post_status = 'wc-partial'
 ", $moderator_id));
 
 // Get total processing orders
 $processing_orders = $wpdb->get_var($wpdb->prepare("
 SELECT COUNT(pm.post_id) 
 FROM {$wpdb->postmeta} pm 
 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
 WHERE pm.meta_key = '_assigned_moderator_id' 
 AND pm.meta_value = %d
 AND p.post_status = 'wc-processing'
 ", $moderator_id));
 
 // Get total assigned orders
 $total_orders = $wpdb->get_var($wpdb->prepare("
 SELECT COUNT(post_id) 
 FROM {$wpdb->postmeta} 
 WHERE meta_key = '_assigned_moderator_id' 
 AND meta_value = %d
 ", $moderator_id));
 
 ?>
 <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 15px;">
 <div style="text-align: center; padding: 15px; background: #e7f3ff; border-radius: 8px;">
 <div style="font-size: 24px; font-weight: bold; color: #0073aa;"><?php echo $today_orders; ?></div>
 <div style="font-size: 12px;">Today's Orders</div>
 </div>
 <div style="text-align: center; padding: 15px; background: #fff8e1; border-radius: 8px;">
 <div style="font-size: 24px; font-weight: bold; color: #ffb900;"><?php echo $pending_orders; ?></div>
 <div style="font-size: 12px;">Pending</div>
 </div>
 <div style="text-align: center; padding: 15px; background: #fff8e1; border-radius: 8px;">
 <div style="font-size: 24px; font-weight: bold; color: #ffb900;"><?php echo $partial_orders; ?></div>
 <div style="font-size: 12px;">Partial</div>
 </div>
 <div style="text-align: center; padding: 15px; background: #e5f7e5; border-radius: 8px;">
 <div style="font-size: 24px; font-weight: bold; color: #46b450;"><?php echo $processing_orders; ?></div>
 <div style="font-size: 12px;">Processing</div>
 </div>
 <div style="text-align: center; padding: 15px; background: #f0f0f0; border-radius: 8px;">
 <div style="font-size: 24px; font-weight: bold; color: #666;"><?php echo $total_orders; ?></div>
 <div style="font-size: 12px;">Total Assigned</div>
 </div>
 </div>
 
 <div style="text-align: center; margin-top: 10px;">
 <a href="<?php echo admin_url('admin.php?page=digitrix-orderflow-my-orders'); ?>" class="button button-primary">
 View All My Orders
 </a>
 </div>
 <?php
}

// Quick actions widget
function moderator_quick_actions_widget() {
 ?>
 <div style="display: grid; grid-template-columns: 1fr; gap: 8px;">
 <a href="<?php echo admin_url('admin.php?page=digitrix-orderflow-my-orders&status=pending'); ?>" class="button" style="text-align: center; padding: 10px;">
 View Pending Orders
 </a>
 <a href="<?php echo admin_url('admin.php?page=digitrix-orderflow-my-orders&status=partial'); ?>" class="button" style="text-align: center; padding: 10px;">
 View Partial Orders
 </a>
 <a href="<?php echo admin_url('admin.php?page=digitrix-orderflow-my-orders&date_filter=today'); ?>" class="button" style="text-align: center; padding: 10px;">
 Today's Orders
 </a>
 <a href="<?php echo admin_url('profile.php'); ?>" class="button" style="text-align: center; padding: 10px;">
 Edit Profile
 </a>
 </div>
 
 <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
 <p style="margin: 0; font-size: 12px; color: #666;">
 <strong>Need Help?</strong><br>
 Contact administrator for any issues with order assignments.
 </p>
 </div>
 <?php
}

// Remove screen options and help tabs for moderators
add_action('admin_head', 'remove_screen_options_for_moderators');

function remove_screen_options_for_moderators() {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 echo '<style>#screen-options-link-wrap { display: none !important; }</style>';
 }
}
// Add custom CSS for moderator admin - ENHANCED
add_action('admin_head', 'moderator_admin_custom_css');

function moderator_admin_custom_css() {
 $current_user = wp_get_current_user();
 
 if (in_array('moderator', $current_user->roles)) {
 ?>
 <style>
 /* Hide ALL WooCommerce and unnecessary elements */
 #woocommerce_dashboard_status,
 #wc_admin_dashboard_setup,
 #dashboard_woocommerce_setup,
 #ecommerce_dashboard_widget,
 .woocommerce-message,
 .woocommerce-setup-wrapper,
 .wc-setup,
 #wpadminbar #wp-admin-bar-woocommerce,
 #wpadminbar #wp-admin-bar-wc-admin,
 #wpadminbar #wp-admin-bar-new-content,
 #wpadminbar #wp-admin-bar-comments,
 #wpadminbar #wp-admin-bar-updates,
 #wpadminbar #wp-admin-bar-search,
 .welcome-panel,
 #dashboard-widgets-wrap .welcome-panel,
 #screen-meta-links,
 #contextual-help-link-wrap,
 .woocommerce-layout__header,
 .woocommerce-page,
 .post-type-shop_order #wpbody-content .wrap h1,
 .post-type-product #wpbody-content .wrap h1 {
 display: none !important;
 }
 
 /* Custom styling for moderator dashboard */
 .moderator-dashboard-welcome {
 background: #f0f6ff;
 padding: 20px;
 border-radius: 8px;
 margin: 20px 0;
 border-left: 4px solid #0073aa;
 }
 
 /* Clean dashboard layout */
 #dashboard-widgets-wrap {
 padding: 10px;
 }
 
 .postbox {
 border-radius: 8px;
 border: 1px solid #ccd0d4;
 }
 
 .postbox .hndle {
 background: #f8f9fa;
 border-bottom: 1px solid #ccd0d4;
 font-size: 14px;
 font-weight: 600;
 }
 #moderator_recent_orders.postbox .inside {
 padding: 10px !important;
 }
 /* Simplify the admin menu */
 #adminmenuwrap {
 width: 160px;
 }
 #adminmenu li a {
 font-size: 14px;
 padding: 8px 12px;
 }
 
 /* Hide update notifications */
 .update-nag,
 .notice:not(.moderator-notice) {
 display: none !important;
 }
 
 /* Ensure our widgets are prominent */
 #moderator_orders_widget .inside,
 #moderator_quick_actions .inside,
 #moderator_recent_orders .inside {
 padding: 0 !important;
 margin: 0 !important;
 }
 </style>
 <?php
 }
}


// Custom welcome panel for moderators
add_action('admin_notices', 'moderator_welcome_panel');

function moderator_welcome_panel() {
 $current_user = wp_get_current_user();
 $current_screen = get_current_screen();
 
 if (in_array('moderator', $current_user->roles) && $current_screen->id === 'dashboard') {
 ?>
 <div class="moderator-dashboard-welcome">
 <h2> Welcome, <?php echo esc_html($current_user->display_name); ?>!</h2>
 <p>You are logged in as a <strong>Moderator</strong>. Here's what you can do:</p>
 <ul>
 <li> <strong>View and manage</strong> your assigned orders in "My Orders"</li>
 <li> <strong>Update order status</strong> and track order progress</li>
 <li> <strong>Update your profile</strong> information</li>
 </ul>
 <p>Use the quick action buttons above to get started!</p>
 </div>
 <?php
 }
}

function moderator_reassign_orders_page() {
 if (!current_user_can('manage_options')) {
 wp_die(esc_html__('You do not have permission to access this page.', 'digitrix-orderflow-for-woocommerce'));
 }
 
 // Get assigned roles
 $assigned_roles = aoam_get_assigned_roles();
 
 // Process form submission
 if (aoam_is_post_request() && isset($_POST['reassign_orders'])) {
 $result = process_reassignment_form();
 
 if ($result['success']) {
 echo '<div class="notice notice-success is-dismissible">';
 echo '<p>' . esc_html($result['message']) . '</p>';
 if (isset($result['details'])) {
 echo '<pre style="background: #f8f9fa; padding: 10px; margin: 10px 0;">' . esc_html($result['details']) . '</pre>';
 }
 echo '</div>';
 } else {
 echo '<div class="notice notice-error is-dismissible">';
 echo '<p>' . esc_html($result['message']) . '</p>';
 echo '</div>';
 }
 }
 
 // Get users
 $all_users = get_users(array(
 'role__in' => $assigned_roles,
 'orderby' => 'display_name',
 'order' => 'ASC',
 'number' => -1
 ));
 
 $active_users = array();
 $inactive_users = array();
 $all_reassign_users = array();
 
 foreach ($all_users as $user) {
 $status = get_user_meta($user->ID, 'moderator_status', true);
 $user_data = array(
 'ID' => $user->ID,
 'display_name' => $user->display_name,
 'user_email' => $user->user_email,
 'sequence' => get_user_meta($user->ID, 'moderator_sequence', true)
 );
 
 if ($status === 'inactive') {
 $inactive_users[] = $user_data;
 } else {
 $active_users[] = $user_data;
 }
 
 $user_data['status'] = ($status === 'inactive') ? 'inactive' : 'active';
 $all_reassign_users[] = $user_data;
 }
 
 // Get shift settings
 $shift_settings = aoam_get_shift_settings();
 
 // Order statuses
 $order_status_options = array(
 'pending' => 'Pending',
 'partial' => 'Partial', 
 'processing' => 'Processing',
 'on-hold' => 'On Hold'
 );
 ?>
 
 <div class="wrap">
 <h1>Reassign Orders</h1>
 
 <!-- Navigation -->
 <div class="nav-tab-wrapper">
 <a href="<?php echo admin_url('admin.php?page=moderator-settings'); ?>" class="nav-tab">Dashboard</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-recent-assignments'); ?>" class="nav-tab">Recent Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-sequence-status'); ?>" class="nav-tab">Sequence & Status</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-product-assignments'); ?>" class="nav-tab">Product Assignments</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-reassign-orders'); ?>" class="nav-tab nav-tab-active">Reassign</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-plugin-settings'); ?>" class="nav-tab">Plugin Settings</a>
 <a href="<?php echo admin_url('admin.php?page=moderator-remote-import'); ?>" class="nav-tab">Remote Import</a>
 </div>

 <div class="card">
 <h2> Bulk Order Reassignment</h2>
 <p>Transfer orders from selected source users to active target users.</p>
 
 <!-- Hidden form for actual submission -->
 <form method="post" id="reassign-main-form" style="display: none;">
 <?php wp_nonce_field('reassign_orders_main', 'reassign_nonce_main'); ?>
 <input type="hidden" name="inactive_user_data" id="inactive_user_data">
 <input type="hidden" name="active_users_data" id="active_users_data">
 <input type="hidden" name="order_statuses_data" id="order_statuses_data">
 <input type="hidden" name="selected_shifts_data" id="selected_shifts_data">
 <input type="submit" name="reassign_orders" id="reassign_orders_submit">
 </form>
 
 <!-- Step 1: Select Source Users -->
 <div class="reassign-step" id="step-1">
 <h3><span class="step-number">1</span> Select Source Users</h3>
 <p>Choose one or more users whose orders should be reassigned. Active and inactive users can be selected here.</p>
 
 <?php if (empty($all_reassign_users)): ?>
 <div class="notice notice-warning">
 <p>No users found for reassignment.</p>
 <a href="<?php echo admin_url('admin.php?page=moderator-sequence-status'); ?>" class="button">
 Go to Sequence & Status
 </a>
 </div>
 <?php else: ?>
 <div class="user-grid">
 <?php foreach ($all_reassign_users as $user): 
 $order_count = count_user_orders($user['ID']);
 $status_counts = get_user_order_status_counts($user['ID']);
 $user_initial = strtoupper(substr($user['display_name'], 0, 1));
 $is_inactive = ($user['status'] === 'inactive');
 ?>
 <div class="user-card source-user-card" data-user-id="<?php echo esc_attr($user['ID']); ?>">
 <div class="user-card-header">
 <div class="user-avatar" style="background: <?php echo $is_inactive ? '#dc3232' : '#46b450'; ?>;position: relative;border-radius: 50px;overflow: hidden;">
 <span class="aoam-user-initial"><?php echo esc_html($user_initial); ?></span>
 </div>
 <div class="user-info">
 <h4><?php echo esc_html($user['display_name']); ?></h4>
 <p class="user-meta">User <?php echo esc_html($user['sequence']); ?> <?php echo esc_html($user['user_email']); ?></p>
 <span class="aoam-user-status <?php echo $is_inactive ? 'is-inactive' : 'is-active'; ?>"><?php echo $is_inactive ? 'Inactive' : 'Active'; ?></span>
 </div>
 </div>
 
 <label class="checkbox-container">
 <input type="checkbox" class="source-user-checkbox" value="<?php echo esc_attr($user['ID']); ?>">
 <span class="checkmark"></span>
 Select Source User
 </label>
 </div>
 <?php endforeach; ?>
 </div>
 <div style="margin-top: 20px;">
 <button type="button" class="button" id="select-all-source-btn">Select All</button>
 <button type="button" class="button" id="deselect-all-source-btn">Deselect All</button>
 </div>
 <div style="margin-top: 30px; text-align: right;">
 <button type="button" class="button button-primary" onclick="goToStep(2)">Next </button>
 </div>
 <?php endif; ?>
 </div>
 
 <!-- Step 2: Select Active Users -->
 <div class="reassign-step" id="step-2" style="display: none;">
 <h3><span class="step-number">2</span> Select Active Users</h3>
 <p>Choose active users who will receive the reassigned orders:</p>
 
 <div class="user-grid">
 <?php foreach ($active_users as $user): ?>
 <?php $user_initial = strtoupper(substr($user['display_name'], 0, 1)); ?>
 <div class="user-card active-user-card">
 <div class="user-card-header">
 <div class="user-avatar" style="background: #46b450;position: relative;border-radius: 50px;overflow: hidden;">
 <span class="aoam-user-initial"><?php echo esc_html($user_initial); ?></span>
 </div>
 <div class="user-info">
 <h4><?php echo esc_html($user['display_name']); ?></h4>
 <p class="user-meta">User <?php echo esc_html($user['sequence']); ?></p>
 </div>
 </div>
 
 <div class="user-actions">
 <label class="checkbox-container">
 <input type="checkbox" class="active-user-checkbox" value="<?php echo esc_attr($user['ID']); ?>">
 <span class="checkmark"></span>
 Select User
 </label>
 </div>
 </div>
 <?php endforeach; ?>
 </div>
 
 <div style="margin-top: 20px;">
 <button type="button" class="button" id="select-all-active-btn">Select All</button>
 <button type="button" class="button" id="deselect-all-active-btn">Deselect All</button>
 </div>
 
 <div style="margin-top: 30px; text-align: right;">
 <button type="button" class="button button-secondary" onclick="goToStep(1)"> Back</button>
 <button type="button" class="button button-primary" onclick="goToStep(3)">Next </button>
 </div>
 </div>
 
 <!-- Step 3: Select Order Statuses -->
 <div class="reassign-step" id="step-3" style="display: none;">
 <h3><span class="step-number">3</span> Select Order Statuses</h3>
 <p>Choose which status orders to reassign:</p>
 
 <div class="status-grid">
 <?php foreach ($order_status_options as $status_key => $status_name): ?>
 <div class="status-card">
 <label class="checkbox-container">
 <input type="checkbox" class="status-checkbox" value="<?php echo esc_attr($status_key); ?>">
 <span class="checkmark"></span>
 <div class="status-content">
 <div class="status-name" style="color: <?php echo aoam_get_status_color($status_key); ?>;">
 <?php echo esc_html($status_name); ?>
 </div>
 </div>
 </label>
 </div>
 <?php endforeach; ?>
 </div>
 
 <div style="margin-top: 20px;">
 <button type="button" class="button" id="select-all-status-btn">Select All</button>
 <button type="button" class="button" id="deselect-all-status-btn">Deselect All</button>
 </div>
 
 <div style="margin-top: 30px; text-align: right;">
 <button type="button" class="button button-secondary" onclick="goToStep(2)"> Back</button>
 <button type="button" class="button button-primary" onclick="goToStep(4)">Next </button>
 </div>
 </div>
 
 <!-- Step 4: Preview & Confirm -->
 <div class="reassign-step" id="step-4" style="display: none;">
 <h3><span class="step-number">4</span> Preview & Confirm</h3>
 
 <div id="preview-container">
 <div style="text-align: center; padding: 40px;">
 <div class="spinner is-active" style="float: none;"></div>
 <p>Loading preview...</p>
 </div>
 
 <div style="margin-top: 20px;">
 <button type="button" class="button button-secondary" onclick="goToStep(3)">
 Go Back
 </button>
 </div>
 </div>
 
 <div id="confirmation-section" style="display: none;">
 <div style="padding: 20px; background: #fff8e1; border-radius: 8px; margin: 20px 0;">
 <h4 style="margin-top: 0;"> Final Confirmation</h4>
 <p>Are you sure you want to proceed with the reassignment?</p>
 <p><strong>This action cannot be undone automatically.</strong></p>
 
 <div style="margin-top: 20px;">
 <button type="button" class="button button-primary" id="confirm-reassign-btn">
 Yes, Reassign Orders
 </button>
 <button type="button" class="button button-secondary" onclick="goToStep(3)">
 Go Back
 </button>
 </div>
 </div>
 </div>
 </div>
 </div>
 </div>
 
 <style>
 .card {
 margin: 20px 0;
 padding: 30px;
 background: #fff;
 border: 1px solid #ccd0d4;
 border-radius: 10px;
 box-shadow: 0 4px 8px rgba(0,0,0,0.1);
 max-width: 100%;
 }
 
 .reassign-step {
 padding: 30px;
 border: 2px solid #e0e0e0;
 border-radius: 10px;
 margin: 30px 0;
 background: #fafafa;
 }
 
 .step-number {
 display: inline-block;
 width: 30px;
 height: 30px;
 background: #0073aa;
 color: white;
 border-radius: 50%;
 text-align: center;
 line-height: 30px;
 margin-right: 10px;
 }
 
 .user-grid {
 display: grid;
 grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
 gap: 20px;
 margin: 20px 0;
 }
 
 .user-card {
 background: white;
 border: 2px solid #ddd;
 border-radius: 8px;
 padding: 20px;
 transition: all 0.3s;
 }
 
 .user-card:hover {
 border-color: #0073aa;
 transform: translateY(-2px);
 box-shadow: 0 6px 12px rgba(0,0,0,0.1);
 }
 
 .user-card.selected {
 border-color: #46b450;
 background: #f8fff8;
 }
 
 .aoam-user-status {
 display: inline-flex;
 align-items: center;
 margin-top: 6px;
 padding: 2px 8px;
 border-radius: 999px;
 font-size: 11px;
 font-weight: 700;
 }
 
 .aoam-user-status.is-active {
 background: #edfaef;
 color: #1d7f2d;
 }
 
 .aoam-user-status.is-inactive {
 background: #fdecec;
 color: #b42318;
 }
 
 .user-card-header {
 display: flex;
 align-items: center;
 gap: 15px;
 margin-bottom: 15px;
 }
 
 .user-avatar {
 width: 50px;
 height: 50px;
 border-radius: 50%;
 display: flex;
 align-items: center;
 justify-content: center;
 font-size: 20px;
 font-weight: bold;
 color: white;
 }
 
 .user-info h4 {
 margin: 0 0 5px 0;
 font-size: 18px;
 }
 
 .user-meta {
 margin: 0;
 color: #666;
 font-size: 13px;
 }
 
 .user-stats {
 margin: 15px 0;
 padding: 15px;
 background: #f8f9fa;
 border-radius: 6px;
 }
 
 .stat-total {
 text-align: center;
 margin-bottom: 10px;
 }
 
 .stat-number {
 font-size: 28px;
 font-weight: bold;
 color: #0073aa;
 display: block;
 }
 
 .stat-label {
 font-size: 12px;
 color: #666;
 text-transform: uppercase;
 letter-spacing: 1px;
 }
 
 .status-badges {
 display: flex;
 flex-wrap: wrap;
 gap: 5px;
 justify-content: center;
 }
 
 .status-badge {
 padding: 3px 8px;
 border-radius: 4px;
 font-size: 11px;
 color: white;
 font-weight: bold;
 }
 
 .status-grid {
 display: grid;
 grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
 gap: 15px;
 margin: 20px 0;
 }
 
 .status-card {
 padding: 15px;
 border: 2px solid #ddd;
 border-radius: 8px;
 background: white;
 cursor: pointer;
 }
 
 .status-card:hover {
 border-color: #0073aa;
 }
 
 .status-content {
 text-align: center;
 }
 
 .status-name {
 font-size: 16px;
 font-weight: bold;
 }
 
 .checkbox-container {
 display: block;
 position: relative;
 padding-left: 35px;
 cursor: pointer;
 user-select: none;
 }
 
 .checkbox-container input {
 position: absolute;
 opacity: 0;
 cursor: pointer;
 height: 0;
 width: 0;
 }
 
 .checkmark {
 position: absolute;
 top: 0;
 left: 0;
 height: 25px;
 width: 25px;
 background-color: #eee;
 border-radius: 4px;
 }
 
 .checkbox-container:hover input ~ .checkmark {
 background-color: #ccc;
 }
 
 .checkbox-container input:checked ~ .checkmark {
 background-color: #46b450;
 }
 
 .checkmark:after {
 content: "";
 position: absolute;
 display: none;
 }
 
 .checkbox-container input:checked ~ .checkmark:after {
 display: block;
 }
 
 .checkbox-container .checkmark:after {
 left: 9px;
 top: 5px;
 width: 7px;
 height: 12px;
 border: solid white;
 border-width: 0 3px 3px 0;
 transform: rotate(45deg);
 }
 
 .nav-tab-wrapper { margin: 20px 0; }
 .nav-tab { 
 padding: 12px 20px; 
 margin: 0 5px 0 0; 
 border: 1px solid #ccd0d4;
 border-bottom: none;
 background: #f0f0f0;
 }
 .nav-tab-active { 
 background: #0073aa; 
 color: white; 
 border-bottom: 1px solid #0073aa;
 }
 </style>
 
 <script>
 // Global variables to store selections
 var reassignData = {
 source_users: [],
 active_users: [],
 order_statuses: [],
 selected_shifts: []
};

jQuery(document).ready(function($) {
 // Use $ inside this function only
 
 // Step 1: Source users selection
 $('.source-user-checkbox').change(function() {
 updateSourceUsersList();
 $(this).closest('.source-user-card').toggleClass('selected', $(this).is(':checked'));
 });
 
 $('#select-all-source-btn').click(function() {
 $('.source-user-checkbox').prop('checked', true).trigger('change');
 });
 
 $('#deselect-all-source-btn').click(function() {
 $('.source-user-checkbox').prop('checked', false).trigger('change');
 });
 
 // Step 2: Active users selection
 $('.active-user-checkbox').change(function() {
 updateActiveUsersList();
 });
 
 $('#select-all-active-btn').click(function() {
 $('.active-user-checkbox').prop('checked', true).trigger('change');
 });
 
 $('#deselect-all-active-btn').click(function() {
 $('.active-user-checkbox').prop('checked', false).trigger('change');
 });
 
 // Step 3: Status selection
 $('.status-checkbox').change(function() {
 updateStatusList();
 });
 
 $('#select-all-status-btn').click(function() {
 $('.status-checkbox').prop('checked', true).trigger('change');
 });
 
 $('#deselect-all-status-btn').click(function() {
 $('.status-checkbox').prop('checked', false).trigger('change');
 });
 
 // Step 4: Preview
 $(document).on('click', '#confirm-reassign-btn', function() {
 submitReassignment();
 });
});

function goToStep(stepNumber) {
 if (stepNumber === 2 && reassignData.source_users.length === 0) {
 alert('Please select at least one source user.');
 return;
 }
 
 if (stepNumber === 3 && reassignData.active_users.length === 0) {
 alert('Please select at least one active target user.');
 return;
 }
 
 // Use jQuery instead of $
 jQuery('.reassign-step').hide();
 jQuery('#step-' + stepNumber).show();
 
 // Load preview for step 4
 if (stepNumber === 4) {
 loadPreview();
 }
}

function updateSourceUsersList() {
 reassignData.source_users = [];
 jQuery('.source-user-checkbox:checked').each(function() {
 reassignData.source_users.push(jQuery(this).val());
 });
}

function updateActiveUsersList() {
 reassignData.active_users = [];
 jQuery('.active-user-checkbox:checked').each(function() {
 reassignData.active_users.push(jQuery(this).val());
 });
}

function updateStatusList() {
 reassignData.order_statuses = [];
 jQuery('.status-checkbox:checked').each(function() {
 reassignData.order_statuses.push(jQuery(this).val());
 });
}

function loadPreview() {
 if (reassignData.source_users.length === 0 || reassignData.active_users.length === 0 || reassignData.order_statuses.length === 0) {
 jQuery('#preview-container').html('<div style="text-align: center; padding: 40px; color: #cc1818;"><p>Please complete all previous steps.</p></div>');
 return;
 }
 
 jQuery('#preview-container').html('<div style="text-align: center; padding: 40px;"><div class="spinner is-active" style="float: none;"></div><p>Loading preview...</p></div>');
 
 jQuery.ajax({
 url: '<?php echo admin_url('admin-ajax.php'); ?>',
 type: 'POST',
 data: {
 action: 'get_reassign_preview_final',
 source_users: reassignData.source_users,
 active_users: reassignData.active_users,
 order_statuses: reassignData.order_statuses,
 nonce: '<?php echo wp_create_nonce('reassign_preview_final'); ?>'
 },
 success: function(response) {
 if (response.success) {
 jQuery('#preview-container').html(response.data);
 jQuery('#confirmation-section').show();
 } else {
 jQuery('#preview-container').html('<div style="color: #cc1818; padding: 20px; text-align: center;">Error: ' + response.data + '</div>');
 }
 },
 error: function() {
 jQuery('#preview-container').html('<div style="color: #cc1818; padding: 20px; text-align: center;">Error loading preview</div>');
 }
 });
}

function submitReassignment() {
 if (!confirm('Are you sure you want to reassign orders? This action cannot be undone.')) {
 return;
 }
 
 // Show loading
 jQuery('#confirm-reassign-btn').html('<span class="spinner is-active"></span> Processing...').prop('disabled', true);
 
 // Prepare data for form submission
 jQuery('#inactive_user_data').val(JSON.stringify({
 source_users: reassignData.source_users,
 users: reassignData.active_users,
 statuses: reassignData.order_statuses,
 shifts: reassignData.selected_shifts,
 timestamp: Date.now()
 }));
 
 // Submit the form
 jQuery('#reassign_orders_submit').click();
}

function aoam_get_status_color(status) {
 var colors = {
 'pending': '#ffb900',
 'partial': '#777',
 'processing': '#00a0d2',
 'on-hold': '#cc1818',
 'completed': '#46b450'
 };
 return colors[status] || '#666';
}
 </script>
 <?php
}

// Helper functions
function count_user_orders($user_id) {
 global $wpdb;
 
 // Try multiple meta keys
 $meta_keys = array('_assigned_moderator_id', '_assigned_moderator', '_moderator_id', '_assigned_to');
 
 foreach ($meta_keys as $key) {
 $count = $wpdb->get_var($wpdb->prepare("
 SELECT COUNT(*) 
 FROM {$wpdb->postmeta} 
 WHERE meta_key = %s 
 AND meta_value = %d
 ", $key, $user_id));
 
 if ($count > 0) {
 return $count;
 }
 }
 
 return 0;
}

function get_user_order_status_counts($user_id) {
 global $wpdb;
 
 $meta_keys = array('_assigned_moderator_id', '_assigned_moderator', '_moderator_id', '_assigned_to');
 
 foreach ($meta_keys as $key) {
 $results = $wpdb->get_results($wpdb->prepare("
 SELECT 
 REPLACE(p.post_status, 'wc-', '') as status,
 COUNT(*) as count
 FROM {$wpdb->postmeta} pm
 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
 WHERE pm.meta_key = %s 
 AND pm.meta_value = %d
 AND p.post_type = 'shop_order'
 GROUP BY p.post_status
 ", $key, $user_id));
 
 if (!empty($results)) {
 $status_counts = array();
 foreach ($results as $row) {
 $status_counts[$row->status] = $row->count;
 }
 return $status_counts;
 }
 }
 
 return array();
}

function aoam_get_status_color($status) {
 $colors = array(
 'pending' => '#ffb900',
 'partial' => '#777',
 'processing' => '#00a0d2',
 'on-hold' => '#cc1818',
 'completed' => '#46b450'
 );
 
 return isset($colors[$status]) ? $colors[$status] : '#666';
}

// AJAX handler for preview
add_action('wp_ajax_get_reassign_preview_final', 'get_reassign_preview_final_ajax');

function get_reassign_preview_final_ajax() {
 if (!current_user_can('manage_options')) {
 wp_send_json_error('Permission denied');
 }
 
 if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'reassign_preview_final')) {
 wp_send_json_error('Security check failed');
 }
 
 $source_users = array();
 if (isset($_POST['source_users'])) {
 $source_users = array_map('intval', (array) wp_unslash($_POST['source_users']));
 } elseif (isset($_POST['inactive_user'])) {
 $source_users = array(absint($_POST['inactive_user']));
 }
 $source_users = array_values(array_filter(array_unique($source_users)));
 $active_users = isset($_POST['active_users']) ? array_map('intval', (array) wp_unslash($_POST['active_users'])) : array();
 $active_users = array_values(array_filter(array_unique($active_users), function($user_id) {
 return get_user_meta($user_id, 'moderator_status', true) !== 'inactive';
 }));
 $order_statuses = isset($_POST['order_statuses']) ? array_map('sanitize_key', (array) wp_unslash($_POST['order_statuses'])) : array();
 
 if (empty($source_users) || empty($active_users) || empty($order_statuses)) {
 wp_send_json_error('Please select source users, active target users, and order statuses.');
 }
 
 // Get orders
 $orders = get_orders_for_reassignment_final($source_users, $order_statuses);
 $source_names = array();
 foreach ($source_users as $source_user_id) {
 $source_user = get_userdata($source_user_id);
 if ($source_user) {
 $source_names[] = $source_user->display_name;
 }
 }
 
 if (empty($orders)) {
 $html = '<div style="text-align: center; padding: 40px;">';
 $html .= '<div style="font-size: 48px; color: #cc1818; margin-bottom: 20px;"></div>';
 $html .= '<h3>No Orders Found</h3>';
 $html .= '<p>No orders match the selected criteria.</p>';
 
 // Debug info
 $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-top: 20px; text-align: left;">';
 $html .= '<p><strong>Source Users:</strong> ' . esc_html(implode(', ', $source_names)) . '</p>';
 $html .= '<p><strong>Statuses:</strong> ' . esc_html(implode(', ', $order_statuses)) . '</p>';
 $html .= '</div>';
 
 $html .= '</div>';
 
 wp_send_json_success($html);
 return;
 }
 
 // Generate preview
 $html = '<div class="final-preview">';
 
 // Summary
 $html .= '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 25px; border-radius: 10px; color: white; margin-bottom: 25px;">';
 $html .= '<h3 style="color: white; margin-top: 0;"> Reassignment Summary</h3>';
 $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 15px;">';
 $html .= '<div><div style="font-size: 32px; font-weight: bold;">' . count($orders) . '</div><div>Total Orders</div></div>';
 $html .= '<div><div style="font-size: 32px; font-weight: bold;">' . count($source_users) . '</div><div>Source Users</div></div>';
 $html .= '<div><div style="font-size: 32px; font-weight: bold;">' . count($active_users) . '</div><div>Active Users</div></div>';
 $html .= '<div><div style="font-size: 32px; font-weight: bold;">' . count($order_statuses) . '</div><div>Statuses</div></div>';
 $html .= '</div>';
 $html .= '</div>';
 
 // Orders by status
 $status_counts = array();
 foreach ($orders as $order) {
 $status = $order->get_status();
 if (!isset($status_counts[$status])) {
 $status_counts[$status] = 0;
 }
 $status_counts[$status]++;
 }
 
 $html .= '<h4> Orders by Status</h4>';
 $html .= '<div style="display: flex; gap: 10px; flex-wrap: wrap; margin: 15px 0 25px 0;">';
 foreach ($status_counts as $status => $count) {
 $color = aoam_get_status_color($status);
 $html .= '<div style="padding: 10px 15px; background: ' . $color . '20; border-left: 4px solid ' . $color . '; border-radius: 4px;">';
 $html .= '<div style="font-size: 18px; font-weight: bold; color: ' . $color . ';">' . $count . '</div>';
 $html .= '<div style="font-size: 12px; color: ' . $color . ';">' . ucfirst($status) . '</div>';
 $html .= '</div>';
 }
 $html .= '</div>';
 
 // Sample orders
 $html .= '<h4> Sample Orders</h4>';
 $html .= '<div style="max-height: 250px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; margin: 15px 0 25px 0;">';
 
 $sample_count = 0;
 foreach ($orders as $order) {
 if ($sample_count >= 5) break;
 
 $status = $order->get_status();
 $color = aoam_get_status_color($status);
 
 $html .= '<div style="padding: 12px 15px; border-bottom: 1px solid #eee; background: white;">';
 $html .= '<div style="display: flex; justify-content: space-between; align-items: center;">';
 $html .= '<div>';
 $html .= '<strong>Order #' . $order->get_id() . '</strong>';
 $html .= '<div style="font-size: 13px; color: #666;">';
 $html .= aoam_format_order_local_date($order, 'M j, Y') . ' ';
 $html .= esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . ' ';
 $html .= $order->get_formatted_order_total();
 $html .= '</div>';
 $html .= '</div>';
 $html .= '<span style="padding: 5px 10px; background: ' . $color . '20; color: ' . $color . '; border-radius: 4px; font-size: 12px; font-weight: bold;">';
 $html .= ucfirst($status);
 $html .= '</span>';
 $html .= '</div>';
 $html .= '</div>';
 
 $sample_count++;
 }
 
 if (count($orders) > 5) {
 $html .= '<div style="padding: 10px; text-align: center; color: #666; font-style: italic;">';
 $html .= '+ ' . (count($orders) - 5) . ' more orders';
 $html .= '</div>';
 }
 
 $html .= '</div>';
 
 $html .= '</div>'; // Close final-preview
 
 wp_send_json_success($html);
}

// Function to get orders
function get_orders_for_reassignment_final($user_ids, $statuses) {
 // Method 1: Direct query with all possible meta keys
 global $wpdb;
 
 $user_ids = array_values(array_filter(array_unique(array_map('intval', (array) $user_ids))));
 $statuses = array_values(array_filter(array_map('sanitize_text_field', (array) $statuses)));
 
 if (empty($user_ids) || empty($statuses)) {
 return array();
 }
 
 $meta_keys = array('_assigned_moderator_id', '_assigned_moderator', '_moderator_id', '_assigned_to');
 $user_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
 $found_order_ids = array();
 
 foreach ($meta_keys as $key) {
 // Get order IDs with this meta key
 $query_args = array_merge(array($key), $user_ids);
 $order_ids = $wpdb->get_col($wpdb->prepare("
 SELECT post_id 
 FROM {$wpdb->postmeta} 
 WHERE meta_key = %s 
 AND meta_value IN ($user_placeholders)
 ", $query_args));
 
 if (!empty($order_ids)) {
 $found_order_ids = array_merge($found_order_ids, array_map('intval', $order_ids));
 }
 }
 
 $found_order_ids = array_values(array_unique($found_order_ids));
 if (empty($found_order_ids)) {
 return array();
 }
 
 $orders = array();
 foreach ($found_order_ids as $order_id) {
 $order = wc_get_order($order_id);
 if ($order && in_array($order->get_status(), $statuses, true)) {
 $orders[] = $order;
 }
 }
 
 return $orders;
}

// Form submission handler
function process_reassignment_form() {
 if (!current_user_can('manage_options')) {
 return array(
 'success' => false,
 'message' => 'You do not have permission to perform this action.'
 );
 }
 
 // Verify nonce
 if (!isset($_POST['reassign_nonce_main']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['reassign_nonce_main'])), 'reassign_orders_main')) {
 return array(
 'success' => false,
 'message' => 'Security check failed. Please try again.'
 );
 }
 
 // Get and decode data
 if (empty($_POST['inactive_user_data'])) {
 return array(
 'success' => false,
 'message' => 'No data received. Please fill the form again.'
 );
 }
 
 $data = json_decode(wp_unslash($_POST['inactive_user_data']), true);
 
 if (!$data) {
 return array(
 'success' => false,
 'message' => 'Invalid data format. Please try again.'
 );
 }
 
 if (isset($data['source_users'])) {
 $source_users = array_map('intval', (array) $data['source_users']);
 } else {
 $source_users = isset($data['id']) ? array(intval($data['id'])) : array();
 }
 $source_users = array_values(array_filter(array_unique($source_users)));
 $active_users = isset($data['users']) ? array_map('intval', $data['users']) : array();
 $order_statuses = isset($data['statuses']) ? array_map('sanitize_key', $data['statuses']) : array();
 
 // Validation
 if (empty($source_users)) {
 return array(
 'success' => false,
 'message' => 'No source users selected.'
 );
 }
 
 if (empty($active_users)) {
 return array(
 'success' => false,
 'message' => 'No active users selected.'
 );
 }
 
 $active_users = array_values(array_filter(array_unique(array_map('intval', $active_users)), function($user_id) {
 return get_user_meta($user_id, 'moderator_status', true) !== 'inactive';
 }));
 
 if (empty($active_users)) {
 return array(
 'success' => false,
 'message' => 'Target users must be active. Please select active users only.'
 );
 }
 
 if (empty($order_statuses)) {
 return array(
 'success' => false,
 'message' => 'No order statuses selected.'
 );
 }
 
 // Get orders
 $orders = get_orders_for_reassignment_final($source_users, $order_statuses);
 
 if (empty($orders)) {
 return array(
 'success' => false,
 'message' => 'No orders found to reassign with the selected criteria.'
 );
 }
 
 // Process reassignment
 $result = process_bulk_reassignment($source_users, $active_users, $order_statuses, $orders);
 
 return $result;
}

// Bulk reassignment processor
function process_bulk_reassignment($source_user_ids, $active_users, $order_statuses, $orders) {
 $source_user_ids = array_values(array_filter(array_unique(array_map('intval', (array) $source_user_ids))));
 $active_users = array_values(array_filter(array_unique(array_map('intval', (array) $active_users)), function($user_id) {
 return get_user_meta($user_id, 'moderator_status', true) !== 'inactive';
 }));
 
 if (empty($source_user_ids)) {
 return array(
 'success' => false,
 'message' => 'Source users not found.'
 );
 }
 
 if (empty($active_users)) {
 return array(
 'success' => false,
 'message' => 'No active target users found.'
 );
 }
 
 // Sort active users by sequence
 usort($active_users, function($a, $b) {
 $seq_a = get_user_meta($a, 'moderator_sequence', true) ?: 999;
 $seq_b = get_user_meta($b, 'moderator_sequence', true) ?: 999;
 return $seq_a - $seq_b;
 });
 
 // Initialize
 $reassigned = 0;
 $failed = 0;
 $distribution = array_fill_keys($active_users, 0);
 $current_index = 0;
 
 // Process each order
 foreach ($orders as $order) {
 $new_user_id = $active_users[$current_index];
 $new_user = get_userdata($new_user_id);
 
 if ($new_user) {
 // Find the correct meta key
 $assignment_info = find_assignment_meta_key_for_users($order->get_id(), $source_user_ids);
 $meta_key = $assignment_info['meta_key'];
 $old_user = $assignment_info['user_id'] ? get_userdata($assignment_info['user_id']) : null;
 
 if ($meta_key) {
 // Update assignment
 update_post_meta($order->get_id(), $meta_key, $new_user_id);
 update_post_meta($order->get_id(), '_assigned_moderator_name', $new_user->display_name);
 
 // Update sequence
 $new_sequence = get_user_meta($new_user_id, 'moderator_sequence', true);
 if ($new_sequence) {
 update_post_meta($order->get_id(), '_assigned_moderator_sequence', $new_sequence);
 }
 
 // Add order note
 $order_note = sprintf(
 ' Order reassigned from %s to %s via bulk reassignment tool.',
 $old_user ? $old_user->display_name : 'selected source user',
 $new_user->display_name
 );
 
 $order->add_order_note($order_note);
 
 $reassigned++;
 $distribution[$new_user_id]++;
 
 // Move to next user
 $current_index = ($current_index + 1) % count($active_users);
 } else {
 $failed++;
 }
 } else {
 $failed++;
 }
 }
 
 // Prepare response
 if ($reassigned > 0) {
 $message = " Successfully reassigned $reassigned order(s) from selected source users.\n\n";
 
 $details = "Distribution:\n";
 foreach ($distribution as $user_id => $count) {
 if ($count > 0) {
 $user = get_userdata($user_id);
 $details .= " {$user->display_name}: {$count} order(s)\n";
 }
 }
 
 if ($failed > 0) {
 $details .= "\nFailed to reassign: {$failed} order(s)";
 }
 
 return array(
 'success' => true,
 'message' => $message,
 'details' => $details
 );
 } else {
 return array(
 'success' => false,
 'message' => 'Failed to reassign any orders.'
 );
 }
}

// Helper to find correct meta key
function find_assignment_meta_key($order_id, $user_id) {
 $possible_keys = array('_assigned_moderator_id', '_assigned_moderator', '_moderator_id', '_assigned_to');
 
 foreach ($possible_keys as $key) {
 $assigned_user = get_post_meta($order_id, $key, true);
 if ($assigned_user == $user_id) {
 return $key;
 }
 }
 
 return '_assigned_moderator_id'; // Default
}

function find_assignment_meta_key_for_users($order_id, $user_ids) {
 $possible_keys = array('_assigned_moderator_id', '_assigned_moderator', '_moderator_id', '_assigned_to');
 $user_ids = array_map('intval', (array) $user_ids);
 
 foreach ($possible_keys as $key) {
 $assigned_user = intval(get_post_meta($order_id, $key, true));
 if (in_array($assigned_user, $user_ids, true)) {
 return array(
 'meta_key' => $key,
 'user_id' => $assigned_user
 );
 }
 }
 
 return array(
 'meta_key' => '_assigned_moderator_id',
 'user_id' => 0
 );
}
