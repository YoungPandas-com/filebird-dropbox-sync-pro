<?php
/**
 * FileBird Dropbox Sync Pro
 *
 * @package           FileBirdDropboxSyncPro
 * @author            Young Pandas
 * @copyright         2025 Young Pandas Limited
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       FileBird Dropbox Sync Pro
 * Plugin URI:        https://yp.studio
 * Description:       Two-way synchronization between FileBird folders and Dropbox, with ACF gallery integration
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Young Pandas
 * Author URI:        https://yp.studio
 * Text Domain:       filebird-dropbox-sync-pro
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:        https://example.com/plugins/filebird-dropbox-sync-pro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('FBDS_VERSION', '1.0.0');
define('FBDS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FBDS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FBDS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('FBDS_DEBUG', false);

require_once plugin_dir_path(__FILE__) . 'filebird-diagnostic.php';

/**
 * Check for required plugins (FileBird and ACF)
 */
function fbds_check_required_plugins() {
    if (is_admin() && current_user_can('activate_plugins')) {
        $filebird_active = is_plugin_active('filebird/filebird.php');
        $acf_active = is_plugin_active('advanced-custom-fields/acf.php') || is_plugin_active('advanced-custom-fields-pro/acf.php');
        
        if (!$filebird_active || !$acf_active) {
            add_action('admin_notices', 'fbds_admin_notice_missing_plugins');
        }
    }
}
add_action('admin_init', 'fbds_check_required_plugins');

/**
 * Admin notice for missing required plugins
 */
function fbds_admin_notice_missing_plugins() {
    // Check for missing plugins
    $missing_plugins = get_option('fbds_missing_plugins', array());
    
    if (!empty($missing_plugins)) {
        $message = 'The following plugins are required for FileBird Dropbox Sync Pro: ';
        $message .= implode(', ', $missing_plugins) . '. ';
        $message .= 'Please install and activate the required plugins.';
        
        echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
    }
    
    // Check for incompatible plugin versions
    $incompatible_plugins = get_option('fbds_incompatible_plugins', array());
    
    if (!empty($incompatible_plugins)) {
        $message = 'FileBird Dropbox Sync Pro requires newer versions of the following plugins: ';
        $message .= implode(', ', $incompatible_plugins) . '. ';
        $message .= 'Please update these plugins to their required versions.';
        
        echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
    }
}

/**
 * The code that runs during plugin activation.
 */
function activate_filebird_dropbox_sync() {
    require_once FBDS_PLUGIN_DIR . 'includes/class-filebird-dropbox-sync-activator.php';
    FileBird_Dropbox_Sync_Activator::activate();
}
register_activation_hook(__FILE__, 'activate_filebird_dropbox_sync');

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_filebird_dropbox_sync() {
    require_once FBDS_PLUGIN_DIR . 'includes/class-filebird-dropbox-sync-deactivator.php';
    FileBird_Dropbox_Sync_Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'deactivate_filebird_dropbox_sync');

/**
 * The core plugin class
 */
require FBDS_PLUGIN_DIR . 'includes/class-filebird-dropbox-sync.php';

/**
 * Add this code to the main plugin file: filebird-dropbox-sync-pro.php
 * Just before the line: run_filebird_dropbox_sync();
 */

// Register the REST API routes for Dropbox webhooks
add_action('rest_api_init', 'fbds_register_webhook_routes');

/**
 * Register multiple webhook routes to ensure compatibility
 */
