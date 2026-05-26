<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACMETA_VERSION' ) ) {
	return;
}
define( 'ACMETA_VERSION', '3.0.3' );
define( 'ACMETA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACMETA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACMETA_PLUGIN_FILE', __FILE__ );

// Load amplifi.studio shared framework.
require_once ACMETA_PLUGIN_DIR . 'includes/amplifi-framework.php';

class AC_Bulk_Meta_Pages {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_extra_submenus'), 10);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ac_update_yoast_meta', array($this, 'ajax_update_yoast_meta'));
        add_action('wp_ajax_ac_get_pages_data', array($this, 'ajax_get_pages_data'));
        add_action('wp_ajax_ac_save_openai_key', array($this, 'ajax_save_openai_key'));
        add_action('wp_ajax_ac_validate_openai_key', array($this, 'ajax_validate_openai_key'));
        add_action('wp_ajax_ac_save_webhook_url', array($this, 'ajax_save_webhook_url'));
        add_action('wp_ajax_ac_save_global_prompt', array($this, 'ajax_save_global_prompt'));
        add_action('wp_ajax_ac_save_site_title_override', array($this, 'ajax_save_site_title_override'));
        add_action('wp_ajax_ac_save_dark_mode', array($this, 'ajax_save_dark_mode'));
        add_action('wp_ajax_ac_generate_meta_description', array($this, 'ajax_generate_meta_description'));
        add_action('wp_ajax_ac_generate_title_tag', array($this, 'ajax_generate_title_tag'));
        add_action('wp_ajax_ac_generate_focus_keyphrase', array($this, 'ajax_generate_focus_keyphrase'));
        add_action('wp_ajax_ac_bulk_generate_start', array($this, 'ajax_bulk_generate_start'));
        add_action('wp_ajax_ac_bulk_generate_next', array($this, 'ajax_bulk_generate_next'));
        add_action('wp_ajax_ac_bulk_generate_status', array($this, 'ajax_bulk_generate_status'));
        add_action('wp_ajax_ac_bulk_generate_stop', array($this, 'ajax_bulk_generate_stop'));
        add_action('wp_ajax_ac_bulk_generate_titles', array($this, 'ajax_bulk_generate_titles'));
        add_action('wp_ajax_ac_bulk_generate_descriptions', array($this, 'ajax_bulk_generate_descriptions'));
        add_action('wp_ajax_ac_bulk_generate_focus_keyphrases', array($this, 'ajax_bulk_generate_focus_keyphrases'));
        add_action('wp_ajax_ac_generate_selected', array($this, 'ajax_generate_selected'));
        add_action('wp_ajax_ac_get_ai_logs', array($this, 'ajax_get_ai_logs'));
        add_action('wp_ajax_ac_generate_faqs', array($this, 'ajax_generate_faqs'));
        add_action('wp_ajax_ac_get_faqs_data', array($this, 'ajax_get_faqs_data'));
        add_action('wp_ajax_ac_bulk_generate_faqs', array($this, 'ajax_bulk_generate_faqs'));
        add_action('wp_ajax_ac_export_faqs_csv', array($this, 'ajax_export_faqs_csv'));
        add_action('wp_ajax_ac_delete_faq', array($this, 'ajax_delete_faq'));
        add_action('wp_ajax_ac_add_faq', array($this, 'ajax_add_faq'));
        add_action('wp_ajax_ac_save_faq', array($this, 'ajax_save_faq'));
        add_action('wp_ajax_ac_save_faq_focus', array($this, 'ajax_save_faq_focus'));
        add_action('wp_ajax_ac_save_faq_count', array($this, 'ajax_save_faq_count'));
        add_action('wp_ajax_ac_save_faq_deploy_global', array($this, 'ajax_save_faq_deploy_global'));
        add_action('wp_ajax_ac_deploy_faqs', array($this, 'ajax_deploy_faqs'));
        add_action('wp_ajax_ac_undeploy_faqs', array($this, 'ajax_undeploy_faqs'));
        add_action('wp_ajax_ac_save_jsonld_settings', array($this, 'ajax_save_jsonld_settings'));
        add_action('wp_ajax_ac_generate_jsonld', array($this, 'ajax_generate_jsonld'));
        add_action('wp_ajax_ac_get_jsonld_data', array($this, 'ajax_get_jsonld_data'));
        add_action('wp_ajax_ac_save_jsonld_post', array($this, 'ajax_save_jsonld_post'));
        add_action('wp_ajax_ac_validate_jsonld', array($this, 'ajax_validate_jsonld'));
        add_action('wp_head', array($this, 'output_jsonld'));
        add_action('wp_head', array($this, 'output_faq_deploy_head'));
        add_action('wp_footer', array($this, 'output_faqs_before_footer'));
    }
    
    public function add_extra_submenus() {
        add_submenu_page(
            'amplifi-studio',
            'Meta: FAQ',
            'Meta: FAQ',
            'edit_posts',
            'amplifi-ac-bulk-meta-faq',
            array($this, 'render_faq_page')
        );

        add_submenu_page(
            'amplifi-studio',
            'Meta: JSON-LD',
            'Meta: JSON-LD',
            'edit_posts',
            'amplifi-ac-bulk-meta-jsonld',
            array($this, 'render_jsonld_page')
        );
    }
    
    public function enqueue_scripts($hook) {
        $valid_hooks = array(
            'amplifi-studio_page_amplifi-ac-bulk-meta',
            'amplifi-studio_page_amplifi-ac-bulk-meta-faq',
            'amplifi-studio_page_amplifi-ac-bulk-meta-jsonld',
        );

        if ( ! in_array( $hook, $valid_hooks, true ) ) {
            return;
        }

        wp_enqueue_script('jquery');

        // Enqueue WordPress editor scripts for FAQ page (WYSIWYG support)
        if ('amplifi-studio_page_amplifi-ac-bulk-meta-faq' === $hook) {
            wp_enqueue_editor();
            wp_enqueue_media();
        }
        
        // Inline CSS
        add_action('admin_head', array($this, 'output_inline_styles'));
        
        // Inline JavaScript
        add_action('admin_footer', array($this, 'output_inline_scripts'));
    }
    
    public function output_inline_styles() {
        ?>
        <style>
            .ac-bulk-meta-wrap {
                margin: 20px 20px 20px 0;
            }
            
            .ac-openai-settings {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-openai-settings h2 {
                margin-top: 0;
                font-size: 18px;
            }
            
            .openai-key-wrapper {
                display: flex;
                gap: 10px;
                align-items: center;
                margin-bottom: 10px;
            }
            
            .openai-key-wrapper label {
                font-weight: 600;
            }
            
            #openai-api-key {
                min-width: 350px;
                padding: 6px 10px;
            }
            
            .api-key-status {
                color: #00a32a;
                font-weight: 500;
            }
            
            .ac-global-prompt-settings {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-site-title-settings {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-site-title-settings h2 {
                margin-top: 0;
            }
            
            .site-title-wrapper {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 10px;
            }
            
            .site-title-wrapper label {
                font-weight: 600;
                min-width: 200px;
            }
            
            #site-title-override {
                flex: 1;
                max-width: 400px;
                padding: 5px 10px;
            }
            
            .site-title-status {
                color: #00a32a;
                font-weight: 500;
            }
            
            .ac-global-prompt-settings {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-global-prompt-settings h2 {
                margin-top: 0;
                font-size: 18px;
            }
            
            .global-prompt-wrapper {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            
            .global-prompt-wrapper label {
                font-weight: 600;
            }
            
            #global-prompt {
                width: 100%;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 3px;
                font-size: 14px;
                line-height: 1.4;
                resize: vertical;
                min-height: 80px;
            }
            
            .global-prompt-status {
                color: #00a32a;
                font-weight: 500;
            }
            
            .ac-bulk-meta-controls {
                background: #fff;
                padding: 15px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                display: flex;
                gap: 20px;
                align-items: center;
                flex-wrap: wrap;
            }
            
            .filter-controls,
            .sort-controls {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            
            .sort-controls {
                gap: 8px;
            }
            
            #post-type-select {
                min-width: 150px;
                font-weight: 500;
            }
            
            #pages-table-container {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            #pages-meta-table {
                margin: 0;
            }
            
            #pages-meta-table th {
                background: #f6f7f7;
                font-weight: 600;
            }
            
            .column-id { width: 60px; }
            .column-cb { width: 40px; }
            .column-title { width: 180px; }
            .column-status { width: 80px; }
            .column-targeted-keywords { width: 200px; }
            .column-yoast-title { width: 220px; }
            .column-yoast-desc { width: 280px; }
            .column-yoast-focus { width: 130px; }
            .column-actions { width: 180px; }
            
            .generate-btn {
                background: #8c5bc7;
                color: #fff;
                border: none;
                padding: 4px 10px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
                margin-top: 5px;
                transition: background 0.2s;
            }
            
            .generate-btn:hover {
                background: #7446a8;
            }
            
            .generate-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
            }
            
            .bulk-selected-actions {
                margin: 15px 0;
                padding: 10px;
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                display: none;
            }
            
            .bulk-selected-actions.active {
                display: block;
            }
            
            .bulk-selected-actions strong {
                margin-right: 10px;
            }
            
            .meta-input {
                width: 100%;
                padding: 6px 8px;
                border: 1px solid #ddd;
                border-radius: 3px;
                font-size: 13px;
                font-family: inherit;
            }
            
            textarea.meta-input {
                min-height: 60px;
                resize: vertical;
                line-height: 1.4;
            }
            
            .meta-input:focus {
                border-color: #2271b1;
                outline: none;
                box-shadow: 0 0 0 1px #2271b1;
            }
            
            .meta-input.saving {
                background: #fffbcc;
                border-color: #e6db55;
            }
            
            .meta-input.saved {
                background: #ecf7ed;
                border-color: #68de7c;
            }
            
            .meta-input.error {
                background: #fcf0f1;
                border-color: #d63638;
            }
            
            .meta-input.empty {
                background: #fff3cd;
                border-color: #ffc107;
            }
            
            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            
            .status-publish {
                background: #00a32a;
                color: #fff;
            }
            
            .status-draft {
                background: #dba617;
                color: #fff;
            }
            
            .status-pending {
                background: #996800;
                color: #fff;
            }
            
            .status-private {
                background: #646970;
                color: #fff;
            }
            
            .page-actions {
                display: flex;
                gap: 8px;
            }
            
            .page-actions a {
                text-decoration: none;
                font-size: 13px;
            }
            
            .no-data {
                text-align: center;
                padding: 40px !important;
                color: #646970;
            }
            
            #loading-spinner.is-active {
                display: block;
                visibility: visible;
            }
            
            #status-message.notice {
                padding: 10px 15px;
            }
            
            #status-message.notice-success {
                border-left-color: #00a32a;
                background: #ecf7ed;
            }
            
            #status-message.notice-error {
                border-left-color: #d63638;
                background: #fcf0f1;
            }
            
            .status-trash {
                background: #d63638;
                color: #fff;
            }
            
            .external-link-badge {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
                font-weight: 600;
                text-transform: uppercase;
                background: #f0b849;
                color: #1d2327;
                margin-left: 5px;
            }
            
            .external-link-row {
                opacity: 0.7;
                background-color: #fff8e1 !important;
            }
            
            .ac-bulk-meta-wrap.dark-mode .external-link-row {
                background-color: #3c3414 !important;
            }
            
            .generate-btn.external-disabled {
                background: #ccc;
                cursor: not-allowed;
                opacity: 0.5;
            }
            
            .external-link-badge:hover {
                background: #e6a832;
            }
            
            /* Dark Mode Styles */
            .ac-bulk-meta-wrap.dark-mode {
                background: #1d2327;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode h1,
            .ac-bulk-meta-wrap.dark-mode h2,
            .ac-bulk-meta-wrap.dark-mode h3 {
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode .ac-site-title-settings,
            .ac-bulk-meta-wrap.dark-mode .ac-openai-settings,
            .ac-bulk-meta-wrap.dark-mode .ac-global-prompt-settings,
            .ac-bulk-meta-wrap.dark-mode .ac-faq-focus-settings,
            .ac-bulk-meta-wrap.dark-mode .ac-faq-deploy-global-settings {
                background: #2c3338;
                border-color: #3c434a;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode #faq-focus {
                background: #23282d;
                color: #f0f0f1;
                border-color: #3c434a;
            }
            
            .ac-bulk-meta-wrap.dark-mode .faq-focus-status,
            .ac-bulk-meta-wrap.dark-mode .faq-deploy-global-status {
                color: #4caf50;
            }
            
            .ac-bulk-meta-wrap.dark-mode #faq-deploy-global-mode,
            .ac-bulk-meta-wrap.dark-mode #faq-deploy-global-heading-color,
            .ac-bulk-meta-wrap.dark-mode #faq-deploy-global-answer-color {
                background: #23282d;
                color: #f0f0f1;
                border-color: #3c434a;
            }
            
            .ac-bulk-meta-wrap.dark-mode .ac-bulk-meta-controls {
                background: #2c3338;
                border-color: #3c434a;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode #pages-table-container {
                background: #2c3338;
                border-color: #3c434a;
            }
            
            .ac-bulk-meta-wrap.dark-mode #pages-meta-table th {
                background: #3c434a;
                color: #f0f0f1;
                border-color: #50575e;
            }
            
            .ac-bulk-meta-wrap.dark-mode #pages-meta-table td {
                background: #2c3338;
                color: #f0f0f1;
                border-color: #3c434a;
            }
            
            .ac-bulk-meta-wrap.dark-mode #pages-meta-table tr:nth-child(even) td {
                background: #23282d;
            }
            
            .ac-bulk-meta-wrap.dark-mode .meta-input {
                background: #1d2327;
                border-color: #3c434a;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode .meta-input:focus {
                border-color: #2271b1;
                background: #1d2327;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode .meta-input.empty {
                background: #3c3414;
                border-color: #8c6633;
            }
            
            .ac-bulk-meta-wrap.dark-mode .meta-input.saved {
                background: #1f3a1f;
                border-color: #4a6f4a;
            }
            
            .ac-bulk-meta-wrap.dark-mode select,
            .ac-bulk-meta-wrap.dark-mode input[type="text"],
            .ac-bulk-meta-wrap.dark-mode input[type="password"] {
                background: #1d2327;
                border-color: #3c434a;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode textarea {
                background: #1d2327;
                border-color: #3c434a;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode .bulk-selected-actions {
                background: #2c3338;
                border-color: #3c434a;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode .bulk-generate-controls {
                background: #2c3338;
                border-color: #3c434a;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode .description {
                color: #c3c4c7;
            }
            
            .ac-bulk-meta-wrap.dark-mode .char-count {
                color: #c3c4c7;
            }
            
            .ac-bulk-meta-wrap.dark-mode .notice {
                background: #2c3338;
                border-color: #3c434a;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode .no-data {
                color: #c3c4c7;
            }
            
            .dark-mode-toggle {
                position: fixed;
                top: 32px;
                right: 20px;
                z-index: 1000;
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 8px 12px;
                cursor: pointer;
                font-size: 13px;
                display: flex;
                align-items: center;
                gap: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .dark-mode-toggle:hover {
                background: #e0e0e1;
            }
            
            .ac-bulk-meta-wrap.dark-mode .dark-mode-toggle {
                background: #2c3338;
                border-color: #3c434a;
                color: #f0f0f1;
            }
            
            .ac-bulk-meta-wrap.dark-mode .dark-mode-toggle:hover {
                background: #3c434a;
            }
            
            .dark-mode-toggle-icon {
                font-size: 18px;
            }
            
            .char-count {
                font-size: 11px;
                color: #646970;
                margin-top: 2px;
            }
            
            .char-count.warning {
                color: #996800;
            }
            
            .char-count.error {
                color: #d63638;
            }
            
            .ac-ai-logs-section {
                background: #fff;
                padding: 20px;
                margin-top: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-ai-logs-section h2 {
                margin-top: 0;
                font-size: 18px;
            }
            
            .logs-controls {
                margin-bottom: 15px;
            }
            
            #ai-logs-table th {
                background: #f6f7f7;
                font-weight: 600;
            }
            
            .column-timestamp { width: 150px; }
            .column-user { width: 120px; }
            .column-post { width: 200px; }
            .column-keywords { width: 200px; }
            .column-description { width: 300px; }
            
            .log-description {
                font-size: 12px;
                line-height: 1.4;
                max-height: 60px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .log-keywords {
                font-size: 11px;
                color: #646970;
                font-style: italic;
            }
            
            .log-post-title {
                font-weight: 500;
                color: #2271b1;
            }
            
            .log-timestamp {
                font-size: 11px;
                color: #646970;
            }
            
            .bulk-generate-controls {
                background: #f9f9f9;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            .bulk-generate-controls h3 {
                margin-top: 0;
                font-size: 16px;
                color: #23282d;
            }
            
            .bulk-controls-wrapper {
                margin-bottom: 10px;
            }
            
            .progress-bar {
                width: 100%;
                height: 20px;
                background: #f0f0f0;
                border-radius: 10px;
                overflow: hidden;
                margin: 15px 0;
            }
            
            .progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #00a32a, #68de7c);
                width: 0%;
                transition: width 0.3s ease;
            }
            
            .progress-stats {
                display: flex;
                justify-content: space-between;
                font-size: 14px;
                margin: 10px 0;
                font-weight: 500;
            }
            
            #current-processing {
                font-size: 13px;
                color: #666;
                margin-top: 10px;
                padding: 8px;
                background: #fff;
                border-radius: 3px;
                border: 1px solid #ddd;
            }
            
            .ac-faq-focus-settings {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-faq-focus-settings h2 {
                margin-top: 0;
                font-size: 18px;
            }
            
            .faq-focus-wrapper {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            
            .faq-focus-wrapper label {
                font-weight: 600;
            }
            
            #faq-focus {
                width: 100%;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 3px;
                font-size: 14px;
                line-height: 1.4;
                resize: vertical;
                min-height: 80px;
            }
            
            .faq-focus-status {
                color: #00a32a;
                font-weight: 500;
            }
            
            .ac-faq-deploy-global-settings {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-faq-deploy-global-settings h2 {
                margin-top: 0;
                font-size: 18px;
            }
            
            .deploy-global-wrapper label {
                font-weight: 600;
                display: block;
                margin-bottom: 5px;
            }
            
            .faq-deploy-global-status {
                color: #00a32a;
                font-weight: 500;
                margin-left: 10px;
            }
            
            .ac-faq-generation-section {
                background: #fff;
                padding: 20px;
                margin-top: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-faq-generation-section h2 {
                margin-top: 0;
                font-size: 18px;
            }
            
            .faq-controls {
                margin-bottom: 20px;
            }
            
            .faq-controls-row {
                display: flex;
                gap: 15px;
                align-items: center;
                flex-wrap: wrap;
            }
            
            .faq-controls-row label {
                font-weight: 600;
            }
            
            #faq-post-type-select {
                min-width: 150px;
            }
            
            .column-faq-count { width: 100px; }
            
            .faq-item {
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 15px;
                margin-bottom: 10px;
            }
            
            .faq-question {
                font-weight: 600;
                color: #23282d;
                margin-bottom: 8px;
            }
            
            .faq-answer {
                color: #666;
                line-height: 1.5;
                margin-bottom: 10px;
            }
            
            .faq-actions {
                display: flex;
                gap: 10px;
            }
            
            .faq-delete-btn {
                background: #d63638;
                color: #fff;
                border: none;
                padding: 4px 8px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
            }
            
            .faq-delete-btn:hover {
                background: #b32d2e;
            }
            
            .generate-faq-btn {
                background: #8c5bc7;
                color: #fff;
                border: none;
                padding: 4px 10px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
                margin-top: 5px;
            }
            
            .generate-faq-btn:hover {
                background: #7446a8;
            }
            
            .generate-faq-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
            }
            
            .generate-faq-btn.generating {
                background: #dba617;
            }
            
            .faqs-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .faqs-modal-content {
                background: #fff;
                border-radius: 4px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
                max-width: 800px;
                width: 90%;
                max-height: 80vh;
                overflow: hidden;
            }
            
            .faqs-modal-header {
                background: #f1f1f1;
                padding: 15px 20px;
                border-bottom: 1px solid #ddd;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .faqs-modal-header h3 {
                margin: 0;
                font-size: 16px;
            }
            
            .faqs-modal-close {
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
                color: #666;
                padding: 0;
                width: 30px;
                height: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .faqs-modal-close:hover {
                color: #000;
            }
            
            .faqs-modal-body {
                padding: 20px;
            }
            
            .faqs-text-container {
                margin-bottom: 20px;
            }
            
            #faqs-text-display {
                width: 100%;
                height: 300px;
                max-height: 50vh;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 3px;
                font-family: monospace;
                font-size: 13px;
                line-height: 1.4;
                resize: vertical;
                background: #f9f9f9;
            }
            
            .faqs-modal-actions {
                display: flex;
                gap: 10px;
                justify-content: flex-end;
            }
            
            .toggle-faqs-btn {
                background: #8c5bc7;
                color: #fff;
                border: none;
                padding: 4px 8px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
            }
            
            .toggle-faqs-btn:hover {
                background: #7446a8;
            }
            
            .faq-form {
                padding: 10px 0;
            }
            
            .faq-form label {
                margin-bottom: 5px;
            }
            
            .faq-details-row {
                background: #f9f9f9;
            }
            
            .faq-details-cell {
                padding: 20px;
                border-top: 1px solid #ddd;
            }
            
            .faq-accordion {
                max-width: 100%;
            }
            
            .faq-accordion-item {
                border: 1px solid #ddd;
                border-radius: 4px;
                margin-bottom: 10px;
                background: #fff;
            }
            
            .faq-accordion-header {
                padding: 15px;
                background: #f1f1f1;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-radius: 4px 4px 0 0;
                transition: background-color 0.2s;
            }
            
            .faq-accordion-header:hover {
                background: #e8e8e8;
            }
            
            .faq-number {
                background: #2271b1;
                color: #fff;
                padding: 4px 8px;
                border-radius: 3px;
                font-weight: bold;
                font-size: 12px;
                margin-right: 10px;
                min-width: 25px;
                text-align: center;
            }
            
            .faq-question-text {
                flex: 1;
                font-weight: 500;
                color: #23282d;
            }
            
            .faq-toggle {
                background: #666;
                color: #fff;
                width: 20px;
                height: 20px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                font-size: 14px;
            }
            
            .faq-accordion-content {
                padding: 15px;
                border-top: 1px solid #ddd;
                background: #fff;
            }
            
            .faq-answer-text {
                color: #666;
                line-height: 1.5;
            }
            
            .faq-answer-editor-container {
                margin-top: 10px;
                margin-bottom: 10px;
            }
            
            .faq-answer-editor-container .wp-editor-container {
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            
            .faq-answer-editor-container .mce-tinymce {
                border: none;
            }
            
            .faq-answer-editor-container .wp-editor-tabs {
                border-bottom: 1px solid #ddd;
            }
            
            .faq-loading, .faq-no-data, .faq-error {
                text-align: center;
                padding: 20px;
                color: #666;
                font-style: italic;
            }
            
            .faq-error {
                color: #d63638;
            }
            
            .ac-jsonld-wrap {
                margin: 20px 20px 20px 0;
            }
            
            .ac-jsonld-settings {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-jsonld-settings h2 {
                margin-top: 0;
                font-size: 18px;
            }
            
            .ac-jsonld-posts-section {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            
            .ac-jsonld-posts-section h2 {
                margin-top: 0;
                font-size: 18px;
            }
            
            .jsonld-controls {
                margin-bottom: 20px;
            }
            
            .jsonld-controls-row {
                display: flex;
                gap: 15px;
                align-items: center;
                flex-wrap: wrap;
            }
            
            .jsonld-controls-row label {
                font-weight: 600;
            }
            
            #jsonld-post-type-select {
                min-width: 150px;
            }
            
            .column-jsonld-status { width: 120px; }
            
            .jsonld-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            
            .jsonld-status-present {
                background: #00a32a;
                color: #fff;
            }
            
            .jsonld-status-missing {
                background: #d63638;
                color: #fff;
            }
            
            .jsonld-status-invalid {
                background: #dba617;
                color: #fff;
            }
            
            .jsonld-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .jsonld-modal-content {
                background: #fff;
                border-radius: 4px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
                max-width: 90%;
                width: 1000px;
                max-height: 90vh;
                overflow: hidden;
            }
            
            .jsonld-modal-header {
                background: #f1f1f1;
                padding: 15px 20px;
                border-bottom: 1px solid #ddd;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .jsonld-modal-header h3 {
                margin: 0;
                font-size: 16px;
            }
            
            .jsonld-modal-close {
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
                color: #666;
                padding: 0;
                width: 30px;
                height: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .jsonld-modal-close:hover {
                color: #000;
            }
            
            .jsonld-modal-body {
                padding: 20px;
            }
            
            .jsonld-editor-container {
                margin-bottom: 20px;
            }
            
            #jsonld-editor {
                width: 100%;
                height: 400px;
                font-family: 'Courier New', monospace;
                font-size: 13px;
                border: 1px solid #ddd;
                border-radius: 3px;
                padding: 10px;
                resize: vertical;
            }
            
            .jsonld-modal-actions {
                display: flex;
                gap: 10px;
                justify-content: flex-end;
            }
            
            .edit-jsonld-btn {
                background: #2271b1;
                color: #fff;
                border: none;
                padding: 4px 8px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
            }
            
            .edit-jsonld-btn:hover {
                background: #135e96;
            }
            
            .generate-jsonld-btn {
                background: #8c5bc7;
                color: #fff;
                border: none;
                padding: 4px 8px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
            }
            
            .generate-jsonld-btn:hover {
                background: #7446a8;
            }
            
            .jsonld-validation-message {
                margin-top: 10px;
                padding: 10px;
                border-radius: 3px;
                font-size: 13px;
            }
            
            .jsonld-validation-success {
                background: #ecf7ed;
                border: 1px solid #68de7c;
                color: #00a32a;
            }
            
            .jsonld-validation-error {
                background: #fcf0f1;
                border: 1px solid #d63638;
                color: #d63638;
            }
        </style>
        <?php
    }
    
    public function output_inline_scripts() {
        ?>
        <script>
            console.log('AC Bulk Meta script starting...');
            jQuery(document).ready(function($) {
                console.log('jQuery document ready fired');
                const acBulkMeta = {
                    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    nonce: '<?php echo wp_create_nonce('ac_bulk_meta_nonce'); ?>'
                };
                
                let updateTimeout = null;
                
                // Dark Mode Toggle
                $('#dark-mode-toggle').on('click', function() {
                    const $wrap = $('.ac-bulk-meta-wrap');
                    const $toggle = $(this);
                    const isDarkMode = $wrap.hasClass('dark-mode');
                    const newMode = !isDarkMode;
                    
                    // Toggle immediately for instant feedback
                    if (newMode) {
                        $wrap.addClass('dark-mode');
                        $toggle.find('.dark-mode-toggle-icon').text('☀️');
                        $toggle.find('.dark-mode-toggle-text').text('Light Mode');
                    } else {
                        $wrap.removeClass('dark-mode');
                        $toggle.find('.dark-mode-toggle-icon').text('🌙');
                        $toggle.find('.dark-mode-toggle-text').text('Dark Mode');
                    }
                    
                    // Save preference
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_dark_mode',
                            nonce: acBulkMeta.nonce,
                            dark_mode: newMode ? '1' : '0'
                        },
                        success: function(response) {
                            if (!response.success) {
                                console.error('Failed to save dark mode preference');
                            }
                        },
                        error: function() {
                            console.error('Error saving dark mode preference');
                        }
                    });
                });
                
                // Load pages data
                function loadPagesData() {
                    const postType = $('#post-type-select').val();
                    const orderby = $('#sort-by').val();
                    const order = $('#sort-order').val();
                    const filter = $('#filter-pages').val();
                    
                    $('#loading-spinner').addClass('is-active');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_get_pages_data',
                            nonce: acBulkMeta.nonce,
                            post_type: postType,
                            orderby: orderby,
                            order: order,
                            filter: filter
                        },
                        success: function(response) {
                            if (response.success) {
                                renderPagesTable(response.data);
                            } else {
                                showMessage('Error loading data: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('AJAX error occurred', 'error');
                        },
                        complete: function() {
                            $('#loading-spinner').removeClass('is-active');
                        }
                    });
                }
                
                // Render pages table
                function renderPagesTable(pages) {
                    const tbody = $('#pages-tbody');
                    tbody.empty();
                    
                    if (pages.length === 0) {
                        tbody.html('<tr><td colspan="9" class="no-data">No items found</td></tr>');
                        return;
                    }
                    
                    pages.forEach(function(page) {
                        const row = $('<tr>').attr('data-page-id', page.ID);
                        
                        // Add external link styling if needed
                        if (page.is_external) {
                            row.addClass('external-link-row');
                        }
                        
                        // Checkbox column
                        const checkbox = $('<input>')
                            .attr('type', 'checkbox')
                            .attr('class', 'row-checkbox')
                            .attr('value', page.ID)
                            .attr('data-post-id', page.ID);
                        
                        if (page.is_external) {
                            checkbox.prop('disabled', true)
                                .attr('title', 'Cannot select: External permalink');
                        }
                        
                        row.append($('<td>').append(checkbox));
                        
                        // ID column
                        row.append($('<td>').text(page.ID));
                        
                        // Title column with external link indicator
                        let titleHtml = '<strong>' + escapeHtml(page.title) + '</strong>';
                        if (page.is_external) {
                            titleHtml += '<span class="external-link-badge" title="External URL - Cannot generate content automatically">EXT</span>';
                        }
                        row.append($('<td>').html(titleHtml));
                        
                        // Status column
                        const statusClass = 'status-' + page.status;
                        row.append($('<td>').html('<span class="status-badge ' + statusClass + '">' + page.status + '</span>'));
                        
                        // Targeted Keywords (optional - for AI guidance only, not focus keywords)
                        const keywordsInput = $('<textarea>')
                            .addClass('meta-input')
                            .attr('data-field', 'targeted_keywords')
                            .attr('data-post-id', page.ID)
                            .attr('placeholder', 'Optional: keywords/phrases for AI guidance (not focus keywords)')
                            .attr('rows', '2')
                            .val(page.targeted_keywords || '');
                        
                        if (!page.targeted_keywords) {
                            keywordsInput.addClass('empty');
                        }
                        
                        row.append($('<td>').append(keywordsInput));
                        
                        // Yoast Title
                        const titleInput = $('<textarea>')
                            .addClass('meta-input')
                            .attr('data-field', 'yoast_title')
                            .attr('data-post-id', page.ID)
                            .attr('rows', '2')
                            .val(page.yoast_title || '');
                        
                        if (!page.yoast_title) {
                            titleInput.addClass('empty');
                        }
                        
                        const titleGenerateBtn = $('<button>')
                            .addClass('generate-btn generate-title-btn')
                            .attr('data-post-id', page.ID)
                            .attr('data-keywords', page.targeted_keywords || '')
                            .text('Generate with AI');
                        
                        if (page.is_external) {
                            titleGenerateBtn.addClass('external-disabled')
                                .prop('disabled', true)
                                .attr('title', 'Cannot generate: External permalink');
                        }
                        
                        const titleCell = $('<td>').append(titleInput);
                        titleCell.append('<div class="char-count" data-field="title">' + (page.yoast_title ? page.yoast_title.length : 0) + ' chars</div>');
                        titleCell.append(titleGenerateBtn);
                        row.append(titleCell);
                        
                        // Yoast Description
                        const descInput = $('<textarea>')
                            .addClass('meta-input')
                            .attr('data-field', 'yoast_desc')
                            .attr('data-post-id', page.ID)
                            .attr('rows', '3')
                            .val(page.yoast_desc || '');
                        
                        if (!page.yoast_desc) {
                            descInput.addClass('empty');
                        }
                        
                        const generateBtn = $('<button>')
                            .addClass('generate-btn')
                            .attr('data-post-id', page.ID)
                            .attr('data-keywords', page.targeted_keywords || '')
                            .text('Generate with AI');
                        
                        if (page.is_external) {
                            generateBtn.addClass('external-disabled')
                                .prop('disabled', true)
                                .attr('title', 'Cannot generate: External permalink');
                        }
                        
                        const descCell = $('<td>').append(descInput);
                        descCell.append('<div class="char-count" data-field="desc">' + (page.yoast_desc ? page.yoast_desc.length : 0) + ' chars</div>');
                        descCell.append(generateBtn);
                        row.append(descCell);
                        
                        // Focus Keyword
                        const focusInput = $('<input>')
                            .attr('type', 'text')
                            .addClass('meta-input')
                            .attr('data-field', 'yoast_focus')
                            .attr('data-post-id', page.ID)
                            .val(page.yoast_focus || '');
                        
                        if (!page.yoast_focus) {
                            focusInput.addClass('empty');
                        }
                        
                        const focusGenerateBtn = $('<button>')
                            .addClass('generate-btn generate-focus-btn')
                            .attr('data-post-id', page.ID)
                            .attr('data-keywords', page.targeted_keywords || '')
                            .text('Generate with AI');
                        
                        if (page.is_external) {
                            focusGenerateBtn.addClass('external-disabled')
                                .prop('disabled', true)
                                .attr('title', 'Cannot generate: External permalink');
                        }
                        
                        const focusCell = $('<td>').append(focusInput);
                        focusCell.append(focusGenerateBtn);
                        row.append(focusCell);
                        
                        // Actions
                        const actionsCell = $('<td>').addClass('page-actions');
                        actionsCell.html(
                            '<a href="' + page.url + '" target="_blank" class="button button-small">View</a>' +
                            '<a href="' + page.edit_url + '" target="_blank" class="button button-small">Edit</a>'
                        );
                        row.append(actionsCell);
                        
                        tbody.append(row);
                    });
                    
                    updateCharCounts();
                }
                
                // Update character counts
                function updateCharCounts() {
                    $('.meta-input').each(function() {
                        const input = $(this);
                        const field = input.attr('data-field');
                        const value = input.val();
                        const length = value.length;
                        const charCountDiv = input.siblings('.char-count[data-field="' + field.replace('yoast_', '') + '"]');
                        
                        if (charCountDiv.length) {
                            charCountDiv.text(length + ' chars');
                            
                            charCountDiv.removeClass('warning error');
                            
                            if (field === 'yoast_title') {
                                if (length > 60) {
                                    charCountDiv.addClass('error');
                                } else if (length > 55) {
                                    charCountDiv.addClass('warning');
                                }
                            } else if (field === 'yoast_desc') {
                                if (length > 160) {
                                    charCountDiv.addClass('error');
                                } else if (length > 155) {
                                    charCountDiv.addClass('warning');
                                }
                            }
                        }
                    });
                }
                
                // Update meta field via AJAX
                function updateMetaField(postId, field, value) {
                    return $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_update_yoast_meta',
                            nonce: acBulkMeta.nonce,
                            post_id: postId,
                            field: field,
                            value: value
                        }
                    });
                }
                
                // Show status message
                function showMessage(message, type) {
                    const messageDiv = $('#status-message');
                    messageDiv.removeClass('notice-success notice-error');
                    messageDiv.addClass('notice-' + type);
                    messageDiv.find('p').text(message);
                    messageDiv.show();
                    
                    setTimeout(function() {
                        messageDiv.fadeOut();
                    }, 3000);
                }
                
                // Escape HTML
                function escapeHtml(text) {
                    const map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    };
                    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
                }
                
                // Event listeners
                $(document).on('input', '.meta-input', function() {
                    const input = $(this);
                    const postId = input.attr('data-post-id');
                    const field = input.attr('data-field');
                    const value = input.val();
                    
                    input.removeClass('saved error empty');
                    
                    if (!value) {
                        input.addClass('empty');
                    }
                    
                    updateCharCounts();
                    
                    clearTimeout(updateTimeout);
                    input.addClass('saving');
                    
                    updateTimeout = setTimeout(function() {
                        updateMetaField(postId, field, value)
                            .done(function(response) {
                                input.removeClass('saving');
                                if (response.success) {
                                    input.addClass('saved');
                                    setTimeout(function() {
                                        input.removeClass('saved');
                                        if (!value) {
                                            input.addClass('empty');
                                        }
                                    }, 1500);
                                } else {
                                    input.addClass('error');
                                    showMessage('Error updating: ' + response.data, 'error');
                                }
                            })
                            .fail(function() {
                                input.removeClass('saving').addClass('error');
                                showMessage('Failed to update field', 'error');
                            });
                    }, 800);
                });
                
                $('#post-type-select, #sort-by, #sort-order, #filter-pages').on('change', function() {
                    loadPagesData();
                });
                
                $('#refresh-data').on('click', function() {
                    loadPagesData();
                });
                
                // Validate API key on page load
                function validateApiKey() {
                    const statusEl = $('.api-key-status');
                    statusEl.text('Validating...').css('color', '#666');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_validate_openai_key',
                            nonce: acBulkMeta.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                const data = response.data;
                                if (data.valid) {
                                    statusEl.text('✓ API Key Valid (HTTP ' + data.status_code + ')').css('color', '#00a32a');
                                } else {
                                    statusEl.text('✗ ' + data.message + (data.status_code ? ' (HTTP ' + data.status_code + ')' : '')).css('color', '#d63638');
                                }
                            }
                        },
                        error: function() {
                            statusEl.text('✗ Validation failed').css('color', '#d63638');
                        }
                    });
                }
                
                // Validate on page load
                validateApiKey();
                
                // Save OpenAI API Key
                $('#save-openai-key').on('click', function() {
                    const apiKey = $('#openai-api-key').val().trim();
                    const button = $(this);
                    
                    button.prop('disabled', true).text('Saving...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_openai_key',
                            nonce: acBulkMeta.nonce,
                            api_key: apiKey
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('API key saved successfully', 'success');
                                // Validate the key after saving
                                validateApiKey();
                            } else {
                                showMessage('Error saving API key: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to save API key', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Save API Key');
                        }
                    });
                });
                
                // Save Global Prompt
                $('#save-global-prompt').on('click', function() {
                    const globalPrompt = $('#global-prompt').val().trim();
                    const button = $(this);
                    
                    button.prop('disabled', true).text('Saving...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_global_prompt',
                            nonce: acBulkMeta.nonce,
                            global_prompt: globalPrompt
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('Writing style saved successfully', 'success');
                                $('.global-prompt-status').text(globalPrompt ? '✓ Custom style saved' : 'Using default professional style').css('color', '#00a32a');
                            } else {
                                showMessage('Error saving writing style: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to save writing style', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Save Writing Style');
                        }
                    });
                });
                
                // Save Webhook URL
                $('#save-webhook-url').on('click', function() {
                    const webhookUrl = $('#webhook-url').val().trim();
                    const button = $(this);
                    
                    button.prop('disabled', true).text('Saving...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_webhook_url',
                            nonce: acBulkMeta.nonce,
                            webhook_url: webhookUrl
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('Webhook URL saved successfully', 'success');
                                $('.webhook-status').text(webhookUrl ? '✓ Webhook URL Set' : 'No webhook configured').css('color', webhookUrl ? '#00a32a' : '#666');
                            } else {
                                showMessage('Error saving webhook URL: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to save webhook URL', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Save Webhook URL');
                        }
                    });
                });
                
                // Save Site Title Override
                $('#save-site-title-override').on('click', function() {
                    const siteTitleOverride = $('#site-title-override').val().trim();
                    const button = $(this);
                    
                    button.prop('disabled', true).text('Saving...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_site_title_override',
                            nonce: acBulkMeta.nonce,
                            site_title_override: siteTitleOverride
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('Site title override saved successfully', 'success');
                                const defaultSiteName = $('#site-title-override').attr('placeholder') || 'Site Name';
                                const statusText = siteTitleOverride ? '✓ Using override: ' + siteTitleOverride : 'Using default: ' + defaultSiteName;
                                $('.site-title-status').text(statusText).css('color', '#00a32a');
                            } else {
                                showMessage('Error saving site title override: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to save site title override', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Save');
                        }
                    });
                });
                
                // Update generate button keywords when keywords field changes
                $(document).on('input', '.meta-input[data-field="targeted_keywords"]', function() {
                    const postId = $(this).attr('data-post-id');
                    const keywords = $(this).val();
                    $('.generate-btn[data-post-id="' + postId + '"]').attr('data-keywords', keywords);
                });
                
                // Generate Focus Keyphrase with AI
                $(document).on('click', '.generate-focus-btn', function() {
                    const button = $(this);
                    const postId = button.attr('data-post-id');
                    const keywords = button.attr('data-keywords') || '';
                    const focusInput = $('.meta-input[data-field="yoast_focus"][data-post-id="' + postId + '"]');
                    
                    button.prop('disabled', true).addClass('generating').text('Generating...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_generate_focus_keyphrase',
                            nonce: acBulkMeta.nonce,
                            post_id: postId,
                            targeted_keywords: keywords
                        },
                        success: function(response) {
                            if (response.success) {
                                focusInput.val(response.data.keyphrase);
                                focusInput.removeClass('empty').addClass('saved');
                                updateCharCounts();
                                showMessage(response.data.message, 'success');
                                
                                setTimeout(function() {
                                    focusInput.removeClass('saved');
                                }, 2000);
                            } else {
                                showMessage('Error: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to generate focus keyphrase', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).removeClass('generating').text('Generate with AI');
                        }
                    });
                });
                
                // Generate Meta Description with AI
                $(document).on('click', '.generate-btn:not(.generate-title-btn):not(.generate-focus-btn)', function() {
                    const button = $(this);
                    const postId = button.attr('data-post-id');
                    const keywords = button.attr('data-keywords') || '';
                    const descInput = $('.meta-input[data-field="yoast_desc"][data-post-id="' + postId + '"]');
                    
                    button.prop('disabled', true).addClass('generating').text('Generating...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_generate_meta_description',
                            nonce: acBulkMeta.nonce,
                            post_id: postId,
                            targeted_keywords: keywords
                        },
                        success: function(response) {
                            if (response.success) {
                                descInput.val(response.data.description);
                                descInput.removeClass('empty').addClass('saved');
                                updateCharCounts();
                                showMessage(response.data.message, 'success');
                                
                                setTimeout(function() {
                                    descInput.removeClass('saved');
                                }, 2000);
                            } else {
                                showMessage('Error: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to generate description', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).removeClass('generating').text('Generate with AI');
                        }
                    });
                });
                
                // Generate Title Tag with AI
                $(document).on('click', '.generate-title-btn', function() {
                    const button = $(this);
                    const postId = button.attr('data-post-id');
                    const keywords = button.attr('data-keywords') || '';
                    const titleInput = $('.meta-input[data-field="yoast_title"][data-post-id="' + postId + '"]');
                    
                    button.prop('disabled', true).addClass('generating').text('Generating...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_generate_title_tag',
                            nonce: acBulkMeta.nonce,
                            post_id: postId,
                            targeted_keywords: keywords
                        },
                        success: function(response) {
                            if (response.success) {
                                titleInput.val(response.data.title);
                                titleInput.removeClass('empty').addClass('saved');
                                updateCharCounts();
                                showMessage(response.data.message, 'success');
                                
                                setTimeout(function() {
                                    titleInput.removeClass('saved');
                                }, 2000);
                            } else {
                                showMessage('Error: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to generate title', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).removeClass('generating').text('Generate with AI');
                        }
                    });
                });
                
                // Load AI Logs
                $('#load-ai-logs').on('click', function() {
                    const button = $(this);
                    button.prop('disabled', true).text('Loading...');
                    
                        $.ajax({
                            url: acBulkMeta.ajax_url,
                            type: 'POST',
                            data: {
                            action: 'ac_get_ai_logs',
                                nonce: acBulkMeta.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                renderAILogs(response.data);
                                $('#ai-logs-container').show();
                                } else {
                                showMessage('Error loading logs: ' + response.data, 'error');
                                }
                            },
                            error: function() {
                            showMessage('Failed to load logs', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Load AI Generation Log');
                        }
                    });
                });
                
                // Render AI Logs
                function renderAILogs(logs) {
                    const tbody = $('#ai-logs-tbody');
                    tbody.empty();
                    
                    if (logs.length === 0) {
                        tbody.html('<tr><td colspan="5" class="no-data">No AI generations found</td></tr>');
                        return;
                    }
                    
                    logs.forEach(function(log) {
                        const row = $('<tr>');
                        
                        // Timestamp
                        const timestamp = new Date(log.timestamp).toLocaleString();
                        row.append($('<td>').html('<span class="log-timestamp">' + timestamp + '</span>'));
                        
                        // User
                        row.append($('<td>').text(log.user_name || 'Unknown'));
                        
                        // Post
                        const postLink = '<a href="' + (log.edit_url || '#') + '" target="_blank" class="log-post-title">' + escapeHtml(log.post_title) + '</a>';
                        row.append($('<td>').html(postLink));
                        
                        // Keywords
                        row.append($('<td>').html('<span class="log-keywords">' + escapeHtml(log.targeted_keywords || 'None') + '</span>'));
                        
                        // Generated Description
                        const generatedContent = log.generation_type === 'title' ? (log.generated_title || '') : 
                                                  log.generation_type === 'focus_keyphrase' ? (log.generated_keyphrase || '') : 
                                                  (log.generated_description || '');
                        const contentLabel = log.generation_type === 'title' ? 'Title' : 
                                             log.generation_type === 'focus_keyphrase' ? 'Focus Keyphrase' : 
                                             'Description';
                        row.append($('<td>').html('<div class="log-description" title="' + escapeHtml(generatedContent) + '"><strong>' + contentLabel + ':</strong> ' + escapeHtml(generatedContent) + '</div>'));
                        
                        tbody.append(row);
                    });
                }
                
                // Checkbox selection handling
                $('#cb-select-all').on('change', function() {
                    const isChecked = $(this).prop('checked');
                    $('.row-checkbox').prop('checked', isChecked);
                    updateSelectedCount();
                });
                
                $(document).on('change', '.row-checkbox', function() {
                    updateSelectedCount();
                    // Update select all checkbox state
                    const totalCheckboxes = $('.row-checkbox').length;
                    const checkedCheckboxes = $('.row-checkbox:checked').length;
                    $('#cb-select-all').prop('checked', totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes);
                });
                
                function updateSelectedCount() {
                    const count = $('.row-checkbox:checked').length;
                    $('#selected-count').text(count);
                    if (count > 0) {
                        $('#bulk-selected-actions').addClass('active');
                    } else {
                        $('#bulk-selected-actions').removeClass('active');
                    }
                }
                
                $('#bulk-clear-selection').on('click', function() {
                    $('.row-checkbox').prop('checked', false);
                    $('#cb-select-all').prop('checked', false);
                    updateSelectedCount();
                });
                
                function getSelectedPostIds() {
                    const selected = [];
                    $('.row-checkbox:checked').each(function() {
                        selected.push($(this).data('post-id'));
                    });
                    return selected;
                }
                
                function getSelectedPostsData() {
                    const selected = [];
                    $('.row-checkbox:checked').each(function() {
                        const postId = $(this).data('post-id');
                        const row = $(this).closest('tr');
                        
                        // Skip external links
                        if (row.hasClass('external-link-row')) {
                            return;
                        }
                        
                        const keywords = row.find('.meta-input[data-field="targeted_keywords"]').val() || '';
                        const titleText = row.find('td:eq(2)').find('strong').text().trim() || row.find('td:eq(2)').text().trim();
                        selected.push({
                            ID: postId,
                            title: titleText,
                            keywords: keywords
                        });
                    });
                    return selected;
                }
                
                // Generate selected titles
                $('#bulk-generate-selected-titles').on('click', function() {
                    const selected = getSelectedPostIds();
                    if (selected.length === 0) {
                        showMessage('Please select at least one post', 'error');
                        return;
                    }
                    
                    const postsData = getSelectedPostsData();
                    startBulkGenerationForPosts(postsData, 'titles');
                    $('#bulk-selected-actions').removeClass('active');
                    $('.row-checkbox').prop('checked', false);
                    $('#cb-select-all').prop('checked', false);
                    updateSelectedCount();
                });
                
                // Generate selected descriptions
                $('#bulk-generate-selected-descriptions').on('click', function() {
                    const selected = getSelectedPostIds();
                    if (selected.length === 0) {
                        showMessage('Please select at least one post', 'error');
                        return;
                    }
                    
                    const postsData = getSelectedPostsData();
                    startBulkGenerationForPosts(postsData, 'descriptions');
                    $('#bulk-selected-actions').removeClass('active');
                    $('.row-checkbox').prop('checked', false);
                    $('#cb-select-all').prop('checked', false);
                    updateSelectedCount();
                });
                
                // Generate selected focus keyphrases
                $('#bulk-generate-selected-focus').on('click', function() {
                    const selected = getSelectedPostIds();
                    if (selected.length === 0) {
                        showMessage('Please select at least one post', 'error');
                        return;
                    }
                    
                    const postsData = getSelectedPostsData();
                    startBulkGenerationForPosts(postsData, 'focus_keyphrases');
                    $('#bulk-selected-actions').removeClass('active');
                    $('.row-checkbox').prop('checked', false);
                    $('#cb-select-all').prop('checked', false);
                    updateSelectedCount();
                });
                
                function startBulkGenerationForPosts(postsData, type) {
                    // Send AJAX request to start generation
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_generate_selected',
                            nonce: acBulkMeta.nonce,
                            posts_data: JSON.stringify(postsData),
                            generation_type: type
                        },
                        success: function(response) {
                            if (response.success) {
                                const typeLabel = type === 'titles' ? 'titles' : 
                                                 type === 'focus_keyphrases' ? 'focus keyphrases' : 
                                                 'descriptions';
                                $('#bulk-generate-titles').hide();
                                $('#bulk-generate-descriptions').hide();
                                $('#bulk-generate-focus-keyphrases').hide();
                                $('#bulk-generate-start').hide();
                                $('#bulk-generate-stop').show();
                                $('#bulk-generate-progress').show();
                                showMessage('Bulk ' + typeLabel + ' generation started for ' + response.data.total + ' selected posts', 'success');
                                startBulkGeneration();
                            } else {
                                showMessage('Error starting generation: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to start generation', 'error');
                        }
                    });
                }
                
                // Bulk Generation Controls
                let bulkGenerateInterval = null;
                
                // Start bulk generation for titles
                $('#bulk-generate-titles').on('click', function() {
                    const postType = $('#post-type-select').val();
                    const button = $(this);
                    button.prop('disabled', true).text('Starting...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_bulk_generate_titles',
                            nonce: acBulkMeta.nonce,
                            post_type: postType
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#bulk-generate-titles').hide();
                                $('#bulk-generate-descriptions').hide();
                                $('#bulk-generate-focus-keyphrases').hide();
                                $('#bulk-generate-start').hide();
                                $('#bulk-generate-stop').show();
                                $('#bulk-generate-progress').show();
                                showMessage('Bulk title generation started for ' + response.data.total + ' posts', 'success');
                                startBulkGeneration();
                            } else {
                                showMessage('Error starting bulk generation: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to start bulk generation', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Generate All Missing Titles');
                        }
                    });
                });
                
                // Start bulk generation for descriptions
                $('#bulk-generate-descriptions').on('click', function() {
                    const postType = $('#post-type-select').val();
                    const button = $(this);
                    button.prop('disabled', true).text('Starting...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_bulk_generate_descriptions',
                            nonce: acBulkMeta.nonce,
                            post_type: postType
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#bulk-generate-titles').hide();
                                $('#bulk-generate-descriptions').hide();
                                $('#bulk-generate-focus-keyphrases').hide();
                                $('#bulk-generate-start').hide();
                                $('#bulk-generate-stop').show();
                                $('#bulk-generate-progress').show();
                                showMessage('Bulk description generation started for ' + response.data.total + ' posts', 'success');
                                startBulkGeneration();
                            } else {
                                showMessage('Error starting bulk generation: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to start bulk generation', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Generate All Missing Descriptions');
                        }
                    });
                });
                
                // Start bulk generation for focus keyphrases
                $('#bulk-generate-focus-keyphrases').on('click', function() {
                    const postType = $('#post-type-select').val();
                    const button = $(this);
                    button.prop('disabled', true).text('Starting...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_bulk_generate_focus_keyphrases',
                            nonce: acBulkMeta.nonce,
                            post_type: postType
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#bulk-generate-titles').hide();
                                $('#bulk-generate-descriptions').hide();
                                $('#bulk-generate-focus-keyphrases').hide();
                                $('#bulk-generate-start').hide();
                                $('#bulk-generate-stop').show();
                                $('#bulk-generate-progress').show();
                                showMessage('Bulk focus keyphrase generation started for ' + response.data.total + ' posts', 'success');
                                startBulkGeneration();
                            } else {
                                showMessage('Error starting bulk generation: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to start bulk generation', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Generate All Missing Focus Keyphrases');
                        }
                    });
                });
                
                // Legacy bulk generation (for backward compatibility)
                $('#bulk-generate-start').on('click', function() {
                    $('#bulk-generate-descriptions').trigger('click');
                });
                
                // Stop bulk generation
                $('#bulk-generate-stop').on('click', function() {
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_bulk_generate_stop',
                            nonce: acBulkMeta.nonce
                        },
                        success: function() {
                            stopBulkGeneration();
                            showMessage('Bulk generation stopped', 'success');
                        },
                        error: function() {
                            showMessage('Failed to stop bulk generation', 'error');
                        }
                    });
                });
                
                function startBulkGeneration() {
                    bulkGenerateInterval = setInterval(function() {
                        processNextPost();
                    }, 2000); // 2 second delay between posts
                }
                
                function processNextPost() {
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_bulk_generate_next',
                            nonce: acBulkMeta.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                updateProgress(response.data.progress);
                                
                                if (response.data.remaining === 0) {
                                    stopBulkGeneration();
                                    const typeLabel = response.data.progress.type === 'titles' ? 'titles' : 
                                                      response.data.progress.type === 'focus_keyphrases' ? 'focus keyphrases' : 
                                                      'descriptions';
                                    showMessage('Bulk generation completed! Generated ' + response.data.progress.success + ' ' + typeLabel + ' successfully.', 'success');
                                    loadPagesData(); // Refresh the table
                                }
                            } else {
                                stopBulkGeneration();
                                showMessage('Bulk generation error: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            stopBulkGeneration();
                            showMessage('Bulk generation failed', 'error');
                        }
                    });
                }
                
                function updateProgress(progress) {
                    const percentage = (progress.processed / progress.total) * 100;
                    $('.progress-fill').css('width', percentage + '%');
                    $('#progress-text').text('Processing ' + progress.processed + '/' + progress.total);
                    $('#progress-counts').text(progress.success + ' success, ' + progress.errors + ' errors');
                    $('#current-post').text(progress.current);
                }
                
                function stopBulkGeneration() {
                    if (bulkGenerateInterval) {
                        clearInterval(bulkGenerateInterval);
                        bulkGenerateInterval = null;
                    }
                    
                    $('#bulk-generate-titles').show();
                    $('#bulk-generate-descriptions').show();
                    $('#bulk-generate-focus-keyphrases').show();
                    $('#bulk-generate-start').show();
                    $('#bulk-generate-stop').hide();
                    $('#bulk-generate-progress').hide();
                }
                
                // Check for existing bulk generation on page load
                function checkBulkGenerationStatus() {
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_bulk_generate_status',
                            nonce: acBulkMeta.nonce
                        },
                        success: function(response) {
                            if (response.success && response.data.status === 'running') {
                                $('#bulk-generate-titles').hide();
                                $('#bulk-generate-descriptions').hide();
                                $('#bulk-generate-focus-keyphrases').hide();
                                $('#bulk-generate-start').hide();
                                $('#bulk-generate-stop').show();
                                $('#bulk-generate-progress').show();
                                updateProgress(response.data);
                                startBulkGeneration();
                            }
                        }
                    });
                }
                
                // FAQ Generation Functions
                function loadFaqsData() {
                    const postType = $('#faq-post-type-select').val();
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_get_faqs_data',
                            nonce: acBulkMeta.nonce,
                            post_type: postType
                        },
                        success: function(response) {
                            if (response.success) {
                                renderFaqsTable(response.data);
                                $('#faq-posts-container').show();
                            } else {
                                showMessage('Error loading FAQ data: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('AJAX error occurred', 'error');
                        }
                    });
                }
                
                function renderFaqsTable(posts) {
                    const tbody = $('#faq-posts-tbody');
                    tbody.empty();
                    
                    if (posts.length === 0) {
                        tbody.html('<tr><td colspan="5" class="no-data">No posts found</td></tr>');
                        return;
                    }
                    
                    posts.forEach(function(post) {
                        const row = $('<tr>').attr('data-post-id', post.ID);
                        
                        // ID column
                        row.append($('<td>').text(post.ID));
                        
                        // Title column with external link indicator
                        let titleHtml = '<strong>' + escapeHtml(post.title) + '</strong>';
                        if (post.is_external) {
                            titleHtml += '<span class="external-link-badge" title="External URL - Cannot generate FAQs automatically">EXT</span>';
                        }
                        row.append($('<td>').html(titleHtml));
                        
                        // Status column
                        const statusClass = 'status-' + post.status;
                        row.append($('<td>').html('<span class="status-badge ' + statusClass + '">' + post.status + '</span>'));
                        
                        // FAQ Count column with deployed indicator
                        const faqCountCell = $('<td>');
                        faqCountCell.text(post.faq_count || 0);
                        if (post.faqs_deployed) {
                            faqCountCell.append($('<span>').addClass('faq-deployed-badge').text('Deployed').css({
                                'margin-left': '8px',
                                'padding': '2px 8px',
                                'background': '#00a32a',
                                'color': '#fff',
                                'border-radius': '3px',
                                'font-size': '11px',
                                'font-weight': '600'
                            }));
                        }
                        row.append(faqCountCell);
                        
                        // Actions column
                        const actionsCell = $('<td>').addClass('page-actions');
                        const generateBtn = $('<button>')
                            .addClass('generate-faq-btn')
                            .attr('data-post-id', post.ID)
                            .text('Generate FAQs');
                        
                        if (post.is_external) {
                            generateBtn.prop('disabled', true)
                                .css('opacity', '0.5')
                                .css('cursor', 'not-allowed')
                                .attr('title', 'Cannot generate: External permalink');
                        }
                        
                        const viewBtn = $('<a>')
                            .attr('href', post.url)
                            .attr('target', '_blank')
                            .addClass('button button-small')
                            .text('View');
                        
                        const editBtn = $('<a>')
                            .attr('href', post.edit_url)
                            .attr('target', '_blank')
                            .addClass('button button-small')
                            .text('Edit');
                        
                        const toggleFaqsBtn = $('<button>')
                            .addClass('button button-small toggle-faqs-btn')
                            .attr('data-post-id', post.ID)
                            .text('Toggle FAQs');
                        
                        actionsCell.append(generateBtn);
                        
                        // Add deploy/undeploy button if FAQs exist (and not external)
                        if (post.faq_count > 0 && !post.is_external) {
                            if (post.faqs_deployed) {
                                const undeployBtn = $('<button>')
                                    .addClass('button button-small faq-undeploy-btn-inline')
                                    .attr('data-post-id', post.ID)
                                    .text('Undeploy');
                                actionsCell.append(undeployBtn);
                            } else {
                                const deployBtn = $('<button>')
                                    .addClass('button button-small faq-deploy-btn-inline')
                                    .attr('data-post-id', post.ID)
                                    .text('Deploy');
                                actionsCell.append(deployBtn);
                            }
                        }
                        
                        actionsCell.append(toggleFaqsBtn);
                        actionsCell.append(viewBtn);
                        actionsCell.append(editBtn);
                        row.append(actionsCell);
                        
                        tbody.append(row);
                        
                        // Add expandable FAQ row
                        const faqRow = $('<tr>')
                            .addClass('faq-details-row')
                            .attr('data-post-id', post.ID)
                            .attr('data-deployed', post.faqs_deployed ? 'true' : 'false')
                            .css('display', 'none');
                        
                        const faqCell = $('<td>')
                            .attr('colspan', '5')
                            .addClass('faq-details-cell');
                        
                        const faqContainer = $('<div>')
                            .addClass('faq-accordion-content')
                            .html('<div class="faq-loading">Loading FAQs...</div>');
                        
                        faqCell.append(faqContainer);
                        faqRow.append(faqCell);
                        tbody.append(faqRow);
                    });
                }
                
                function showFaqDetails(postId) {
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_get_faqs_data',
                            nonce: acBulkMeta.nonce,
                            post_id: postId
                        },
                        success: function(response) {
                            if (response.success && response.data.faqs) {
                                renderFaqDetails(response.data.faqs);
                                $('#faq-details-container').show();
                            }
                        }
                    });
                }
                
                function renderFaqDetails(faqs) {
                    const container = $('#faq-details-content');
                    container.empty();
                    
                    if (faqs.length === 0) {
                        container.html('<p>No FAQs generated yet.</p>');
                        return;
                    }
                    
                    faqs.forEach(function(faq) {
                        const faqItem = $('<div>').addClass('faq-item');
                        
                        const question = $('<div>').addClass('faq-question').text(faq.question);
                        const answer = $('<div>').addClass('faq-answer').text(faq.answer);
                        
                        const actions = $('<div>').addClass('faq-actions');
                        const deleteBtn = $('<button>')
                            .addClass('faq-delete-btn')
                            .attr('data-faq-id', faq.id)
                            .text('Delete');
                        
                        actions.append(deleteBtn);
                        
                        faqItem.append(question);
                        faqItem.append(answer);
                        faqItem.append(actions);
                        
                        container.append(faqItem);
                    });
                }
                
                function showFaqsModal(faqs) {
                    // Create modal HTML if it doesn't exist
                    if ($('#faqs-modal').length === 0) {
                        $('body').append(`
                            <div id="faqs-modal" class="faqs-modal-overlay" style="display: none;">
                                <div class="faqs-modal-content">
                                    <div class="faqs-modal-header">
                                        <h3>Generated FAQs</h3>
                                        <button class="faqs-modal-close">&times;</button>
                                    </div>
                                    <div class="faqs-modal-body">
                                        <div class="faqs-text-container">
                                            <textarea id="faqs-text-display" readonly></textarea>
                                        </div>
                                        <div class="faqs-modal-actions">
                                            <button id="copy-faqs-btn" class="button button-primary">Copy to Clipboard</button>
                                            <button class="faqs-modal-close button button-secondary">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `);
                    }
                    
                    // Format FAQs for display
                    let faqsText = '';
                    faqs.forEach(function(faq, index) {
                        faqsText += `Q${index + 1}: ${faq.question}\n`;
                        faqsText += `A${index + 1}: ${faq.answer}\n\n`;
                    });
                    
                    // Update modal content
                    $('#faqs-text-display').val(faqsText.trim());
                    $('#faqs-modal').show();
                }
                
                function renderFaqAccordion(container, faqs, postId) {
                    container.empty();
                    
                    const faqAccordion = $('<div>').addClass('faq-accordion');
                    
                    // Add "Add FAQ" button at the top
                    const addFaqBtn = $('<button>')
                        .addClass('button button-primary add-faq-btn')
                        .attr('data-post-id', postId)
                        .css({
                            'margin-bottom': '15px',
                            'display': 'flex',
                            'align-items': 'center',
                            'gap': '5px'
                        })
                        .html('<span style="font-size: 18px; line-height: 1;">+</span> Add FAQ');
                    
                    faqAccordion.append(addFaqBtn);
                    
                    if (faqs.length === 0) {
                        faqAccordion.append($('<div>').addClass('faq-no-data').text('No FAQs found for this post. Click "Add FAQ" to create one manually or generate FAQs.'));
                    } else {
                    faqs.forEach(function(faq, index) {
                        const faqItem = $('<div>').addClass('faq-accordion-item');
                        
                        const faqHeader = $('<div>')
                            .addClass('faq-accordion-header')
                                .html(`<span class="faq-number">Q${index + 1}</span><span class="faq-question-text">${escapeHtml(faq.question)}</span><span class="faq-toggle">-</span>`);
                        
                        const faqContent = $('<div>')
                            .addClass('faq-accordion-content')
                                .css('display', 'block'); // Show by default for editing
                            
                            // Question input
                            const questionInput = $('<textarea>')
                                .addClass('meta-input faq-question-input')
                                .attr('data-faq-id', faq.id)
                                .attr('data-field', 'question')
                                .attr('rows', '2')
                                .val(faq.question || '');
                            
                            // Answer input - WYSIWYG editor container
                            const answerEditorId = 'faq-answer-editor-' + faq.id;
                            const answerEditorContainer = $('<div>')
                                .addClass('faq-answer-editor-container')
                                .css({
                                    'margin-top': '10px',
                                    'margin-bottom': '10px'
                                });
                            
                            const answerTextarea = $('<textarea>')
                                .attr('id', answerEditorId)
                                .addClass('faq-answer-input')
                                .attr('data-faq-id', faq.id)
                                .attr('data-field', 'answer')
                                .css({
                                    'width': '100%',
                                    'min-height': '200px'
                                })
                                .val(faq.answer || '');
                            
                            answerEditorContainer.append(answerTextarea);
                            
                            const faqForm = $('<div>').addClass('faq-form');
                            faqForm.append($('<label>').text('Question:').css('font-weight', '600').css('display', 'block').css('margin-bottom', '5px'));
                            faqForm.append(questionInput);
                            faqForm.append($('<label>').text('Answer:').css('font-weight', '600').css('display', 'block').css('margin-top', '15px').css('margin-bottom', '5px'));
                            faqForm.append(answerEditorContainer);
                            
                            // Delete button
                            const deleteBtn = $('<button>')
                                .addClass('faq-delete-btn')
                                .attr('data-faq-id', faq.id)
                                .css('margin-top', '10px')
                                .text('Delete FAQ');
                            
                            faqForm.append(deleteBtn);
                            faqContent.append(faqForm);
                        
                        faqItem.append(faqHeader);
                        faqItem.append(faqContent);
                        faqAccordion.append(faqItem);
                    });
                    
                    // Add click handler for accordion headers
                        faqAccordion.find('.faq-accordion-header').on('click', function() {
                        const content = $(this).next('.faq-accordion-content');
                        const toggle = $(this).find('.faq-toggle');
                        
                        if (content.is(':visible')) {
                            content.slideUp();
                            toggle.text('+');
                        } else {
                            content.slideDown();
                            toggle.text('-');
                        }
                    });
                        
                        // Auto-save functionality for FAQ inputs
                        faqAccordion.find('.faq-question-input').on('blur', function() {
                            const $input = $(this);
                            const faqId = $input.attr('data-faq-id');
                            const field = $input.attr('data-field');
                            const value = $input.val();
                            
                            // Debounce save
                            clearTimeout($input.data('save-timeout'));
                            const timeout = setTimeout(function() {
                                saveFaqField(faqId, field, value, $input);
                            }, 800);
                            $input.data('save-timeout', timeout);
                        });
                        
                        // Auto-save for WYSIWYG editors
                        faqAccordion.find('.faq-answer-input').each(function() {
                            const $textarea = $(this);
                            const editorId = $textarea.attr('id');
                            
                            // Save on blur for textarea (TinyMCE will sync)
                            $textarea.on('blur', function() {
                                const faqId = $textarea.attr('data-faq-id');
                                const field = $textarea.attr('data-field');
                                
                                // Get content from TinyMCE if available, otherwise from textarea
                                let value = '';
                                if (typeof tinyMCE !== 'undefined' && tinyMCE.get(editorId)) {
                                    value = tinyMCE.get(editorId).getContent();
                                } else {
                                    value = $textarea.val();
                                }
                                
                                // Debounce save
                                clearTimeout($textarea.data('save-timeout'));
                                const timeout = setTimeout(function() {
                                    saveFaqField(faqId, field, value, $textarea);
                                }, 800);
                                $textarea.data('save-timeout', timeout);
                            });
                        });
                    }
                    
                    container.append(faqAccordion);
                    
                    // Initialize TinyMCE editors for all FAQ answers
                    initializeFaqEditors(container);
                }
                
                function initializeFaqEditors(container) {
                    // Remove any existing editors first
                    container.find('.faq-answer-input').each(function() {
                        const editorId = $(this).attr('id');
                        if (editorId && typeof tinyMCE !== 'undefined' && tinyMCE.get(editorId)) {
                            tinyMCE.get(editorId).remove();
                        }
                    });
                    
                    // Initialize new editors
                    container.find('.faq-answer-input').each(function() {
                        const editorId = $(this).attr('id');
                        if (editorId && typeof wp !== 'undefined' && wp.editor) {
                            wp.editor.initialize(editorId, {
                                tinymce: {
                                    wpautop: true,
                                    plugins: 'wordpress, wplink, wpview, paste',
                                    toolbar1: 'bold,italic,underline,bullist,numlist,link,unlink,wp_adv',
                                    toolbar2: 'formatselect,forecolor,backcolor,removeformat,charmap,outdent,indent,undo,redo',
                                    height: 200,
                                    menubar: false,
                                    statusbar: true
                                },
                                quicktags: {
                                    buttons: 'strong,em,link,ul,ol,li,code'
                                }
                            });
                        }
                    });
                }
                
                function renderDeploySettings(container, postId) {
                    // Remove existing deploy settings if any
                    container.find('.faq-deploy-settings').remove();
                    
                    // Add Deploy Settings Section with only deploy/undeploy buttons
                    const deploySettings = $('<div>').addClass('faq-deploy-settings').css({
                        'margin-top': '30px',
                        'padding': '20px',
                        'background': '#f9f9f9',
                        'border': '1px solid #ddd',
                        'border-radius': '4px'
                    });
                    
                    // Deploy/Undeploy Buttons
                    const deployButtonsGroup = $('<div>').css({
                        'display': 'flex',
                        'gap': '10px'
                    });
                    
                    // Check if FAQs are deployed
                    const isDeployed = container.closest('.faq-details-row').attr('data-deployed') === 'true';
                    
                    if (isDeployed) {
                        const undeployBtn = $('<button>')
                            .addClass('button button-secondary faq-undeploy-btn')
                            .attr('data-post-id', postId)
                            .text('Undeploy FAQs')
                            .css('flex', '1');
                        deployButtonsGroup.append(undeployBtn);
                    } else {
                        const deployBtn = $('<button>')
                            .addClass('button button-primary faq-deploy-btn')
                            .attr('data-post-id', postId)
                            .text('Deploy FAQs')
                            .css('flex', '1');
                        deployButtonsGroup.append(deployBtn);
                    }
                    
                    deploySettings.append(deployButtonsGroup);
                    container.append(deploySettings);
                }
                
                function saveFaqField(faqId, field, value, $input) {
                    // For WYSIWYG editors, get content from TinyMCE if available
                    if (field === 'answer' && $input.hasClass('faq-answer-input')) {
                        const editorId = $input.attr('id');
                        if (editorId && typeof tinyMCE !== 'undefined' && tinyMCE.get(editorId)) {
                            value = tinyMCE.get(editorId).getContent();
                        }
                    }
                    
                    $input.addClass('saving');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_faq',
                            nonce: acBulkMeta.nonce,
                            faq_id: faqId,
                            field: field,
                            value: value
                        },
                        success: function(response) {
                            if (response.success) {
                                $input.removeClass('saving').addClass('saved');
                                setTimeout(function() {
                                    $input.removeClass('saved');
                                }, 2000);
                                
                                // Update accordion header if question changed
                                if (field === 'question') {
                                    try {
                                        const faqItem = $input.closest('.faq-accordion-item');
                                        const questionText = faqItem.find('.faq-question-text');
                                        if (questionText.length) {
                                            // Use jQuery's text() method which auto-escapes HTML
                                            questionText.text(value);
                                        }
                                    } catch (e) {
                                        // Silently fail if update fails - save was successful
                                        console.log('Could not update accordion header:', e);
                                    }
                                }
                            } else {
                                $input.removeClass('saving').addClass('error');
                                showMessage('Error saving FAQ: ' + response.data, 'error');
                                setTimeout(function() {
                                    $input.removeClass('error');
                                }, 3000);
                            }
                        },
                        error: function(xhr, status, error) {
                            $input.removeClass('saving').addClass('error');
                            showMessage('Failed to save FAQ: ' + error, 'error');
                            setTimeout(function() {
                                $input.removeClass('error');
                            }, 3000);
                        }
                    });
                }
                
                // Save FAQ Focus
                $('#save-faq-focus').on('click', function() {
                    const faqFocus = $('#faq-focus').val().trim();
                    const button = $(this);
                    
                    button.prop('disabled', true).text('Saving...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_faq_focus',
                            nonce: acBulkMeta.nonce,
                            faq_focus: faqFocus
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('FAQ focus saved successfully', 'success');
                                const statusText = faqFocus ? '✓ FAQ focus saved' : 'No specific focus set';
                                $('.faq-focus-status').text(statusText).css('color', '#00a32a');
                            } else {
                                showMessage('Error saving FAQ focus: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to save FAQ focus', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Save FAQ Focus');
                        }
                    });
                });
                
                // Save FAQ Count
                $('#save-faq-count').on('click', function() {
                    const faqCount = parseInt($('#faq-count').val());
                    const button = $(this);
                    
                    if (isNaN(faqCount) || faqCount < 1 || faqCount > 15) {
                        showMessage('Please enter a number between 1 and 15', 'error');
                        return;
                    }
                    
                    button.prop('disabled', true).text('Saving...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_faq_count',
                            nonce: acBulkMeta.nonce,
                            faq_count: faqCount
                        },
                        success: function(response) {
                            if (response.success) {
                                $('.faq-count-status').text('✓ Using ' + faqCount + ' FAQs per generation');
                                showMessage('FAQ count saved successfully', 'success');
                            } else {
                                showMessage('Error saving FAQ count: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to save FAQ count', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Save');
                        }
                    });
                });
                
                // Global Deploy Settings - Color Picker Sync
                $('#faq-deploy-global-heading-color-picker').on('change', function() {
                    $('#faq-deploy-global-heading-color').val($(this).val());
                });
                
                $('#faq-deploy-global-heading-color').on('input', function() {
                    const hex = $(this).val();
                    if (/^#[0-9A-F]{6}$/i.test(hex)) {
                        $('#faq-deploy-global-heading-color-picker').val(hex);
                    }
                });
                
                $('#faq-deploy-global-answer-color-picker').on('change', function() {
                    $('#faq-deploy-global-answer-color').val($(this).val());
                });
                
                $('#faq-deploy-global-answer-color').on('input', function() {
                    const hex = $(this).val();
                    if (/^#[0-9A-F]{6}$/i.test(hex)) {
                        $('#faq-deploy-global-answer-color-picker').val(hex);
                    }
                });
                
                // Global Deploy Settings - Header Color Picker Sync
                $('#faq-deploy-global-header-color-picker').on('change', function() {
                    $('#faq-deploy-global-header-color').val($(this).val());
                });
                
                $('#faq-deploy-global-header-color').on('input', function() {
                    const hex = $(this).val();
                    if (/^#[0-9A-F]{6}$/i.test(hex)) {
                        $('#faq-deploy-global-header-color-picker').val(hex);
                    }
                });
                
                // Global Deploy Settings - Selector Button Handlers
                $(document).on('click', '.faq-selector-btn', function() {
                    const template = $(this).attr('data-template');
                    const textarea = $('#faq-deploy-global-wrapper-css');
                    const currentValue = textarea.val();
                    
                    // Decode HTML entities in template
                    const decodedTemplate = $('<textarea>').html(template).text();
                    
                    // Add template to textarea (append if there's content, otherwise set)
                    if (currentValue.trim()) {
                        textarea.val(currentValue + '\n\n' + decodedTemplate);
                    } else {
                        textarea.val(decodedTemplate);
                    }
                    
                    // Visual feedback
                    $(this).css({
                        'background': '#d4edda',
                        'border-color': '#28a745'
                    });
                    setTimeout(function() {
                        $(this).css({
                            'background': '#fff',
                            'border-color': '#ccc'
                        });
                    }.bind(this), 500);
                    
                    // Focus textarea
                    textarea.focus();
                });
                
                // Save Global Deploy Settings
                $('#save-faq-deploy-global').on('click', function() {
                    const button = $(this);
                    const settings = {
                        header: $('#faq-deploy-global-header').val(),
                        container_class: $('#faq-deploy-global-container-class').val(),
                        selector: $('#faq-deploy-global-selector').val(),
                        mode: $('#faq-deploy-global-mode').val(),
                        heading_color: $('#faq-deploy-global-heading-color').val(),
                        answer_color: $('#faq-deploy-global-answer-color').val(),
                        header_color: $('#faq-deploy-global-header-color').val(),
                        header_font_weight: $('#faq-deploy-global-header-font-weight').val(),
                        heading_font_weight: $('#faq-deploy-global-heading-font-weight').val(),
                        answer_font_weight: $('#faq-deploy-global-answer-font-weight').val(),
                        number_faqs: $('#faq-deploy-global-number-faqs').is(':checked') ? 1 : 0,
                        wrapper_css: $('#faq-deploy-global-wrapper-css').val()
                    };
                    
                    button.prop('disabled', true).text('Saving...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_faq_deploy_global',
                            nonce: acBulkMeta.nonce,
                            settings: settings
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('Global deploy settings saved successfully', 'success');
                                $('.faq-deploy-global-status').text('✓ Global settings saved').css('color', '#00a32a').show();
                                setTimeout(function() {
                                    $('.faq-deploy-global-status').fadeOut(function() {
                                        $(this).hide();
                                    });
                                }, 3000);
                            } else {
                                showMessage('Error saving global settings: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to save global settings', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Save Global Settings');
                        }
                    });
                });
                
                // Inline Deploy Button Handler - Deploy directly without opening
                $(document).on('click', '.faq-deploy-btn-inline', function() {
                    const button = $(this);
                    const postId = button.attr('data-post-id');
                    
                    button.prop('disabled', true).text('Deploying...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_deploy_faqs',
                            nonce: acBulkMeta.nonce,
                            post_id: postId
                        },
                            success: function(response) {
                                if (response.success) {
                                showMessage('FAQs deployed successfully', 'success');
                                // Update button to undeploy
                                button.removeClass('faq-deploy-btn-inline').addClass('faq-undeploy-btn-inline')
                                    .text('Undeploy').prop('disabled', false);
                                // Update deployed badge
                                const row = $('tr[data-post-id="' + postId + '"]');
                                const faqCountCell = row.find('td').eq(3);
                                if (faqCountCell.find('.faq-deployed-badge').length === 0) {
                                    faqCountCell.append($('<span>').addClass('faq-deployed-badge').text('Deployed').css({
                                        'margin-left': '8px',
                                        'padding': '2px 8px',
                                        'background': '#00a32a',
                                        'color': '#fff',
                                        'border-radius': '3px',
                                        'font-size': '11px',
                                        'font-weight': '600'
                                    }));
                                }
                                // Update FAQ row data attribute
                                const faqRowUpdate = $('.faq-details-row[data-post-id="' + postId + '"]');
                                faqRowUpdate.attr('data-deployed', 'true');
                                // Update inline button if exists
                                const inlineBtn = $('.faq-deploy-btn-inline[data-post-id="' + postId + '"]');
                                if (inlineBtn.length) {
                                    inlineBtn.removeClass('faq-deploy-btn-inline').addClass('faq-undeploy-btn-inline')
                                        .text('Undeploy');
                                }
                                // Update deploy button in FAQ details section if exists
                                const deployBtnSection = $('.faq-deploy-btn[data-post-id="' + postId + '"]');
                                if (deployBtnSection.length) {
                                    deployBtnSection.removeClass('faq-deploy-btn').addClass('faq-undeploy-btn')
                                        .removeClass('button-primary').addClass('button-secondary')
                                        .text('Undeploy FAQs');
                                }
                                // Close FAQ toggle if open
                                if (faqRowUpdate.is(':visible')) {
                                    faqRowUpdate.slideUp();
                                    $('.toggle-faqs-btn[data-post-id="' + postId + '"]').text('Toggle FAQs');
                                }
                                } else {
                                showMessage('Error deploying FAQs: ' + response.data, 'error');
                                button.prop('disabled', false).text('Deploy');
                                }
                            },
                            error: function() {
                            showMessage('Failed to deploy FAQs', 'error');
                            button.prop('disabled', false).text('Deploy');
                        }
                    });
                });
                
                // Inline Undeploy Button Handler
                $(document).on('click', '.faq-undeploy-btn-inline', function() {
                    const button = $(this);
                    const postId = button.attr('data-post-id');
                    
                    button.prop('disabled', true).text('Undeploying...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_undeploy_faqs',
                            nonce: acBulkMeta.nonce,
                            post_id: postId
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('FAQs undeployed successfully', 'success');
                                // Update button to deploy
                                button.removeClass('faq-undeploy-btn-inline').addClass('faq-deploy-btn-inline')
                                    .text('Deploy').prop('disabled', false);
                                // Remove deployed badge
                                const row = $('tr[data-post-id="' + postId + '"]');
                                row.find('.faq-deployed-badge').remove();
                                // Update FAQ row data attribute
                                const faqRowUpdate = $('.faq-details-row[data-post-id="' + postId + '"]');
                                faqRowUpdate.attr('data-deployed', 'false');
                                // Update inline button if exists
                                const inlineBtn = $('.faq-undeploy-btn-inline[data-post-id="' + postId + '"]');
                                if (inlineBtn.length) {
                                    inlineBtn.removeClass('faq-undeploy-btn-inline').addClass('faq-deploy-btn-inline')
                                        .text('Deploy');
                                }
                                // Update undeploy button in FAQ details section if exists
                                const undeployBtnSection = $('.faq-undeploy-btn[data-post-id="' + postId + '"]');
                                if (undeployBtnSection.length) {
                                    undeployBtnSection.removeClass('faq-undeploy-btn').addClass('faq-deploy-btn')
                                        .removeClass('button-secondary').addClass('button-primary')
                                        .text('Deploy FAQs');
                                }
                                // Close FAQ toggle if open
                                if (faqRowUpdate.is(':visible')) {
                                    faqRowUpdate.slideUp();
                                    $('.toggle-faqs-btn[data-post-id="' + postId + '"]').text('Toggle FAQs');
                                }
                            } else {
                                showMessage('Error undeploying FAQs: ' + response.data, 'error');
                                button.prop('disabled', false).text('Undeploy');
                            }
                        },
                        error: function() {
                            showMessage('Failed to undeploy FAQs', 'error');
                            button.prop('disabled', false).text('Undeploy');
                        }
                    });
                });
                
                // Deploy Button Handler (in FAQ details section)
                $(document).on('click', '.faq-deploy-btn', function() {
                    const button = $(this);
                    const postId = button.attr('data-post-id');
                    const faqRow = $('.faq-details-row[data-post-id="' + postId + '"]');
                    
                    button.prop('disabled', true).text('Deploying...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_deploy_faqs',
                            nonce: acBulkMeta.nonce,
                            post_id: postId
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('FAQs deployed successfully', 'success');
                                // Update button to undeploy
                                button.removeClass('faq-deploy-btn').addClass('faq-undeploy-btn')
                                    .removeClass('button-primary').addClass('button-secondary')
                                    .text('Undeploy FAQs').prop('disabled', false);
                                // Update deployed badge
                                const row = $('tr[data-post-id="' + postId + '"]');
                                const faqCountCell = row.find('td').eq(3);
                                if (faqCountCell.find('.faq-deployed-badge').length === 0) {
                                    faqCountCell.append($('<span>').addClass('faq-deployed-badge').text('Deployed').css({
                                        'margin-left': '8px',
                                        'padding': '2px 8px',
                                        'background': '#00a32a',
                                        'color': '#fff',
                                        'border-radius': '3px',
                                        'font-size': '11px',
                                        'font-weight': '600'
                                    }));
                                }
                                // Update FAQ row data attribute
                                faqRow.attr('data-deployed', 'true');
                                // Update inline button if exists
                                const inlineBtn = $('.faq-deploy-btn-inline[data-post-id="' + postId + '"]');
                                if (inlineBtn.length) {
                                    inlineBtn.removeClass('faq-deploy-btn-inline').addClass('faq-undeploy-btn-inline')
                                        .text('Undeploy');
                                }
                                // Close FAQ toggle
                                faqRow.slideUp();
                                $('.toggle-faqs-btn[data-post-id="' + postId + '"]').text('Toggle FAQs');
                            } else {
                                showMessage('Error deploying FAQs: ' + response.data, 'error');
                                button.prop('disabled', false).text('Deploy FAQs');
                            }
                        },
                        error: function() {
                            showMessage('Failed to deploy FAQs', 'error');
                            button.prop('disabled', false).text('Deploy FAQs');
                        }
                    });
                });
                
                // Undeploy Button Handler (in FAQ details section)
                $(document).on('click', '.faq-undeploy-btn', function() {
                    const button = $(this);
                    const postId = button.attr('data-post-id');
                    const faqRow = $('.faq-details-row[data-post-id="' + postId + '"]');
                    
                    button.prop('disabled', true).text('Undeploying...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_undeploy_faqs',
                            nonce: acBulkMeta.nonce,
                            post_id: postId
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('FAQs undeployed successfully', 'success');
                                // Update button to deploy
                                button.removeClass('faq-undeploy-btn').addClass('faq-deploy-btn')
                                    .removeClass('button-secondary').addClass('button-primary')
                                    .text('Deploy FAQs').prop('disabled', false);
                                // Remove deployed badge
                                const row = $('tr[data-post-id="' + postId + '"]');
                                row.find('.faq-deployed-badge').remove();
                                // Update FAQ row data attribute
                                faqRow.attr('data-deployed', 'false');
                                // Update inline button if exists
                                const inlineBtn = $('.faq-undeploy-btn-inline[data-post-id="' + postId + '"]');
                                if (inlineBtn.length) {
                                    inlineBtn.removeClass('faq-undeploy-btn-inline').addClass('faq-deploy-btn-inline')
                                        .text('Deploy');
                                }
                                // Close FAQ toggle
                                faqRow.slideUp();
                                $('.toggle-faqs-btn[data-post-id="' + postId + '"]').text('Toggle FAQs');
                            } else {
                                showMessage('Error undeploying FAQs: ' + response.data, 'error');
                                button.prop('disabled', false).text('Undeploy FAQs');
                            }
                        },
                        error: function() {
                            showMessage('Failed to undeploy FAQs', 'error');
                            button.prop('disabled', false).text('Undeploy FAQs');
                        }
                    });
                });
                
                // FAQ Event Listeners
                $('#faq-post-type-select').on('change', function() {
                    loadFaqsData();
                });
                
                $('#export-faqs-csv').on('click', function() {
                    const postType = $('#faq-post-type-select').val();
                    
                    window.location.href = acBulkMeta.ajax_url + '?action=ac_export_faqs_csv&post_type=' + postType + '&nonce=' + acBulkMeta.nonce;
                });
                
                $(document).on('click', '.generate-faq-btn', function() {
                    const button = $(this);
                    
                    // Prevent action if button is disabled (external permalink)
                    if (button.prop('disabled')) {
                        return false;
                    }
                    
                    const postId = button.attr('data-post-id');
                    const row = button.closest('tr');
                    const faqCountCell = row.find('td').eq(3);
                    const existingCount = parseInt(faqCountCell.text().trim()) || 0;
                    
                    // If FAQs already exist, ask user what to do
                    if (existingCount > 0) {
                        const action = confirm('This post already has ' + existingCount + ' FAQ(s).\n\nClick OK to REPLACE existing FAQs with new ones.\nClick Cancel to APPEND new FAQs to existing ones.');
                        const replaceExisting = action === true;
                        
                        generateFAQs(postId, button, replaceExisting);
                    } else {
                        generateFAQs(postId, button, false);
                    }
                });
                
                function generateFAQs(postId, button, replaceExisting) {
                    button.prop('disabled', true).addClass('generating').text('Generating...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_generate_faqs',
                            nonce: acBulkMeta.nonce,
                            post_id: postId,
                            replace: replaceExisting ? 1 : 0
                        },
                        success: function(response) {
                            if (response.success) {
                                const actionText = replaceExisting ? 'regenerated' : 'added';
                                showMessage('FAQs ' + actionText + ' successfully (' + response.data.faqs_count + ' FAQs)', 'success');
                                loadFaqsData(); // Refresh the table
                            } else {
                                showMessage('Error generating FAQs: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to generate FAQs', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).removeClass('generating').text('Generate FAQs');
                        }
                    });
                }
                
                $(document).on('click', '.faq-delete-btn', function() {
                    const button = $(this);
                    const faqId = button.attr('data-faq-id');
                    
                    if (confirm('Are you sure you want to delete this FAQ?')) {
                        const faqItem = button.closest('.faq-accordion-item');
                        
                        // Remove TinyMCE editor before deleting
                        const editorId = faqItem.find('.faq-answer-input').attr('id');
                        if (editorId && typeof tinyMCE !== 'undefined' && tinyMCE.get(editorId)) {
                            tinyMCE.get(editorId).remove();
                        }
                        
                        $.ajax({
                            url: acBulkMeta.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'ac_delete_faq',
                                nonce: acBulkMeta.nonce,
                                faq_id: faqId
                            },
                            success: function(response) {
                                if (response.success) {
                                    faqItem.fadeOut(function() {
                                        $(this).remove();
                                        // Reload FAQs if accordion is empty
                                        const container = button.closest('.faq-accordion');
                                        if (container.length && container.find('.faq-accordion-item').length === 0) {
                                            container.closest('.faq-accordion-content').html('<div class="faq-no-data">No FAQs found for this post.</div>');
                                        }
                                    });
                                    showMessage('FAQ deleted successfully', 'success');
                                } else {
                                    showMessage('Error deleting FAQ: ' + response.data, 'error');
                                }
                            },
                            error: function() {
                                showMessage('Failed to delete FAQ', 'error');
                            }
                        });
                    }
                });
                
                // Remove view-faqs-btn handler since button is removed
                
                // Add FAQ button handler
                $(document).on('click', '.add-faq-btn', function() {
                    const button = $(this);
                    const postId = button.attr('data-post-id');
                    const faqContainer = button.closest('.faq-accordion').parent();
                    
                    button.prop('disabled', true).text('Adding...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_add_faq',
                            nonce: acBulkMeta.nonce,
                            post_id: postId,
                            question: '',
                            answer: ''
                        },
                        success: function(response) {
                            if (response.success) {
                                // Reload FAQs to show the new one
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_get_faqs_data',
                            nonce: acBulkMeta.nonce,
                            post_id: postId
                        },
                                    success: function(reloadResponse) {
                                        if (reloadResponse.success) {
                                            const faqs = reloadResponse.data.faqs || [];
                                            renderFaqAccordion(faqContainer, faqs, postId);
                                            renderDeploySettings(faqContainer, postId);
                                            // Focus on the new FAQ's question field
                                            const newFaqId = response.data.faq.id;
                                            setTimeout(function() {
                                                faqContainer.find('.faq-question-input[data-faq-id="' + newFaqId + '"]').focus();
                                                // Initialize editor for the new FAQ answer
                                                initializeFaqEditors(faqContainer);
                                            }, 500);
                                        }
                                    }
                                });
                                showMessage('FAQ added successfully', 'success');
                            } else {
                                showMessage('Error adding FAQ: ' + response.data, 'error');
                                button.prop('disabled', false).html('<span style="font-size: 18px; line-height: 1;">+</span> Add FAQ');
                            }
                        },
                        error: function() {
                            showMessage('Failed to add FAQ', 'error');
                            button.prop('disabled', false).html('<span style="font-size: 18px; line-height: 1;">+</span> Add FAQ');
                        }
                    });
                });
                
                $(document).on('click', '.toggle-faqs-btn', function() {
                    const button = $(this);
                    const postId = button.attr('data-post-id');
                    const faqRow = $('.faq-details-row[data-post-id="' + postId + '"]');
                    const faqContainer = faqRow.find('.faq-accordion-content');
                    
                    if (faqRow.is(':visible')) {
                        // Collapse
                        faqRow.slideUp();
                        button.text('Toggle FAQs');
                    } else {
                        // Expand
                        faqRow.slideDown();
                        button.text('Hide FAQs');
                        
                        // Load FAQs if not already loaded
                        if (faqContainer.find('.faq-loading').length > 0) {
                            $.ajax({
                                url: acBulkMeta.ajax_url,
                                type: 'POST',
                                data: {
                                    action: 'ac_get_faqs_data',
                                    nonce: acBulkMeta.nonce,
                                    post_id: postId
                                },
                                success: function(response) {
                                    if (response.success && response.data.faqs) {
                                        renderFaqAccordion(faqContainer, response.data.faqs, postId);
                                        renderDeploySettings(faqContainer, postId);
                                        // Set deployed status
                                        const isDeployed = response.data.faqs_deployed || false;
                                        faqRow.attr('data-deployed', isDeployed ? 'true' : 'false');
                                        // Initialize editors after rendering
                                        setTimeout(function() {
                                            initializeFaqEditors(faqContainer);
                                        }, 100);
                                    } else {
                                        // Show empty state with Add FAQ button
                                        renderFaqAccordion(faqContainer, [], postId);
                                        renderDeploySettings(faqContainer, postId);
                                    }
                                },
                                error: function() {
                                    faqContainer.html('<div class="faq-error">Failed to load FAQs.</div>');
                                }
                            });
                        }
                    }
                });
                
                // Modal event listeners
                $(document).on('click', '.faqs-modal-close', function() {
                    $('#faqs-modal').hide();
                });
                
                $(document).on('click', '#copy-faqs-btn', function() {
                    const button = $(this);
                    const textarea = $('#faqs-text-display');
                    textarea.select();
                    document.execCommand('copy');
                    
                    // Show green checkmark animation
                    button.html('✓ Copied!').css({
                        'background': '#00a32a',
                        'color': '#fff'
                    });
                    
                    setTimeout(function() {
                        button.html('Copy to Clipboard').css({
                            'background': '',
                            'color': ''
                        });
                    }, 2000);
                    
                    showMessage('FAQs copied to clipboard!', 'success');
                });
                
                // Close modal when clicking overlay
                $(document).on('click', '#faqs-modal', function(e) {
                    if (e.target === this) {
                        $(this).hide();
                    }
                });
                
                // JSON-LD Functions
                function loadJsonldData() {
                    const postType = $('#jsonld-post-type-select').val();
                    console.log('Loading JSON-LD data for post type:', postType);
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_get_jsonld_data',
                            nonce: acBulkMeta.nonce,
                            post_type: postType
                        },
                        success: function(response) {
                            console.log('JSON-LD AJAX response:', response);
                            if (response.success) {
                                renderJsonldTable(response.data);
                                $('#jsonld-posts-container').show();
                            } else {
                                showMessage('Error loading JSON-LD data: ' + response.data, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.log('JSON-LD AJAX error:', xhr, status, error);
                            showMessage('AJAX error occurred: ' + error, 'error');
                        }
                    });
                }
                
                function renderJsonldTable(posts) {
                    const tbody = $('#jsonld-posts-tbody');
                    tbody.empty();
                    
                    if (posts.length === 0) {
                        tbody.html('<tr><td colspan="5" class="no-data">No posts found</td></tr>');
                        return;
                    }
                    
                    posts.forEach(function(post) {
                        const row = $('<tr>').attr('data-post-id', post.ID);
                        
                        // ID column
                        row.append($('<td>').text(post.ID));
                        
                        // Title column
                        row.append($('<td>').html('<strong>' + escapeHtml(post.title) + '</strong>'));
                        
                        // Status column
                        const statusClass = 'status-' + post.status;
                        row.append($('<td>').html('<span class="status-badge ' + statusClass + '">' + post.status + '</span>'));
                        
                        // JSON-LD Status column
                        let jsonldStatus = 'Missing';
                        let jsonldStatusClass = 'jsonld-status-missing';
                        if (post.jsonld_status === 'present') {
                            jsonldStatus = 'Present';
                            jsonldStatusClass = 'jsonld-status-present';
                        } else if (post.jsonld_status === 'invalid') {
                            jsonldStatus = 'Invalid';
                            jsonldStatusClass = 'jsonld-status-invalid';
                        }
                        row.append($('<td>').html('<span class="jsonld-status-badge ' + jsonldStatusClass + '">' + jsonldStatus + '</span>'));
                        
                        // Actions column
                        const actionsCell = $('<td>').addClass('page-actions');
                        const editBtn = $('<button>')
                            .addClass('edit-jsonld-btn')
                            .attr('data-post-id', post.ID)
                            .text('Edit JSON-LD');
                        
                        const generateBtn = $('<button>')
                            .addClass('generate-jsonld-btn')
                            .attr('data-post-id', post.ID)
                            .text('Generate JSON-LD');
                        
                        const viewBtn = $('<a>')
                            .attr('href', post.url)
                            .attr('target', '_blank')
                            .addClass('button button-small')
                            .text('View');
                        
                        actionsCell.append(editBtn);
                        actionsCell.append(generateBtn);
                        actionsCell.append(viewBtn);
                        row.append(actionsCell);
                        
                        tbody.append(row);
                    });
                }
                
                function showJsonldModal(postId, jsonldData = null) {
                    $('#jsonld-editor').val(jsonldData || '');
                    $('#jsonld-modal').attr('data-post-id', postId).show();
                }
                
                function validateJsonld(jsonldString) {
                    try {
                        JSON.parse(jsonldString);
                        return { valid: true, error: null };
                    } catch (e) {
                        return { valid: false, error: e.message };
                    }
                }
                
                // JSON-LD Event Listeners
                $('#jsonld-post-type-select').on('change', function() {
                    loadJsonldData();
                });
                
                // Posts auto-load on page load and post type change
                
                function saveJsonldSettings() {
                    const formData = {
                        org_name: $('#org_name').val(),
                        org_url: $('#org_url').val(),
                        org_logo: $('#org_logo').val(),
                        org_description: $('#org_description').val(),
                        org_phone: $('#org_phone').val(),
                        org_email: $('#org_email').val(),
                        org_address: $('#org_address').val(),
                        org_facebook: $('#org_facebook').val(),
                        org_twitter: $('#org_twitter').val(),
                        org_linkedin: $('#org_linkedin').val()
                    };
                    
                    console.log('Saving JSON-LD settings:', formData);
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_jsonld_settings',
                            nonce: acBulkMeta.nonce,
                            settings: formData
                        },
                        success: function(response) {
                            console.log('Save settings response:', response);
                            if (response.success) {
                                showMessage('Organization settings saved successfully', 'success');
                            } else {
                                showMessage('Error saving settings: ' + response.data, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.log('Save settings error:', xhr, status, error);
                            showMessage('Failed to save settings: ' + error, 'error');
                        }
                    });
                }
                
                // JSON-LD Settings Event Listeners
                console.log('Setting up JSON-LD form event listeners...');
                
                $('#jsonld-settings-form').on('submit', function(e) {
                    console.log('Form submit event triggered');
                    e.preventDefault();
                    e.stopPropagation();
                    saveJsonldSettings();
                    return false;
                });
                
                $('#save-jsonld-settings').on('click', function(e) {
                    console.log('Save button clicked');
                    e.preventDefault();
                    e.stopPropagation();
                    saveJsonldSettings();
                    return false;
                });
                
                // Test if elements exist
                console.log('Form element found:', $('#jsonld-settings-form').length);
                console.log('Save button found:', $('#save-jsonld-settings').length);
                
                $(document).on('click', '.edit-jsonld-btn', function() {
                    const postId = $(this).attr('data-post-id');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_get_jsonld_data',
                            nonce: acBulkMeta.nonce,
                            post_id: postId
                        },
                        success: function(response) {
                            if (response.success && response.data.jsonld) {
                                showJsonldModal(postId, response.data.jsonld);
                            } else {
                                showJsonldModal(postId);
                            }
                        },
                        error: function() {
                            showJsonldModal(postId);
                        }
                    });
                });
                
                $(document).on('click', '.generate-jsonld-btn', function() {
                    const postId = $(this).attr('data-post-id');
                    const button = $(this);
                    
                    console.log('Generate JSON-LD button clicked for post ID:', postId);
                    console.log('Button element:', button);
                    console.log('AJAX URL:', acBulkMeta.ajax_url);
                    console.log('Nonce:', acBulkMeta.nonce);
                    
                    button.prop('disabled', true).text('Generating...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_generate_jsonld',
                            nonce: acBulkMeta.nonce,
                            post_id: postId
                        },
                        beforeSend: function() {
                            console.log('AJAX request starting for JSON-LD generation...');
                        },
                        success: function(response) {
                            console.log('Generate JSON-LD AJAX response:', response);
                            if (response.success) {
                                console.log('JSON-LD generation successful');
                                showMessage('JSON-LD generated successfully', 'success');
                                loadJsonldData(); // Refresh the table
                            } else {
                                console.log('JSON-LD generation failed:', response.data);
                                showMessage('Error generating JSON-LD: ' + response.data, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.log('Generate JSON-LD AJAX error:', xhr, status, error);
                            console.log('Response text:', xhr.responseText);
                            showMessage('Failed to generate JSON-LD: ' + error, 'error');
                        },
                        complete: function() {
                            console.log('Generate JSON-LD AJAX complete');
                            button.prop('disabled', false).text('Generate JSON-LD');
                        }
                    });
                });
                
                $('#bulk-generate-jsonld').on('click', function() {
                    const postType = $('#jsonld-post-type-select').val();
                    const button = $(this);
                    
                    button.prop('disabled', true).text('Generating...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_bulk_generate_jsonld',
                            nonce: acBulkMeta.nonce,
                            post_type: postType
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('Bulk JSON-LD generation completed', 'success');
                                loadJsonldData(); // Refresh the table
                            } else {
                                showMessage('Error in bulk generation: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to generate JSON-LD', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Generate JSON-LD for All Posts');
                        }
                    });
                });
                
                // Modal event listeners
                $('.jsonld-modal-close').on('click', function() {
                    $('#jsonld-modal').hide();
                });
                
                $('#validate-jsonld').on('click', function() {
                    const jsonldString = $('#jsonld-editor').val();
                    const validation = validateJsonld(jsonldString);
                    
                    let message = '';
                    if (validation.valid) {
                        message = '<div class="jsonld-validation-message jsonld-validation-success">✓ Valid JSON-LD</div>';
                    } else {
                        message = '<div class="jsonld-validation-message jsonld-validation-error">✗ Invalid JSON: ' + validation.error + '</div>';
                    }
                    
                    $('.jsonld-validation-message').remove();
                    $('#jsonld-editor').after(message);
                });
                
                $('#generate-jsonld').on('click', function() {
                    const postId = $('#jsonld-modal').attr('data-post-id');
                    const button = $(this);
                    
                    button.prop('disabled', true).text('Generating...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_generate_jsonld',
                            nonce: acBulkMeta.nonce,
                            post_id: postId
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#jsonld-editor').val(response.data.jsonld);
                                showMessage('JSON-LD generated successfully', 'success');
                            } else {
                                showMessage('Error generating JSON-LD: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to generate JSON-LD', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Generate JSON-LD');
                        }
                    });
                });
                
                $('#save-jsonld').on('click', function() {
                    const postId = $('#jsonld-modal').attr('data-post-id');
                    const jsonldString = $('#jsonld-editor').val();
                    const button = $(this);
                    
                    // Validate JSON first
                    const validation = validateJsonld(jsonldString);
                    if (!validation.valid) {
                        showMessage('Invalid JSON: ' + validation.error, 'error');
                        return;
                    }
                    
                    button.prop('disabled', true).text('Saving...');
                    
                    $.ajax({
                        url: acBulkMeta.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ac_save_jsonld_post',
                            nonce: acBulkMeta.nonce,
                            post_id: postId,
                            jsonld: jsonldString
                        },
                        success: function(response) {
                            if (response.success) {
                                showMessage('JSON-LD saved successfully', 'success');
                                $('#jsonld-modal').hide();
                                loadJsonldData(); // Refresh the table
                            } else {
                                showMessage('Error saving JSON-LD: ' + response.data, 'error');
                            }
                        },
                        error: function() {
                            showMessage('Failed to save JSON-LD', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Save JSON-LD');
                        }
                    });
                });
                
                // Close modal when clicking overlay
                $(document).on('click', '#jsonld-modal', function(e) {
                    if (e.target === this) {
                        $(this).hide();
                    }
                });
                
                // Check if we're on the FAQ page
                const isFaqPage = window.location.href.includes('ac-faq-generation');
                
                if (isFaqPage) {
                    // FAQ page specific initialization - auto-load posts
                    loadFaqsData();
                } else if (window.location.href.includes('ac-jsonld-generator')) {
                    // JSON-LD page specific initialization
                    console.log('JSON-LD page detected, initializing...');
                    
                    // Test if jQuery is working
                    if (typeof $ === 'undefined') {
                        console.error('jQuery not loaded!');
                        return;
                    }
                    
                    // Test if our AJAX object is available
                    if (typeof acBulkMeta === 'undefined') {
                        console.error('acBulkMeta object not available!');
                        return;
                    }
                    
                    console.log('acBulkMeta object:', acBulkMeta);
                    
                    // Auto-load posts on page load
                    setTimeout(function() {
                        console.log('Attempting to load JSON-LD data...');
                        loadJsonldData();
                    }, 100);
                    
                    // Auto-load when post type changes
                    $('#jsonld-post-type-select').on('change', function() {
                        console.log('Post type changed, reloading...');
                        loadJsonldData();
                    });
                } else {
                    // Original bulk meta page initialization
                    loadPagesData();
                    checkBulkGenerationStatus();
                }
            });
        </script>
        <?php
    }
    
    public function ajax_get_pages_data() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'page';
        $orderby = isset($_POST['orderby']) ? sanitize_text_field($_POST['orderby']) : 'title';
        $order = isset($_POST['order']) ? sanitize_text_field($_POST['order']) : 'ASC';
        $filter = isset($_POST['filter']) ? sanitize_text_field($_POST['filter']) : 'all';
        
        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => $order,
            'post_status' => 'any'
        );
        
        if ($orderby === 'ID') {
            $args['orderby'] = 'ID';
        }
        
        $posts = get_posts($args);
        $data = array();
        
        foreach ($posts as $post) {
            $yoast_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
            $yoast_desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
            $yoast_focus = get_post_meta($post->ID, '_yoast_wpseo_focuskw', true);
            $targeted_keywords = get_post_meta($post->ID, '_ac_targeted_keywords', true);
            
            $permalink = get_permalink($post->ID);
            $is_external = $this->is_external_url($permalink);
            
            $has_missing = empty($yoast_title) || empty($yoast_desc) || empty($yoast_focus);
            
            if ($filter === 'missing' && !$has_missing) {
                continue;
            }
            
            if ($filter === 'complete' && $has_missing) {
                continue;
            }
            
            $data[] = array(
                'ID' => $post->ID,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'url' => $permalink,
                'is_external' => $is_external,
                'edit_url' => get_edit_post_link($post->ID),
                'yoast_title' => $yoast_title,
                'yoast_desc' => $yoast_desc,
                'yoast_focus' => $yoast_focus,
                'targeted_keywords' => $targeted_keywords,
                'has_missing' => $has_missing
            );
        }
        
        // Custom sorting for populated fields
        if ($orderby === 'yoast_title' || $orderby === 'yoast_desc' || $orderby === 'yoast_focus') {
            usort($data, function($a, $b) use ($orderby, $order) {
                $val_a = $a[$orderby] ?? '';
                $val_b = $b[$orderby] ?? '';
                
                if ($order === 'DESC') {
                    return strcmp($val_b, $val_a);
                }
                return strcmp($val_a, $val_b);
            });
        }
        
        wp_send_json_success($data);
    }
    
    public function ajax_update_yoast_meta() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $field = sanitize_text_field($_POST['field']);
        // Use textarea sanitization for targeted_keywords, text field for others
        if ($field === 'targeted_keywords') {
            $value = sanitize_textarea_field($_POST['value']);
        } else {
            $value = sanitize_text_field($_POST['value']);
        }
        
        $allowed_fields = array(
            'yoast_title' => '_yoast_wpseo_title',
            'yoast_desc' => '_yoast_wpseo_metadesc',
            'yoast_focus' => '_yoast_wpseo_focuskw',
            'targeted_keywords' => '_ac_targeted_keywords'
        );
        
        if (!isset($allowed_fields[$field])) {
            wp_send_json_error('Invalid field');
        }
        
        $meta_key = $allowed_fields[$field];
        update_post_meta($post_id, $meta_key, $value);
        
        wp_send_json_success(array(
            'message' => 'Updated successfully',
            'post_id' => $post_id,
            'field' => $field,
            'value' => $value
        ));
    }
    
    public function ajax_save_openai_key() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $api_key = sanitize_text_field($_POST['api_key']);
        update_option('ac_openai_api_key', $api_key);
        
        wp_send_json_success(array('message' => 'API key saved successfully'));
    }
    
    public function ajax_save_global_prompt() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $global_prompt = sanitize_textarea_field($_POST['global_prompt']);
        update_option('ac_global_prompt', $global_prompt);
        
        wp_send_json_success(array('message' => 'Global prompt saved successfully'));
    }
    
    public function ajax_save_site_title_override() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $site_title_override = sanitize_text_field($_POST['site_title_override']);
        update_option('ac_site_title_override', $site_title_override);
        
        wp_send_json_success(array('message' => 'Site title override saved successfully'));
    }
    
    public function ajax_save_dark_mode() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $dark_mode = isset($_POST['dark_mode']) && $_POST['dark_mode'] === '1' ? '1' : '0';
        update_user_meta(get_current_user_id(), 'ac_bulk_meta_dark_mode', $dark_mode);
        
        wp_send_json_success(array(
            'message' => 'Dark mode preference saved successfully',
            'dark_mode' => $dark_mode
        ));
    }
    
    public function ajax_generate_meta_description() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $targeted_keywords = !empty($_POST['targeted_keywords']) ? sanitize_textarea_field($_POST['targeted_keywords']) : '';
        $post_title = get_the_title($post_id);
        $api_key = get_option('ac_openai_api_key');
        
        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }
        
        // Get the permalink URL
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            wp_send_json_error('Could not get page URL');
        }
        
        // Fetch the full HTML content of the page
        $page_response = wp_remote_get($permalink, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        ));
        
        if (is_wp_error($page_response)) {
            wp_send_json_error('Failed to fetch page content: ' . $page_response->get_error_message());
        }
        
        $page_html = wp_remote_retrieve_body($page_response);
        if (empty($page_html)) {
            wp_send_json_error('Page content is empty');
        }
        
        // Extract and clean the content with smart prioritization
        $page_content = $this->extract_prioritized_content($page_html);
        
        // Get global prompt
        $global_prompt = get_option('ac_global_prompt', '');
        
        // Prepare the enhanced prompt with full page content
        $prompt = "Write a compelling SEO meta description (maximum 155 characters) for a webpage titled '{$post_title}'. 

