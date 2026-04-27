<?php
/**
 * Search Tool Admin Page
 * Global search & replace UI for WordPress
 */

declare(strict_types=1);

namespace WUDT\Admin;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Search_Page
 * 
 * Admin interface for global search and replace
 */
class Search_Page {
	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'wudt-diagnostics',
			__('Search & Replace', 'wp-ultimate-diagnostics-toolkit'),
			__('Search', 'wp-ultimate-diagnostics-toolkit'),
			'manage_options',
			'wudt-search',
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		if ('wp-diagnostics_page_wudt-search' !== $hook) {
			return;
		}

		// Modern CSS and UI utils
		wp_enqueue_style('wudt-admin-modern', WUDT_PLUGIN_URL . 'assets/css/admin-modern.css', array(), WUDT_VERSION);
		wp_enqueue_style('wudt-admin', WUDT_PLUGIN_URL . 'assets/css/admin.css', array('wudt-admin-modern'), WUDT_VERSION);
		
		wp_enqueue_script('wudt-ui-utils', WUDT_PLUGIN_URL . 'assets/js/ui-utils.js', array('jquery'), WUDT_VERSION, true);
		wp_enqueue_script('wudt-search-tool', WUDT_PLUGIN_URL . 'assets/js/search-tool.js', array('jquery', 'wudt-ui-utils'), WUDT_VERSION, true);

		// Localize data
		$directories = \WUDT\Modules\SearchTool\File_Search::get_wp_directories();
		$patterns    = \WUDT\Modules\SearchTool\File_Search::get_malware_patterns();

