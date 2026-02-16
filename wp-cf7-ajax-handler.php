<?php
/**
 * Plugin Name: CF7 AJAX Handler for External Forms
 * Description: Handles Contact Form 7 submissions from external React applications
 * Version: 1.1.1
 * Author: Brilliant Mindworks
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log plugin activity for debugging
 */
function bm_log($message, $data = null) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('BM CF7 Plugin: ' . $message);
        if ($data !== null) {
            error_log('Data: ' . print_r($data, true));
        }
    }
}

// Log when plugin is loaded
bm_log('Plugin loaded successfully');

/**
 * Test endpoint to verify plugin is working
 */
function bm_test_endpoint() {
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');
    
    global $wp_filter;
    
    // Check what actions are registered
    $ajax_actions = [];
    if (isset($wp_filter['wp_ajax_bm_cf7_submit'])) {
        $ajax_actions['wp_ajax_bm_cf7_submit'] = 'registered';
    }
    if (isset($wp_filter['wp_ajax_nopriv_bm_cf7_submit'])) {
        $ajax_actions['wp_ajax_nopriv_bm_cf7_submit'] = 'registered';
    }
    
    wp_send_json_success([
        'message' => 'Plugin is working!',
        'wordpress_version' => get_bloginfo('version'),
        'cf7_active' => class_exists('WPCF7_ContactForm'),
        'plugin_version' => '1.0.9',
        'registered_actions' => $ajax_actions,
    ]);
}

/**
 * Handle Contact Form 7 submission via AJAX
 * This allows external applications to submit to CF7 without CORS issues
 */
function bm_handle_cf7_submission() {
    // Log that function was called
    bm_log('=== FORM SUBMISSION HANDLER CALLED ===');
    bm_log('Request Method', $_SERVER['REQUEST_METHOD']);
    bm_log('POST data', $_POST);
    
    // Allow requests from any origin (adjust as needed for security)
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Content-Type: application/json');
    
    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        bm_log('Handling OPTIONS preflight request');
        http_response_code(200);
        exit;
    }
    
    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        bm_log('ERROR: Method not allowed');
        wp_send_json_error(['message' => 'Method not allowed'], 405);
        exit;
    }
    
    // Check if Contact Form 7 is active
    if (!class_exists('WPCF7_ContactForm')) {
        bm_log('ERROR: CF7 not active');
        wp_send_json_error(['message' => 'Contact Form 7 is not installed or activated'], 500);
        exit;
    }
    
    // Get the form ID from POST data
    $form_id = isset($_POST['_wpcf7']) ? sanitize_text_field($_POST['_wpcf7']) : '';
    bm_log('Form ID received', $form_id);
    
    if (empty($form_id)) {
        bm_log('ERROR: Form ID is empty');
        wp_send_json_error(['message' => 'Form ID is required'], 400);
        exit;
    }
    
    // Get the contact form by ID or hash
    // CF7 forms can be identified by numeric ID or hash ID
    $contact_form = null;
    
    // First, try as numeric ID
    if (is_numeric($form_id)) {
        $contact_form = wpcf7_contact_form($form_id);
        bm_log('Tried numeric ID', $contact_form ? 'Found' : 'Not found');
    }
    
    // If not found, search all forms and check their hash
    if (!$contact_form) {
        $args = array(
            'post_type' => 'wpcf7_contact_form',
            'posts_per_page' => -1,
        );
        
        $forms = get_posts($args);
        bm_log('Searching through forms for hash match', count($forms) . ' forms found');
        
        foreach ($forms as $form_post) {
            $cf = wpcf7_contact_form($form_post->ID);
            if ($cf && $cf->hash() === $form_id) {
                $contact_form = $cf;
                bm_log('Form found by hash!', 'ID: ' . $form_post->ID . ', Hash: ' . $cf->hash());
                break;
            }
        }
    }
    
    bm_log('Contact form object', $contact_form ? 'Found' : 'Not found');
    
    if (!$contact_form) {
        bm_log('ERROR: Contact form not found for ID: ' . $form_id);
        wp_send_json_error(['message' => 'Contact form not found'], 404);
        exit;
    }
    
    bm_log('Processing form submission...');
    
    // Let Contact Form 7 handle the submission naturally
    // CF7 reads from $_POST automatically
    $submission = WPCF7_Submission::get_instance($contact_form);
    
    if (!$submission) {
        bm_log('ERROR: Could not get submission instance');
        wp_send_json_error(['message' => 'Could not process submission'], 500);
        exit;
    }
    
    // Get the result from CF7
    $result = [
        'status' => $submission->get_status(),
        'message' => $submission->get_response(),
    ];
    
    // Add invalid fields if validation failed
    $invalid_fields = $submission->get_invalid_fields();
    if (!empty($invalid_fields)) {
        $result['invalid_fields'] = array_map(function($field) {
            return [
                'field' => $field->get_name(),
                'message' => $field->get_message(),
            ];
        }, $invalid_fields);
    }
    
    bm_log('Submission result', $result);
    
    // Send JSON response
    wp_send_json($result);
    exit;
}

// Register AJAX handlers for both logged-in and non-logged-in users
add_action('wp_ajax_bm_cf7_submit', 'bm_handle_cf7_submission');
add_action('wp_ajax_nopriv_bm_cf7_submit', 'bm_handle_cf7_submission');

// Register test endpoint
add_action('wp_ajax_bm_test_endpoint', 'bm_test_endpoint');
add_action('wp_ajax_nopriv_bm_test_endpoint', 'bm_test_endpoint');

/**
 * Debug endpoint - echoes back what WordPress receives
 */
function bm_debug_request() {
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');
    
    wp_send_json_success([
        'message' => 'Debug endpoint called successfully',
        'post_data' => $_POST,
        'get_data' => $_GET,
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
        'http_origin' => $_SERVER['HTTP_ORIGIN'] ?? 'not set',
    ]);
}

add_action('wp_ajax_bm_debug_request', 'bm_debug_request');
add_action('wp_ajax_nopriv_bm_debug_request', 'bm_debug_request');

/**
 * List all CF7 forms - for debugging
 */
function bm_list_cf7_forms() {
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');
    
    if (!class_exists('WPCF7_ContactForm')) {
        wp_send_json_error(['message' => 'CF7 not installed']);
        exit;
    }
    
    // Get all CF7 forms
    $args = array(
        'post_type' => 'wpcf7_contact_form',
        'posts_per_page' => -1,
    );
    
    $forms = get_posts($args);
    $form_data = [];
    
    foreach ($forms as $form_post) {
        $contact_form = wpcf7_contact_form($form_post->ID);
        
        $form_info = [
            'id' => $form_post->ID,
            'title' => $form_post->post_title,
            'hash' => $contact_form ? $contact_form->hash() : 'N/A',
            'shortcode' => $contact_form ? $contact_form->shortcode() : 'N/A',
        ];
        
        // Get all post meta
        $all_meta = get_post_meta($form_post->ID);
        $form_info['meta_keys'] = array_keys($all_meta);
        
        $form_data[] = $form_info;
    }
    
    wp_send_json_success([
        'forms_found' => count($forms),
        'forms' => $form_data,
    ]);
}

add_action('wp_ajax_bm_list_forms', 'bm_list_cf7_forms');
add_action('wp_ajax_nopriv_bm_list_forms', 'bm_list_cf7_forms');
