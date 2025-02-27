<?php
/**
 * FileBird Diagnostic Tool
 *
 * This file can be added to your plugin folder to test FileBird integration
 * Access at: YOUR_SITE/wp-admin/admin.php?page=filebird-dropbox-diagnostic
 */

// Add the diagnostic page to admin menu
add_action('admin_menu', 'fbds_add_diagnostic_page');

function fbds_add_diagnostic_page() {
    add_submenu_page(
        null, // No parent - hidden page
        'FileBird Diagnostic',
        'FileBird Diagnostic',
        'manage_options',
        'filebird-dropbox-diagnostic',
        'fbds_render_diagnostic_page'
    );
}

// Render the diagnostic page
function fbds_render_diagnostic_page() {
    // Security check
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    global $wpdb;
    
    echo '<div class="wrap">';
    echo '<h1>FileBird Integration Diagnostic</h1>';
    
    echo '<div style="background: #fff; padding: 15px; border: 1px solid #ccc; margin: 10px 0;">';
    echo '<h2>FileBird Detection</h2>';
    
    // Check for FileBird class
    $filebird_class_exists = class_exists('\\FileBird\\FileBird') || 
                           class_exists('FileBird\\FileBird') || 
                           class_exists('FileBird');
    
    echo '<p><strong>FileBird Class:</strong> ' . ($filebird_class_exists ? '<span style="color:green">Found</span>' : '<span style="color:red">Not Found</span>') . '</p>';
    
    // Check for FileBird constants
    $filebird_version = defined('NJFB_VERSION') ? NJFB_VERSION : (defined('FILEBIRD_VERSION') ? FILEBIRD_VERSION : 'Not defined');
    
    echo '<p><strong>FileBird Version:</strong> ' . $filebird_version . '</p>';
    
    // Check for FileBird tables
    $fbv_table = $wpdb->prefix . 'fbv';
    $fbv_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$fbv_table}'") === $fbv_table;
    
    echo '<p><strong>FileBird Table (fbv):</strong> ' . ($fbv_table_exists ? '<span style="color:green">Found</span>' : '<span style="color:red">Not Found</span>') . '</p>';
    
    $fbv_attachment_table = $wpdb->prefix . 'fbv_attachment_folder';
    $fbv_attachment_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$fbv_attachment_table}'") === $fbv_attachment_table;
    
    echo '<p><strong>FileBird Attachment Table:</strong> ' . ($fbv_attachment_table_exists ? '<span style="color:green">Found</span>' : '<span style="color:red">Not Found</span>') . '</p>';
    
    // Check for FileBird taxonomy
    $filebird_taxonomy = false;
    $possible_taxonomies = [
        'filebird_folder',
        'nt_wmc_folder',
        'media_folder',
        'folder',
        'fbv'
    ];
    
    $found_taxonomy = '';
    
    foreach ($possible_taxonomies as $taxonomy) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1",
                $taxonomy
            )
        );
        
        if ((int)$exists > 0) {
            $filebird_taxonomy = true;
            $found_taxonomy = $taxonomy;
            break;
        }
    }
    
    echo '<p><strong>FileBird Taxonomy:</strong> ' . ($filebird_taxonomy ? '<span style="color:green">Found</span> (' . $found_taxonomy . ')' : '<span style="color:red">Not Found</span>') . '</p>';
    
    echo '</div>';
    
    echo '<div style="background: #fff; padding: 15px; border: 1px solid #ccc; margin: 10px 0;">';
    echo '<h2>FileBird Folders</h2>';
    
    // Try using FileBird API
    $folders_api = array();
    $fbv_class_method_exists = false;
    
    if (class_exists('\\FileBird\\Model\\Folder') && method_exists('\\FileBird\\Model\\Folder', 'allFolders')) {
        try {
            $fbv_class_method_exists = true;
            $folders_api = \FileBird\Model\Folder::allFolders();
        } catch (Exception $e) {
            echo '<p><strong>Error using FileBird API:</strong> ' . $e->getMessage() . '</p>';
        }
    }
    
    echo '<p><strong>FileBird Folder API:</strong> ' . ($fbv_class_method_exists ? '<span style="color:green">Available</span>' : '<span style="color:red">Not Available</span>') . '</p>';
    
    // Folders from direct queries
    $folders_db = array();
    
    if ($filebird_taxonomy && !empty($found_taxonomy)) {
        $folders_db = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.term_id, t.name, tt.parent 
                FROM {$wpdb->terms} AS t 
                INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id 
                WHERE tt.taxonomy = %s 
                ORDER BY t.name ASC",
                $found_taxonomy
            )
        );
    } elseif ($fbv_table_exists) {
        $folders_db = $wpdb->get_results(
            "SELECT id as term_id, name, parent 
            FROM {$fbv_table} 
            ORDER BY name ASC"
        );
    }
    
    $folders_count = count($folders_api) ?: count($folders_db);
    
    echo '<p><strong>Total Folders:</strong> ' . $folders_count . '</p>';
    
    // Display folders
    if ($folders_count > 0) {
        echo '<table class="widefat" style="margin-top:10px;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>ID</th>';
        echo '<th>Name</th>';
        echo '<th>Parent</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $folders = !empty($folders_api) ? $folders_api : $folders_db;
        
        foreach ($folders as $folder) {
            echo '<tr>';
            echo '<td>' . (isset($folder['id']) ? $folder['id'] : $folder->term_id) . '</td>';
            echo '<td>' . (isset($folder['name']) ? $folder['name'] : $folder->name) . '</td>';
            echo '<td>' . (isset($folder['parent']) ? $folder['parent'] : $folder->parent) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<p><em>No FileBird folders found.</em></p>';
    }
    
    echo '</div>';
    
    echo '<div style="background: #fff; padding: 15px; border: 1px solid #ccc; margin: 10px 0;">';
    echo '<h2>WordPress Environment</h2>';
    
    echo '<p><strong>WordPress Version:</strong> ' . get_bloginfo('version') . '</p>';
    echo '<p><strong>PHP Version:</strong> ' . phpversion() . '</p>';
    echo '<p><strong>MySQL Version:</strong> ' . $wpdb->db_version() . '</p>';
    
    // List active plugins
    echo '<p><strong>Active Plugins:</strong></p>';
    echo '<ul>';
    $active_plugins = get_option('active_plugins');
    foreach ($active_plugins as $plugin) {
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
        echo '<li>' . $plugin_data['Name'] . ' ' . $plugin_data['Version'] . '</li>';
    }
    echo '</ul>';
    
    echo '</div>';
    
    echo '<a href="' . admin_url('admin.php?page=filebird-dropbox-sync') . '" class="button button-primary" style="margin-top:10px;">Return to FileBird Dropbox Sync</a>';
    
    echo '</div>';
}