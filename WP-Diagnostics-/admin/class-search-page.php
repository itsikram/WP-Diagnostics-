<?php
/**
 * Search Tool Admin Page
 * Global search & replace UI for WordPress
 */

declare(strict_types=1);

namespace WUDT\Admin;

use WUDT\Modules\SearchTool\File_Search;
use WUDT\Modules\SearchTool\Search_Controller;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Search_Page
 *
 * Admin interface for global search and replace
 */
class Search_Page {
	private const SLUG = 'wudt-search';

	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'wudt-diagnostics',
			__('Search & Replace', 'diagnostics-toolkit'),
			__('Search & Replace', 'diagnostics-toolkit'),
			'manage_options',
			self::SLUG,
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		// The hook suffix depends on the (translatable) parent menu title, so match on the slug.
		$page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check.
		if (self::SLUG !== $page && false === strpos($hook, '_page_' . self::SLUG)) {
			return;
		}

		wp_enqueue_style('dashicons');
		wp_enqueue_style('wudt-admin-modern', WUDT_PLUGIN_URL . 'assets/css/admin-modern.css', array(), WUDT_VERSION);
		wp_enqueue_style('wudt-search-tool', WUDT_PLUGIN_URL . 'assets/css/search-tool.css', array('wudt-admin-modern'), self::asset_version('assets/css/search-tool.css'));

		wp_enqueue_script('wudt-ui-utils', WUDT_PLUGIN_URL . 'assets/js/ui-utils.js', array('jquery'), WUDT_VERSION, true);
		wp_enqueue_script('wudt-search-tool', WUDT_PLUGIN_URL . 'assets/js/search-tool.js', array('jquery', 'wudt-ui-utils'), self::asset_version('assets/js/search-tool.js'), true);