PAGE CONTENT:
{$page_content}" . (!empty($targeted_keywords) ? "

TARGETED KEYWORDS: {$targeted_keywords}" : "") . "

WRITING GUIDELINES:
- Keep the tone professional and serious
- Avoid exclamation points
- Write in a clear, authoritative voice
- Focus on value and benefits
- Use active voice when possible" . ($global_prompt ? "\n\nCUSTOM INSTRUCTIONS:\n{$global_prompt}" : "") . "

Create a meta description that:" . (!empty($targeted_keywords) ? "
1. Naturally incorporates the targeted keywords/phrases
2. Accurately represents the page content
3. Is engaging and click-worthy
4. Stays within 155 characters
5. Appeals to the target audience based on the content
6. Follows the writing guidelines above" : "
1. Accurately represents the page content
2. Is engaging and click-worthy
3. Stays within 155 characters
4. Appeals to the target audience based on the content
5. Follows the writing guidelines above");
        
        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are an expert SEO copywriter. Always respond with ONLY the meta description text, no quotes, no explanations, no additional text.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'temperature' => 0.7,
                'max_tokens' => 100
            ))
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('API request failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            wp_send_json_error('OpenAI error: ' . $body['error']['message']);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            wp_send_json_error('Invalid API response');
        }
        
        $generated_description = trim($body['choices'][0]['message']['content']);
        
        // Ensure meta description doesn't exceed 155 characters and doesn't cut words
        if (strlen($generated_description) > 155) {
            $generated_description = $this->truncate_at_word_boundary($generated_description, 155);
        }
        
        // Update the meta description
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $generated_description);
        
        // Log the generation
        $this->log_ai_generation($post_id, $post_title, $targeted_keywords, $generated_description, 'description');
        
        wp_send_json_success(array(
            'description' => $generated_description,
            'message' => 'Meta description generated successfully'
        ));
    }
    
    public function ajax_generate_title_tag() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $targeted_keywords = !empty($_POST['targeted_keywords']) ? sanitize_textarea_field($_POST['targeted_keywords']) : '';
        $post_title = get_the_title($post_id);
        $api_key = get_option('ac_openai_api_key');
        
        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }
        
        // Get the permalink URL
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            wp_send_json_error('Could not get page URL');
        }
        
        // Fetch the full HTML content of the page
        $page_response = wp_remote_get($permalink, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        ));
        
        if (is_wp_error($page_response)) {
            wp_send_json_error('Failed to fetch page content: ' . $page_response->get_error_message());
        }
        
        $page_html = wp_remote_retrieve_body($page_response);
        if (empty($page_html)) {
            wp_send_json_error('Page content is empty');
        }
        
        // Extract and clean the content with smart prioritization
        $page_content = $this->extract_prioritized_content($page_html);
        
        // Get global prompt
        $global_prompt = get_option('ac_global_prompt', '');
        
        // Get site name (override if set, otherwise use WordPress site name)
        $site_name_override = get_option('ac_site_title_override', '');
        $site_name = !empty($site_name_override) ? $site_name_override : get_bloginfo('name');
        
        // Calculate max title length: 65 total - site name length - 3 for " | "
        $max_title_length = 65 - strlen($site_name) - 3; // 3 for " | "
        if ($max_title_length < 20) {
            $max_title_length = 20; // Minimum reasonable length
        }
        
        // Prepare the enhanced prompt with full page content
        $prompt = "Write a compelling SEO title tag (maximum {$max_title_length} characters, NOT including site name) for a webpage titled '{$post_title}'. 

