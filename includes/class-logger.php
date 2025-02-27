<?php
/**
 * The logger class.
 *
 * @since      1.0.0
 * @package    FileBirdDropboxSyncPro
 */

class FileBird_Dropbox_Sync_Logger {

    /**
     * The log file path.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $log_file    The log file path.
     */
    private $log_file;

    /**
     * Maximum log size in bytes (5MB).
     *
     * @since    1.0.0
     * @access   private
     * @var      int    $max_size    Maximum log size in bytes.
     */
    private $max_size = 5242880;

    /**
     * Maximum number of log entries to keep in memory.
     *
     * @since    1.0.0
     * @access   private
     * @var      int    $max_entries    Maximum number of log entries.
     */
    private $max_entries = 1000;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_file = trailingslashit($upload_dir['basedir']) . 'fbds-log.txt';
        
        // Create log file if it doesn't exist
        if (!file_exists($this->log_file)) {
            $this->create_log_file();
        }
    }

    /**
     * Create the log file.
     *
     * @since    1.0.0
     */
    private function create_log_file() {
        $header = "FileBird Dropbox Sync Pro - Log File\n";
        $header .= "Created: " . date('Y-m-d H:i:s') . "\n";
        $header .= "----------------------------------------\n\n";
        
        file_put_contents($this->log_file, $header);
    }

    /**
     * Log a message.
     *
     * @since    1.0.0
     * @param    string    $message    The message to log.
     * @param    string    $level      The log level (info, warning, error).
     */
    public function log($message, $level = 'info') {
        // Format log entry
        $time = date('Y-m-d H:i:s');
        $entry = "[{$time}] [{$level}] {$message}\n";
        
        // Check if log file exists and is writable
        if (file_exists($this->log_file) && is_writable($this->log_file)) {
            // Check if log file has exceeded max size
            if (filesize($this->log_file) > $this->max_size) {
                $this->rotate_log();
            }
            
            // Append to log file
            file_put_contents($this->log_file, $entry, FILE_APPEND);
        }
        
        // Also store log in database for quick access
        $this->store_log_in_db($message, $level);
    }

    /**
     * Store log entry in database.
     *
     * @since    1.0.0
     * @param    string    $message    The message to log.
     * @param    string    $level      The log level (info, warning, error).
     */
    private function store_log_in_db($message, $level) {
        $logs = get_option('fbds_logs', []);
        
        // Add new log entry
        $logs[] = [
            'time' => time(),
            'level' => $level,
            'message' => $message,
        ];
        
        // Keep only the most recent entries
        if (count($logs) > $this->max_entries) {
            $logs = array_slice($logs, -$this->max_entries);
        }
        
        update_option('fbds_logs', $logs);
    }

    /**
     * Get recent logs from database.
     *
     * @since    1.0.0
     * @param    int       $limit    The number of log entries to retrieve.
     * @return   array               The log entries.
     */
    public function get_recent_logs($limit = 100) {
        $logs = get_option('fbds_logs', []);
        
        // Sort by time (newest first)
        usort($logs, function($a, $b) {
            return $b['time'] - $a['time'];
        });
        
        // Return only the requested number of logs
        return array_slice($logs, 0, $limit);
    }

    /**
     * Get all logs from the log file.
     *
     * @since    1.0.0
     * @return   string    The log file contents.
     */
    public function get_log_file_contents() {
        if (file_exists($this->log_file)) {
            return file_get_contents($this->log_file);
        }
        
        return '';
    }

    /**
     * Clear all logs.
     *
     * @since    1.0.0
     */
    public function clear_logs() {
        // Clear log file
        $this->create_log_file();
        
        // Clear database logs
        update_option('fbds_logs', []);
    }

    /**
     * Rotate the log file.
     *
     * @since    1.0.0
     */
    private function rotate_log() {
        // Create a backup of the current log
        $backup_file = $this->log_file . '.' . date('Y-m-d-H-i-s') . '.bak';
        copy($this->log_file, $backup_file);
        
        // Create a new log file
        $this->create_log_file();
        
        // Keep only 5 most recent backup files
        $pattern = $this->log_file . '.*.bak';
        $backup_files = glob($pattern);
        
        if (count($backup_files) > 5) {
            usort($backup_files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $files_to_delete = array_slice($backup_files, 0, count($backup_files) - 5);
            
            foreach ($files_to_delete as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Get the log file path.
     *
     * @since    1.0.0
     * @return   string    The log file path.
     */
    public function get_log_file_path() {
        return $this->log_file;
    }

    /**
     * Get logs filtered by level.
     *
     * @since    1.0.0
     * @param    string    $level    The log level to filter by.
     * @return   array               The filtered log entries.
     */
    public function get_logs_by_level($level) {
        $logs = get_option('fbds_logs', []);
        
        return array_filter($logs, function($log) use ($level) {
            return $log['level'] === $level;
        });
    }
}