		wp_localize_script('wudt-search-tool', 'wudtSearchTool', array(
			'ajaxUrl'  => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('wudt_admin_nonce'),
			'history'  => Search_Controller::get_history(),
			'dbPrefix' => $GLOBALS['wpdb']->prefix,
			'defaults' => array(
				'excludeDirs' => array_values(array_diff(File_Search::default_options()['exclude_dirs'], array('wudt-search-backups'))),
			),
			'i18n'     => array(
				'enterSearch'      => __('Type something to search for.', 'diagnostics-toolkit'),
				'pickTarget'       => __('Choose where to search.', 'diagnostics-toolkit'),
				'pickExtension'    => __('Select at least one file type.', 'diagnostics-toolkit'),
				'searchingFiles'   => __('Searching files…', 'diagnostics-toolkit'),
				'searchingDb'      => __('Searching database…', 'diagnostics-toolkit'),
				'done'             => __('Done', 'diagnostics-toolkit'),
				'failed'           => __('Failed', 'diagnostics-toolkit'),
				'cancelled'        => __('Search cancelled.', 'diagnostics-toolkit'),
				'noResults'        => __('No matches found', 'diagnostics-toolkit'),
				'noResultsHint'    => __('Try a shorter term, turn off "Match case" or "Whole word", or widen the folder and file types.', 'diagnostics-toolkit'),
				'truncatedLimit'   => __('Result limit reached — showing the first matches only. Narrow the search to see the rest.', 'diagnostics-toolkit'),
				'truncatedTime'    => __('Time limit reached — the search stopped early. Pick a smaller folder or fewer tables.', 'diagnostics-toolkit'),
				'truncatedRows'    => __('Row limit reached for regex search — not every row was checked.', 'diagnostics-toolkit'),
				'previewing'       => __('Building preview…', 'diagnostics-toolkit'),
				'replacing'        => __('Replacing…', 'diagnostics-toolkit'),
				'nothingToReplace' => __('Nothing would change with this replacement.', 'diagnostics-toolkit'),
				'nothingSelected'  => __('Select at least one item to replace.', 'diagnostics-toolkit'),
				'confirmDelete'    => __('The replacement is empty, so every match will be deleted. Continue?', 'diagnostics-toolkit'),
				'confirmRestore'   => __('Put back the original content of every file and row changed by this replace?', 'diagnostics-toolkit'),
				'confirmDeleteBak' => __('Delete this undo backup? You will no longer be able to undo that replace.', 'diagnostics-toolkit'),
				'restored'         => __('Undo complete', 'diagnostics-toolkit'),
				'copied'           => __('Copied to clipboard', 'diagnostics-toolkit'),
				'requestFailed'    => __('Request failed. Check your connection or server error log.', 'diagnostics-toolkit'),
				'patternLoaded'    => __('Pattern loaded — regex enabled.', 'diagnostics-toolkit'),
			),
		));
	}

	/**
	 * Plugin version plus file time, so browsers never keep a stale copy after an update.
	 */
	private static function asset_version(string $relative): string {
		$mtime = @filemtime(WUDT_PLUGIN_DIR . $relative); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return WUDT_VERSION . ($mtime ? '.' . $mtime : '');
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'diagnostics-toolkit'));
		}

		$folders  = File_Search::get_directory_choices();
		$patterns = File_Search::get_malware_patterns();
		$labels   = File_Search::get_malware_pattern_labels();
		$types    = array(
			'php'      => true,
			'js'       => true,
			'css'      => true,
			'html'     => false,
			'htm'      => false,
			'txt'      => false,
			'json'     => false,
			'xml'      => false,
			'svg'      => false,
			'htaccess' => false,
			'ini'      => false,
			'sql'      => false,
			'md'       => false,
		);
		?>
		<div class="wudt-fullscreen-page wudt-sr-page">
			<div class="wudt-sr">
				<header class="wudt-sr-header">
					<div>
						<h1 class="wudt-sr-title"><?php esc_html_e('Search & Replace', 'diagnostics-toolkit'); ?></h1>
						<p class="wudt-sr-subtitle"><?php esc_html_e('Find text or code across files and the database, preview every change, and undo it later.', 'diagnostics-toolkit'); ?></p>
					</div>
					<button type="button" id="wudt-sr-history-btn" class="wudt-btn wudt-btn--secondary">
						<span class="dashicons dashicons-backup" aria-hidden="true"></span>
						<?php esc_html_e('Undo history', 'diagnostics-toolkit'); ?>
					</button>
				</header>

				<!-- Query -->
				<section class="wudt-sr-panel wudt-sr-query-panel">
					<form id="wudt-sr-form" class="wudt-sr-row" autocomplete="off" onsubmit="return false;">
						<div class="wudt-sr-field">
							<span class="dashicons dashicons-search wudt-sr-field__icon" aria-hidden="true"></span>
							<label class="screen-reader-text" for="wudt-search-input"><?php esc_html_e('Search for', 'diagnostics-toolkit'); ?></label>
							<input type="text" id="wudt-search-input" class="wudt-sr-input" spellcheck="false"
								placeholder="<?php esc_attr_e('Search text, code or a regular expression…', 'diagnostics-toolkit'); ?>" />
							<div class="wudt-sr-toggles" role="group" aria-label="<?php esc_attr_e('Match options', 'diagnostics-toolkit'); ?>">
								<button type="button" class="wudt-sr-toggle" data-option="case_sensitive" aria-pressed="false" title="<?php esc_attr_e('Match case (Alt+C)', 'diagnostics-toolkit'); ?>">Aa</button>
								<button type="button" class="wudt-sr-toggle" data-option="whole_word" aria-pressed="false" title="<?php esc_attr_e('Whole word (Alt+W)', 'diagnostics-toolkit'); ?>"><span class="wudt-sr-ww">ab</span></button>
								<button type="button" class="wudt-sr-toggle" data-option="regex" aria-pressed="false" title="<?php esc_attr_e('Regular expression (Alt+R)', 'diagnostics-toolkit'); ?>">.*</button>
							</div>
						</div>
						<button type="submit" id="wudt-search-btn" class="wudt-btn wudt-btn--primary wudt-btn--lg wudt-sr-action">
							<span class="dashicons dashicons-search" aria-hidden="true"></span>
							<span class="wudt-sr-btn-label"><?php esc_html_e('Search', 'diagnostics-toolkit'); ?></span>
						</button>
					</form>

					<div class="wudt-sr-row">
						<div class="wudt-sr-field">
							<span class="dashicons dashicons-randomize wudt-sr-field__icon" aria-hidden="true"></span>
							<label class="screen-reader-text" for="wudt-replace-input"><?php esc_html_e('Replace with', 'diagnostics-toolkit'); ?></label>
							<input type="text" id="wudt-replace-input" class="wudt-sr-input" spellcheck="false"
								placeholder="<?php esc_attr_e('Replace with… (optional — leave empty to delete matches)', 'diagnostics-toolkit'); ?>" />
						</div>
						<button type="button" id="wudt-preview-replace-btn" class="wudt-btn wudt-btn--secondary wudt-btn--lg wudt-sr-action" disabled
							title="<?php esc_attr_e('Run a search first', 'diagnostics-toolkit'); ?>">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
							<?php esc_html_e('Preview replace', 'diagnostics-toolkit'); ?>
						</button>
					</div>

					<div id="wudt-sr-query-error" class="wudt-sr-alert wudt-sr-alert--error" role="alert" hidden></div>
					<p id="wudt-sr-regex-hint" class="wudt-sr-hint" hidden>
						<?php esc_html_e('Regex uses PHP (PCRE) syntax and is matched one line at a time. Use $1, $2… in the replacement for captured groups.', 'diagnostics-toolkit'); ?>
					</p>

					<div class="wudt-sr-chips-row" id="wudt-sr-recent" hidden>
						<span class="wudt-sr-chips-label"><?php esc_html_e('Recent', 'diagnostics-toolkit'); ?></span>
						<div class="wudt-sr-chips" id="wudt-sr-recent-list"></div>
						<button type="button" class="wudt-sr-link" id="wudt-sr-clear-history"><?php esc_html_e('Clear', 'diagnostics-toolkit'); ?></button>
					</div>

					<details class="wudt-sr-patterns">
						<summary>
							<span class="dashicons dashicons-shield" aria-hidden="true"></span>
							<?php esc_html_e('Suspicious code patterns', 'diagnostics-toolkit'); ?>
							<span class="wudt-sr-muted"><?php esc_html_e('— one-click regex searches often used to find injected malware', 'diagnostics-toolkit'); ?></span>
						</summary>
						<div class="wudt-sr-chips">
							<?php foreach ($patterns as $key => $pattern) : ?>
								<button type="button" class="wudt-sr-chip wudt-sr-chip--pattern" data-pattern="<?php echo esc_attr($pattern); ?>" title="<?php echo esc_attr($pattern); ?>">
									<code><?php echo esc_html($labels[ $key ] ?? $key); ?></code>
								</button>
							<?php endforeach; ?>
						</div>
					</details>
				</section>

				<div class="wudt-sr-layout">
					<!-- Options -->
					<aside class="wudt-sr-panel wudt-sr-options">
						<div class="wudt-sr-section">
							<span class="wudt-sr-label" id="wudt-sr-scope-label"><?php esc_html_e('Search in', 'diagnostics-toolkit'); ?></span>
							<div class="wudt-sr-segmented" role="radiogroup" aria-labelledby="wudt-sr-scope-label">
								<button type="button" role="radio" data-scope="files" aria-checked="true"><span class="dashicons dashicons-media-code" aria-hidden="true"></span><?php esc_html_e('Files', 'diagnostics-toolkit'); ?></button>
								<button type="button" role="radio" data-scope="db" aria-checked="false"><span class="dashicons dashicons-database" aria-hidden="true"></span><?php esc_html_e('Database', 'diagnostics-toolkit'); ?></button>
								<button type="button" role="radio" data-scope="both" aria-checked="false"><?php esc_html_e('Both', 'diagnostics-toolkit'); ?></button>
							</div>
						</div>

						<div class="wudt-sr-section" data-scope-section="files">
							<label class="wudt-sr-label" for="wudt-directory"><?php esc_html_e('Folder', 'diagnostics-toolkit'); ?></label>
							<select id="wudt-directory" class="wudt-sr-select">
								<optgroup label="<?php esc_attr_e('Locations', 'diagnostics-toolkit'); ?>">
									<?php foreach ($folders['general'] as $value => $label) : ?>
										<option value="<?php echo esc_attr($value); ?>" <?php selected($value, 'wp-content'); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</optgroup>
								<?php if ($folders['plugins']) : ?>
									<optgroup label="<?php esc_attr_e('A single plugin', 'diagnostics-toolkit'); ?>">
										<?php foreach ($folders['plugins'] as $value => $label) : ?>
											<option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php endif; ?>
								<?php if ($folders['themes']) : ?>
									<optgroup label="<?php esc_attr_e('A single theme', 'diagnostics-toolkit'); ?>">
										<?php foreach ($folders['themes'] as $value => $label) : ?>
											<option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php endif; ?>
							</select>

							<span class="wudt-sr-label wudt-sr-label--spaced"><?php esc_html_e('File types', 'diagnostics-toolkit'); ?></span>
							<div class="wudt-sr-chips wudt-sr-ext-list" id="wudt-sr-ext-list">
								<?php foreach ($types as $ext => $checked) : ?>
									<label class="wudt-sr-check-chip">
										<input type="checkbox" value="<?php echo esc_attr($ext); ?>" <?php checked($checked); ?> />
										<span>.<?php echo esc_html($ext); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
							<input type="text" id="wudt-sr-ext-custom" class="wudt-sr-select wudt-sr-small-input" spellcheck="false"
								placeholder="<?php esc_attr_e('More types, comma separated (e.g. twig, po)', 'diagnostics-toolkit'); ?>" />

							<label class="wudt-sr-label wudt-sr-label--spaced" for="wudt-sr-exclude"><?php esc_html_e('Skip folders named', 'diagnostics-toolkit'); ?></label>
							<input type="text" id="wudt-sr-exclude" class="wudt-sr-select wudt-sr-small-input" spellcheck="false" />

							<label class="wudt-sr-switch">
								<input type="checkbox" id="wudt-sr-skip-min" checked />
								<span><?php esc_html_e('Skip minified .min.js / .min.css', 'diagnostics-toolkit'); ?></span>
							</label>
						</div>

						<div class="wudt-sr-section" data-scope-section="db" hidden>
							<div class="wudt-sr-label-row">
								<span class="wudt-sr-label"><?php esc_html_e('Tables', 'diagnostics-toolkit'); ?></span>
								<span class="wudt-sr-muted" id="wudt-sr-table-count"></span>
							</div>
							<input type="search" id="wudt-sr-table-filter" class="wudt-sr-select wudt-sr-small-input"
								placeholder="<?php esc_attr_e('Filter tables…', 'diagnostics-toolkit'); ?>" />
							<div class="wudt-sr-table-actions">
								<button type="button" class="wudt-sr-link" data-tables="all"><?php esc_html_e('Select all', 'diagnostics-toolkit'); ?></button>
								<button type="button" class="wudt-sr-link" data-tables="none"><?php esc_html_e('Clear', 'diagnostics-toolkit'); ?></button>
								<button type="button" class="wudt-sr-link" data-tables="content"><?php esc_html_e('Content only', 'diagnostics-toolkit'); ?></button>
							</div>
							<div class="wudt-sr-table-list" id="wudt-sr-table-list">
								<div class="wudt-sr-skeleton"></div><div class="wudt-sr-skeleton"></div><div class="wudt-sr-skeleton"></div>
							</div>
							<p class="wudt-sr-hint"><?php esc_html_e('No tables selected = search every table.', 'diagnostics-toolkit'); ?></p>
						</div>
					</aside>

					<!-- Results -->
					<main class="wudt-sr-main">
						<section id="wudt-sr-status" class="wudt-sr-panel wudt-sr-status" hidden aria-live="polite">
							<div class="wudt-sr-progress"><div class="wudt-sr-progress__bar"></div></div>
							<div class="wudt-sr-status__row">
								<ul class="wudt-sr-status__steps" id="wudt-sr-steps"></ul>
								<button type="button" class="wudt-btn wudt-btn--ghost wudt-btn--sm" id="wudt-sr-cancel"><?php esc_html_e('Cancel', 'diagnostics-toolkit'); ?></button>
							</div>
						</section>

						<div id="wudt-sr-banner"></div>

						<section id="wudt-sr-results" class="wudt-sr-results" hidden>
							<div class="wudt-sr-summary" id="wudt-sr-summary"></div>
							<div id="wudt-sr-notices"></div>

							<div class="wudt-sr-toolbar">
								<div class="wudt-sr-tabs" role="tablist">
									<button type="button" role="tab" class="wudt-sr-tab" data-tab="files" aria-selected="true">
										<?php esc_html_e('Files', 'diagnostics-toolkit'); ?> <span class="wudt-sr-count" data-count="files">0</span>
									</button>
									<button type="button" role="tab" class="wudt-sr-tab" data-tab="db" aria-selected="false">
										<?php esc_html_e('Database', 'diagnostics-toolkit'); ?> <span class="wudt-sr-count" data-count="db">0</span>
									</button>
								</div>
								<div class="wudt-sr-toolbar__right">
									<input type="search" id="wudt-sr-filter" class="wudt-sr-select wudt-sr-small-input" placeholder="<?php esc_attr_e('Filter results by path or table…', 'diagnostics-toolkit'); ?>" />
									<button type="button" class="wudt-btn wudt-btn--ghost wudt-btn--sm" id="wudt-sr-toggle-context" aria-pressed="true" title="<?php esc_attr_e('Show surrounding lines', 'diagnostics-toolkit'); ?>">
										<span class="dashicons dashicons-editor-alignleft" aria-hidden="true"></span><?php esc_html_e('Context', 'diagnostics-toolkit'); ?>
									</button>
									<button type="button" class="wudt-btn wudt-btn--ghost wudt-btn--sm" id="wudt-sr-collapse-all">
										<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span><span><?php esc_html_e('Collapse all', 'diagnostics-toolkit'); ?></span>
									</button>
									<div class="wudt-sr-menu">
										<button type="button" class="wudt-btn wudt-btn--ghost wudt-btn--sm" id="wudt-export-results" aria-haspopup="true" aria-expanded="false">
											<span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e('Export', 'diagnostics-toolkit'); ?>
										</button>
										<div class="wudt-sr-menu__list" hidden>
											<button type="button" data-export="csv">CSV</button>
											<button type="button" data-export="json">JSON</button>
										</div>
									</div>
								</div>
							</div>

							<div class="wudt-sr-list" id="wudt-file-results-list" role="tabpanel" data-panel="files"></div>
							<div class="wudt-sr-list" id="wudt-db-results-list" role="tabpanel" data-panel="db" hidden></div>
						</section>

						<section id="wudt-sr-empty" class="wudt-sr-panel wudt-sr-empty">
							<span class="dashicons dashicons-search wudt-sr-empty__icon" aria-hidden="true"></span>
							<h2 class="wudt-sr-empty__title"><?php esc_html_e('Search your whole site', 'diagnostics-toolkit'); ?></h2>
							<ul class="wudt-sr-tips">
								<li><?php echo wp_kses(__('Press <kbd>Enter</kbd> to search, <kbd>Alt</kbd>+<kbd>C</kbd> / <kbd>W</kbd> / <kbd>R</kbd> to toggle match case, whole word and regex.', 'diagnostics-toolkit'), array('kbd' => array())); ?></li>
								<li><?php esc_html_e('Pick a single plugin or theme as the folder to make searches much faster.', 'diagnostics-toolkit'); ?></li>
								<li><?php esc_html_e('Replacing always shows a preview first. Choose exactly which files or rows to change, and undo it any time from Undo history.', 'diagnostics-toolkit'); ?></li>
								<li><?php esc_html_e('Serialized database values (widgets, theme options) are unpacked before replacing, so they stay valid.', 'diagnostics-toolkit'); ?></li>
							</ul>
						</section>
					</main>
				</div>
			</div>

			<!-- Replace Preview Modal -->
			<div id="wudt-replace-preview-modal" class="wudt-modal-overlay wudt-sr-modal" hidden>
				<div class="wudt-modal wudt-sr-modal__box" role="dialog" aria-modal="true" aria-labelledby="wudt-sr-preview-title">
					<div class="wudt-modal__header">
						<h3 class="wudt-modal__title" id="wudt-sr-preview-title"><?php esc_html_e('Review replacements', 'diagnostics-toolkit'); ?></h3>
						<button type="button" class="wudt-modal__close" data-close aria-label="<?php esc_attr_e('Close', 'diagnostics-toolkit'); ?>">&times;</button>
					</div>
					<div class="wudt-sr-modal__body">
						<div id="wudt-preview-summary" class="wudt-sr-preview-summary"></div>
						<div id="wudt-preview-changes" class="wudt-sr-preview-list"></div>
					</div>
					<div class="wudt-modal__footer wudt-sr-modal__footer">
						<label class="wudt-sr-switch">
							<input type="checkbox" id="wudt-create-backup" checked />
							<span><?php esc_html_e('Keep an undo backup', 'diagnostics-toolkit'); ?></span>
						</label>
						<div class="wudt-sr-modal__actions">
							<button type="button" class="wudt-btn wudt-btn--secondary" data-close><?php esc_html_e('Cancel', 'diagnostics-toolkit'); ?></button>
							<button type="button" class="wudt-btn wudt-btn--danger" id="wudt-confirm-replace"><?php esc_html_e('Replace', 'diagnostics-toolkit'); ?></button>
						</div>
					</div>
				</div>
			</div>

			<!-- Undo History Modal -->
			<div id="wudt-sr-history-modal" class="wudt-modal-overlay wudt-sr-modal" hidden>
				<div class="wudt-modal wudt-sr-modal__box" role="dialog" aria-modal="true" aria-labelledby="wudt-sr-history-title">
					<div class="wudt-modal__header">
						<h3 class="wudt-modal__title" id="wudt-sr-history-title"><?php esc_html_e('Undo history', 'diagnostics-toolkit'); ?></h3>
						<button type="button" class="wudt-modal__close" data-close aria-label="<?php esc_attr_e('Close', 'diagnostics-toolkit'); ?>">&times;</button>
					</div>
					<div class="wudt-sr-modal__body">
						<p class="wudt-sr-hint"><?php esc_html_e('Each replace keeps the original content of what it changed. The 20 most recent are kept.', 'diagnostics-toolkit'); ?></p>
						<div id="wudt-sr-history-list" class="wudt-sr-history-list"></div>
					</div>
				</div>
			</div>

			<!-- Full Value Modal -->
			<div id="wudt-sr-value-modal" class="wudt-modal-overlay wudt-sr-modal" hidden>
				<div class="wudt-modal wudt-sr-modal__box" role="dialog" aria-modal="true" aria-labelledby="wudt-sr-value-title">
					<div class="wudt-modal__header">
						<h3 class="wudt-modal__title" id="wudt-sr-value-title"></h3>
						<button type="button" class="wudt-modal__close" data-close aria-label="<?php esc_attr_e('Close', 'diagnostics-toolkit'); ?>">&times;</button>
					</div>
					<div class="wudt-sr-modal__body">
						<div id="wudt-sr-value-meta" class="wudt-sr-hint"></div>
						<pre id="wudt-sr-value-body" class="wudt-sr-value"></pre>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
