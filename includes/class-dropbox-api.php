<?php
/**
 * The Dropbox API wrapper class.
 *
 * @since      1.0.0
 * @package    FileBirdDropboxSyncPro
 */

class FileBird_Dropbox_API {

    /**
     * Dropbox app key.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $app_key    The Dropbox app key.
     */
    private $app_key;

    /**
     * Dropbox app secret.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $app_secret    The Dropbox app secret.
     */
    private $app_secret;

    /**
     * Dropbox access token.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $access_token    The Dropbox access token.
     */
    private $access_token;

    /**
     * Dropbox refresh token.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $refresh_token    The Dropbox refresh token.
     */
    private $refresh_token;

    /**
     * Logger instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      FileBird_Dropbox_Sync_Logger    $logger    Logger instance.
     */
    private $logger;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->app_key = get_option('fbds_dropbox_app_key', '');
        $this->app_secret = get_option('fbds_dropbox_app_secret', '');
        $this->access_token = get_option('fbds_dropbox_access_token', '');
        $this->refresh_token = get_option('fbds_dropbox_refresh_token', '');
        $this->logger = new FileBird_Dropbox_Sync_Logger();
    }

    /**
     * Get the authorization URL for Dropbox OAuth.
     *
     * @since    1.0.0
     * @return   string    The authorization URL.
     */
    public function get_auth_url() {
        $redirect_uri = admin_url('admin-ajax.php?action=fbds_dropbox_oauth_callback');
        $state = wp_create_nonce('fbds_dropbox_auth');
        
        return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
            'client_id' => $this->app_key,
            'response_type' => 'code',
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'token_access_type' => 'offline',
        ]);
    }

    /**
     * Exchange an authorization code for an access token.
     *
     * @since    1.0.0
     * @param    string    $code    The authorization code.
     * @return   bool               Whether the token exchange was successful.
     */
    public function exchange_code_for_token($code) {
        $redirect_uri = admin_url('admin-ajax.php?action=fbds_dropbox_oauth_callback');
        
        $response = wp_remote_post('https://api.dropboxapi.com/oauth2/token', [
            'body' => [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $this->app_key,
                'client_secret' => $this->app_secret,
                'redirect_uri' => $redirect_uri,
            ],
        ]);

        if (is_wp_error($response)) {
            $this->logger->log('Error exchanging code for token: ' . $response->get_error_message(), 'error');
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'], $body['refresh_token'])) {
            $this->access_token = $body['access_token'];
            $this->refresh_token = $body['refresh_token'];
            
            update_option('fbds_dropbox_access_token', $this->access_token);
            update_option('fbds_dropbox_refresh_token', $this->refresh_token);
            
            return true;
        }

        $this->logger->log('Invalid response from Dropbox when exchanging code for token', 'error');
        return false;
    }

    /**
     * Refresh the access token.
     *
     * @since    1.0.0
     * @return   bool    Whether the token refresh was successful.
     */
    public function refresh_access_token() {
        if (empty($this->refresh_token)) {
            $this->logger->log('No refresh token available', 'error');
            return false;
        }

        $response = wp_remote_post('https://api.dropboxapi.com/oauth2/token', [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refresh_token,
                'client_id' => $this->app_key,
                'client_secret' => $this->app_secret,
            ],
        ]);

        if (is_wp_error($response)) {
            $this->logger->log('Error refreshing token: ' . $response->get_error_message(), 'error');
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            $this->access_token = $body['access_token'];
            update_option('fbds_dropbox_access_token', $this->access_token);
            return true;
        }

        $this->logger->log('Invalid response from Dropbox when refreshing token', 'error');
        return false;
    }

    /**
     * Make a request to the Dropbox API with improved timeout handling.
     *
     * @since    1.0.0
     * @param    string    $endpoint      The API endpoint.
     * @param    array     $params        The request parameters.
     * @param    string    $method        The HTTP method (POST or GET).
     * @param    string    $api_version   The API version (1 or 2).
     * @return   array|WP_Error           The response data or a WP_Error.
     */
    public function make_request($endpoint, $params = [], $method = 'POST', $api_version = '2') {
        if (empty($this->access_token)) {
            return new WP_Error('no_token', 'No access token available');
        }

        $url = "https://api.dropboxapi.com/{$api_version}/{$endpoint}";
        $headers = [
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type' => 'application/json',
        ];

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 15,  // Increase timeout to 15 seconds
            'sslverify' => true,
        ];

        if (!empty($params)) {
            $args['body'] = json_encode($params);
        }

        // Try up to 3 times
        $retries = 3;
        $response = null;

        while ($retries > 0) {
            $response = wp_remote_request($url, $args);
            
            if (!is_wp_error($response)) {
                // Check for 429 (rate limit) response
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 429) {
                    // Rate limited, wait and retry
                    $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                    $wait = $retry_after ? intval($retry_after) : 10;
                    sleep($wait);
                    $retries--;
                    continue;
                }
                
                // Check for 401 (auth expired)
                if ($code === 401) {
                    // Token expired, try to refresh
                    if ($this->refresh_access_token()) {
                        // Retry with the new token
                        $headers['Authorization'] = 'Bearer ' . $this->access_token;
                        $args['headers'] = $headers;
                        $retries--;
                        continue;
                    } else {
                        return new WP_Error('token_refresh_failed', 'Could not refresh access token');
                    }
                }
                
                // Success or other error that we don't retry
                break;
            } else {
                // Check if it's a timeout error
                $error_message = $response->get_error_message();
                if (strpos($error_message, 'timed out') !== false || 
                    strpos($error_message, 'timeout') !== false) {
                    $this->logger->log('API request timed out, retrying...', 'warning');
                    $retries--;
                    if ($retries > 0) {
                        // Increase timeout for each retry
                        $args['timeout'] += 10;
                        sleep(2);  // Wait 2 seconds before retrying
                        continue;
                    }
                } else {
                    // Other WP_Error, no need to retry
                    break;
                }
            }
        }

    if (is_wp_error($response)) {
        $this->logger->log('API request error: ' . $response->get_error_message(), 'error');
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body;
}

    /**
     * Upload a file to Dropbox.
     *
     * @since    1.0.0
     * @param    string    $file_path     The local file path.
     * @param    string    $dropbox_path  The Dropbox path.
     * @return   array|WP_Error           The response data or a WP_Error.
     */
    public function upload_file($file_path, $dropbox_path) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'File not found: ' . $file_path);
        }

        $file_size = filesize($file_path);
        $chunk_size = 4 * 1024 * 1024; // 4MB chunks

        if ($file_size <= $chunk_size) {
            // Small file, upload in one request
            return $this->upload_small_file($file_path, $dropbox_path);
        } else {
            // Large file, use chunked upload
            return $this->upload_large_file($file_path, $dropbox_path, $file_size, $chunk_size);
        }
    }

    /**
     * Upload a small file (< 4MB) to Dropbox.
     *
     * @since    1.0.0
     * @param    string    $file_path     The local file path.
     * @param    string    $dropbox_path  The Dropbox path.
     * @return   array|WP_Error           The response data or a WP_Error.
     */
    private function upload_small_file($file_path, $dropbox_path) {
        $file_contents = file_get_contents($file_path);
        
        $headers = [
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type' => 'application/octet-stream',
            'Dropbox-API-Arg' => json_encode([
                'path' => $dropbox_path,
                'mode' => 'overwrite',
                'autorename' => true,
                'mute' => false,
            ]),
        ];
        
        $response = wp_remote_post('https://content.dropboxapi.com/2/files/upload', [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $file_contents,
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log('Error uploading file: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }

    /**
     * Upload a large file (> 4MB) to Dropbox using chunked upload.
     *
     * @since    1.0.0
     * @param    string    $file_path     The local file path.
     * @param    string    $dropbox_path  The Dropbox path.
     * @param    int       $file_size     The file size.
     * @param    int       $chunk_size    The chunk size.
     * @return   array|WP_Error           The response data or a WP_Error.
     */
    private function upload_large_file($file_path, $dropbox_path, $file_size, $chunk_size) {
        $file = fopen($file_path, 'rb');
        $session_id = null;
        $offset = 0;
        
        // Start session
        $headers = [
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type' => 'application/octet-stream',
            'Dropbox-API-Arg' => json_encode([
                'close' => false,
            ]),
        ];
        
        $chunk = fread($file, $chunk_size);
        $response = wp_remote_post('https://content.dropboxapi.com/2/files/upload_session/start', [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $chunk,
        ]);
        
        if (is_wp_error($response)) {
            fclose($file);
            $this->logger->log('Error starting upload session: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $session_id = $body['session_id'];
        $offset += $chunk_size;
        
        // Append chunks
        while ($offset < $file_size) {
            $chunk = fread($file, $chunk_size);
            $headers = [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => json_encode([
                    'cursor' => [
                        'session_id' => $session_id,
                        'offset' => $offset,
                    ],
                    'close' => false,
                ]),
            ];
            
            $response = wp_remote_post('https://content.dropboxapi.com/2/files/upload_session/append_v2', [
                'method' => 'POST',
                'headers' => $headers,
                'body' => $chunk,
            ]);
            
            if (is_wp_error($response)) {
                fclose($file);
                $this->logger->log('Error appending to upload session: ' . $response->get_error_message(), 'error');
                return $response;
            }
            
            $offset += strlen($chunk);
        }
        
        fclose($file);
        
        // Finish session
        $headers = [
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type' => 'application/json',
        ];
        
        $response = wp_remote_post('https://api.dropboxapi.com/2/files/upload_session/finish', [
            'method' => 'POST',
            'headers' => $headers,
            'body' => json_encode([
                'cursor' => [
                    'session_id' => $session_id,
                    'offset' => $offset,
                ],
                'commit' => [
                    'path' => $dropbox_path,
                    'mode' => 'overwrite',
                    'autorename' => true,
                    'mute' => false,
                ],
            ]),
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log('Error finishing upload session: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }

    /**
     * Download a file from Dropbox with improved timeout handling.
     *
     * @since    1.0.0
     * @param    string    $dropbox_path  The Dropbox path.
     * @param    string    $local_path    The local file path.
     * @return   bool                     Whether the download was successful.
     */
    public function download_file($dropbox_path, $local_path) {
        $headers = [
            'Authorization' => 'Bearer ' . $this->access_token,
            'Dropbox-API-Arg' => json_encode([
                'path' => $dropbox_path,
            ]),
        ];
        
        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'stream' => true,
            'filename' => $local_path,
            'timeout' => 30,  // Increase timeout to 30 seconds for downloads
        ];
        
        // Try up to 3 times
        $retries = 3;
        $success = false;
        
        while (!$success && $retries > 0) {
            $response = wp_remote_get('https://content.dropboxapi.com/2/files/download', $args);
            
            if (!is_wp_error($response)) {
                $success = true;
            } else {
                $error_message = $response->get_error_message();
                $this->logger->log('Error downloading file (attempt ' . (4 - $retries) . '): ' . $error_message, 'warning');
                
                // Check if it's a timeout error
                if (strpos($error_message, 'timed out') !== false || 
                    strpos($error_message, 'timeout') !== false) {
                    $retries--;
                    if ($retries > 0) {
                        $args['timeout'] += 15;  // Increase timeout for each retry
                        sleep(2);  // Wait 2 seconds before retrying
                    }
                } else {
                    // Other WP_Error, no need to retry
                    $retries = 0;
                }
            }
        }
        
        if (!$success) {
            $this->logger->log('Error downloading file: ' . $dropbox_path, 'error');
            return false;
        }
        
        return true;
    }
    
    /**
     * Create a folder in Dropbox.
     *
     * @since    1.0.0
     * @param    string    $path  The folder path.
     * @return   array|WP_Error   The response data or a WP_Error.
     */
    public function create_folder($path) {
        return $this->make_request('files/create_folder_v2', [
            'path' => $path,
            'autorename' => false,
        ]);
    }

    /**
     * Delete a file or folder from Dropbox.
     *
     * @since    1.0.0
     * @param    string    $path  The path to delete.
     * @return   array|WP_Error   The response data or a WP_Error.
     */
    public function delete($path) {
        return $this->make_request('files/delete_v2', [
            'path' => $path,
        ]);
    }

    /**
     * List the contents of a folder.
     *
     * @since    1.0.0
     * @param    string    $path  The folder path.
     * @return   array|WP_Error   The response data or a WP_Error.
     */
    public function list_folder($path) {
        return $this->make_request('files/list_folder', [
            'path' => $path,
            'recursive' => false,
            'include_media_info' => true,
            'include_deleted' => false,
            'include_has_explicit_shared_members' => false,
        ]);
    }

    /**
     * Get metadata for a file or folder.
     *
     * @since    1.0.0
     * @param    string    $path  The path.
     * @return   array|WP_Error   The response data or a WP_Error.
     */
    public function get_metadata($path) {
        return $this->make_request('files/get_metadata', [
            'path' => $path,
            'include_media_info' => true,
            'include_deleted' => false,
            'include_has_explicit_shared_members' => false,
        ]);
    }

    /**
     * Move a file or folder.
     *
     * @since    1.0.0
     * @param    string    $from_path  The source path.
     * @param    string    $to_path    The destination path.
     * @return   array|WP_Error        The response data or a WP_Error.
     */
    public function move($from_path, $to_path) {
        return $this->make_request('files/move_v2', [
            'from_path' => $from_path,
            'to_path' => $to_path,
            'allow_shared_folder' => false,
            'autorename' => true,
            'allow_ownership_transfer' => false,
        ]);
    }

    /**
     * Check if the Dropbox connection is active.
     *
     * @since    1.0.0
     * @return   bool    Whether the connection is active.
     */
    public function is_connected() {
        if (empty($this->access_token)) {
            return false;
        }

        $response = $this->make_request('users/get_current_account', [], 'POST');
        return !is_wp_error($response);
    }

    /**
     * Register the Dropbox webhook endpoint.
     *
     * @since    1.0.0
     */
    public function register_webhook_endpoint() {
        register_rest_route('filebird-dropbox-sync/v1', '/webhook', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // No authentication required for Dropbox webhook
        ]);
    }

    /**
     * Check and refresh the access token if needed.
     *
     * @since    1.0.0
     * @return   bool    Whether the token is valid (refreshed if needed).
     */
    public function check_and_refresh_token() {
        // If we don't have a refresh token, we can't refresh
        if (empty($this->refresh_token)) {
            $this->logger->log('No refresh token available, cannot check token status', 'warning');
            return false;
        }
        
        // Try a simple API call to check if token is valid
        $result = $this->make_request('users/get_current_account', [], 'POST');
        
        // If the call succeeded, token is valid
        if (!is_wp_error($result) && !isset($result['error_summary'])) {
            $this->logger->log('Dropbox token is valid', 'info');
            return true;
        }
        
        // Token may be expired, try to refresh
        $this->logger->log('Dropbox token may be expired, attempting to refresh', 'info');
        
        $response = wp_remote_post('https://api.dropboxapi.com/oauth2/token', [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refresh_token,
                'client_id' => $this->app_key,
                'client_secret' => $this->app_secret,
            ],
            'timeout' => 15,
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log('Error refreshing token: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['access_token'])) {
            // Success - update the token
            $this->access_token = $body['access_token'];
            update_option('fbds_dropbox_access_token', $this->access_token);
            
            // If refresh token was returned (rare), update it too
            if (isset($body['refresh_token'])) {
                $this->refresh_token = $body['refresh_token'];
                update_option('fbds_dropbox_refresh_token', $this->refresh_token);
            }
            
            $this->logger->log('Successfully refreshed Dropbox access token', 'info');
            return true;
        }
        
        $this->logger->log('Failed to refresh Dropbox token: ' . 
            (isset($body['error_description']) ? $body['error_description'] : 'Unknown error'), 'error');
        return false;
    }

    /**
     * Get the current connection status with details.
     *
     * @since    1.0.0
     * @return   array    Connection status details.
     */
    public function get_connection_status() {
        $status = [
            'connected' => false,
            'app_key_set' => !empty($this->app_key),
            'app_secret_set' => !empty($this->app_secret),
            'access_token_set' => !empty($this->access_token),
            'refresh_token_set' => !empty($this->refresh_token),
            'error' => null
        ];
        
        if (empty($this->access_token)) {
            $status['error'] = 'No access token available';
            return $status;
        }
        
        if (empty($this->refresh_token)) {
            $status['error'] = 'No refresh token available';
        }
        
        // Try to get account info as a connection test
        $response = wp_remote_get('https://api.dropboxapi.com/2/users/get_current_account', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
            ],
            'method' => 'POST',
            'timeout' => 10,
        ]);
        
        if (is_wp_error($response)) {
            $status['error'] = 'Connection error: ' . $response->get_error_message();
            return $status;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code === 200) {
            $status['connected'] = true;
            if (isset($body['email'])) {
                $status['email'] = $body['email'];
            }
            if (isset($body['name']) && isset($body['name']['display_name'])) {
                $status['name'] = $body['name']['display_name'];
            }
        } else {
            $status['error'] = isset($body['error_summary']) ? $body['error_summary'] : 'HTTP error: ' . $code;
        }
        
        return $status;
    }

    /**
     * Handle the Dropbox webhook.
     *
     * @since    1.0.0
     * @param    WP_REST_Request    $request    The request object.
     * @return   WP_REST_Response               The response object.
     */
    public function handle_webhook($request) {
        $challenge = $request->get_param('challenge');
        
        if ($challenge) {
            // This is a verification request from Dropbox
            $this->logger->log('Received verification challenge from Dropbox', 'info');
            return new WP_REST_Response($challenge, 200);
        }
        
        // This is a notification about changes
        $this->logger->log('Received webhook notification from Dropbox', 'info');
        
        // Queue a sync job
        $sync_time = time() + 60; // Wait for a minute to allow Dropbox to process changes
        wp_schedule_single_event($sync_time, 'fbds_scheduled_sync');
        
        return new WP_REST_Response(['status' => 'success'], 200);
    }
}