PAGE CONTENT:
{$page_content}" . (!empty($targeted_keywords) ? "

TARGETED KEYWORDS: {$targeted_keywords}" : "") . "

WRITING GUIDELINES:
- Keep the tone professional and serious
- Avoid exclamation points
- Write in a clear, authoritative voice
- Focus on value and benefits
- Use active voice when possible" . ($global_prompt ? "\n\nCUSTOM INSTRUCTIONS:\n{$global_prompt}" : "") . "

Create a title tag that:" . (!empty($targeted_keywords) ? "
1. Naturally incorporates the targeted keywords/phrases
2. Accurately represents the page content
3. Is engaging and click-worthy
4. Stays within {$max_title_length} characters (excluding site name)
5. Appeals to the target audience based on the content
6. Follows the writing guidelines above
7. IMPORTANT: The total title tag including site name must be exactly 65 characters or less. Respond with ONLY the title text, without the site name or pipe separator. The site name will be added automatically." : "
1. Accurately represents the page content
2. Is engaging and click-worthy
3. Stays within {$max_title_length} characters (excluding site name)
4. Appeals to the target audience based on the content
5. Follows the writing guidelines above
6. IMPORTANT: The total title tag including site name must be exactly 65 characters or less. Respond with ONLY the title text, without the site name or pipe separator. The site name will be added automatically.");

        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are an expert SEO copywriter. Always respond with ONLY the title text, no quotes, no explanations, no additional text, no site name, no pipe separator.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'temperature' => 0.7,
                'max_tokens' => 100
            ))
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('API request failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            wp_send_json_error('OpenAI error: ' . $body['error']['message']);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            wp_send_json_error('Invalid API response');
        }
        
        $generated_title = trim($body['choices'][0]['message']['content']);
        
        // Remove any pipe or site name if AI included it
        $generated_title = preg_replace('/\s*\|\s*.*$/', '', $generated_title);
        $generated_title = trim($generated_title);
        
        // Format: "GENERATED TITLE | SITENAME"
        $final_title = $generated_title . ' | ' . $site_name;
        
        // Ensure total length is max 65 characters
        if (strlen($final_title) > 65) {
            // Truncate the generated title to fit within 65 characters total
            $available_length = 65 - strlen($site_name) - 3; // 3 for " | "
            if ($available_length > 0) {
                // Truncate at word boundary to avoid cutting words
                $generated_title = $this->truncate_at_word_boundary($generated_title, $available_length);
                $final_title = $generated_title . ' | ' . $site_name;
                
                // Double-check total length and truncate again if needed (shouldn't happen, but safety check)
                if (strlen($final_title) > 65) {
                    $available_length = 65 - strlen($site_name) - 3;
                    $generated_title = $this->truncate_at_word_boundary($generated_title, $available_length);
                    $final_title = $generated_title . ' | ' . $site_name;
                }
            } else {
                // If site name is too long, just use site name (unlikely but handle edge case)
                $final_title = $this->truncate_at_word_boundary($site_name, 65);
            }
        }
        
        // Update the title tag
        update_post_meta($post_id, '_yoast_wpseo_title', $final_title);
        
        // Log the generation
        $this->log_ai_generation($post_id, $post_title, $targeted_keywords, $generated_title, 'title');
        
        wp_send_json_success(array(
            'title' => $final_title,
            'message' => 'Title tag generated successfully'
        ));
    }
    
    public function ajax_generate_focus_keyphrase() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $targeted_keywords = !empty($_POST['targeted_keywords']) ? sanitize_textarea_field($_POST['targeted_keywords']) : '';
        $post_title = get_the_title($post_id);
        $api_key = get_option('ac_openai_api_key');
        
        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }
        
        // Get the permalink URL
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            wp_send_json_error('Could not get page URL');
        }
        
        // Fetch the full HTML content of the page
        $page_response = wp_remote_get($permalink, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        ));
        
        if (is_wp_error($page_response)) {
            wp_send_json_error('Failed to fetch page content: ' . $page_response->get_error_message());
        }
        
        $page_html = wp_remote_retrieve_body($page_response);
        if (empty($page_html)) {
            wp_send_json_error('Page content is empty');
        }
        
        // Extract and clean the content with smart prioritization
        $page_content = $this->extract_prioritized_content($page_html);
        
        // Get global prompt
        $global_prompt = get_option('ac_global_prompt', '');
        
        // Prepare the enhanced prompt with full page content
        $prompt = "Based on the following webpage content, suggest a single SEO focus keyphrase (1-3 words maximum) that will maximize search visibility and traffic. Prioritize keyphrases that follow SEO best practices for maximum views.

