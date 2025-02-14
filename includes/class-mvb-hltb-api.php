<?php
/**
 * HLTB API Integration
 *
 * @package MVB
 */

declare(strict_types=1);

/**
 * Class MVB_HLTB_API
 */
class MVB_HLTB_API {

	/**
	 * Base URL for HLTB API
	 */
	const API_URL = 'https://howlongtobeat.com/api/search';

	/**
	 * Initialize the HLTB API integration
	 */
	public static function init() {
		add_action('add_meta_boxes', array(__CLASS__, 'add_hltb_meta_box'));
		add_action('save_post_videogame', array(__CLASS__, 'save_hltb_data'), 10, 2);
	}

	/**
	 * Search for a game on HLTB
	 *
	 * @param string $game_name The name of the game to search for.
	 * @return array|WP_Error The search results or WP_Error on failure.
	 */
	public static function search_game($game_name) {
		$response = wp_remote_post(
			self::API_URL,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Origin' => 'https://howlongtobeat.com',
					'Referer' => 'https://howlongtobeat.com/',
				),
				'body' => wp_json_encode(array(
					'searchType' => 'games',
					'searchTerms' => array(explode(' ', $game_name)),
					'searchPage' => 1,
					'size' => 1,
					'searchOptions' => array(
						'games' => array(
							'userId' => 0,
							'platform' => '',
							'sortCategory' => 'popular',
							'rangeCategory' => 'main',
							'rangeTime' => array(
								'min' => 0,
								'max' => 0,
							),
							'gameplay' => array(
								'perspective' => '',
								'flow' => '',
								'genre' => '',
							),
							'modifier' => '',
						),
						'users' => array(
							'sortCategory' => 'postcount',
						),
						'filter' => '',
						'sort' => 0,
						'randomizer' => 0,
					),
				)),
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (empty($data['data'])) {
			return new WP_Error('no_results', __('No results found', 'mvb'));
		}

		return $data['data'][0];
	}

	/**
	 * Add HLTB meta box
	 */
	public static function add_hltb_meta_box() {
		add_meta_box(
			'mvb_hltb_meta_box',
			__('How Long to Beat', 'mvb'),
			array(__CLASS__, 'render_hltb_meta_box'),
			'videogame',
			'side',
			'default'
		);
	}

	/**
	 * Render HLTB meta box
	 *
	 * @param WP_Post $post The post object.
	 */
	public static function render_hltb_meta_box($post) {
		$main_story_time = get_post_meta($post->ID, 'hltb_main_story', true);
		$game_title = get_the_title($post->ID);
		wp_nonce_field('mvb_hltb_data', 'mvb_hltb_nonce');
		?>
		<p>
			<label for="hltb_main_story"><?php esc_html_e('Main Story (Hours):', 'mvb'); ?></label>
			<input type="number" step="0.1" id="hltb_main_story" name="hltb_main_story" 
				value="<?php echo esc_attr($main_story_time); ?>" />
		</p>
		<p>
			<button type="button" class="button" id="fetch-hltb-data">
				<?php esc_html_e('Fetch from HLTB', 'mvb'); ?>
			</button>
		</p>
		<script>
		jQuery(document).ready(function($) {
			$('#fetch-hltb-data').on('click', function() {
				const button = $(this);
				const title = <?php echo wp_json_encode($game_title); ?>;
				
				if (!title) {
					alert('<?php esc_html_e('Please enter a game title first', 'mvb'); ?>');
					return;
				}

				button.prop('disabled', true);
				button.text('<?php esc_html_e('Fetching...', 'mvb'); ?>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'fetch_hltb_data',
						nonce: '<?php echo wp_create_nonce('mvb_fetch_hltb'); ?>',
						game_title: title.trim()
					},
					success: function(response) {
						if (response.success && response.data) {
							const hours = parseFloat(response.data.main_story).toFixed(1);
							$('#hltb_main_story').val(hours);
						} else {
							alert(response.data.message || '<?php esc_html_e('Error fetching data', 'mvb'); ?>');
						}
					},
					error: function() {
						alert('<?php esc_html_e('Error fetching HLTB data', 'mvb'); ?>');
					},
					complete: function() {
						button.prop('disabled', false);
						button.text('<?php esc_html_e('Fetch from HLTB', 'mvb'); ?>');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Save HLTB data
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post The post object.
	 */
	public static function save_hltb_data($post_id, $post) {
		if (!isset($_POST['mvb_hltb_nonce']) || !wp_verify_nonce($_POST['mvb_hltb_nonce'], 'mvb_hltb_data')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if (isset($_POST['hltb_main_story'])) {
			update_post_meta(
				$post_id,
				'hltb_main_story',
				sanitize_text_field($_POST['hltb_main_story'])
			);
		}
	}
} 