		wp_localize_script('wudt-search-tool', 'wudtSearchTool', array(
			'ajaxUrl'         => admin_url('admin-ajax.php'),
			'nonce'           => wp_create_nonce('wudt_admin_nonce'),
			'directories'     => $directories,
			'malwarePatterns' => $patterns,
			'labels'          => array(
				'searching'         => __('Searching...', 'wp-ultimate-diagnostics-toolkit'),
				'noResults'         => __('No results found.', 'wp-ultimate-diagnostics-toolkit'),
				'matchesFound'      => __('matches found', 'wp-ultimate-diagnostics-toolkit'),
				'replacePreview'    => __('Replace Preview', 'wp-ultimate-diagnostics-toolkit'),
				'confirmReplace'    => __('Are you sure you want to proceed with replacement?', 'wp-ultimate-diagnostics-toolkit'),
				'backupFirst'       => __('Create backup before replacing', 'wp-ultimate-diagnostics-toolkit'),
				'filesModified'     => __('files modified', 'wp-ultimate-diagnostics-toolkit'),
				'rowsModified'      => __('rows modified', 'wp-ultimate-diagnostics-toolkit'),
				'error'             => __('Error', 'wp-ultimate-diagnostics-toolkit'),
				'success'           => __('Success', 'wp-ultimate-diagnostics-toolkit'),
			),
		));
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-ultimate-diagnostics-toolkit'));
		}
		?>
		<div class="wrap wudt-wrap">
			<h1><?php esc_html_e('Global Search & Replace', 'wp-ultimate-diagnostics-toolkit'); ?></h1>
			<p><?php esc_html_e('Search across WordPress files and database with advanced filters.', 'wp-ultimate-diagnostics-toolkit'); ?></p>

			<!-- Search Form -->
			<div class="wudt-card">
				<div class="wudt-form-group">
					<label class="wudt-label" for="wudt-search-input">
						<?php esc_html_e('Search Text', 'wp-ultimate-diagnostics-toolkit'); ?>
					</label>
					<div class="wudt-search-input-wrap">
						<input type="text" id="wudt-search-input" class="wudt-input wudt-input--lg" 
							placeholder="<?php esc_attr_e('Enter text or pattern to search...', 'wp-ultimate-diagnostics-toolkit'); ?>" />
						<button type="button" id="wudt-search-btn" class="wudt-btn wudt-btn--primary wudt-btn--lg">
							<span class="dashicons dashicons-search"></span>
							<?php esc_html_e('Search', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
					</div>
				</div>

				<!-- Malware Pattern Presets -->
				<div class="wudt-form-group">
					<label class="wudt-label"><?php esc_html_e('Quick Patterns (Malware Detection)', 'wp-ultimate-diagnostics-toolkit'); ?></label>
					<div class="wudt-pattern-presets" id="wudt-pattern-presets">
						<button type="button" class="wudt-btn wudt-btn--sm" data-pattern="eval\s*\(\s*base64_decode">
							<?php esc_html_e('eval(base64)', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
						<button type="button" class="wudt-btn wudt-btn--sm" data-pattern="base64_decode\s*\(">
							<?php esc_html_e('base64_decode', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
						<button type="button" class="wudt-btn wudt-btn--sm" data-pattern="shell_exec\s*\(">
							<?php esc_html_e('shell_exec', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
						<button type="button" class="wudt-btn wudt-btn--sm" data-pattern="<iframe[^>]*src\s*=\s*[\"\']https?://">
							<?php esc_html_e('iframe injection', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
					</div>
				</div>

				<!-- Search Options -->
				<div class="wudt-form-group">
					<label class="wudt-checkbox">
						<input type="checkbox" id="wudt-regex" />
						<?php esc_html_e('Regular Expression', 'wp-ultimate-diagnostics-toolkit'); ?>
					</label>
					<label class="wudt-checkbox">
						<input type="checkbox" id="wudt-case-sensitive" />
						<?php esc_html_e('Case Sensitive', 'wp-ultimate-diagnostics-toolkit'); ?>
					</label>
					<label class="wudt-checkbox">
						<input type="checkbox" id="wudt-whole-word" />
						<?php esc_html_e('Whole Word Match', 'wp-ultimate-diagnostics-toolkit'); ?>
					</label>
				</div>
			</div>

			<!-- Target Selection -->
			<div class="wudt-card">
				<h3><?php esc_html_e('Search In', 'wp-ultimate-diagnostics-toolkit'); ?></h3>
				
				<div class="wudt-search-targets">
					<label class="wudt-checkbox wudt-search-target">
						<input type="checkbox" id="wudt-search-files" checked />
						<span class="wudt-checkbox-label">
							<?php esc_html_e('Files', 'wp-ultimate-diagnostics-toolkit'); ?>
							<span class="wudt-badge wudt-badge--neutral">PHP, JS, CSS, HTML</span>
						</span>
					</label>

					<label class="wudt-checkbox wudt-search-target">
						<input type="checkbox" id="wudt-search-db" />
						<span class="wudt-checkbox-label">
							<?php esc_html_e('Database', 'wp-ultimate-diagnostics-toolkit'); ?>
							<span class="wudt-badge wudt-badge--neutral">posts, meta, options</span>
						</span>
					</label>
				</div>

				<!-- File Search Filters -->
				<div id="wudt-file-filters" class="wudt-filters-section">
					<h4><?php esc_html_e('File Filters', 'wp-ultimate-diagnostics-toolkit'); ?></h4>
					
					<div class="wudt-form-group">
						<label class="wudt-label"><?php esc_html_e('Directory', 'wp-ultimate-diagnostics-toolkit'); ?></label>
						<select id="wudt-directory" class="wudt-select">
							<option value="root"><?php esc_html_e('WordPress Root', 'wp-ultimate-diagnostics-toolkit'); ?></option>
							<option value="wp-content" selected><?php esc_html_e('wp-content/', 'wp-ultimate-diagnostics-toolkit'); ?></option>
							<option value="plugins"><?php esc_html_e('plugins/', 'wp-ultimate-diagnostics-toolkit'); ?></option>
							<option value="themes"><?php esc_html_e('themes/', 'wp-ultimate-diagnostics-toolkit'); ?></option>
							<option value="uploads"><?php esc_html_e('uploads/', 'wp-ultimate-diagnostics-toolkit'); ?></option>
						</select>
					</div>

					<div class="wudt-form-group">
						<label class="wudt-label"><?php esc_html_e('File Extensions', 'wp-ultimate-diagnostics-toolkit'); ?></label>
						<div class="wudt-checkbox-group">
							<label class="wudt-checkbox"><input type="checkbox" value="php" checked /> .php</label>
							<label class="wudt-checkbox"><input type="checkbox" value="js" checked /> .js</label>
							<label class="wudt-checkbox"><input type="checkbox" value="css" checked /> .css</label>
							<label class="wudt-checkbox"><input type="checkbox" value="html" /> .html</label>
							<label class="wudt-checkbox"><input type="checkbox" value="txt" /> .txt</label>
						</div>
					</div>
				</div>

				<!-- Database Filters -->
				<div id="wudt-db-filters" class="wudt-filters-section" style="display:none;">
					<h4><?php esc_html_e('Database Filters', 'wp-ultimate-diagnostics-toolkit'); ?></h4>
					
					<div class="wudt-form-group">
						<label class="wudt-label"><?php esc_html_e('Select Tables', 'wp-ultimate-diagnostics-toolkit'); ?></label>
						<select id="wudt-tables" class="wudt-select" multiple>
							<option value=""><?php esc_html_e('Loading tables...', 'wp-ultimate-diagnostics-toolkit'); ?></option>
						</select>
						<p class="wudt-input-hint"><?php esc_html_e('Leave empty to search all tables. Hold Ctrl/Cmd to select multiple.', 'wp-ultimate-diagnostics-toolkit'); ?></p>
					</div>
				</div>
			</div>

			<!-- Replace Section -->
			<div class="wudt-card" id="wudt-replace-section" style="display:none;">
				<h3><?php esc_html_e('Replace With', 'wp-ultimate-diagnostics-toolkit'); ?></h3>
				
				<div class="wudt-form-group">
					<label class="wudt-label" for="wudt-replace-input">
						<?php esc_html_e('Replace Text (Optional)', 'wp-ultimate-diagnostics-toolkit'); ?>
					</label>
					<input type="text" id="wudt-replace-input" class="wudt-input" 
						placeholder="<?php esc_attr_e('Leave empty for search-only...', 'wp-ultimate-diagnostics-toolkit'); ?>" />
				</div>

				<div class="wudt-form-group">
					<label class="wudt-checkbox">
						<input type="checkbox" id="wudt-create-backup" checked />
						<?php esc_html_e('Create backup before replacing', 'wp-ultimate-diagnostics-toolkit'); ?>
					</label>
				</div>

				<button type="button" id="wudt-preview-replace-btn" class="wudt-btn wudt-btn--secondary">
					<?php esc_html_e('Preview Changes', 'wp-ultimate-diagnostics-toolkit'); ?>
				</button>
			</div>

			<!-- Progress Indicator -->
			<div id="wudt-search-progress" class="wudt-card" style="display:none;">
				<h3><?php esc_html_e('Searching...', 'wp-ultimate-diagnostics-toolkit'); ?></h3>
				<div class="wudt-progress wudt-progress--lg">
					<div class="wudt-progress__bar" style="width: 0%"></div>
				</div>
				<p class="wudt-progress-text"></p>
			</div>

			<!-- Results Container -->
			<div id="wudt-results-container" style="display:none;">
				<div class="wudt-results-header">
					<h2><?php esc_html_e('Search Results', 'wp-ultimate-diagnostics-toolkit'); ?></h2>
					<div class="wudt-results-actions">
						<span id="wudt-results-count" class="wudt-badge wudt-badge--primary"></span>
						<button type="button" id="wudt-export-results" class="wudt-btn wudt-btn--ghost wudt-btn--sm">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e('Export', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
					</div>
				</div>

				<!-- File Results -->
				<div id="wudt-file-results" class="wudt-results-section">
					<h3><?php esc_html_e('File Matches', 'wp-ultimate-diagnostics-toolkit'); ?></h3>
					<div class="wudt-results-list" id="wudt-file-results-list"></div>
				</div>

				<!-- Database Results -->
				<div id="wudt-db-results" class="wudt-results-section" style="display:none;">
					<h3><?php esc_html_e('Database Matches', 'wp-ultimate-diagnostics-toolkit'); ?></h3>
					<div class="wudt-results-list" id="wudt-db-results-list"></div>
				</div>
			</div>

			<!-- Replace Preview Modal -->
			<div id="wudt-replace-preview-modal" class="wudt-modal-overlay" style="display:none;">
				<div class="wudt-modal wudt-modal--lg">
					<div class="wudt-modal__header">
						<h3 class="wudt-modal__title"><?php esc_html_e('Replace Preview', 'wp-ultimate-diagnostics-toolkit'); ?></h3>
						<button type="button" class="wudt-modal__close" id="wudt-close-preview">&times;</button>
					</div>
					<div class="wudt-modal__body">
						<div id="wudt-preview-summary"></div>
						<div id="wudt-preview-changes" class="wudt-preview-list"></div>
					</div>
					<div class="wudt-modal__footer">
						<button type="button" class="wudt-btn wudt-btn--secondary" id="wudt-cancel-replace">
							<?php esc_html_e('Cancel', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
						<button type="button" class="wudt-btn wudt-btn--danger" id="wudt-confirm-replace">
							<?php esc_html_e('Confirm Replace', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<style>
			.wudt-search-input-wrap {
				display: flex;
				gap: 10px;
			}

			.wudt-search-input-wrap .wudt-input {
				flex: 1;
			}

			.wudt-pattern-presets {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin-top: 8px;
			}

			.wudt-search-targets {
				display: flex;
				gap: 20px;
				margin-bottom: 20px;
			}

			.wudt-search-target {
				display: flex;
				align-items: center;
				gap: 8px;
			}

			.wudt-checkbox-label {
				display: flex;
				align-items: center;
				gap: 8px;
			}

			.wudt-filters-section {
				margin-top: 20px;
				padding-top: 20px;
				border-top: 1px solid var(--wudt-gray-200);
			}

			.wudt-checkbox-group {
				display: flex;
				flex-wrap: wrap;
				gap: 15px;
			}

			.wudt-results-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 20px;
			}

			.wudt-results-actions {
				display: flex;
				align-items: center;
				gap: 10px;
			}

			.wudt-results-section {
				margin-bottom: 30px;
			}

			.wudt-results-section h3 {
				margin-bottom: 15px;
				padding-bottom: 10px;
				border-bottom: 1px solid var(--wudt-gray-200);
			}

			.wudt-result-item {
				background: #fff;
				border: 1px solid var(--wudt-gray-200);
				border-radius: 8px;
				padding: 15px;
				margin-bottom: 10px;
			}

			.wudt-result-file {
				font-family: monospace;
				font-size: 0.9em;
				color: var(--wudt-primary);
				margin-bottom: 8px;
			}

			.wudt-result-line {
				font-size: 0.85em;
				color: var(--wudt-gray-500);
				margin-bottom: 8px;
			}

			.wudt-result-code {
				background: var(--wudt-gray-50);
				border-radius: 4px;
				padding: 10px;
				font-family: monospace;
				font-size: 0.85em;
				overflow-x: auto;
				white-space: pre-wrap;
			}

			.wudt-result-match {
				background: var(--wudt-warning-light);
				padding: 2px 4px;
				border-radius: 3px;
			}

			.wudt-preview-list {
				max-height: 400px;
				overflow-y: auto;
			}

			.wudt-preview-item {
				border: 1px solid var(--wudt-gray-200);
				border-radius: 8px;
				margin-bottom: 15px;
				overflow: hidden;
			}

			.wudt-preview-header {
				background: var(--wudt-gray-50);
				padding: 10px 15px;
				font-weight: 500;
			}

			.wudt-preview-content {
				padding: 15px;
			}

			.wudt-preview-before,
			.wudt-preview-after {
				margin-bottom: 10px;
			}

			.wudt-preview-label {
				font-size: 0.8em;
				text-transform: uppercase;
				color: var(--wudt-gray-500);
				margin-bottom: 5px;
			}

			.wudt-preview-code {
				background: var(--wudt-gray-50);
				border-radius: 4px;
				padding: 10px;
				font-family: monospace;
				font-size: 0.85em;
				white-space: pre-wrap;
			}

			.wudt-modal--lg {
				max-width: 900px;
			}

			select[multiple] {
				height: 120px;
			}
		</style>
		<?php
	}
}