PAGE TITLE: {$post_title}

PAGE CONTENT:
{$page_content}" . (!empty($targeted_keywords) ? "

TARGETED KEYWORDS: {$targeted_keywords}" : "") . "

SEO BEST PRACTICES FOR MAXIMUM VISIBILITY:
1. **Search Volume**: Choose keyphrases that people actually search for (not too niche, not too generic)
2. **Competition Balance**: Select keyphrases with moderate competition where ranking is achievable
3. **User Intent Match**: Ensure the keyphrase matches what users searching for this content actually want
4. **Relevance**: The keyphrase must accurately represent the primary topic of the page content
5. **Trending Potential**: Prefer keyphrases that are currently popular or growing in search volume
6. **Commercial/Informational Intent**: Consider whether users searching this phrase are looking for information, products, or services
7. **Length**: Balance between specificity (long-tail) and search volume (short-tail). 1-3 words is optimal for focus keyphrases
8. **Brandability**: If applicable, consider if the keyphrase has brand potential or is commonly associated with known entities

SELECTION CRITERIA FOR MAXIMUM VISIBILITY:
- Choose keyphrases that represent the PRIMARY and MOST IMPORTANT topic of the page
- Prefer keyphrases that users would naturally type into search engines
- Avoid overly generic terms (too much competition) or overly specific terms (too little search volume)
- Select keyphrases that have strong potential for ranking in search results
- Ensure the keyphrase aligns with what this page actually provides to users
- Consider seasonal trends or current events if relevant to the content" . ($global_prompt ? "\n\nCUSTOM INSTRUCTIONS:\n{$global_prompt}" : "") . "

