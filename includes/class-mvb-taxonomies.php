<?php
/**
 * MVB Taxonomies
 *
 * @package MVB
 */

declare(strict_types=1);

/**
 * Class MVB_Taxonomies
 */
class MVB_Taxonomies {

	/**
	 * Initialize taxonomies
	 */
	public static function init() {
		// Register taxonomies after post types are registered (default priority is 10)
		add_action('init', array(__CLASS__, 'register_taxonomies'), 11);
	}

	/**
	 * Register custom taxonomies
	 */
	public static function register_taxonomies() {
		error_log('=== Registering MVB taxonomies ===');
		
		// Register Company Taxonomy
		$result = register_taxonomy(
			'mvb_company',
			'videogame',
			array(
				'labels' => array(
					'name'              => __('Companies', 'mvb'),
					'singular_name'     => __('Company', 'mvb'),
					'search_items'      => __('Search Companies', 'mvb'),
					'all_items'         => __('All Companies', 'mvb'),
					'parent_item'       => __('Parent Company', 'mvb'),
					'parent_item_colon' => __('Parent Company:', 'mvb'),
					'edit_item'         => __('Edit Company', 'mvb'),
					'update_item'       => __('Update Company', 'mvb'),
					'add_new_item'      => __('Add New Company', 'mvb'),
					'new_item_name'     => __('New Company Name', 'mvb'),
					'menu_name'         => __('Companies', 'mvb'),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => array('slug' => 'company'),
				'show_in_rest'      => true,
			)
		);

		// Register Platform Taxonomy
		$result = register_taxonomy(
			'mvb_platform',
			'videogame',
			array(
				'labels' => array(
					'name'              => __('Platforms', 'mvb'),
					'singular_name'     => __('Platform', 'mvb'),
					'search_items'      => __('Search Platforms', 'mvb'),
					'all_items'         => __('All Platforms', 'mvb'),
					'parent_item'       => __('Parent Platform', 'mvb'),
					'parent_item_colon' => __('Parent Platform:', 'mvb'),
					'edit_item'         => __('Edit Platform', 'mvb'),
					'update_item'       => __('Update Platform', 'mvb'),
					'add_new_item'      => __('Add New Platform', 'mvb'),
					'new_item_name'     => __('New Platform Name', 'mvb'),
					'menu_name'         => __('Platforms', 'mvb'),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => array('slug' => 'platform'),
				'show_in_rest'      => true,
			)
		);

		error_log('Register taxonomy result: ' . print_r($result, true));
	}

	/**
	 * Add or update company from IGDB data
	 *
	 * @param array $igdb_company IGDB company data.
	 * @return int|WP_Error Term ID on success, WP_Error on failure.
	 */
	public static function add_or_update_company($igdb_company) {
		error_log('Adding/updating company: ' . print_r($igdb_company, true));

		// Skip if company name is just a number or empty
		if (empty($igdb_company['name']) || 
			is_numeric($igdb_company['name']) || 
			preg_match('/^\d+$/', $igdb_company['name']) ||
			trim($igdb_company['name']) === ''
		) {
			error_log('Skipping invalid company name: ' . ($igdb_company['name'] ?? 'empty'));
			return new WP_Error('invalid_company_name', 'Invalid company name');
		}

		// Create slug if not present
		if (empty($igdb_company['slug'])) {
			$igdb_company['slug'] = sanitize_title($igdb_company['name']);
		}
		
		$term = term_exists($igdb_company['slug'], 'mvb_company');
		
		$term_data = array(
			'description' => wp_kses_post($igdb_company['description'] ?? ''),
		);

		if ($term) {
			$term_id = $term['term_id'];
			$result = wp_update_term($term_id, 'mvb_company', array_merge(
				array('name' => $igdb_company['name']),
				$term_data
			));
			if (is_wp_error($result)) {
				error_log('Error updating company term: ' . $result->get_error_message());
				return $result;
			}
			$term_id = $result['term_id'];
		} else {
			$result = wp_insert_term(
				$igdb_company['name'],
				'mvb_company',
				array_merge(
					array('slug' => $igdb_company['slug']),
					$term_data
				)
			);
			if (is_wp_error($result)) {
				error_log('Error inserting company term: ' . $result->get_error_message());
				return $result;
			}
			$term_id = $result['term_id'];
		}

		if (!is_wp_error($term_id)) {
			update_term_meta($term_id, 'igdb_id', $igdb_company['id']);
			if (!empty($igdb_company['url'])) {
				update_term_meta($term_id, 'igdb_url', $igdb_company['url']);
			}
			if (!empty($igdb_company['logo']['url'])) {
				update_term_meta($term_id, 'company_logo', $igdb_company['logo']['url']);
			}
		}

		return $term_id;
	}

	/**
	 * Link company to game with role
	 * 
	 * @param int    $post_id Post ID.
	 * @param int    $term_id Term ID.
	 * @param string $role Company role (developer, publisher, etc.).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function link_company_to_game($post_id, $term_id, $role) {
		$result = wp_set_object_terms($post_id, $term_id, 'mvb_company', true);
		if (is_wp_error($result)) {
			error_log('Error linking company: ' . $result->get_error_message());
			return $result;
		}
		update_post_meta($post_id, "_company_{$term_id}_role", $role);
		return true;
	}

	/**
	 * Add or update platform from IGDB data
	 *
	 * @param array $igdb_platform IGDB platform data.
	 * @return int|WP_Error Term ID on success, WP_Error on failure.
	 */
	public static function add_or_update_platform($igdb_platform) {
		error_log('Adding/updating platform: ' . print_r($igdb_platform, true));

		// Create slug if not present
		if (empty($igdb_platform['slug'])) {
			$igdb_platform['slug'] = sanitize_title($igdb_platform['name']);
		}
		
		$term = term_exists($igdb_platform['slug'], 'mvb_platform');
		
		$term_data = array(
			'description' => wp_kses_post($igdb_platform['summary'] ?? ''),
		);

		if ($term) {
			$term_id = $term['term_id'];
			$result = wp_update_term($term_id, 'mvb_platform', array_merge(
				array('name' => $igdb_platform['name']),
				$term_data
			));
			if (is_wp_error($result)) {
				error_log('Error updating platform term: ' . $result->get_error_message());
				return $result;
			}
			$term_id = $result['term_id'];
		} else {
			$result = wp_insert_term(
				$igdb_platform['name'],
				'mvb_platform',
				array_merge(
					array('slug' => $igdb_platform['slug']),
					$term_data
				)
			);
			if (is_wp_error($result)) {
				error_log('Error inserting platform term: ' . $result->get_error_message());
				return $result;
			}
			$term_id = $result['term_id'];
		}

		if (!is_wp_error($term_id)) {
			update_term_meta($term_id, 'igdb_id', $igdb_platform['id']);
			if (!empty($igdb_platform['url'])) {
				update_term_meta($term_id, 'igdb_url', $igdb_platform['url']);
			}
			if (!empty($igdb_platform['platform_logo']['url'])) {
				update_term_meta($term_id, 'platform_logo', $igdb_platform['platform_logo']['url']);
			}
		}

		return $term_id;
	}

	/**
	 * Link platform to game
	 * 
	 * @param int $post_id Post ID.
	 * @param int $term_id Term ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function link_platform_to_game($post_id, $term_id) {
		return wp_set_object_terms($post_id, $term_id, 'mvb_platform', true);
	}
} 