function fbds_register_webhook_routes() {
    // Register the direct webhook URLs (no namespace)
    register_rest_route('', '/filebird-dropbox-webhook', array(
        'methods' => 'GET,POST',
        'callback' => 'fbds_handle_webhook_request',
        'permission_callback' => '__return_true',
    ));
    
    register_rest_route('', '/dropbox-webhook', array(
        'methods' => 'GET,POST',
        'callback' => 'fbds_handle_webhook_request',
        'permission_callback' => '__return_true',
    ));
    
    // Register the namespaced webhook URLs
    register_rest_route('filebird-dropbox-sync/v1', '/webhook', array(
        'methods' => 'GET,POST',
        'callback' => 'fbds_handle_webhook_request',
        'permission_callback' => '__return_true',
    ));
    
    register_rest_route('dropbox/v1', '/webhook', array(
        'methods' => 'GET,POST',
        'callback' => 'fbds_handle_webhook_request',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Handle webhook requests from Dropbox
 * 
 * @param WP_REST_Request $request The request object
 * @return mixed The response
 */
function fbds_handle_webhook_request($request) {
    try {
        // Ensure logger class is loaded
        if (!class_exists('FileBird_Dropbox_Sync_Logger')) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
        }
        
        $logger = new FileBird_Dropbox_Sync_Logger();
        $method = $request->get_method();
        
        $logger->log("Received Dropbox webhook request via method: {$method}", 'info');
        
        // Check if this is a verification request
        if ($method === 'GET') {
            // Get challenge parameter and respond
            $challenge = $request->get_param('challenge');
            
            if ($challenge) {
                $logger->log('Received Dropbox verification challenge: ' . $challenge, 'info');
                // CRITICAL: Return challenge directly as plain text without JSON formatting
                header('Content-Type: text/plain');
                echo $challenge;
                exit; // Important: prevent any additional output or WordPress processing
            }
            
            return new WP_REST_Response('Invalid challenge', 400);
        } 
        else if ($method === 'POST') {
            // This is a notification about changes
            $raw_body = $request->get_body();
            $data = json_decode($raw_body, true);
            
            $logger->log('Received webhook notification from Dropbox: ' . json_encode($data), 'info');
            
            // Schedule multiple sync events with staggered timing
            // First sync after 30 seconds (quick response)
            wp_schedule_single_event(time() + 30, 'fbds_scheduled_sync', array('from_dropbox'));
            
            // Second sync after 2 minutes (allows time for Dropbox to process changes)
            wp_schedule_single_event(time() + 120, 'fbds_scheduled_sync', array('from_dropbox'));
            
            // Third sync that's bidirectional after 5 minutes (complete reconciliation)
            wp_schedule_single_event(time() + 300, 'fbds_scheduled_sync', array('both'));
            
            $logger->log('Scheduled multiple syncs in response to Dropbox webhook', 'info');
            
            return new WP_REST_Response(array('status' => 'success'), 200);
        }
        
        return new WP_REST_Response('Invalid method', 405);
    } 
    catch (Exception $e) {
        // Log the error
        if (isset($logger)) {
            $logger->log('Error in webhook handler: ' . $e->getMessage(), 'error');
        }
        
        // Even on error, return 200 to prevent Dropbox from disabling the webhook
        return new WP_REST_Response(array('status' => 'error handled'), 200);
    }
}

    /**
     * Set a consistent root path through the filter. This ensures anywhere the
     * filter is used, we get '/Website'.
     */
    function fbds_set_root_path($path) {
        return '/Website';
    }
    add_filter('fbds_dropbox_root_path', 'fbds_set_root_path', 10, 1);

    // Add this to filebird-dropbox-sync-pro.php
    
    // Add this action to run after 60 seconds for testing sync
    function fbds_activate_test_sync() {
        // Schedule a sync from Dropbox to FileBird
        $sync_time = time() + 60; // Start in 60 seconds
        wp_schedule_single_event($sync_time, 'fbds_scheduled_sync', array('from_dropbox'));
        
        // Log that we scheduled it
        $logger = new FileBird_Dropbox_Sync_Logger();
        $logger->log('Scheduled test sync from Dropbox to FileBird in 60 seconds', 'info');
    }
    
    // Uncomment this line to test the sync after plugin activation
    register_activation_hook(__FILE__, 'fbds_activate_test_sync');
    
    // Add this to your admin menu for a manual test button
    function fbds_add_test_button() {
        if (isset($_GET['page']) && $_GET['page'] === 'filebird-dropbox-sync' && isset($_GET['test_sync']) && $_GET['test_sync'] === '1') {
            // Schedule a sync from Dropbox to FileBird
            wp_schedule_single_event(time() + 10, 'fbds_scheduled_sync', array('from_dropbox'));
            
            // Add admin notice
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Test sync from Dropbox to FileBird scheduled! Check logs in 1-2 minutes.</p></div>';
            });
        }
    }
    add_action('admin_init', 'fbds_add_test_button');
    
    // Add button to dashboard
    function fbds_add_test_button_html() {
        if (isset($_GET['page']) && $_GET['page'] === 'filebird-dropbox-sync') {
            echo '<a href="' . admin_url('admin.php?page=filebird-dropbox-sync&test_sync=1') . '" class="button button-secondary">Test Dropbox-to-FileBird Sync</a>';
        }
    }
    add_action('admin_footer', 'fbds_add_test_button_html');


    /**
     * Debug and troubleshooting tools for FileBird-Dropbox Sync
     *
     * Add this code to your filebird-dropbox-sync-pro.php file
     * before the final line: run_filebird_dropbox_sync();
     */

    // Enable the debug mode in the connector
    define('FBDS_DEBUG', true);

    /**
     * Add admin tools to diagnose and trigger sync
     */
    function fbds_add_admin_tools() {
        // Only add these tools on our plugin's admin page
        $screen = get_current_screen();
        if (!$screen || !isset($_GET['page']) || strpos($_GET['page'], 'filebird-dropbox') !== 0) {
            return;
        }
        
        // Handle test sync requests
        if (isset($_GET['fbds_action'])) {
            if (current_user_can('manage_options')) {
                $action = sanitize_text_field($_GET['fbds_action']);
                $logger = new FileBird_Dropbox_Sync_Logger();
                
                switch ($action) {
                    case 'test_dropbox_sync':
                        // Trigger Dropbox to FileBird sync
                        wp_schedule_single_event(time() + 5, 'fbds_scheduled_sync', array('from_dropbox'));
                        $logger->log('Manual test sync (Dropbox → FileBird) scheduled', 'info');
                        add_action('admin_notices', function() {
                            echo '<div class="notice notice-success"><p>Dropbox to FileBird sync has been scheduled! Check logs in about 30 seconds.</p></div>';
                        });
                        break;
                        
                    case 'test_filebird_sync':
                        // Trigger FileBird to Dropbox sync
                        wp_schedule_single_event(time() + 5, 'fbds_scheduled_sync', array('to_dropbox'));
                        $logger->log('Manual test sync (FileBird → Dropbox) scheduled', 'info');
                        add_action('admin_notices', function() {
                            echo '<div class="notice notice-success"><p>FileBird to Dropbox sync has been scheduled! Check logs in about 30 seconds.</p></div>';
                        });
                        break;
                        
                    case 'dump_filebird_tables':
                        // Dump FileBird tables for debugging
                        $fbConnector = new FileBird_Connector();
                        $dump_data = $fbConnector->dump_filebird_tables();
                        update_option('fbds_table_dump', $dump_data);
                        $logger->log('FileBird tables dumped for debugging', 'info');
                        add_action('admin_notices', function() {
                            echo '<div class="notice notice-success"><p>FileBird tables have been dumped for debugging. Check the fbds_table_dump option in the database.</p></div>';
                        });
                        break;
                    
                    case 'verify_webhook':
                        // Verify webhook configuration
                        if (class_exists('FileBird_Dropbox_API')) {
                            $dropbox_api = new FileBird_Dropbox_API();
                            if ($dropbox_api->is_connected()) {
                                $logger->log('Webhook verification requested', 'info');
                                echo '<div class="notice notice-info"><p>Dropbox is connected. Please check logs for further details.</p></div>';
                            } else {
                                echo '<div class="notice notice-error"><p>Dropbox is not connected. Please configure Dropbox API settings first.</p></div>';
                            }
                        }
                        break;
                }
            }
        }
        
        // Add debug toolbar
        add_action('admin_footer', 'fbds_render_debug_toolbar');
    }
    add_action('admin_notices', 'fbds_add_admin_tools');

    /**
     * Render debug toolbar
     */
    function fbds_render_debug_toolbar() {
        // Only show on our plugin's admin page
        if (!isset($_GET['page']) || strpos($_GET['page'], 'filebird-dropbox') !== 0) {
            return;
        }
        
        // Only show to admins
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $page = isset($_GET['page']) ? $_GET['page'] : '';
        $base_url = admin_url('admin.php?page=' . $page);
        
        ?>
        <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border: 1px solid #ccc;">
            <h3>Debug Tools</h3>
            <p>Use these tools to test and diagnose the FileBird-Dropbox sync functionality:</p>
            
            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                <a href="<?php echo esc_url(add_query_arg('fbds_action', 'test_dropbox_sync', $base_url)); ?>" 
                class="button button-secondary">
                    Test Dropbox → FileBird Sync
                </a>
                
                <a href="<?php echo esc_url(add_query_arg('fbds_action', 'test_filebird_sync', $base_url)); ?>" 
                class="button button-secondary">
                    Test FileBird → Dropbox Sync
                </a>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <a href="<?php echo esc_url(add_query_arg('fbds_action', 'dump_filebird_tables', $base_url)); ?>" 
                class="button button-secondary">
                    Dump FileBird Tables
                </a>
                
                <a href="<?php echo esc_url(add_query_arg('fbds_action', 'verify_webhook', $base_url)); ?>" 
                class="button button-secondary">
                    Verify Webhook
                </a>
            </div>
            
            <p><strong>Note:</strong> These are debugging tools and should be used carefully. Check logs for details after running tests.</p>
        </div>
        <?php
    }

    /**
     * Add dropdown to view logs directly in admin
     */
    function fbds_add_log_viewer() {
        // Only show on our plugin's admin page
        if (!isset($_GET['page']) || strpos($_GET['page'], 'filebird-dropbox') !== 0) {
            return;
        }
        
        // Only for admins
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get recent logs
        $logger = new FileBird_Dropbox_Sync_Logger();
        $logs = $logger->get_recent_logs(100);
        
        if (empty($logs)) {
            return;
        }
        
        ?>
        <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border: 1px solid #ccc;">
            <h3>Recent Logs</h3>
            <table class="widefat" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Level</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr class="<?php echo $log['level'] === 'error' ? 'error' : ''; ?>">
                            <td><?php echo date('Y-m-d H:i:s', $log['time']); ?></td>
                            <td><?php echo ucfirst($log['level']); ?></td>
                            <td><?php echo esc_html($log['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    add_action('admin_footer', 'fbds_add_log_viewer');


/**
 * Begins execution of the plugin.
 */
function run_filebird_dropbox_sync() {
    $plugin = new FileBird_Dropbox_Sync();
    $plugin->run();
}
run_filebird_dropbox_sync();