IMPORTANT: 
- Respond with ONLY the focus keyphrase text (1-3 words), no quotes, no explanations, no additional text.
- Choose a keyphrase that will maximize search visibility and traffic based on SEO best practices.
- The keyphrase should be a single keyword or short phrase like 'digital marketing' or 'wordpress plugins' that balances search volume, competition, and relevance.";

        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are an expert SEO consultant specializing in keyword research and selection for maximum search visibility. Always respond with ONLY the focus keyphrase text (1-3 words), no quotes, no explanations, no additional text. Prioritize keyphrases that will drive the most traffic and visibility based on SEO best practices.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'temperature' => 0.7,
                'max_tokens' => 20
            ))
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('API request failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            wp_send_json_error('OpenAI error: ' . $body['error']['message']);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            wp_send_json_error('Invalid API response');
        }
        
        $generated_keyphrase = trim($body['choices'][0]['message']['content']);
        
        // Clean up - remove quotes if present, limit to reasonable length
        $generated_keyphrase = trim($generated_keyphrase, '"\'');
        $generated_keyphrase = trim($generated_keyphrase);
        
        // Limit to 100 characters (but should be much shorter)
        if (strlen($generated_keyphrase) > 100) {
            $generated_keyphrase = substr($generated_keyphrase, 0, 100);
        }
        
        // Update the focus keyphrase using Yoast SEO field
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $generated_keyphrase);
        
        // Log the generation
        $this->log_ai_generation($post_id, $post_title, $targeted_keywords, $generated_keyphrase, 'focus_keyphrase');
        
        wp_send_json_success(array(
            'keyphrase' => $generated_keyphrase,
            'message' => 'Focus keyphrase generated successfully'
        ));
    }
    
    public function ajax_generate_selected() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $posts_data_json = isset($_POST['posts_data']) ? stripslashes($_POST['posts_data']) : '';
        $generation_type = isset($_POST['generation_type']) ? sanitize_text_field($_POST['generation_type']) : 'descriptions';
        
        if (empty($posts_data_json)) {
            wp_send_json_error('No posts selected');
        }
        
        $posts_data = json_decode($posts_data_json, true);
        
        if (!is_array($posts_data) || empty($posts_data)) {
            wp_send_json_error('Invalid posts data');
        }
        
        // Sanitize and prepare posts for processing
        $posts_to_process = array();
        foreach ($posts_data as $post_data) {
            $post_id = intval($post_data['ID']);
            if ($post_id > 0) {
                $posts_to_process[] = array(
                    'ID' => $post_id,
                    'title' => sanitize_text_field($post_data['title']),
                    'keywords' => isset($post_data['keywords']) ? sanitize_text_field($post_data['keywords']) : ''
                );
            }
        }
        
        if (empty($posts_to_process)) {
            wp_send_json_error('No valid posts to process');
        }
        
        // Store in transient for processing
        set_transient('ac_bulk_generate_queue_' . get_current_user_id(), $posts_to_process, 3600);
        set_transient('ac_bulk_generate_progress_' . get_current_user_id(), array(
            'total' => count($posts_to_process),
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'current' => '',
            'status' => 'running',
            'type' => $generation_type
        ), 3600);
        
        wp_send_json_success(array(
            'total' => count($posts_to_process),
            'message' => 'Bulk generation started for selected posts'
        ));
    }
    
    private function is_external_url($url) {
        if (empty($url)) {
            return false;
        }
        
        $home_url = home_url();
        $permalink_host = parse_url($url, PHP_URL_HOST);
        $home_host = parse_url($home_url, PHP_URL_HOST);
        
        // If we can't parse the URL, assume it's external to be safe
        if (empty($permalink_host) || empty($home_host)) {
            return true;
        }
        
        // Check if hosts match (case-insensitive)
        return strtolower($permalink_host) !== strtolower($home_host);
    }
    
    private function truncate_at_word_boundary($text, $max_length) {
        // If text is already short enough, return as-is
        if (strlen($text) <= $max_length) {
            return $text;
        }
        
        // Truncate to max length
        $truncated = substr($text, 0, $max_length);
        
        // Check if we cut in the middle of a word
        // Look for the last space before the cutoff point
        $last_space = strrpos($truncated, ' ');
        
        // If we found a space and it's not too close to the start (at least 10 chars), use it
        if ($last_space !== false && $last_space > 10) {
            return trim(substr($text, 0, $last_space));
        }
        
        // If no space found or too close to start, just return truncated version
        // (this handles edge cases where words are very long)
        return trim($truncated);
    }
    
    private function log_ai_generation($post_id, $post_title, $targeted_keywords, $generated_content, $type = 'description', $additional_data = array()) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'post_id' => $post_id,
            'post_title' => $post_title,
            'targeted_keywords' => $targeted_keywords,
            'generated_description' => $type === 'description' ? $generated_content : '',
            'generated_title' => $type === 'title' ? $generated_content : '',
            'generated_keyphrase' => $type === 'focus_keyphrase' ? $generated_content : '',
            'generated_faqs' => $type === 'faq' ? $generated_content : '',
            'generated_jsonld' => $type === 'jsonld' ? $generated_content : '',
            'generation_type' => $type,
            'user_id' => get_current_user_id(),
            'user_name' => wp_get_current_user()->display_name
        );
        
        // Merge any additional data (e.g., FAQ count, FAQ items array, etc.)
        if (!empty($additional_data) && is_array($additional_data)) {
            $log_entry = array_merge($log_entry, $additional_data);
        }
        
        $logs = get_option('ac_ai_generation_logs', array());
        $logs[] = $log_entry;
        
        // Keep only last 100 entries to prevent database bloat
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        
        update_option('ac_ai_generation_logs', $logs);
        
        // Send to webhook if configured
        $webhook_url = get_option('ac_webhook_url', '');
        if (!empty($webhook_url)) {
            $this->send_log_to_webhook($webhook_url, $log_entry);
        }
    }
    
    private function send_log_to_webhook($webhook_url, $log_entry) {
        // Send log entry to webhook asynchronously to avoid blocking
        wp_remote_post($webhook_url, array(
            'timeout' => 5,
            'blocking' => false, // Don't wait for response
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($log_entry),
            'sslverify' => true
        ));
    }
    
    public function ajax_get_ai_logs() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $logs = get_option('ac_ai_generation_logs', array());
        
        // Reverse to show newest first
        $logs = array_reverse($logs);
        
        wp_send_json_success($logs);
    }
    
    public function ajax_validate_openai_key() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $api_key = get_option('ac_openai_api_key', '');
        
        if (empty($api_key)) {
            wp_send_json_success(array(
                'valid' => false,
                'status_code' => 0,
                'message' => 'No API key configured'
            ));
        }
        
        // Make a minimal API call to validate the key
        $response = wp_remote_get('https://api.openai.com/v1/models', array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            )
        ));
        
        $status_code = 0;
        $is_valid = false;
        $message = 'Unknown error';
        
        if (is_wp_error($response)) {
            $message = $response->get_error_message();
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($status_code === 200) {
                $is_valid = true;
                $message = 'API key is valid';
            } elseif ($status_code === 401) {
                $is_valid = false;
                $message = 'Invalid API key (401 Unauthorized)';
            } elseif ($status_code === 429) {
                $is_valid = true; // Key is valid but rate limited
                $message = 'API key is valid but rate limited (429 Too Many Requests)';
            } else {
                $is_valid = false;
                $decoded = json_decode($body, true);
                $message = isset($decoded['error']['message']) ? $decoded['error']['message'] : "HTTP {$status_code}";
            }
        }
        
        wp_send_json_success(array(
            'valid' => $is_valid,
            'status_code' => $status_code,
            'message' => $message
        ));
    }
    
    public function ajax_save_webhook_url() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $current_user_id = get_current_user_id();
        $webhook_set_by = get_option('ac_webhook_url_set_by', 0);
        $existing_webhook = get_option('ac_webhook_url', '');
        
        // If webhook exists and was set by a different user, prevent changes
        if (!empty($existing_webhook) && $webhook_set_by > 0 && $webhook_set_by !== $current_user_id) {
            $set_by_user = get_userdata($webhook_set_by);
            $set_by_name = $set_by_user ? $set_by_user->display_name : 'Unknown User';
            wp_send_json_error('Webhook URL is locked. Only ' . esc_html($set_by_name) . ' can modify or disable it.');
        }
        
        $webhook_url = !empty($_POST['webhook_url']) ? esc_url_raw($_POST['webhook_url']) : '';
        update_option('ac_webhook_url', $webhook_url);
        
        // Store who set it (or clear if being disabled)
        if (!empty($webhook_url)) {
            update_option('ac_webhook_url_set_by', $current_user_id);
        } else {
            delete_option('ac_webhook_url_set_by');
        }
        
        wp_send_json_success(array('message' => 'Webhook URL saved successfully'));
    }
    
    public function ajax_bulk_generate_start() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_type = sanitize_text_field($_POST['post_type']);
        $filter = sanitize_text_field($_POST['filter']);
        
        // Get posts that need generation (missing descriptions) - LIMIT to prevent memory issues
        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => 500, // Limit to prevent memory issues
            'post_status' => 'any',
            'fields' => 'ids' // Only get IDs to save memory
        );
        
        $post_ids = get_posts($args);
        $posts_to_process = array();
        
        // Process IDs in chunks to avoid memory issues
        foreach ($post_ids as $post_id) {
            $yoast_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            $targeted_keywords = get_post_meta($post_id, '_ac_targeted_keywords', true);
            
            // Only process if missing description (keywords are optional now)
            if (empty($yoast_desc)) {
                $posts_to_process[] = array(
                    'ID' => $post_id,
                    'title' => get_the_title($post_id),
                    'keywords' => $targeted_keywords
                );
            }
            
            // Clean up memory after each post
            unset($yoast_desc, $targeted_keywords);
        }
        
        // Clean up
        wp_reset_postdata();
        unset($post_ids, $args);
        
        // Store in transient for processing
        set_transient('ac_bulk_generate_queue_' . get_current_user_id(), $posts_to_process, 3600);
        set_transient('ac_bulk_generate_progress_' . get_current_user_id(), array(
            'total' => count($posts_to_process),
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'current' => '',
            'status' => 'running',
            'type' => 'descriptions'
        ), 3600);
        
        wp_send_json_success(array(
            'total' => count($posts_to_process),
            'message' => 'Bulk generation started'
        ));
    }
    
    public function ajax_bulk_generate_next() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $queue = get_transient('ac_bulk_generate_queue_' . get_current_user_id());
        $progress = get_transient('ac_bulk_generate_progress_' . get_current_user_id());
        
        if (empty($queue) || $progress['status'] !== 'running') {
            wp_send_json_error('No active generation process');
        }
        
        $current_post = array_shift($queue);
        $progress['current'] = $current_post['title'];
        $progress['processed']++;
        
        // Determine generation type
        $generation_type = isset($progress['type']) ? $progress['type'] : 'descriptions';
        
        // Process this post based on type
        $result = false;
        if ($generation_type === 'titles') {
            $result = $this->generate_single_title_tag($current_post['ID'], $current_post['keywords']);
        } elseif ($generation_type === 'focus_keyphrases') {
            $result = $this->generate_single_focus_keyphrase($current_post['ID'], $current_post['keywords']);
        } else {
        $result = $this->generate_single_meta_description($current_post['ID'], $current_post['keywords']);
        }
        
        if ($result && $result['success']) {
            $progress['success']++;
        } else {
            $progress['errors']++;
        }
        
        // Update progress
        set_transient('ac_bulk_generate_queue_' . get_current_user_id(), $queue);
        set_transient('ac_bulk_generate_progress_' . get_current_user_id(), $progress);
        
        // Store result for response before cleanup
        $response_result = $result;
        
        // Clean up memory (but don't flush all cache as it affects other plugins)
        unset($current_post);
        
        wp_send_json_success(array(
            'progress' => $progress,
            'result' => $response_result,
            'remaining' => count($queue)
        ));
    }
    
    public function ajax_bulk_generate_status() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        $progress = get_transient('ac_bulk_generate_progress_' . get_current_user_id());
        
        if (!$progress) {
            wp_send_json_success(array('status' => 'not_running'));
        }
        
        wp_send_json_success($progress);
    }
    
    public function ajax_bulk_generate_stop() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        delete_transient('ac_bulk_generate_queue_' . get_current_user_id());
        delete_transient('ac_bulk_generate_progress_' . get_current_user_id());
        
        wp_send_json_success(array('message' => 'Bulk generation stopped'));
    }
    
    public function ajax_bulk_generate_titles() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_type = sanitize_text_field($_POST['post_type']);
        
        // Get posts that need generation (missing titles) - LIMIT to prevent memory issues
        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => 500,
            'post_status' => 'any',
            'fields' => 'ids'
        );
        
        $post_ids = get_posts($args);
        $posts_to_process = array();
        
        foreach ($post_ids as $post_id) {
            $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
            $targeted_keywords = get_post_meta($post_id, '_ac_targeted_keywords', true);
            
            if (empty($yoast_title)) {
                $posts_to_process[] = array(
                    'ID' => $post_id,
                    'title' => get_the_title($post_id),
                    'keywords' => $targeted_keywords
                );
            }
            unset($yoast_title, $targeted_keywords);
        }
        
        wp_reset_postdata();
        unset($post_ids, $args);
        
        set_transient('ac_bulk_generate_queue_' . get_current_user_id(), $posts_to_process, 3600);
        set_transient('ac_bulk_generate_progress_' . get_current_user_id(), array(
            'total' => count($posts_to_process),
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'current' => '',
            'status' => 'running',
            'type' => 'titles'
        ), 3600);
        
        wp_send_json_success(array(
            'total' => count($posts_to_process),
            'message' => 'Bulk title generation started'
        ));
    }
    
    public function ajax_bulk_generate_descriptions() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_type = sanitize_text_field($_POST['post_type']);
        
        // Get posts that need generation (missing descriptions) - LIMIT to prevent memory issues
        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => 500,
            'post_status' => 'any',
            'fields' => 'ids'
        );
        
        $post_ids = get_posts($args);
        $posts_to_process = array();
        
        foreach ($post_ids as $post_id) {
            $yoast_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            $targeted_keywords = get_post_meta($post_id, '_ac_targeted_keywords', true);
            
            if (empty($yoast_desc)) {
                $posts_to_process[] = array(
                    'ID' => $post_id,
                    'title' => get_the_title($post_id),
                    'keywords' => $targeted_keywords
                );
            }
            unset($yoast_desc, $targeted_keywords);
        }
        
        wp_reset_postdata();
        unset($post_ids, $args);
        
        set_transient('ac_bulk_generate_queue_' . get_current_user_id(), $posts_to_process, 3600);
        set_transient('ac_bulk_generate_progress_' . get_current_user_id(), array(
            'total' => count($posts_to_process),
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'current' => '',
            'status' => 'running',
            'type' => 'descriptions'
        ), 3600);
        
        wp_send_json_success(array(
            'total' => count($posts_to_process),
            'message' => 'Bulk description generation started'
        ));
    }
    
    public function ajax_bulk_generate_focus_keyphrases() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_type = sanitize_text_field($_POST['post_type']);
        
        // Get posts that need generation (missing focus keyphrases) - LIMIT to prevent memory issues
        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => 500,
            'post_status' => 'any',
            'fields' => 'ids'
        );
        
        $post_ids = get_posts($args);
        $posts_to_process = array();
        
        foreach ($post_ids as $post_id) {
            $yoast_focus = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
            $targeted_keywords = get_post_meta($post_id, '_ac_targeted_keywords', true);
            
            if (empty($yoast_focus)) {
                $posts_to_process[] = array(
                    'ID' => $post_id,
                    'title' => get_the_title($post_id),
                    'keywords' => $targeted_keywords
                );
            }
            unset($yoast_focus, $targeted_keywords);
        }
        
        wp_reset_postdata();
        unset($post_ids, $args);
        
        set_transient('ac_bulk_generate_queue_' . get_current_user_id(), $posts_to_process, 3600);
        set_transient('ac_bulk_generate_progress_' . get_current_user_id(), array(
            'total' => count($posts_to_process),
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'current' => '',
            'status' => 'running',
            'type' => 'focus_keyphrases'
        ), 3600);
        
        wp_send_json_success(array(
            'total' => count($posts_to_process),
            'message' => 'Bulk focus keyphrase generation started'
        ));
    }
    
    private function generate_single_meta_description($post_id, $targeted_keywords = '') {
        // Increase memory limit for this operation
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '256M');
        }
        
        $post_title = get_the_title($post_id);
        $api_key = get_option('ac_openai_api_key');
        
        if (empty($api_key)) {
            return array('success' => false, 'error' => 'OpenAI API key not configured');
        }
        
        // Get the permalink URL
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            return array('success' => false, 'error' => 'Could not get page URL');
        }
        
        // Fetch the full HTML content of the page
        $page_response = wp_remote_get($permalink, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        ));
        
        if (is_wp_error($page_response)) {
            return array('success' => false, 'error' => 'Failed to fetch page content: ' . $page_response->get_error_message());
        }
        
        $page_html = wp_remote_retrieve_body($page_response);
        
        // Clean up HTTP response immediately
        unset($page_response);
        
        if (empty($page_html)) {
            return array('success' => false, 'error' => 'Page content is empty');
        }
        
        // Extract and clean the content
        $page_content = wp_strip_all_tags($page_html);
        
        // Clean up HTML immediately
        unset($page_html);
        
        $page_content = preg_replace('/\s+/', ' ', $page_content);
        $page_content = trim($page_content);
        
        // Limit content length to avoid token limits
        if (strlen($page_content) > 3000) {
            $page_content = substr($page_content, 0, 3000) . '...';
        }
        
        // Get global prompt
        $global_prompt = get_option('ac_global_prompt', '');
        
        // Prepare the enhanced prompt
        $prompt = "Write a compelling SEO meta description (maximum 155 characters) for a webpage titled '{$post_title}'. 

PAGE CONTENT:
{$page_content}" . (!empty($targeted_keywords) ? "

TARGETED KEYWORDS: {$targeted_keywords}" : "") . "

WRITING GUIDELINES:
- Keep the tone professional and serious
- Avoid exclamation points
- Write in a clear, authoritative voice
- Focus on value and benefits
- Use active voice when possible" . ($global_prompt ? "\n\nCUSTOM INSTRUCTIONS:\n{$global_prompt}" : "") . "

Create a meta description that:" . (!empty($targeted_keywords) ? "
1. Naturally incorporates the targeted keywords/phrases
2. Accurately represents the page content
3. Is engaging and click-worthy
4. Stays within 155 characters
5. Appeals to the target audience based on the content
6. Follows the writing guidelines above" : "
1. Accurately represents the page content
2. Is engaging and click-worthy
3. Stays within 155 characters
4. Appeals to the target audience based on the content
5. Follows the writing guidelines above");
        
        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are an expert SEO copywriter. Always respond with ONLY the meta description text, no quotes, no explanations, no additional text.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'temperature' => 0.7,
                'max_tokens' => 100
            ))
        ));
        
        // Clean up prompt immediately
        unset($prompt, $page_content);
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => 'API request failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Clean up response immediately
        unset($response);
        
        if (isset($body['error'])) {
            return array('success' => false, 'error' => 'OpenAI error: ' . $body['error']['message']);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            return array('success' => false, 'error' => 'Invalid API response');
        }
        
        $generated_description = trim($body['choices'][0]['message']['content']);
        
        // Clean up body immediately
        unset($body);
        
        // Ensure meta description doesn't exceed 155 characters and doesn't cut words
        if (strlen($generated_description) > 155) {
            $generated_description = $this->truncate_at_word_boundary($generated_description, 155);
        }
        
        // Update the meta description
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $generated_description);
        
        // Clean up post cache
        clean_post_cache($post_id);
        
        // Log the generation
        $this->log_ai_generation($post_id, $post_title, $targeted_keywords, $generated_description, 'description');
        
        // Final cleanup
        wp_reset_postdata();
        
        return array(
            'success' => true,
            'description' => $generated_description
        );
    }
    
    private function generate_single_title_tag($post_id, $targeted_keywords = '') {
        // Increase memory limit for this operation
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '256M');
        }
        
        $post_title = get_the_title($post_id);
        $api_key = get_option('ac_openai_api_key');
        
        if (empty($api_key)) {
            return array('success' => false, 'error' => 'OpenAI API key not configured');
        }
        
        // Get the permalink URL
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            return array('success' => false, 'error' => 'Could not get page URL');
        }
        
        // Fetch the full HTML content of the page
        $page_response = wp_remote_get($permalink, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        ));
        
        if (is_wp_error($page_response)) {
            return array('success' => false, 'error' => 'Failed to fetch page content: ' . $page_response->get_error_message());
        }
        
        $page_html = wp_remote_retrieve_body($page_response);
        unset($page_response);
        
        if (empty($page_html)) {
            return array('success' => false, 'error' => 'Page content is empty');
        }
        
        // Extract and clean the content
        $page_content = $this->extract_prioritized_content($page_html);
        unset($page_html);
        
        // Get global prompt
        $global_prompt = get_option('ac_global_prompt', '');
        
        // Get site name (override if set, otherwise use WordPress site name)
        $site_name_override = get_option('ac_site_title_override', '');
        $site_name = !empty($site_name_override) ? $site_name_override : get_bloginfo('name');
        
        // Calculate max title length: 65 total - site name length - 3 for " | "
        $max_title_length = 65 - strlen($site_name) - 3;
        if ($max_title_length < 20) {
            $max_title_length = 20;
        }
        
        // Prepare the enhanced prompt
        $prompt = "Write a compelling SEO title tag (maximum {$max_title_length} characters, NOT including site name) for a webpage titled '{$post_title}'. 

PAGE CONTENT:
{$page_content}" . (!empty($targeted_keywords) ? "

TARGETED KEYWORDS: {$targeted_keywords}" : "") . "

WRITING GUIDELINES:
- Keep the tone professional and serious
- Avoid exclamation points
- Write in a clear, authoritative voice
- Focus on value and benefits
- Use active voice when possible" . ($global_prompt ? "\n\nCUSTOM INSTRUCTIONS:\n{$global_prompt}" : "") . "

