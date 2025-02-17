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

		error_log('Register taxonomy result: ' . print_r($result, true));
	}

	/**
	 * Add or update company from IGDB data
	 *
	 * @param array $igdb_company IGDB company data.
	 * @return int|WP_Error Term ID on success, WP_Error on failure.
	 */
	public static function add_or_update_company($igdb_company) {
		$term = term_exists($igdb_company['slug'], 'mvb_company');
		
		$term_data = array(
			'description' => wp_kses_post($igdb_company['description'] ?? ''),
		);

		if ($term) {
			$term_id = $term['term_id'];
			wp_update_term($term_id, 'mvb_company', array_merge(
				array('name' => $igdb_company['name']),
				$term_data
			));
		} else {
			$term = wp_insert_term(
				$igdb_company['name'],
				'mvb_company',
				array_merge(
					array('slug' => $igdb_company['slug']),
					$term_data
				)
			);
			$term_id = $term['term_id'];
		}

		if (!is_wp_error($term_id)) {
			update_term_meta($term_id, 'igdb_id', $igdb_company['id']);
			update_term_meta($term_id, 'igdb_url', $igdb_company['url'] ?? '');
			if (!empty($igdb_company['logo'])) {
				update_term_meta($term_id, 'company_logo', $igdb_company['logo']['url'] ?? '');
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
	 */
	public static function link_company_to_game($post_id, $term_id, $role) {
		wp_set_object_terms($post_id, $term_id, 'mvb_company', true);
		update_post_meta($post_id, "_company_{$term_id}_role", $role);
	}
} 