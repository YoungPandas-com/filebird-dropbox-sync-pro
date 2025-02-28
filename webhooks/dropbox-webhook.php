<?php
/**
 * Dropbox webhook handler
 *
 * @package FileBirdDropboxSyncPro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Dropbox Webhook Handler Class
 */
class FileBird_Dropbox_Webhook_Handler {

    /**
     * Logger instance.
     *
     * @var FileBird_Dropbox_Sync_Logger
     */
    private $logger;

    /**
     * Dropbox API instance.
     *
     * @var FileBird_Dropbox_API
     */
    private $dropbox_api;

    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->logger = new FileBird_Dropbox_Sync_Logger();
        $this->dropbox_api = new FileBird_Dropbox_API();
        
        // Register the webhook endpoint
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }

    /**
     * Register the webhook endpoint.
     */
    public function register_webhook_endpoint() {
        // Register the standard plugin route
        register_rest_route('filebird-dropbox-sync/v1', '/webhook', array(
            'methods' => WP_REST_Server::ALLMETHODS, // Handle GET and POST
            'callback' => array($this, 'handle_webhook_request'),
            'permission_callback' => '__return_true', // No authentication required for Dropbox webhook
        ));
        
        // Also register the route that's currently configured in Dropbox for backward compatibility
        register_rest_route('dropbox/v1', '/webhook', array(
            'methods' => WP_REST_Server::ALLMETHODS, // Handle GET and POST
            'callback' => array($this, 'handle_webhook_request'),
            'permission_callback' => '__return_true', // No authentication required for Dropbox webhook
        ));
    }

    /**
     * Handle webhook request from Dropbox with better error handling.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_webhook_request($request) {
        try {
            // Get request method
            $method = $request->get_method();
            
            if ($method === 'GET') {
                // This is a verification request from Dropbox
                return $this->handle_verification_request($request);
            } else if ($method === 'POST') {
                // This is a notification about changes
                return $this->handle_notification_request($request);
            }
            
            // Invalid request method
            return new WP_REST_Response(
                array('error' => 'Invalid request method'),
                405
            );
        } catch (Exception $e) {
            // Log the error but return a success response to prevent Dropbox from disabling the webhook
            $this->logger->log('Error in webhook handler: ' . $e->getMessage(), 'error');
            return new WP_REST_Response(
                array('status' => 'success', 'message' => 'Error handled gracefully'),
                200
            );
        }
    }

/**
 * Handle verification request from Dropbox with better error handling.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response The response object.
 */
private function handle_verification_request($request) {
    try {
        // Get challenge parameter
        $challenge = $request->get_param('challenge');
        
        if (!$challenge) {
            $this->logger->log('Missing challenge parameter in Dropbox verification', 'warning');
            // Return a 200 response anyway to prevent Dropbox from disabling the webhook
            return new WP_REST_Response('', 200);
        }
        
        $this->logger->log('Received verification challenge from Dropbox: ' . $challenge, 'info');
        
        // FIXED: Return challenge directly as plain text
        header('Content-Type: text/plain');
        echo $challenge;
        exit; // Important: prevent any additional output
    } catch (Exception $e) {
        $this->logger->log('Error in verification handler: ' . $e->getMessage(), 'error');
        // Return an empty 200 response to prevent Dropbox from disabling the webhook
        return new WP_REST_Response('', 200);
    }
}

    /**
     * Handle notification request from Dropbox.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    private function handle_notification_request($request) {
        // Log the notification
        $this->logger->log('Received webhook notification from Dropbox', 'info');
        
        // Verify the request is from Dropbox (check signature if provided)
        if (!$this->verify_dropbox_request($request)) {
            $this->logger->log('Invalid webhook request signature', 'error');
            return new WP_REST_Response(
                array('error' => 'Invalid request signature'),
                403
            );
        }
        
        // Get the request body
        $body = $request->get_body();
        $data = json_decode($body, true);
        
        // Log notification details if available
        if ($data && isset($data['list_folder']['accounts'])) {
            $accounts = implode(', ', $data['list_folder']['accounts']);
            $this->logger->log('Dropbox accounts with changes: ' . $accounts, 'info');
        }
        
        // Schedule multiple sync events with different timing to ensure changes are captured
        // First sync after 1 minute
        $sync_time_1 = time() + 60;
        wp_schedule_single_event($sync_time_1, 'fbds_scheduled_sync', array('from_dropbox'));
        
        // Second sync after 5 minutes in case the first one misses anything
        $sync_time_2 = time() + 300;
        wp_schedule_single_event($sync_time_2, 'fbds_scheduled_sync', array('from_dropbox'));
        
        // Third sync that's bidirectional after 10 minutes to ensure everything is synced
        $sync_time_3 = time() + 600;
        wp_schedule_single_event($sync_time_3, 'fbds_scheduled_sync', array('both'));
        
        $this->logger->log('Scheduled multiple syncs in response to Dropbox webhook', 'info');
        
        // Return success response
        return new WP_REST_Response(
            array('status' => 'success'),
            200
        );
    }

    /**
     * Verify that the request is from Dropbox.
     *
     * @param WP_REST_Request $request The request object.
     * @return bool Whether the request is valid.
     */
    private function verify_dropbox_request($request) {
        // Get signature from header
        $signature = $request->get_header('X-Dropbox-Signature');
        
        // If no signature provided, we can't verify
        // But for simplicity, we'll accept it (you might want to change this in production)
        if (!$signature) {
            $this->logger->log('No Dropbox signature in webhook request', 'warning');
            return true;
        }
        
        // Get app secret
        $app_secret = get_option('fbds_dropbox_app_secret', '');
        
        // If no app secret, we can't verify
        if (!$app_secret) {
            $this->logger->log('No Dropbox app secret available for webhook verification', 'warning');
            return true;
        }
        
        // Get request body
        $body = $request->get_body();
        
        // Calculate HMAC using app secret
        $calculated_signature = hash_hmac('sha256', $body, $app_secret);
        
        // Compare signatures
        return hash_equals($signature, $calculated_signature);
    }
}

// Initialize the webhook handler
new FileBird_Dropbox_Webhook_Handler();