Create a title tag that:" . (!empty($targeted_keywords) ? "
1. Naturally incorporates the targeted keywords/phrases
2. Accurately represents the page content
3. Is engaging and click-worthy
4. Stays within {$max_title_length} characters (excluding site name)
5. Appeals to the target audience based on the content
6. Follows the writing guidelines above
7. IMPORTANT: The total title tag including site name must be exactly 65 characters or less. Respond with ONLY the title text, without the site name or pipe separator. The site name will be added automatically." : "
1. Accurately represents the page content
2. Is engaging and click-worthy
3. Stays within {$max_title_length} characters (excluding site name)
4. Appeals to the target audience based on the content
5. Follows the writing guidelines above
6. IMPORTANT: The total title tag including site name must be exactly 65 characters or less. Respond with ONLY the title text, without the site name or pipe separator. The site name will be added automatically.");
        
        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are an expert SEO copywriter. Always respond with ONLY the title text, no quotes, no explanations, no additional text, no site name, no pipe separator.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'temperature' => 0.7,
                'max_tokens' => 100
            ))
        ));
        
        unset($prompt, $page_content);
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => 'API request failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        unset($response);
        
        if (isset($body['error'])) {
            return array('success' => false, 'error' => 'OpenAI error: ' . $body['error']['message']);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            return array('success' => false, 'error' => 'Invalid API response');
        }
        
        $generated_title = trim($body['choices'][0]['message']['content']);
        $generated_title = preg_replace('/\s*\|\s*.*$/', '', $generated_title);
        $generated_title = trim($generated_title);
        
        // Format: "GENERATED TITLE | SITENAME"
        $final_title = $generated_title . ' | ' . $site_name;
        
        // Ensure total length is max 65 characters
        if (strlen($final_title) > 65) {
            $available_length = 65 - strlen($site_name) - 3;
            if ($available_length > 0) {
                // Truncate at word boundary to avoid cutting words
                $generated_title = $this->truncate_at_word_boundary($generated_title, $available_length);
                $final_title = $generated_title . ' | ' . $site_name;
                
                // Double-check total length and truncate again if needed (shouldn't happen, but safety check)
                if (strlen($final_title) > 65) {
                    $available_length = 65 - strlen($site_name) - 3;
                    $generated_title = $this->truncate_at_word_boundary($generated_title, $available_length);
                    $final_title = $generated_title . ' | ' . $site_name;
                }
            } else {
                $final_title = $this->truncate_at_word_boundary($site_name, 65);
            }
        }
        
        unset($body);
        
        // Update the title tag
        update_post_meta($post_id, '_yoast_wpseo_title', $final_title);
        clean_post_cache($post_id);
        
        // Log the generation
        $this->log_ai_generation($post_id, $post_title, $targeted_keywords, $generated_title, 'title');
        
        wp_reset_postdata();
        
        return array(
            'success' => true,
            'title' => $final_title
        );
    }
    
    private function generate_single_focus_keyphrase($post_id, $targeted_keywords = '') {
        // Increase memory limit for this operation
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '256M');
        }
        
        $post_title = get_the_title($post_id);
        $api_key = get_option('ac_openai_api_key');
        
        if (empty($api_key)) {
            return array('success' => false, 'error' => 'OpenAI API key not configured');
        }
        
        // Get the permalink URL
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            return array('success' => false, 'error' => 'Could not get page URL');
        }
        
        // Fetch the full HTML content of the page
        $page_response = wp_remote_get($permalink, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        ));
        
        if (is_wp_error($page_response)) {
            return array('success' => false, 'error' => 'Failed to fetch page content: ' . $page_response->get_error_message());
        }
        
        $page_html = wp_remote_retrieve_body($page_response);
        unset($page_response);
        
        if (empty($page_html)) {
            return array('success' => false, 'error' => 'Page content is empty');
        }
        
        // Extract and clean the content
        $page_content = $this->extract_prioritized_content($page_html);
        unset($page_html);
        
        // Get global prompt
        $global_prompt = get_option('ac_global_prompt', '');
        
        // Prepare the enhanced prompt
        $prompt = "Based on the following webpage content, suggest a single SEO focus keyphrase (1-3 words maximum) that will maximize search visibility and traffic. Prioritize keyphrases that follow SEO best practices for maximum views.

PAGE TITLE: {$post_title}

PAGE CONTENT:
{$page_content}" . (!empty($targeted_keywords) ? "

TARGETED KEYWORDS: {$targeted_keywords}" : "") . "

SEO BEST PRACTICES FOR MAXIMUM VISIBILITY:
1. **Search Volume**: Choose keyphrases that people actually search for (not too niche, not too generic)
2. **Competition Balance**: Select keyphrases with moderate competition where ranking is achievable
3. **User Intent Match**: Ensure the keyphrase matches what users searching for this content actually want
4. **Relevance**: The keyphrase must accurately represent the primary topic of the page content
5. **Trending Potential**: Prefer keyphrases that are currently popular or growing in search volume
6. **Commercial/Informational Intent**: Consider whether users searching this phrase are looking for information, products, or services
7. **Length**: Balance between specificity (long-tail) and search volume (short-tail). 1-3 words is optimal for focus keyphrases
8. **Brandability**: If applicable, consider if the keyphrase has brand potential or is commonly associated with known entities

SELECTION CRITERIA FOR MAXIMUM VISIBILITY:
- Choose keyphrases that represent the PRIMARY and MOST IMPORTANT topic of the page
- Prefer keyphrases that users would naturally type into search engines
- Avoid overly generic terms (too much competition) or overly specific terms (too little search volume)
- Select keyphrases that have strong potential for ranking in search results
- Ensure the keyphrase aligns with what this page actually provides to users
- Consider seasonal trends or current events if relevant to the content" . ($global_prompt ? "\n\nCUSTOM INSTRUCTIONS:\n{$global_prompt}" : "") . "

IMPORTANT: 
- Respond with ONLY the focus keyphrase text (1-3 words), no quotes, no explanations, no additional text.
- Choose a keyphrase that will maximize search visibility and traffic based on SEO best practices.
- The keyphrase should be a single keyword or short phrase like 'digital marketing' or 'wordpress plugins' that balances search volume, competition, and relevance.";

        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are an expert SEO consultant specializing in keyword research and selection for maximum search visibility. Always respond with ONLY the focus keyphrase text (1-3 words), no quotes, no explanations, no additional text. Prioritize keyphrases that will drive the most traffic and visibility based on SEO best practices.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'temperature' => 0.7,
                'max_tokens' => 20
            ))
        ));
        
        unset($prompt, $page_content);
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => 'API request failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        unset($response);
        
        if (isset($body['error'])) {
            return array('success' => false, 'error' => 'OpenAI error: ' . $body['error']['message']);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            return array('success' => false, 'error' => 'Invalid API response');
        }
        
        $generated_keyphrase = trim($body['choices'][0]['message']['content']);
        $generated_keyphrase = trim($generated_keyphrase, '"\'');
        $generated_keyphrase = trim($generated_keyphrase);
        
        if (strlen($generated_keyphrase) > 100) {
            $generated_keyphrase = substr($generated_keyphrase, 0, 100);
        }
        
        unset($body);
        
        // Update the focus keyphrase
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $generated_keyphrase);
        clean_post_cache($post_id);
        
        // Log the generation
        $this->log_ai_generation($post_id, $post_title, $targeted_keywords, $generated_keyphrase, 'focus_keyphrase');
        
        wp_reset_postdata();
        
        return array(
            'success' => true,
            'keyphrase' => $generated_keyphrase
        );
    }
    
    public function ajax_generate_faqs() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $replace_existing = isset($_POST['replace']) && intval($_POST['replace']) === 1;
        
        // Get the permalink URL
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            wp_send_json_error('Could not get page URL');
        }
        
        // Check if permalink is external
        if ($this->is_external_url($permalink)) {
            wp_send_json_error('Cannot generate FAQs for external permalinks');
        }
        
        // Delete existing FAQs if replacing
        if ($replace_existing) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'ac_faqs';
            $wpdb->delete($table_name, array('post_id' => $post_id), array('%d'));
        }
        
        $api_key = get_option('ac_openai_api_key');
        
        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }
        
        // Fetch the full HTML content of the page
        $page_response = wp_remote_get($permalink, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        ));
        
        if (is_wp_error($page_response)) {
            wp_send_json_error('Failed to fetch page content: ' . $page_response->get_error_message());
        }
        
        $page_html = wp_remote_retrieve_body($page_response);
        if (empty($page_html)) {
            wp_send_json_error('Page content is empty');
        }
        
        // Extract and clean the content with smart prioritization
        $page_content = $this->extract_prioritized_content($page_html);
        
        $post_title = get_the_title($post_id);
        $faq_focus = get_option('ac_faq_focus', '');
        $faq_count = get_option('ac_faq_count', 5); // Default 5, max 15
        
        // Prepare the enhanced FAQ generation prompt focused on real user questions
        $prompt = "Based on the following webpage content, generate exactly {$faq_count} FAQ questions and answers that people would ACTUALLY search for or ask about this topic. Focus on questions that real users would type into Google or ask a support team.

PAGE TITLE: {$post_title}

PAGE CONTENT:
{$page_content}" . (!empty($faq_focus) ? "

SPECIFIC FOCUS AREAS:
{$faq_focus}

Please prioritize generating FAQs about the topics listed above. Generate questions that people would actually ask about these specific areas." : "") . "

CRITICAL: Generate questions that REAL PEOPLE would actually ask, such as:
- 'How do I...' questions about processes or procedures
- 'What is...' questions about concepts or definitions
- 'How much does...' questions about pricing, costs, or requirements
- 'Can I...' or 'Is it possible to...' questions about capabilities or limitations
- 'Why should I...' or 'What are the benefits of...' questions about value propositions
- 'How long does...' questions about timeframes or duration
- 'What happens if...' questions about scenarios or edge cases
- 'Do I need...' questions about requirements or prerequisites
- 'What's the difference between...' comparison questions
- Questions about common problems, issues, or concerns people have

Avoid generic or obvious questions. Focus on questions that demonstrate confusion, need clarification, or seek practical information that would help someone make a decision or take action.

Format your response as:
Q1: [Question]
A1: [Answer]

Q2: [Question]
A2: [Answer]

...and so on for all {$faq_count} FAQs.";
        
        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are an expert at understanding what questions real people actually ask. Generate exactly ' . $faq_count . ' FAQ questions and answers that people would genuinely search for or ask about. Focus on practical, actionable questions that demonstrate real user intent. Format as Q1: [Question] A1: [Answer] etc.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'temperature' => 0.7,
                'max_tokens' => 2000
            ))
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('API request failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            wp_send_json_error('OpenAI error: ' . $body['error']['message']);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            wp_send_json_error('Invalid API response');
        }
        
        $generated_content = trim($body['choices'][0]['message']['content']);
        
        // Parse the FAQ content
        $faqs = $this->parse_faq_content($generated_content);
        
        if (empty($faqs)) {
            wp_send_json_error('Could not parse FAQ content from AI response');
        }
        
        // Store FAQs in database
        $stored_count = 0;
        foreach ($faqs as $faq) {
            $faq_id = $this->store_faq($post_id, $faq['question'], $faq['answer']);
            if ($faq_id) {
                $stored_count++;
            }
        }
        
        // Log FAQ generation for webhook
        $this->log_ai_generation(
            $post_id,
            $post_title,
            $faq_focus, // Use FAQ focus as "targeted keywords" equivalent
            $generated_content, // Raw AI response
            'faq',
            array(
                'faq_count' => $stored_count,
                'faqs' => $faqs, // Include parsed FAQs array
                'faq_focus' => $faq_focus
            )
        );
        
        wp_send_json_success(array(
            'message' => "Generated {$stored_count} FAQs successfully",
            'faqs_count' => $stored_count,
            'replaced' => $replace_existing
        ));
    }
    
    private function parse_faq_content($content) {
        $faqs = array();
        
        // Try to parse Q1: A1: format
        preg_match_all('/Q(\d+):\s*(.+?)\s*A\1:\s*(.+?)(?=Q\d+:|$)/s', $content, $matches, PREG_SET_ORDER);
        
        if (!empty($matches)) {
            foreach ($matches as $match) {
                $faqs[] = array(
                    'question' => trim($match[2]),
                    'answer' => trim($match[3])
                );
            }
        } else {
            // Fallback: try to parse any Q: A: format
            preg_match_all('/Q:\s*(.+?)\s*A:\s*(.+?)(?=Q:|$)/s', $content, $matches, PREG_SET_ORDER);
            
            foreach ($matches as $match) {
                $faqs[] = array(
                    'question' => trim($match[1]),
                    'answer' => trim($match[2])
                );
            }
        }
        
        return $faqs;
    }
    
    private function store_faq($post_id, $question, $answer) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ac_faqs';
        
        // Create table if it doesn't exist
        $this->create_faqs_table();
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'post_id' => $post_id,
                'question' => $question,
                'answer' => $answer,
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id()
            ),
            array('%d', '%s', '%s', '%s', '%d')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    public function create_faqs_table_on_activate() {
        $this->create_faqs_table();
    }

    private function create_faqs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ac_faqs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            question text NOT NULL,
            answer text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_by bigint(20) NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY created_by (created_by)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function ajax_get_faqs_data() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'page';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if ($post_id > 0) {
            // Get FAQs for specific post
            $faqs = $this->get_faqs_for_post($post_id);
            $faqs_deployed = get_post_meta($post_id, '_ac_faqs_deployed', true);
            wp_send_json_success(array(
                'faqs' => $faqs,
                'faqs_deployed' => (bool)$faqs_deployed
            ));
        } else {
            // Get posts with FAQ counts
            $args = array(
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => 'any'
            );
            
            $posts = get_posts($args);
            $data = array();
            
            foreach ($posts as $post) {
                $faq_count = $this->get_faq_count_for_post($post->ID);
                $faqs_deployed = get_post_meta($post->ID, '_ac_faqs_deployed', true);
                $permalink = get_permalink($post->ID);
                $is_external = $this->is_external_url($permalink);
                
                $data[] = array(
                    'ID' => $post->ID,
                    'title' => $post->post_title,
                    'status' => $post->post_status,
                    'url' => $permalink,
                    'edit_url' => get_edit_post_link($post->ID),
                    'faq_count' => $faq_count,
                    'faqs_deployed' => (bool)$faqs_deployed,
                    'is_external' => $is_external
                );
            }
            
            wp_send_json_success($data);
        }
    }
    
    private function get_faqs_for_post($post_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ac_faqs';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE post_id = %d ORDER BY id ASC",
            $post_id
        ));
        
        return $results ? $results : array();
    }
    
    private function get_faq_count_for_post($post_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ac_faqs';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE post_id = %d",
            $post_id
        ));
        
        return intval($count);
    }
    
    public function ajax_bulk_generate_faqs() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_type = sanitize_text_field($_POST['post_type']);
        
        // Get all posts of the specified type
        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => 'any'
        );
        
        $posts = get_posts($args);
        $total = count($posts);
        $processed = 0;
        $success = 0;
        $errors = 0;
        
        foreach ($posts as $post) {
            $processed++;
            
            // Check if post already has FAQs
            $existing_count = $this->get_faq_count_for_post($post->ID);
            if ($existing_count > 0) {
                continue; // Skip posts that already have FAQs
            }
            
            // Generate FAQs for this post
            $result = $this->generate_faqs_for_post($post->ID);
            
            if ($result['success']) {
                $success++;
            } else {
                $errors++;
            }
            
            // Add a small delay to avoid overwhelming the API
            usleep(500000); // 0.5 second delay
        }
        
        wp_send_json_success(array(
            'total' => $total,
            'processed' => $processed,
            'success' => $success,
            'errors' => $errors,
            'message' => "Bulk FAQ generation completed. Generated FAQs for {$success} posts."
        ));
    }
    
    private function generate_faqs_for_post($post_id) {
        $api_key = get_option('ac_openai_api_key');
        
        if (empty($api_key)) {
            return array('success' => false, 'error' => 'OpenAI API key not configured');
        }
        
        // Get the permalink URL
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            return array('success' => false, 'error' => 'Could not get page URL');
        }
        
        // Fetch the full HTML content of the page
        $page_response = wp_remote_get($permalink, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        ));
        
        if (is_wp_error($page_response)) {
            return array('success' => false, 'error' => 'Failed to fetch page content: ' . $page_response->get_error_message());
        }
        
        $page_html = wp_remote_retrieve_body($page_response);
        if (empty($page_html)) {
            return array('success' => false, 'error' => 'Page content is empty');
        }
        
        // Extract and clean the content with smart prioritization
        $page_content = $this->extract_prioritized_content($page_html);
        
        $post_title = get_the_title($post_id);
        $faq_focus = get_option('ac_faq_focus', '');
        
        // Prepare the enhanced FAQ generation prompt focused on real user questions
        $prompt = "Based on the following webpage content, generate exactly 10 FAQ questions and answers that people would ACTUALLY search for or ask about this topic. Focus on questions that real users would type into Google or ask a support team.

PAGE TITLE: {$post_title}

PAGE CONTENT:
{$page_content}" . (!empty($faq_focus) ? "

SPECIFIC FOCUS AREAS:
{$faq_focus}

Please prioritize generating FAQs about the topics listed above. Generate questions that people would actually ask about these specific areas." : "") . "

CRITICAL: Generate questions that REAL PEOPLE would actually ask, such as:
- 'How do I...' questions about processes or procedures
- 'What is...' questions about concepts or definitions
- 'How much does...' questions about pricing, costs, or requirements
- 'Can I...' or 'Is it possible to...' questions about capabilities or limitations
- 'Why should I...' or 'What are the benefits of...' questions about value propositions
- 'How long does...' questions about timeframes or duration
- 'What happens if...' questions about scenarios or edge cases
- 'Do I need...' questions about requirements or prerequisites
- 'What's the difference between...' comparison questions
- Questions about common problems, issues, or concerns people have

Avoid generic or obvious questions. Focus on questions that demonstrate confusion, need clarification, or seek practical information that would help someone make a decision or take action.

Format your response as:
Q1: [Question]
A1: [Answer]

Q2: [Question]
A2: [Answer]

...and so on for all 10 FAQs.";
        
        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are an expert at understanding what questions real people actually ask. Generate exactly 10 FAQ questions and answers that people would genuinely search for or ask about. Focus on practical, actionable questions that demonstrate real user intent. Format as Q1: [Question] A1: [Answer] etc.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'temperature' => 0.7,
                'max_tokens' => 2000
            ))
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => 'API request failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return array('success' => false, 'error' => 'OpenAI error: ' . $body['error']['message']);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            return array('success' => false, 'error' => 'Invalid API response');
        }
        
        $generated_content = trim($body['choices'][0]['message']['content']);
        
        // Parse the FAQ content
        $faqs = $this->parse_faq_content($generated_content);
        
        if (empty($faqs)) {
            return array('success' => false, 'error' => 'Could not parse FAQ content from AI response');
        }
        
        // Store FAQs in database
        $stored_count = 0;
        foreach ($faqs as $faq) {
            $faq_id = $this->store_faq($post_id, $faq['question'], $faq['answer']);
            if ($faq_id) {
                $stored_count++;
            }
        }
        
        // Log FAQ generation for webhook
        $this->log_ai_generation(
            $post_id,
            $post_title,
            $faq_focus, // Use FAQ focus as "targeted keywords" equivalent
            $generated_content, // Raw AI response
            'faq',
            array(
                'faq_count' => $stored_count,
                'faqs' => $faqs, // Include parsed FAQs array
                'faq_focus' => $faq_focus,
                'bulk_generation' => true // Flag to indicate this was bulk generation
            )
        );
        
        return array(
            'success' => true,
            'faqs_count' => $stored_count
        );
    }
    
    public function ajax_export_faqs_csv() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }
        
        $post_type = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : 'page';
        
        // Get all FAQs for the specified post type
        global $wpdb;
        $table_name = $wpdb->prefix . 'ac_faqs';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, p.post_title, p.post_status 
             FROM $table_name f 
             INNER JOIN {$wpdb->posts} p ON f.post_id = p.ID 
             WHERE p.post_type = %s 
             ORDER BY p.post_title, f.id",
            $post_type
        ));
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="faqs_' . $post_type . '_' . date('Y-m-d') . '.csv"');
        
        // Create CSV content
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, array('Post ID', 'Post Title', 'Post Status', 'FAQ ID', 'Question', 'Answer', 'Created At', 'Created By'));
        
        // CSV data
        foreach ($results as $faq) {
            fputcsv($output, array(
                $faq->post_id,
                $faq->post_title,
                $faq->post_status,
                $faq->id,
                $faq->question,
                $faq->answer,
                $faq->created_at,
                $faq->created_by
            ));
        }
        
        fclose($output);
        exit;
    }
    
    public function ajax_delete_faq() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $faq_id = intval($_POST['faq_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ac_faqs';
        
        $result = $wpdb->delete(
            $table_name,
            array('id' => $faq_id),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to delete FAQ');
        }
        
        wp_send_json_success(array('message' => 'FAQ deleted successfully'));
    }
    
    public function ajax_add_faq() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $question = !empty($_POST['question']) ? sanitize_textarea_field($_POST['question']) : '';
        // Allow HTML in answer field
        $answer = !empty($_POST['answer']) ? wp_kses_post($_POST['answer']) : '';
        
        // Check if permalink is external
        $permalink = get_permalink($post_id);
        if ($this->is_external_url($permalink)) {
            wp_send_json_error('Cannot add FAQs for external permalinks');
        }
        
        // Use store_faq method to create new FAQ
        $faq_id = $this->store_faq($post_id, $question, $answer);
        
        if ($faq_id === false) {
            wp_send_json_error('Failed to create FAQ');
        }
        
        // Return the new FAQ data
        wp_send_json_success(array(
            'message' => 'FAQ created successfully',
            'faq' => array(
                'id' => $faq_id,
                'post_id' => $post_id,
                'question' => $question,
                'answer' => $answer
            )
        ));
    }
    
    public function ajax_save_faq() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $faq_id = intval($_POST['faq_id']);
        $field = sanitize_text_field($_POST['field']);
        
        // For answer field, allow HTML content and sanitize with wp_kses_post
        // For question field, use textarea_field (plain text)
        if ($field === 'answer') {
            $value = wp_kses_post($_POST['value']);
        } else {
            $value = sanitize_textarea_field($_POST['value']);
        }
        
        if (!in_array($field, array('question', 'answer'))) {
            wp_send_json_error('Invalid field');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ac_faqs';
        
        $result = $wpdb->update(
            $table_name,
            array($field => $value),
            array('id' => $faq_id),
            array('%s'),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to save FAQ');
        }
        
        wp_send_json_success(array('message' => 'FAQ saved successfully'));
    }
    
    public function ajax_save_faq_focus() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $faq_focus = sanitize_textarea_field($_POST['faq_focus']);
        update_option('ac_faq_focus', $faq_focus);
        
        wp_send_json_success(array('message' => 'FAQ focus saved successfully'));
    }
    
    public function ajax_save_faq_count() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $faq_count = intval($_POST['faq_count']);
        
        // Validate: must be between 1 and 15
        if ($faq_count < 1 || $faq_count > 15) {
            wp_send_json_error('FAQ count must be between 1 and 15');
        }
        
        update_option('ac_faq_count', $faq_count);
        
        wp_send_json_success(array('message' => 'FAQ count saved successfully'));
    }
    
    public function ajax_save_faq_deploy_global() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $settings = array(
            'mode' => sanitize_text_field($_POST['settings']['mode']),
            'heading_color' => sanitize_text_field($_POST['settings']['heading_color']),
            'answer_color' => sanitize_text_field($_POST['settings']['answer_color']),
            'selector' => sanitize_text_field($_POST['settings']['selector']),
            'header' => sanitize_text_field($_POST['settings']['header']),
            'container_class' => sanitize_text_field($_POST['settings']['container_class']),
            'header_color' => sanitize_text_field($_POST['settings']['header_color']),
            'header_font_weight' => sanitize_text_field($_POST['settings']['header_font_weight']),
            'heading_font_weight' => sanitize_text_field($_POST['settings']['heading_font_weight']),
            'answer_font_weight' => sanitize_text_field($_POST['settings']['answer_font_weight']),
            'number_faqs' => isset($_POST['settings']['number_faqs']) ? (bool) $_POST['settings']['number_faqs'] : false,
            'wrapper_css' => sanitize_textarea_field($_POST['settings']['wrapper_css'])
        );
        
        update_option('ac_faq_deploy_global', $settings);
        
        wp_send_json_success(array('message' => 'Global deploy settings saved successfully'));
    }
    
    public function ajax_deploy_faqs() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        
        // Only set deployed flag - all settings come from global
        update_post_meta($post_id, '_ac_faqs_deployed', true);
        
        wp_send_json_success(array('message' => 'FAQs deployed successfully'));
    }
    
    public function ajax_undeploy_faqs() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        
        // Remove deployed flag
        delete_post_meta($post_id, '_ac_faqs_deployed');
        
        wp_send_json_success(array('message' => 'FAQs undeployed successfully'));
    }
    
    public function ajax_save_jsonld_settings() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $settings = array(
            'org_name' => sanitize_text_field($_POST['settings']['org_name']),
            'org_url' => esc_url_raw($_POST['settings']['org_url']),
            'org_logo' => esc_url_raw($_POST['settings']['org_logo']),
            'org_description' => sanitize_textarea_field($_POST['settings']['org_description']),
            'org_phone' => sanitize_text_field($_POST['settings']['org_phone']),
            'org_email' => sanitize_email($_POST['settings']['org_email']),
            'org_address' => sanitize_textarea_field($_POST['settings']['org_address']),
            'org_facebook' => esc_url_raw($_POST['settings']['org_facebook']),
            'org_twitter' => esc_url_raw($_POST['settings']['org_twitter']),
            'org_linkedin' => esc_url_raw($_POST['settings']['org_linkedin'])
        );
        
        update_option('ac_jsonld_settings', $settings);
        
        wp_send_json_success(array('message' => 'Organization settings saved successfully'));
    }
    
    public function ajax_generate_jsonld() {
        error_log('JSON-LD Generation: Starting AJAX request');
        
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        error_log('JSON-LD Generation: Nonce check passed');
        
        if (!current_user_can('edit_posts')) {
            error_log('JSON-LD Generation: User not authorized');
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        error_log('JSON-LD Generation: Post ID = ' . $post_id);
        
        $post = get_post($post_id);
        if (!$post) {
            error_log('JSON-LD Generation: Post not found for ID ' . $post_id);
            wp_send_json_error('Post not found');
        }
        
        error_log('JSON-LD Generation: Post found - ' . $post->post_title);
        
        $jsonld_settings = get_option('ac_jsonld_settings', array());
        error_log('JSON-LD Generation: Settings = ' . print_r($jsonld_settings, true));
        
        $jsonld = $this->generate_jsonld_for_post($post, $jsonld_settings);
        error_log('JSON-LD Generation: Generated JSON-LD = ' . substr($jsonld, 0, 200) . '...');
        
        // Save the JSON-LD to post meta
        update_post_meta($post_id, '_ac_jsonld', $jsonld);
        error_log('JSON-LD Generation: Saved to post meta');
        
        // Log JSON-LD generation for webhook
        $this->log_ai_generation(
            $post_id,
            $post->post_title,
            '', // No targeted keywords for JSON-LD
            $jsonld,
            'jsonld',
            array(
                'jsonld_type' => 'Organization', // Could be enhanced to detect actual type
                'jsonld_settings' => $jsonld_settings
            )
        );
        
        wp_send_json_success(array('jsonld' => $jsonld));
    }
    
    public function ajax_get_jsonld_data() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'page';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if ($post_id > 0) {
            // Get JSON-LD for specific post
            $jsonld = get_post_meta($post_id, '_ac_jsonld', true);
            wp_send_json_success(array('jsonld' => $jsonld));
        } else {
            // Get posts with JSON-LD status
            $args = array(
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => 'any'
            );
            
            $posts = get_posts($args);
            $data = array();
            
            foreach ($posts as $post) {
                $jsonld = get_post_meta($post->ID, '_ac_jsonld', true);
                $jsonld_status = 'missing';
                
                if (!empty($jsonld)) {
                    // Validate JSON
                    $decoded = json_decode($jsonld, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $jsonld_status = 'present';
                    } else {
                        $jsonld_status = 'invalid';
                    }
                }
                
                $data[] = array(
                    'ID' => $post->ID,
                    'title' => $post->post_title,
                    'status' => $post->post_status,
                    'url' => get_permalink($post->ID),
                    'edit_url' => get_edit_post_link($post->ID),
                    'jsonld_status' => $jsonld_status
                );
            }
            
            wp_send_json_success($data);
        }
    }
    
    public function ajax_save_jsonld_post() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $jsonld = sanitize_textarea_field($_POST['jsonld']);
        
        // Validate JSON
        $decoded = json_decode($jsonld, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON: ' . json_last_error_msg());
        }
        
        update_post_meta($post_id, '_ac_jsonld', $jsonld);
        
        wp_send_json_success(array('message' => 'JSON-LD saved successfully'));
    }
    
    public function ajax_validate_jsonld() {
        check_ajax_referer('ac_bulk_meta_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $jsonld = sanitize_textarea_field($_POST['jsonld']);
        $decoded = json_decode($jsonld, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            wp_send_json_success(array('valid' => true));
        } else {
            wp_send_json_success(array('valid' => false, 'error' => json_last_error_msg()));
        }
    }
    
    private function generate_jsonld_for_post($post, $org_settings) {
        $post_url = get_permalink($post->ID);
        $post_title = get_the_title($post->ID);
        $post_excerpt = get_the_excerpt($post->ID);
        $post_date = get_the_date('c', $post->ID);
        $post_modified = get_the_modified_date('c', $post->ID);
        $author = get_the_author_meta('display_name', $post->post_author);
        
        // Get featured image
        $featured_image = get_the_post_thumbnail_url($post->ID, 'full');
        
        // Determine schema type based on post type
        $schema_type = 'Article';
        if ($post->post_type === 'page') {
            $schema_type = 'WebPage';
        }
        
        $jsonld = array(
            '@context' => 'https://schema.org',
            '@type' => $schema_type,
            'headline' => $post_title,
            'url' => $post_url,
            'datePublished' => $post_date,
            'dateModified' => $post_modified,
            'author' => array(
                '@type' => 'Person',
                'name' => $author
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => $org_settings['org_name'] ?? get_bloginfo('name'),
                'url' => $org_settings['org_url'] ?? home_url()
            )
        );
        
        // Add description if available
        if (!empty($post_excerpt)) {
            $jsonld['description'] = $post_excerpt;
        }
        
        // Add image if available
        if ($featured_image) {
            $jsonld['image'] = array(
                '@type' => 'ImageObject',
                'url' => $featured_image
            );
        }
        
        // Add organization logo if available
        if (!empty($org_settings['org_logo'])) {
            $jsonld['publisher']['logo'] = array(
                '@type' => 'ImageObject',
                'url' => $org_settings['org_logo']
            );
        }
        
        // Add organization details if available
        if (!empty($org_settings['org_description'])) {
            $jsonld['publisher']['description'] = $org_settings['org_description'];
        }
        
        // Add social media profiles
        $same_as = array();
        if (!empty($org_settings['org_facebook'])) {
            $same_as[] = $org_settings['org_facebook'];
        }
        if (!empty($org_settings['org_twitter'])) {
            $same_as[] = $org_settings['org_twitter'];
        }
        if (!empty($org_settings['org_linkedin'])) {
            $same_as[] = $org_settings['org_linkedin'];
        }
        if (!empty($same_as)) {
            $jsonld['publisher']['sameAs'] = $same_as;
        }
        
        // Add contact information if available
        if (!empty($org_settings['org_phone']) || !empty($org_settings['org_email']) || !empty($org_settings['org_address'])) {
            $contact_point = array('@type' => 'ContactPoint');
            if (!empty($org_settings['org_phone'])) {
                $contact_point['telephone'] = $org_settings['org_phone'];
            }
            if (!empty($org_settings['org_email'])) {
                $contact_point['email'] = $org_settings['org_email'];
            }
            $jsonld['publisher']['contactPoint'] = $contact_point;
        }
        
        return json_encode($jsonld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    public function output_jsonld() {
        if ( defined( 'AMPLIFI_SCHEMA_ACTIVE' ) ) { return; }
        // Only output on singular posts/pages
        if (!is_singular()) {
            return;
        }
        
        global $post;
        if (!$post) {
            return;
        }
        
        // Get the JSON-LD for this post
        $jsonld = get_post_meta($post->ID, '_ac_jsonld', true);
        
        // Output the JSON-LD in the head if it exists
        if (!empty($jsonld)) {
        // Validate JSON before outputting
        $decoded = json_decode($jsonld, true);
            if (json_last_error() === JSON_ERROR_NONE) {
        echo "\n<!-- AC Bulk Meta Editor JSON-LD -->\n";
        echo '<script type="application/ld+json">' . "\n";
        echo $jsonld . "\n";
        echo '</script>' . "\n";
        echo "<!-- End JSON-LD -->\n\n";
            }
        }
        
        // Always output FAQPage schema if FAQs are deployed (independent of other JSON-LD)
        $faqs_deployed = get_post_meta($post->ID, '_ac_faqs_deployed', true);
        if ($faqs_deployed) {
            $faqs = $this->get_faqs_for_post($post->ID);
            if (!empty($faqs)) {
                $faq_schema = $this->generate_faqpage_schema($faqs, $post);
                if ($faq_schema) {
                    echo "<!-- AC Bulk Meta Editor FAQPage Schema -->\n";
                    echo '<script type="application/ld+json">' . "\n";
                    echo $faq_schema . "\n";
                    echo '</script>' . "\n";
                    echo "<!-- End FAQPage Schema -->\n\n";
                }
            }
        }
    }
    
    private function generate_faqpage_schema($faqs, $post) {
        if (empty($faqs)) {
            return false;
        }
        
        $faq_items = array();
        foreach ($faqs as $faq) {
            $faq_items[] = array(
                '@type' => 'Question',
                'name' => wp_strip_all_tags($faq->question),
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => wp_strip_all_tags($faq->answer)
                )
            );
        }
        
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $faq_items
        );
        
        return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    private function scope_css_to_wrapper($css) {
        // Remove comments first
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);
        
        // Split by closing braces to get individual rules
        $rules = preg_split('/\}\s*/', $css);
        $scoped_rules = array();
        
        foreach ($rules as $rule) {
            $rule = trim($rule);
            if (empty($rule)) {
                continue;
            }
            
            // Split selector and properties
            $parts = explode('{', $rule, 2);
            if (count($parts) !== 2) {
                continue;
            }
            
            $selectors = trim($parts[0]);
            $properties = trim($parts[1]);
            
            if (empty($selectors) || empty($properties)) {
                continue;
            }
            
            // Handle multiple selectors separated by commas
            $selector_list = explode(',', $selectors);
            $scoped_selectors = array();
            
            foreach ($selector_list as $selector) {
                $selector = trim($selector);
                if (empty($selector)) {
                    continue;
                }
                
                // If selector already starts with .ac-faq-wrapper, don't duplicate
                if (strpos($selector, '.ac-faq-wrapper') === 0) {
                    $scoped_selectors[] = $selector;
                } else {
                    // Scope to wrapper
                    $scoped_selectors[] = '.ac-faq-wrapper ' . $selector;
                }
            }
            
            if (!empty($scoped_selectors)) {
                $scoped_rules[] = implode(', ', $scoped_selectors) . ' { ' . $properties . ' }';
            }
        }
        
        return implode("\n", $scoped_rules);
    }
    
    public function output_faq_deploy_head() {
        // Only output on singular posts/pages with deployed FAQs
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $faqs_deployed = get_post_meta($post->ID, '_ac_faqs_deployed', true);
        if (!$faqs_deployed) {
            return;
        }

        $faqs = $this->get_faqs_for_post($post->ID);
        if (empty($faqs)) {
            return;
        }

        // Output FAQ styles matching the blog page FAQ layout
        echo "\n<!-- AC Bulk Meta Editor FAQ Deploy Styles -->\n";
        echo '<style id="ac-faq-deploy-styles">' . "\n";
        echo '
.ac-faq-section {
    padding: 75px 20px;
}
.faqs[data-ac-deployed] {
    background-color: #EFEFE8;
    padding: 100px;
    max-width: 1200px;
    margin: 0 auto;
    box-sizing: border-box;
    counter-reset: faq-counter;
}
.faqs[data-ac-deployed] h2:before {
    content: "FAQ";
    display: block!important;
    font-size: 22px;
    font-family: "Poppins";
    letter-spacing: 20px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 25px;
}
.faqs[data-ac-deployed] h2 {
    font-family: "DM Serif Display";
    margin-bottom: 20px;
    font-size: 48px;
}
.faqs[data-ac-deployed] h2:after {
    content: "";
    display: block;
    width: 83px;
    height: 1px;
    background-color: black;
    margin-top: 25px;
    margin-bottom: 25px;
    opacity: .2;
}
.faq-layout {
    display: flex;
    gap: 50px;
    align-items: flex-start;
}
.faq-left {
    flex: 0 0 45%;
}
/* Question items */
.faqs[data-ac-deployed] .faq-q {
    counter-increment: faq-counter;
    position: relative;
    padding-left: 2em;
    padding-right: 2em;
    margin-bottom: 20px;
    cursor: pointer;
    transition: opacity 0.35s ease, transform 0.3s ease;
}
.faqs[data-ac-deployed] .faq-q::before {
    content: counter(faq-counter) ".";
    position: absolute;
    left: -5px;
    font-family: "DM Serif Display";
    font-size: 26px;
    line-height: 1.5em;
    transition: opacity 0.35s ease;
}
.faqs[data-ac-deployed] .faq-q h3 {
    font-family: "DM Serif Display";
    font-size: 26px!important;
    line-height: 1.5em;
    margin: 0;
    transition: opacity 0.35s ease;
}
/* Dimmed / active / hover states */
.faqs[data-ac-deployed] .faq-q:not(.faq-active) {
    opacity: 0.35;
}
.faqs[data-ac-deployed] .faq-q.faq-active {
    opacity: 1;
    transform: translateX(4px);
}
.faqs[data-ac-deployed] .faq-q:hover {
    opacity: 0.75;
}
.faqs[data-ac-deployed] .faq-q.faq-active:hover {
    opacity: 1;
}
/* Caret arrow pointing toward the answer panel */
.faq-questions-primary .faq-q::after {
    content: "";
    position: absolute;
    right: 0;
    top: 50%;
    width: 0;
    height: 0;
    border-top: 7px solid transparent;
    border-bottom: 7px solid transparent;
    border-left: 10px solid #000;
    opacity: 0;
    transform: translateY(-50%) translateX(-8px);
    transition: opacity 0.35s ease, transform 0.35s ease;
}
.faq-questions-primary .faq-q.faq-active::after {
    opacity: 0.6;
    transform: translateY(-50%) translateX(0);
}
/* Caret for overflow questions points up toward the answer */
.faq-questions-overflow .faq-q::after {
    content: "";
    position: absolute;
    right: 10px;
    top: 5px;
    width: 0;
    height: 0;
    border-left: 6px solid transparent;
    border-right: 6px solid transparent;
    border-bottom: 8px solid #000;
    opacity: 0;
    transform: translateY(4px);
    transition: opacity 0.35s ease, transform 0.35s ease;
}
.faq-questions-overflow .faq-q.faq-active::after {
    opacity: 0.5;
    transform: translateY(0);
}
/* Overflow grid */
.faq-questions-overflow {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px 30px;
    margin-top: 30px;
    padding-top: 30px;
    border-top: 1px solid rgba(0,0,0,0.1);
}
.faq-questions-overflow .faq-q {
    padding-left: 1.8em;
}
.faq-questions-overflow .faq-q::before {
    font-size: 20px;
}
.faq-questions-overflow .faq-q h3 {
    font-size: 20px!important;
}
/* CTA */
.faq-cta {
    margin-top: 50px;
    padding-top: 30px;
    border-top: 1px solid rgba(0,0,0,0.1);
    text-align: center;
}
.faq-cta p {
    margin: 0;
}
.faq-cta a {
    display: inline-block;
    font-family: "Poppins", sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: #000;
    text-decoration: none;
    border: 1px solid #000;
    padding: 14px 35px;
    background: transparent;
    transition: background 0.3s ease, color 0.3s ease;
}
.faq-cta a:hover {
    background: #000;
    color: #fff;
}
/* Answer panel — stacked absolute so no layout shift */
.faq-answers {
    flex: 1;
    position: sticky;
    top: 100px;
    border-left: 2px solid rgba(0,0,0,0.08);
    padding-left: 40px;
    min-height: 200px;
}
.faq-answers-inner {
    position: relative;
}
.faq-a {
    font-size: 18px;
    line-height: 2em;
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    opacity: 0;
    transform: translateY(10px);
    transition: opacity 0.4s ease, transform 0.4s ease;
    pointer-events: none;
    visibility: hidden;
}
.faq-a.faq-active {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
    position: relative;
    visibility: visible;
}
.faq-a p {
    margin: 0 0 15px 0;
}
.faq-a p:last-child {
    margin-bottom: 0;
}

/* ===== Mobile (tablet & below) ===== */
@media screen and (max-width: 990px) {
    .ac-faq-section {
        padding: 40px 0;
    }
    .faqs[data-ac-deployed] {
        padding: 40px 20px;
    }
    .faqs[data-ac-deployed] h2:before {
        font-size: 16px;
        letter-spacing: 12px;
        margin-bottom: 15px;
    }
    .faqs[data-ac-deployed] h2 {
        font-size: 32px;
        margin-bottom: 15px;
    }
    .faqs[data-ac-deployed] h2:after {
        margin-top: 15px;
        margin-bottom: 20px;
    }
    /* Stack layout vertically */
    .faq-layout {
        flex-direction: column;
        gap: 0;
    }
    .faq-left {
        flex: none;
        width: 100%;
    }
    /* Answer panel below questions on mobile */
    .faq-answers {
        position: static;
        border-left: none;
        padding-left: 0;
        border-top: 1px solid rgba(0,0,0,0.1);
        padding-top: 25px;
        margin-top: 25px;
        min-height: 120px;
    }
    /* Smaller questions on mobile */
    .faqs[data-ac-deployed] .faq-q {
        margin-bottom: 14px;
        padding-left: 1.6em;
        padding-right: 0;
    }
    .faqs[data-ac-deployed] .faq-q h3 {
        font-size: 18px!important;
        line-height: 1.4em;
    }
    .faqs[data-ac-deployed] .faq-q::before {
        font-size: 18px;
        line-height: 1.4em;
    }
    .faqs[data-ac-deployed] .faq-q.faq-active {
        transform: translateX(2px);
    }
    /* Hide carets on mobile */
    .faq-questions-primary .faq-q::after,
    .faq-questions-overflow .faq-q::after {
        display: none;
    }
    /* Overflow grid single column on mobile */
    .faq-questions-overflow {
        grid-template-columns: 1fr;
        gap: 10px;
        margin-top: 20px;
        padding-top: 20px;
    }
    .faq-questions-overflow .faq-q h3 {
        font-size: 16px!important;
    }
    .faq-questions-overflow .faq-q::before {
        font-size: 16px;
    }
    /* Answer text smaller on mobile */
    .faq-a {
        font-size: 16px;
        line-height: 1.8em;
    }
}

/* ===== Small phones ===== */
@media screen and (max-width: 480px) {
    .faqs[data-ac-deployed] {
        padding: 30px 16px;
    }
    .faqs[data-ac-deployed] h2:before {
        font-size: 14px;
        letter-spacing: 8px;
    }
    .faqs[data-ac-deployed] h2 {
        font-size: 26px;
    }
    .faqs[data-ac-deployed] .faq-q h3 {
        font-size: 16px!important;
    }
    .faqs[data-ac-deployed] .faq-q::before {
        font-size: 16px;
    }
}' . "\n";
        echo '</style>' . "\n";
        echo "<!-- End FAQ Deploy Styles -->\n\n";
    }
    
    public function output_faqs_before_footer() {
        // Only output on singular posts/pages with deployed FAQs
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $faqs_deployed = get_post_meta($post->ID, '_ac_faqs_deployed', true);
        if (!$faqs_deployed) {
            return;
        }

        $faqs = $this->get_faqs_for_post($post->ID);
        if (empty($faqs)) {
            return;
        }

        // Get heading from global deploy settings
        $global_settings = get_option('ac_faq_deploy_global', array(
            'header' => 'Frequently Asked Questions',
        ));
        $header = $global_settings['header'] ?? 'Frequently Asked Questions';

        // Build FAQ HTML — first 3 questions beside answer, overflow in 2-col grid below
        $faq_html = '<div class="ac-faq-section">';
        $faq_html .= '<div class="faqs" data-ac-deployed="true">';
        $faq_html .= '<h2>' . esc_html($header) . '</h2>';
        $faq_html .= '<div class="faq-layout">';

        // Left column: questions split into primary + overflow
        $faq_html .= '<div class="faq-left">';

        // Primary questions (first 3)
        $faq_html .= '<div class="faq-questions-primary">';
        $faq_data = [];
        $i = 0;
        foreach ($faqs as $faq) {
            if ($i >= 3) break;
            $active = ($i === 0) ? ' faq-active' : '';
            $faq_html .= '<div class="faq-q' . $active . '" data-faq="' . $i . '">';
            $faq_html .= '<h3>' . esc_html($faq->question) . '</h3>';
            $faq_html .= '</div>';
            $i++;
        }
        $faq_html .= '</div>'; // .faq-questions-primary

        $faq_html .= '</div>'; // .faq-left

        // Right column: answers (all of them, stacked via absolute positioning)
        $faq_html .= '<div class="faq-answers"><div class="faq-answers-inner">';
        $i = 0;
        foreach ($faqs as $faq) {
            $active = ($i === 0) ? ' faq-active' : '';
            $faq_html .= '<div class="faq-a' . $active . '" data-faq="' . $i . '">';
            $faq_html .= wpautop($faq->answer);
            $faq_html .= '</div>';

            $faq_data[] = [
                "@type" => "Question",
                "name" => $faq->question,
                "acceptedAnswer" => [
                    "@type" => "Answer",
                    "text" => wp_strip_all_tags($faq->answer),
                ],
            ];
            $i++;
        }
        $faq_html .= '</div></div>'; // .faq-answers-inner + .faq-answers

        $faq_html .= '</div>'; // .faq-layout

        // Overflow questions (4+) full-width in 2-column grid
        if (count($faqs) > 3) {
            $faq_html .= '<div class="faq-questions-overflow">';
            $i = 0;
            foreach ($faqs as $faq) {
                if ($i < 3) { $i++; continue; }
                $faq_html .= '<div class="faq-q" data-faq="' . $i . '">';
                $faq_html .= '<h3>' . esc_html($faq->question) . '</h3>';
                $faq_html .= '</div>';
                $i++;
            }
            $faq_html .= '</div>'; // .faq-questions-overflow
        }

        $faq_html .= '<div class="faq-cta"><a href="/contact/">Still have questions? Reach us here.</a></div>';
        $faq_html .= '</div>'; // .faqs
        $faq_html .= '</div>'; // .ac-faq-section

        // Output JSON-LD structured data
        $jsonld = json_encode([
            "@context" => "https://schema.org",
            "@type" => "FAQPage",
            "mainEntity" => $faq_data
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $faq_html_encoded = json_encode($faq_html);

        // Output the FAQ section via JS, inserted right before the footer
        echo "\n<!-- AC Bulk Meta Editor FAQ Deploy -->\n";
        echo '<script type="application/ld+json">' . $jsonld . '</script>' . "\n";
        echo '<script type="text/javascript">' . "\n";
        echo "(function() {\n";
        echo "  var faqHTML = {$faq_html_encoded};\n";
        echo "  function insertFAQs() {\n";
        echo "    if (document.querySelector('.faqs[data-ac-deployed]')) return;\n";
        echo "    var footer = document.querySelector('.elementor-location-footer')\n";
        echo "      || document.querySelector('footer')\n";
        echo "      || document.querySelector('.site-footer')\n";
        echo "      || document.querySelector('#footer')\n";
        echo "      || document.querySelector('.footer');\n";
        echo "    if (!footer) return;\n";
        echo "    var tempDiv = document.createElement('div');\n";
        echo "    tempDiv.innerHTML = faqHTML;\n";
        echo "    var faqElement = tempDiv.firstElementChild;\n";
        echo "    footer.parentNode.insertBefore(faqElement, footer);\n";
        echo "    initFaqClicks();\n";
        echo "  }\n";
        echo "  function initFaqClicks() {\n";
        echo "    var wrapper = document.querySelector('.faqs[data-ac-deployed]');\n";
        echo "    if (!wrapper) return;\n";
        echo "    var questions = wrapper.querySelectorAll('.faq-q');\n";
        echo "    var answers = wrapper.querySelectorAll('.faq-a');\n";
        echo "    var answerPanel = wrapper.querySelector('.faq-answers');\n";
        echo "    questions.forEach(function(q) {\n";
        echo "      q.addEventListener('click', function() {\n";
        echo "        var idx = this.getAttribute('data-faq');\n";
        echo "        questions.forEach(function(el) { el.classList.remove('faq-active'); });\n";
        echo "        answers.forEach(function(el) { el.classList.remove('faq-active'); });\n";
        echo "        this.classList.add('faq-active');\n";
        echo "        var answer = wrapper.querySelector('.faq-a[data-faq=\"' + idx + '\"]');\n";
        echo "        if (answer) answer.classList.add('faq-active');\n";
        echo "        if (this.closest('.faq-questions-overflow') && answerPanel) {\n";
        echo "          answerPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });\n";
        echo "        }\n";
        echo "      });\n";
        echo "    });\n";
        echo "  }\n";
        echo "  if (document.readyState === 'loading') {\n";
        echo "    document.addEventListener('DOMContentLoaded', insertFAQs);\n";
        echo "  } else {\n";
        echo "    insertFAQs();\n";
        echo "  }\n";
        echo "  setTimeout(insertFAQs, 500);\n";
        echo "  setTimeout(insertFAQs, 1500);\n";
        echo "  setTimeout(insertFAQs, 3000);\n";
        echo "  window.addEventListener('load', function() { setTimeout(insertFAQs, 1000); });\n";
        echo "})();\n";
        echo '</script>' . "\n";
        echo "<!-- End AC FAQ Deploy -->\n";
    }
    
    private function extract_prioritized_content($html) {
        // Just extract all content without artificial limits
        $page_content = wp_strip_all_tags($html);
        $page_content = preg_replace('/\s+/', ' ', $page_content);
        $page_content = trim($page_content);
        
        // Only limit if we hit actual API limits (OpenAI has ~128k token limit)
        // Roughly 1 token = 4 characters, so 128k tokens = ~500k characters
        $max_chars = 500000; // Very generous limit
        
        if (strlen($page_content) > $max_chars) {
            $page_content = substr($page_content, 0, $max_chars) . '...';
        }
        
        return $page_content;
    }
    
    
    public function render_admin_page() {
        // Get all public post types
        $post_types = get_post_types(array('public' => true), 'objects');
        $openai_key = get_option('ac_openai_api_key', '');
        $global_prompt = get_option('ac_global_prompt', '');
        $site_title_override = get_option('ac_site_title_override', '');
        $webhook_url = get_option('ac_webhook_url', '');
        $webhook_set_by = get_option('ac_webhook_url_set_by', 0);
        $current_user_id = get_current_user_id();
        $show_webhook_field = empty($webhook_url) || $webhook_set_by === 0 || $webhook_set_by === $current_user_id;
        
        ?>
        <div class="wrap ac-bulk-meta-wrap<?php echo get_user_meta(get_current_user_id(), 'ac_bulk_meta_dark_mode', true) ? ' dark-mode' : ''; ?>">
            <button class="dark-mode-toggle" id="dark-mode-toggle" title="Toggle Dark Mode">
                <span class="dark-mode-toggle-icon"><?php echo get_user_meta(get_current_user_id(), 'ac_bulk_meta_dark_mode', true) ? '☀️' : '🌙'; ?></span>
                <span class="dark-mode-toggle-text"><?php echo get_user_meta(get_current_user_id(), 'ac_bulk_meta_dark_mode', true) ? 'Light Mode' : 'Dark Mode'; ?></span>
            </button>
            
            <h1>amplifi.meta</h1>
            
            <div class="ac-instructions" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 15px 20px; margin: 20px 0; border-radius: 4px;">
                <h2 style="margin-top: 0; font-size: 16px; color: #2271b1;">📝 How This Works</h2>
                <p style="margin-bottom: 10px; line-height: 1.6;"><strong>This tool helps you create better search descriptions for your pages.</strong> When someone searches on Google, they see your page title and a short description. This plugin uses AI to automatically write these for you.</p>
                <p style="margin-bottom: 10px; line-height: 1.6;"><strong>What you can generate:</strong></p>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li><strong>SEO Title:</strong> The title that shows up in Google search results (like "Best Coffee Shops | Your Site Name")</li>
                    <li><strong>Meta Description:</strong> The short text that appears under your title in search results - this helps people decide if they want to click on your page</li>
                    <li><strong>Focus Keyphrase:</strong> The main word or phrase you want your page to rank for in search engines</li>
                </ul>
                <p style="margin-bottom: 0; line-height: 1.6;"><strong>How to use it:</strong> First, enter your OpenAI API key below. Then select a post type (like Pages or Posts), pick the pages you want to work on, and click "Generate" for whatever you need. You can edit any generated text before saving it.</p>
            </div>
            
            <div class="ac-site-title-settings">
                <h2>Title Tag Settings</h2>
                <div class="site-title-wrapper">
                    <label for="site-title-override">Site Title Override (for title tags):</label>
                    <input type="text" id="site-title-override" value="<?php echo esc_attr($site_title_override); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>" />
                    <button id="save-site-title-override" class="button button-primary">Save</button>
                    <span class="site-title-status"><?php echo $site_title_override ? '✓ Using override: ' . esc_html($site_title_override) : 'Using default: ' . esc_html(get_bloginfo('name')); ?></span>
                </div>
                <p class="description">Override the site name used in generated title tags. If left empty, will use your WordPress site name. Title tags will be formatted as "GENERATED TITLE | SITE NAME" with a maximum total length of 65 characters.</p>
            </div>
            
            <div class="ac-openai-settings">
                <h2>OpenAI Settings</h2>
                <div class="openai-key-wrapper">
                    <label for="openai-api-key">OpenAI API Key:</label>
                    <input type="password" id="openai-api-key" value="<?php echo esc_attr($openai_key); ?>" placeholder="sk-..." />
                    <button id="save-openai-key" class="button button-primary">Save API Key</button>
                    <span class="api-key-status"><?php echo $openai_key ? '✓ API Key Set' : 'No API key configured'; ?></span>
                </div>
                <p class="description">Enter your OpenAI API key to enable AI-powered meta description generation. <a href="https://platform.openai.com/api-keys" target="_blank">Get your API key here</a>.</p>
            </div>
            
            <?php if ($show_webhook_field): ?>
            <div class="ac-openai-settings">
                <h2>Webhook Settings (Optional)</h2>
                <div class="openai-key-wrapper">
                    <label for="webhook-url">Webhook URL:</label>
                    <input type="url" id="webhook-url" value="<?php echo esc_attr($webhook_url); ?>" placeholder="https://your-webhook-url.com/endpoint" style="min-width: 400px;" />
                    <button id="save-webhook-url" class="button button-primary">Save Webhook URL</button>
                    <span class="webhook-status"><?php echo $webhook_url ? '✓ Webhook URL Set' : 'No webhook configured'; ?></span>
                </div>
                <p class="description">Optional: Send AI generation logs to a webhook URL. All logged data will be sent as JSON POST requests. Once set, only you will be able to change or disable it.</p>
            </div>
            <?php endif; ?>
            
            <div class="ac-global-prompt-settings">
                <h2>AI Writing Style</h2>
                <div class="global-prompt-wrapper">
                    <label for="global-prompt">Custom Writing Instructions:</label>
                    <textarea id="global-prompt" rows="4" placeholder="Enter custom instructions for how the AI should write meta descriptions and title tags. This will be added to every generation request."><?php echo esc_textarea($global_prompt); ?></textarea>
                    <button id="save-global-prompt" class="button button-primary">Save Writing Style</button>
                    <span class="global-prompt-status"><?php echo $global_prompt ? '✓ Custom style saved' : 'Using default professional style'; ?></span>
                </div>
                <p class="description">Customize how the AI writes meta descriptions and title tags. Leave empty to use the default professional style (no exclamation points, serious tone, authoritative voice).</p>
            </div>
            
            <div class="ac-bulk-meta-controls">
                <div class="filter-controls">
                    <label for="post-type-select"><strong>Post Type:</strong></label>
                    <select id="post-type-select">
                        <?php foreach ($post_types as $post_type): ?>
                            <option value="<?php echo esc_attr($post_type->name); ?>" <?php selected($post_type->name, 'page'); ?>>
                                <?php echo esc_html($post_type->labels->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-controls">
                    <label for="filter-pages">Filter:</label>
                    <select id="filter-pages">
                        <option value="all">All Items</option>
                        <option value="missing">Missing Metadata</option>
                        <option value="complete">Complete Metadata</option>
                    </select>
                </div>
                
                <div class="sort-controls">
                    <label for="sort-by">Sort By:</label>
                    <select id="sort-by">
                        <option value="title">Title (A-Z)</option>
                        <option value="ID">Post ID</option>
                        <option value="yoast_title">SEO Title</option>
                        <option value="yoast_desc">Meta Description</option>
                        <option value="yoast_focus">Focus Keyword</option>
                    </select>
                    
                    <select id="sort-order">
                        <option value="ASC">Ascending</option>
                        <option value="DESC">Descending</option>
                    </select>
                </div>
                
                <button id="refresh-data" class="button button-secondary">Refresh</button>
            </div>
            
            <div class="bulk-generate-controls">
                <h3>Bulk AI Generation</h3>
                <div class="bulk-controls-wrapper">
                    <div style="margin-bottom: 15px;">
                        <button id="bulk-generate-titles" class="button button-primary" style="margin-right: 10px;">Generate All Missing Titles</button>
                        <button id="bulk-generate-descriptions" class="button button-primary" style="margin-right: 10px;">Generate All Missing Descriptions</button>
                        <button id="bulk-generate-focus-keyphrases" class="button button-primary" style="margin-right: 10px;">Generate All Missing Focus Keyphrases</button>
                    <button id="bulk-generate-stop" class="button button-secondary" style="display: none;">Stop Generation</button>
                    </div>
                    <div id="bulk-generate-progress" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <div class="progress-stats">
                            <span id="progress-text">Processing...</span>
                            <span id="progress-counts">0/0 (0 success, 0 errors)</span>
                        </div>
                        <div id="current-processing">Currently processing: <strong id="current-post"></strong></div>
                    </div>
                </div>
                <p class="description">Generate SEO titles, meta descriptions, or focus keyphrases for all posts that are missing them. Targeted keywords are optional and only used for AI guidance (not focus keywords). Processing happens one post at a time with 2-second delays.</p>
            </div>
            
            <div class="bulk-selected-actions" id="bulk-selected-actions">
                <strong><span id="selected-count">0</span> selected</strong>
                <button id="bulk-generate-selected-titles" class="button button-primary">Generate Titles for Selected</button>
                <button id="bulk-generate-selected-descriptions" class="button button-primary">Generate Descriptions for Selected</button>
                <button id="bulk-generate-selected-focus" class="button button-primary">Generate Focus Keyphrases for Selected</button>
                <button id="bulk-clear-selection" class="button button-secondary">Clear Selection</button>
            </div>
            
            <div id="loading-spinner" class="spinner" style="float: none; margin: 20px auto; display: none;"></div>
            
            <div id="pages-table-container">
                <table class="wp-list-table widefat fixed striped" id="pages-meta-table">
                    <thead>
                        <tr>
                            <th class="column-cb check-column"><input type="checkbox" id="cb-select-all" /></th>
                            <th class="column-id">ID</th>
                            <th class="column-title">Title</th>
                            <th class="column-status">Status</th>
                            <th class="column-targeted-keywords">Targeted Keywords (Optional)</th>
                            <th class="column-yoast-title">SEO Title</th>
                            <th class="column-yoast-desc">Meta Description</th>
                            <th class="column-yoast-focus">Focus Keyword</th>
                            <th class="column-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="pages-tbody">
                        <tr>
                            <td colspan="9" class="no-data">Select a post type to load data...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div id="status-message" class="notice" style="display: none; margin-top: 20px;">
                <p></p>
            </div>
            
            <div class="ac-ai-logs-section">
                <h2>AI Generation Log</h2>
                <div class="logs-controls">
                    <button id="load-ai-logs" class="button button-secondary">Load AI Generation Log</button>
                </div>
                <div id="ai-logs-container" style="display: none;">
                    <table class="wp-list-table widefat fixed striped" id="ai-logs-table">
                        <thead>
                            <tr>
                                <th class="column-timestamp">Date/Time</th>
                                <th class="column-user">User</th>
                                <th class="column-post">Post</th>
                                <th class="column-keywords">Keywords</th>
                                <th class="column-description">Generated Content</th>
                            </tr>
                        </thead>
                        <tbody id="ai-logs-tbody">
                            <tr>
                                <td colspan="5" class="no-data">Click "Load AI Generation Log" to view logs</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
        <?php
    }
    
    public function render_faq_page() {
        // Get all public post types
        $post_types = get_post_types(array('public' => true), 'objects');
        $openai_key = get_option('ac_openai_api_key', '');
        $faq_focus = get_option('ac_faq_focus', '');
        $faq_count = get_option('ac_faq_count', 5); // Default 5, max 15
        $webhook_url = get_option('ac_webhook_url', '');
        $webhook_set_by = get_option('ac_webhook_url_set_by', 0);
        $current_user_id = get_current_user_id();
        $show_webhook_field = empty($webhook_url) || $webhook_set_by === 0 || $webhook_set_by === $current_user_id;
        $global_deploy_settings = get_option('ac_faq_deploy_global', array(
            'mode' => 'accordion',
            'heading_color' => '#000000',
            'answer_color' => '#333333',
            'selector' => '',
            'header' => 'Frequently Asked Questions',
            'container_class' => '.container'
        ));
        
        ?>
        <div class="wrap ac-faq-wrap">
            <h1>amplifi.meta &mdash; FAQ Generation</h1>
            
            <div class="ac-instructions" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 15px 20px; margin: 20px 0; border-radius: 4px;">
                <h2 style="margin-top: 0; font-size: 16px; color: #2271b1;">❓ How This Works</h2>
                <p style="margin-bottom: 10px; line-height: 1.6;"><strong>This tool automatically creates questions and answers (FAQs) for your pages.</strong> FAQs help answer common questions visitors might have, which can improve your search rankings and help people find what they need faster.</p>
                <p style="margin-bottom: 10px; line-height: 1.6;"><strong>How it works:</strong></p>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li>The AI reads your page content and generates realistic questions that real people would actually search for</li>
                    <li>You can optionally add focus topics to guide what types of questions to generate (like "pricing" or "how to get started")</li>
                    <li>Once generated, you can edit any question or answer, add new ones manually, or delete ones you don't want</li>
                    <li>When you're happy with your FAQs, click "Deploy" to add them to your page so visitors can see them</li>
                </ul>
                <p style="margin-bottom: 0; line-height: 1.6;"><strong>Tip:</strong> FAQs can be displayed as an accordion (click to expand) or as an expanded list, depending on your preference. You can customize colors and styling in the Global Deploy Settings below.</p>
            </div>
            
            <div class="ac-faq-focus-settings">
                <h2>FAQ Generation Focus</h2>
                <div class="faq-focus-wrapper">
                    <label for="faq-focus">FAQ Focus Topics (Optional):</label>
                    <textarea id="faq-focus" rows="4" placeholder="Enter specific topics, themes, or areas you'd like FAQs to focus on. For example: 'Questions about pricing, implementation timeline, integration requirements, support options'"><?php echo esc_textarea($faq_focus); ?></textarea>
                    <button id="save-faq-focus" class="button button-primary">Save FAQ Focus</button>
                    <span class="faq-focus-status"><?php echo $faq_focus ? '✓ FAQ focus saved' : 'No specific focus set'; ?></span>
                </div>
                <p class="description">Optional: Specify topics or themes you want FAQs to focus on. This will help guide the AI to generate questions that people actually ask about these specific areas. Leave empty to generate FAQs based on the page content alone.</p>
            </div>
            
            <div class="ac-faq-generation-settings">
                <h2>FAQ Generation Settings</h2>
                <div class="faq-generation-wrapper">
                    <label for="faq-count">Number of FAQs to Generate:</label>
                    <input type="number" id="faq-count" min="1" max="15" value="<?php echo esc_attr($faq_count); ?>" style="width: 100px; padding: 8px;" />
                    <button id="save-faq-count" class="button button-primary">Save</button>
                    <span class="faq-count-status"><?php echo $faq_count ? '✓ Using ' . esc_html($faq_count) . ' FAQs per generation' : 'Using default: 5 FAQs'; ?></span>
                </div>
                <p class="description">Set the number of FAQs to generate per post (default: 5, maximum: 15).</p>
            </div>
            
            <div class="ac-faq-deploy-global-settings">
                <h2>Global Deploy Settings</h2>
                <div class="deploy-global-wrapper">
                    <div style="margin-bottom: 15px;">
                        <label for="faq-deploy-global-header">Section Header:</label>
                        <input type="text" id="faq-deploy-global-header" value="<?php echo esc_attr($global_deploy_settings['header'] ?? 'Frequently Asked Questions'); ?>" placeholder="Frequently Asked Questions" style="width: 100%; padding: 8px;">
                        <p class="description" style="margin-top: 5px; font-size: 12px; color: #666;">Header text for FAQ sections.</p>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="faq-deploy-global-container-class">Container Class Selector:</label>
                        <input type="text" id="faq-deploy-global-container-class" value="<?php echo esc_attr($global_deploy_settings['container_class'] ?? '.container'); ?>" placeholder=".container" style="width: 100%; padding: 8px;">
                        <p class="description" style="margin-top: 5px; font-size: 12px; color: #666;">CSS selector to match the inner width of your content. Default: .container</p>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="faq-deploy-global-selector">CSS Selector (where to inject FAQs):</label>
                        <input type="text" id="faq-deploy-global-selector" value="<?php echo esc_attr($global_deploy_settings['selector'] ?? ''); ?>" placeholder="e.g., .entry-content, #main-content, article" style="width: 100%; padding: 8px;">
                        <p class="description" style="margin-top: 5px; font-size: 12px; color: #666;">CSS selector for all posts where FAQs will be injected.</p>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="faq-deploy-global-mode">Display Mode:</label>
                        <select id="faq-deploy-global-mode" style="width: 200px; padding: 8px;">
                            <option value="accordion" <?php selected($global_deploy_settings['mode'] ?? 'accordion', 'accordion'); ?>>Accordion</option>
                            <option value="expanded" <?php selected($global_deploy_settings['mode'] ?? 'accordion', 'expanded'); ?>>Expanded</option>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <h3 style="margin-bottom: 10px; font-size: 14px;">Typography & Colors</h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                            <div>
                                <label for="faq-deploy-global-header-color">Section Header Color:</label>
                                <div style="display: flex; gap: 5px;">
                                    <input type="color" id="faq-deploy-global-header-color-picker" value="<?php echo esc_attr($global_deploy_settings['header_color'] ?? '#000000'); ?>" style="width: 60px; height: 38px;">
                                    <input type="text" id="faq-deploy-global-header-color" value="<?php echo esc_attr($global_deploy_settings['header_color'] ?? '#000000'); ?>" placeholder="#000000" style="flex: 1; padding: 8px;">
                                </div>
                            </div>
                            
                            <div>
                                <label for="faq-deploy-global-header-font-weight">Section Header Font Weight:</label>
                                <select id="faq-deploy-global-header-font-weight" style="width: 100%; padding: 8px;">
                                    <option value="300" <?php selected($global_deploy_settings['header_font_weight'] ?? '600', '300'); ?>>300 - Light</option>
                                    <option value="400" <?php selected($global_deploy_settings['header_font_weight'] ?? '600', '400'); ?>>400 - Normal</option>
                                    <option value="500" <?php selected($global_deploy_settings['header_font_weight'] ?? '600', '500'); ?>>500 - Medium</option>
                                    <option value="600" <?php selected($global_deploy_settings['header_font_weight'] ?? '600', '600'); ?>>600 - Semi-bold</option>
                                    <option value="700" <?php selected($global_deploy_settings['header_font_weight'] ?? '600', '700'); ?>>700 - Bold</option>
                                    <option value="800" <?php selected($global_deploy_settings['header_font_weight'] ?? '600', '800'); ?>>800 - Extra-bold</option>
                                </select>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                            <div>
                                <label for="faq-deploy-global-heading-color">Question Color:</label>
                                <div style="display: flex; gap: 5px;">
                                    <input type="color" id="faq-deploy-global-heading-color-picker" value="<?php echo esc_attr($global_deploy_settings['heading_color'] ?? '#000000'); ?>" style="width: 60px; height: 38px;">
                                    <input type="text" id="faq-deploy-global-heading-color" value="<?php echo esc_attr($global_deploy_settings['heading_color'] ?? '#000000'); ?>" placeholder="#000000" style="flex: 1; padding: 8px;">
                                </div>
                            </div>
                            
                            <div>
                                <label for="faq-deploy-global-heading-font-weight">Question Font Weight:</label>
                                <select id="faq-deploy-global-heading-font-weight" style="width: 100%; padding: 8px;">
                                    <option value="300" <?php selected($global_deploy_settings['heading_font_weight'] ?? '600', '300'); ?>>300 - Light</option>
                                    <option value="400" <?php selected($global_deploy_settings['heading_font_weight'] ?? '600', '400'); ?>>400 - Normal</option>
                                    <option value="500" <?php selected($global_deploy_settings['heading_font_weight'] ?? '600', '500'); ?>>500 - Medium</option>
                                    <option value="600" <?php selected($global_deploy_settings['heading_font_weight'] ?? '600', '600'); ?>>600 - Semi-bold</option>
                                    <option value="700" <?php selected($global_deploy_settings['heading_font_weight'] ?? '600', '700'); ?>>700 - Bold</option>
                                    <option value="800" <?php selected($global_deploy_settings['heading_font_weight'] ?? '600', '800'); ?>>800 - Extra-bold</option>
                                </select>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div>
                                <label for="faq-deploy-global-answer-color">Answer Color:</label>
                                <div style="display: flex; gap: 5px;">
                                    <input type="color" id="faq-deploy-global-answer-color-picker" value="<?php echo esc_attr($global_deploy_settings['answer_color'] ?? '#333333'); ?>" style="width: 60px; height: 38px;">
                                    <input type="text" id="faq-deploy-global-answer-color" value="<?php echo esc_attr($global_deploy_settings['answer_color'] ?? '#333333'); ?>" placeholder="#333333" style="flex: 1; padding: 8px;">
                                </div>
                            </div>
                            
                            <div>
                                <label for="faq-deploy-global-answer-font-weight">Answer Font Weight:</label>
                                <select id="faq-deploy-global-answer-font-weight" style="width: 100%; padding: 8px;">
                                    <option value="300" <?php selected($global_deploy_settings['answer_font_weight'] ?? '400', '300'); ?>>300 - Light</option>
                                    <option value="400" <?php selected($global_deploy_settings['answer_font_weight'] ?? '400', '400'); ?>>400 - Normal</option>
                                    <option value="500" <?php selected($global_deploy_settings['answer_font_weight'] ?? '400', '500'); ?>>500 - Medium</option>
                                    <option value="600" <?php selected($global_deploy_settings['answer_font_weight'] ?? '400', '600'); ?>>600 - Semi-bold</option>
                                    <option value="700" <?php selected($global_deploy_settings['answer_font_weight'] ?? '400', '700'); ?>>700 - Bold</option>
                                    <option value="800" <?php selected($global_deploy_settings['answer_font_weight'] ?? '400', '800'); ?>>800 - Extra-bold</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <h3 style="margin-bottom: 10px; font-size: 14px;">Display Options</h3>
                        
                        <div style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="faq-deploy-global-number-faqs" value="1" <?php checked($global_deploy_settings['number_faqs'] ?? false, true); ?>>
                                <span>Number FAQs (add sequential numbers to questions)</span>
                            </label>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <h3 style="margin-bottom: 10px; font-size: 14px;">Custom CSS (Scoped to FAQ Wrapper)</h3>
                        
                        <label style="display: block; margin-bottom: 8px; font-weight: 600;">CSS Selectors (click to copy template):</label>
                        <div id="faq-selectors-reference" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; padding: 8px; background: #f5f5f5; border-radius: 4px;">
                            <?php
                            $header_color = esc_attr($global_deploy_settings['header_color'] ?? '#000000');
                            $header_font_weight = esc_attr($global_deploy_settings['header_font_weight'] ?? '600');
                            $heading_color = esc_attr($global_deploy_settings['heading_color'] ?? '#000000');
                            $heading_font_weight = esc_attr($global_deploy_settings['heading_font_weight'] ?? '600');
                            $answer_color = esc_attr($global_deploy_settings['answer_color'] ?? '#333333');
                            $answer_font_weight = esc_attr($global_deploy_settings['answer_font_weight'] ?? '400');
                            ?>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-wrapper" data-template=".ac-faq-wrapper {&#10;  margin: 20px 0;&#10;  padding-top: 2.5em;&#10;  padding-bottom: 2.5em;&#10;  /* background, border */&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-wrapper</button>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-section-header" data-template=".ac-faq-section-header {&#10;  margin-bottom: 20px;&#10;  font-size: 24px;&#10;  font-weight: <?php echo $header_font_weight; ?>;&#10;  color: <?php echo $header_color; ?>;&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-section-header</button>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-container" data-template=".ac-faq-container {&#10;  margin: 20px 0;&#10;  /* padding */&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-container</button>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-item" data-template=".ac-faq-item {&#10;  margin-bottom: 15px;&#10;  border: 1px solid #ddd;&#10;  border-radius: 4px;&#10;  overflow: hidden;&#10;  /* padding */&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-item</button>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-question" data-template=".ac-faq-question {&#10;  margin: 0;&#10;  padding: 15px;&#10;  font-size: 18px;&#10;  font-weight: <?php echo $heading_font_weight; ?>;&#10;  color: <?php echo $heading_color; ?>;&#10;  /* background, cursor, display */&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-question</button>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-answer" data-template=".ac-faq-answer {&#10;  margin: 0;&#10;  padding: 0 15px;&#10;  color: <?php echo $answer_color; ?>;&#10;  font-weight: <?php echo $answer_font_weight; ?>;&#10;  /* font-size */&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-answer</button>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-number" data-template=".ac-faq-number {&#10;  font-weight: bold;&#10;  margin-right: 8px;&#10;  /* color */&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-number</button>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-item.active" data-template=".ac-faq-item.active {&#10;  /* styles for active accordion item */&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-item.active</button>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-item.active .ac-faq-question" data-template=".ac-faq-item.active .ac-faq-question {&#10;  /* background, color */&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-item.active .ac-faq-question</button>
                            <button type="button" class="faq-selector-btn" data-selector=".ac-faq-item.active .ac-faq-answer" data-template=".ac-faq-item.active .ac-faq-answer {&#10;  padding: 15px;&#10;  max-height: 1000px;&#10;  /* color, margin */&#10;}" style="padding: 4px 8px; font-size: 11px; font-family: monospace; background: #fff; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">.ac-faq-item.active .ac-faq-answer</button>
                        </div>
                        
                        <label for="faq-deploy-global-wrapper-css">Custom CSS (scoped to .ac-faq-wrapper):</label>
                        <textarea id="faq-deploy-global-wrapper-css" rows="8" style="width: 100%; padding: 8px; font-family: monospace; font-size: 12px;" placeholder="/* CSS scoped to .ac-faq-wrapper only */&#10;.ac-faq-wrapper { }&#10;.ac-faq-wrapper .ac-faq-item { }"><?php echo esc_textarea($global_deploy_settings['wrapper_css'] ?? ''); ?></textarea>
                        <p class="description" style="margin-top: 5px; font-size: 12px; color: #666;">Add custom CSS that will be scoped to the FAQ wrapper. All CSS will be automatically prefixed with .ac-faq-wrapper. Click selectors above to insert templates.</p>
                    </div>
                    
                    <button id="save-faq-deploy-global" class="button button-primary">Save Global Settings</button>
                    <span class="faq-deploy-global-status" style="display: none;"></span>
                </div>
                <p class="description">Set default display mode, colors, header, container class, and CSS selector for all FAQ deployments. Display mode and colors are global-only and cannot be overridden per post.</p>
            </div>
            
            <div class="ac-openai-settings">
                <h2>OpenAI Settings</h2>
                <div class="openai-key-wrapper">
                    <label for="openai-api-key">OpenAI API Key:</label>
                    <input type="password" id="openai-api-key" value="<?php echo esc_attr($openai_key); ?>" placeholder="sk-..." />
                    <button id="save-openai-key" class="button button-primary">Save API Key</button>
                    <span class="api-key-status"><?php echo $openai_key ? '✓ API Key Set' : 'No API key configured'; ?></span>
                </div>
                <p class="description">Enter your OpenAI API key to enable AI-powered FAQ generation. <a href="https://platform.openai.com/api-keys" target="_blank">Get your API key here</a>.</p>
            </div>
            
            <?php if ($show_webhook_field): ?>
            <div class="ac-openai-settings">
                <h2>Webhook Settings (Optional)</h2>
                <div class="openai-key-wrapper">
                    <label for="webhook-url">Webhook URL:</label>
                    <input type="url" id="webhook-url" value="<?php echo esc_attr($webhook_url); ?>" placeholder="https://your-webhook-url.com/endpoint" style="min-width: 400px;" />
                    <button id="save-webhook-url" class="button button-primary">Save Webhook URL</button>
                    <span class="webhook-status"><?php echo $webhook_url ? '✓ Webhook URL Set' : 'No webhook configured'; ?></span>
                </div>
                <p class="description">Optional: Send AI generation logs to a webhook URL. All logged data will be sent as JSON POST requests. Once set, only you will be able to change or disable it.</p>
            </div>
            <?php endif; ?>
            
            <div class="ac-faq-generation-section">
                <h2>FAQ Generation Controls</h2>
                <div class="faq-controls">
                    <div class="faq-controls-row">
                        <label for="faq-post-type-select"><strong>Post Type:</strong></label>
                        <select id="faq-post-type-select">
                            <?php foreach ($post_types as $post_type): ?>
                                <option value="<?php echo esc_attr($post_type->name); ?>" <?php selected($post_type->name, 'page'); ?>>
                                    <?php echo esc_html($post_type->labels->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button id="export-faqs-csv" class="button button-secondary">Export FAQs to CSV</button>
                    </div>
                </div>
                <div id="faq-posts-container" style="display: none;">
                    <table class="wp-list-table widefat fixed striped" id="faq-posts-table">
                        <thead>
                            <tr>
                                <th class="column-id">ID</th>
                                <th class="column-title">Title</th>
                                <th class="column-status">Status</th>
                                <th class="column-faq-count">FAQ Count</th>
                                <th class="column-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="faq-posts-tbody">
                            <tr>
                                <td colspan="5" class="no-data">Click "Load Posts" to view posts</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div id="faq-details-container" style="display: none;">
                    <h3>Generated FAQs</h3>
                    <div id="faq-details-content"></div>
                </div>
            </div>
            
            <div id="status-message" class="notice" style="display: none; margin-top: 20px;">
                <p></p>
            </div>
        </div>
        <?php
    }
    
    public function render_jsonld_page() {
        // Get all public post types
        $post_types = get_post_types(array('public' => true), 'objects');
        $jsonld_settings = get_option('ac_jsonld_settings', array());
        
        ?>
        <div class="wrap ac-jsonld-wrap">
            <?php if ( defined( 'AMPLIFI_SCHEMA_ACTIVE' ) ) : ?>
                <div class="notice notice-info" style="margin-top:20px;"><p><strong>JSON-LD is now managed by <a href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-ac-schema' ) ); ?>">amplifi.schema</a>.</strong> This page remains read-only for reference.</p></div>
            <?php endif; ?>
            <h1>amplifi.meta &mdash; JSON-LD Generator</h1>
            
            <div class="ac-instructions" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 15px 20px; margin: 20px 0; border-radius: 4px;">
                <h2 style="margin-top: 0; font-size: 16px; color: #2271b1;">🏢 How This Works</h2>
                <p style="margin-bottom: 10px; line-height: 1.6;"><strong>This tool helps search engines understand your business better.</strong> JSON-LD is a special code that tells Google and other search engines important information about your organization, like your name, address, phone number, and social media links.</p>
                <p style="margin-bottom: 10px; line-height: 1.6;"><strong>Why it matters:</strong> When search engines understand your business information, they can display it in search results (like showing your phone number or address when someone searches for your business). This can help people find and contact you more easily.</p>
                <p style="margin-bottom: 10px; line-height: 1.6;"><strong>How to use it:</strong></p>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li>First, fill in your organization details below (name, website, address, phone, etc.)</li>
                    <li>Click "Save Organization Settings" to save your information</li>
                    <li>Then go to the posts/pages table and click "Generate" next to any page you want to add this information to</li>
                    <li>The information will automatically appear in the code of your page (visitors won't see it, but search engines will)</li>
                </ul>
                <p style="margin-bottom: 0; line-height: 1.6;"><strong>Note:</strong> This information is hidden code that only search engines read. It won't change how your page looks to visitors.</p>
            </div>
            
            <div class="ac-jsonld-settings">
                <h2>Organization Settings</h2>
                <form id="jsonld-settings-form" method="post" action="">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="org_name">Organization Name</label></th>
                            <td><input type="text" id="org_name" name="org_name" value="<?php echo esc_attr($jsonld_settings['org_name'] ?? ''); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="org_url">Organization URL</label></th>
                            <td><input type="url" id="org_url" name="org_url" value="<?php echo esc_attr($jsonld_settings['org_url'] ?? home_url()); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="org_logo">Organization Logo URL</label></th>
                            <td><input type="url" id="org_logo" name="org_logo" value="<?php echo esc_attr($jsonld_settings['org_logo'] ?? ''); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="org_description">Organization Description</label></th>
                            <td><textarea id="org_description" name="org_description" rows="3" cols="50"><?php echo esc_textarea($jsonld_settings['org_description'] ?? ''); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="org_phone">Phone Number</label></th>
                            <td><input type="tel" id="org_phone" name="org_phone" value="<?php echo esc_attr($jsonld_settings['org_phone'] ?? ''); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="org_email">Email Address</label></th>
                            <td><input type="email" id="org_email" name="org_email" value="<?php echo esc_attr($jsonld_settings['org_email'] ?? ''); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="org_address">Address</label></th>
                            <td>
                                <textarea id="org_address" name="org_address" rows="3" cols="50"><?php echo esc_textarea($jsonld_settings['org_address'] ?? ''); ?></textarea>
                                <p class="description">Include street address, city, state, postal code, country</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="org_social">Social Media URLs</label></th>
                            <td>
                                <input type="url" id="org_facebook" name="org_facebook" value="<?php echo esc_attr($jsonld_settings['org_facebook'] ?? ''); ?>" placeholder="Facebook URL" class="regular-text" style="margin-bottom: 5px;" /><br>
                                <input type="url" id="org_twitter" name="org_twitter" value="<?php echo esc_attr($jsonld_settings['org_twitter'] ?? ''); ?>" placeholder="Twitter URL" class="regular-text" style="margin-bottom: 5px;" /><br>
                                <input type="url" id="org_linkedin" name="org_linkedin" value="<?php echo esc_attr($jsonld_settings['org_linkedin'] ?? ''); ?>" placeholder="LinkedIn URL" class="regular-text" />
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" id="save-jsonld-settings" class="button button-primary">Save Organization Settings</button>
                    </p>
                </form>
            </div>
            
            <div class="ac-jsonld-posts-section">
                <h2>Posts JSON-LD Management</h2>
                        <div class="jsonld-controls">
                            <div class="jsonld-controls-row">
                                <label for="jsonld-post-type-select"><strong>Post Type:</strong></label>
                                <select id="jsonld-post-type-select">
                                    <?php foreach ($post_types as $post_type): ?>
                                        <option value="<?php echo esc_attr($post_type->name); ?>" <?php selected($post_type->name, 'page'); ?>>
                                            <?php echo esc_html($post_type->labels->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button id="bulk-generate-jsonld" class="button button-primary">Generate JSON-LD for All Posts</button>
                            </div>
                        </div>
                <div id="jsonld-posts-container">
                    <table class="wp-list-table widefat fixed striped" id="jsonld-posts-table">
                        <thead>
                            <tr>
                                <th class="column-id">ID</th>
                                <th class="column-title">Title</th>
                                <th class="column-status">Status</th>
                                <th class="column-jsonld-status">JSON-LD Status</th>
                                <th class="column-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="jsonld-posts-tbody">
                            <tr>
                                <td colspan="5" class="no-data">Loading posts...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div id="jsonld-modal" class="jsonld-modal-overlay" style="display: none;">
                <div class="jsonld-modal-content">
                    <div class="jsonld-modal-header">
                        <h3>JSON-LD Editor</h3>
                        <button class="jsonld-modal-close">&times;</button>
                    </div>
                    <div class="jsonld-modal-body">
                        <div class="jsonld-editor-container">
                            <textarea id="jsonld-editor" rows="20" cols="80"></textarea>
                        </div>
                        <div class="jsonld-modal-actions">
                            <button id="validate-jsonld" class="button button-secondary">Validate JSON</button>
                            <button id="generate-jsonld" class="button button-primary">Generate JSON-LD</button>
                            <button id="save-jsonld" class="button button-primary">Save JSON-LD</button>
                            <button class="jsonld-modal-close button button-secondary">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="status-message" class="notice" style="display: none; margin-top: 20px;">
                <p></p>
            </div>
        </div>
        <?php
    }
}

// Register with the amplifi.studio framework.
amplifi_register_plugin(
	'ac-bulk-meta',
	'Meta',
	'AI-powered bulk SEO meta editor with FAQ generation and JSON-LD structured data.',
	ACMETA_VERSION,
	__FILE__,
	array( AC_Bulk_Meta_Pages::get_instance(), 'render_admin_page' )
);

// Activation: create the FAQs table.
register_activation_hook( __FILE__, 'acmeta_activate' );

function acmeta_activate() {
	AC_Bulk_Meta_Pages::get_instance()->create_faqs_table_on_activate();
}

// Deactivation (no-op for now, kept for parity).
register_deactivation_hook( __FILE__, '__return_false' );

