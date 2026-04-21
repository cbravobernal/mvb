<?php
/**
 * Devices (consoles/handhelds/etc.) as a first-class CPT.
 *
 * Replaces the legacy `played-on` taxonomy with `mvb_device` posts so each
 * physical device carries ownership (post_author), a platform link, purchase
 * date, notes and cover image. Games reference devices through the
 * `videogame_devices` post meta (array of device post IDs).
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class MVB_Devices
 */
class MVB_Devices {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'mvb_device';

	/**
	 * Post meta key storing a videogame's linked device IDs.
	 */
	const GAME_META_KEY = 'videogame_devices';

	/**
	 * Device post meta: platform term ID.
	 */
	const META_PLATFORM = 'device_platform_id';

	/**
	 * Device post meta: purchase date (Y-m-d).
	 */
	const META_PURCHASE = 'device_purchase_date';

	/**
	 * Option flag — set once the played-on migration has completed.
	 */
	const MIGRATION_FLAG = 'mvb_played_on_migrated';

	/**
	 * Nonce for migration action.
	 */
	const MIGRATION_NONCE = 'mvb_migrate_played_on';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_device_meta' ), 10, 2 );
		add_action( 'save_post_videogame', array( __CLASS__, 'save_videogame_devices' ), 10, 2 );
		add_filter( 'register_taxonomy_args', array( __CLASS__, 'hide_played_on_from_menu' ), 10, 2 );
		add_action( 'admin_post_' . self::MIGRATION_NONCE, array( __CLASS__, 'handle_migration' ) );
	}

	/**
	 * Register the device CPT.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Devices', 'mvb' ),
					'singular_name'      => __( 'Device', 'mvb' ),
					'add_new'            => __( 'Add Device', 'mvb' ),
					'add_new_item'       => __( 'Add New Device', 'mvb' ),
					'edit_item'          => __( 'Edit Device', 'mvb' ),
					'new_item'           => __( 'New Device', 'mvb' ),
					'view_item'          => __( 'View Device', 'mvb' ),
					'search_items'       => __( 'Search Devices', 'mvb' ),
					'not_found'          => __( 'No devices found.', 'mvb' ),
					'not_found_in_trash' => __( 'No devices found in Trash.', 'mvb' ),
					'menu_name'          => __( 'Devices', 'mvb' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=videogame',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'author' ),
				'menu_icon'    => 'dashicons-games',
				'has_archive'  => false,
				'rewrite'      => false,
			)
		);
	}

	/**
	 * Register post meta for devices and the videogame→device link.
	 */
	public static function register_meta() {
		register_post_meta(
			self::POST_TYPE,
			self::META_PLATFORM,
			array(
				'type'              => 'integer',
				'description'       => __( 'Linked platform term ID.', 'mvb' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ) {
					return (int) $value;
				},
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_PURCHASE,
			array(
				'type'              => 'string',
				'description'       => __( 'Purchase date (Y-m-d).', 'mvb' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ) {
					$value = sanitize_text_field( (string) $value );
					return preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $value ) ? $value : '';
				},
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_post_meta(
			'videogame',
			self::GAME_META_KEY,
			array(
				'type'              => 'array',
				'description'       => __( 'Device post IDs the game has been played on.', 'mvb' ),
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_device_ids' ),
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Sanitize an array of device post IDs, dropping any that don't resolve to a device post.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,int>
	 */
	public static function sanitize_device_ids( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids    = array_map( 'absint', $value );
		$ids    = array_filter( $ids );
		$result = array();

		foreach ( $ids as $id ) {
			if ( self::POST_TYPE === get_post_type( $id ) ) {
				$result[] = $id;
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Register admin metaboxes on device and videogame edit screens.
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'mvb_device_details',
			__( 'Device Details', 'mvb' ),
			array( __CLASS__, 'render_device_metabox' ),
			self::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'mvb_videogame_devices',
			__( 'Played on (devices)', 'mvb' ),
			array( __CLASS__, 'render_videogame_devices_metabox' ),
			'videogame',
			'side',
			'default'
		);
	}

	/**
	 * Device edit screen: platform select + purchase date.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_device_metabox( $post ) {
		wp_nonce_field( 'mvb_device_details', 'mvb_device_details_nonce' );

		$platform_id   = (int) get_post_meta( $post->ID, self::META_PLATFORM, true );
		$purchase_date = (string) get_post_meta( $post->ID, self::META_PURCHASE, true );

		$platforms = get_terms(
			array(
				'taxonomy'   => 'mvb_platform',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		?>
		<p>
			<label for="mvb_device_platform">
				<strong><?php esc_html_e( 'Platform', 'mvb' ); ?></strong>
			</label>
			<select name="mvb_device_platform" id="mvb_device_platform" class="widefat">
				<option value="0"><?php esc_html_e( '— None —', 'mvb' ); ?></option>
				<?php if ( ! is_wp_error( $platforms ) ) : ?>
					<?php foreach ( $platforms as $platform ) : ?>
						<option value="<?php echo esc_attr( $platform->term_id ); ?>" <?php selected( $platform_id, $platform->term_id ); ?>>
							<?php echo esc_html( $platform->name ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
		</p>
		<p>
			<label for="mvb_device_purchase">
				<strong><?php esc_html_e( 'Purchase Date', 'mvb' ); ?></strong>
			</label>
			<input type="date" name="mvb_device_purchase" id="mvb_device_purchase" value="<?php echo esc_attr( $purchase_date ); ?>" class="widefat" />
		</p>
		<?php
	}

	/**
	 * Videogame edit screen: list devices belonging to the current user.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_videogame_devices_metabox( $post ) {
		wp_nonce_field( 'mvb_videogame_devices', 'mvb_videogame_devices_nonce' );

		$selected = (array) get_post_meta( $post->ID, self::GAME_META_KEY, true );
		$selected = array_map( 'intval', $selected );

		$devices = self::get_user_devices();

		if ( empty( $devices ) ) {
			?>
			<p class="description">
				<?php
				printf(
					wp_kses(
						/* translators: %s: devices admin URL. */
						__( 'No devices registered yet. <a href="%s">Add your first device</a>.', 'mvb' ),
						array( 'a' => array( 'href' => array() ) )
					),
					esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) )
				);
				?>
			</p>
			<?php
			return;
		}
		?>
		<ul class="mvb-device-checklist" style="max-height:200px;overflow:auto;margin:0;">
			<?php foreach ( $devices as $device ) : ?>
				<li>
					<label>
						<input type="checkbox" name="mvb_videogame_devices[]" value="<?php echo esc_attr( $device->ID ); ?>" <?php checked( in_array( $device->ID, $selected, true ) ); ?> />
						<?php echo esc_html( get_the_title( $device ) ); ?>
					</label>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Fetch devices visible to the current user (own + admin sees all).
	 *
	 * @return WP_Post[]
	 */
	private static function get_user_devices() {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			$args['author'] = get_current_user_id();
		}

		return get_posts( $args );
	}

	/**
	 * Save device metabox fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_device_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['mvb_device_details_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mvb_device_details_nonce'] ) ), 'mvb_device_details' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$platform = isset( $_POST['mvb_device_platform'] ) ? absint( $_POST['mvb_device_platform'] ) : 0;
		if ( $platform > 0 ) {
			update_post_meta( $post_id, self::META_PLATFORM, $platform );
		} else {
			delete_post_meta( $post_id, self::META_PLATFORM );
		}

		$purchase = isset( $_POST['mvb_device_purchase'] ) ? sanitize_text_field( wp_unslash( $_POST['mvb_device_purchase'] ) ) : '';
		if ( preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $purchase ) ) {
			update_post_meta( $post_id, self::META_PURCHASE, $purchase );
		} else {
			delete_post_meta( $post_id, self::META_PURCHASE );
		}
	}

	/**
	 * Save the selected devices for a videogame.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_videogame_devices( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['mvb_videogame_devices_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mvb_videogame_devices_nonce'] ) ), 'mvb_videogame_devices' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST['mvb_videogame_devices'] ) ? (array) wp_unslash( $_POST['mvb_videogame_devices'] ) : array();
		$ids = self::sanitize_device_ids( $raw );

		if ( empty( $ids ) ) {
			delete_post_meta( $post_id, self::GAME_META_KEY );
		} else {
			update_post_meta( $post_id, self::GAME_META_KEY, $ids );
		}
	}

	/**
	 * Force the SCF-registered `played-on` taxonomy out of the admin menu.
	 *
	 * @param array  $args     Taxonomy args.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array
	 */
	public static function hide_played_on_from_menu( $args, $taxonomy ) {
		if ( 'played-on' === $taxonomy ) {
			$args['show_in_menu'] = false;
		}

		return $args;
	}

	/**
	 * Check whether migration has already run.
	 */
	public static function has_migrated() {
		return (bool) get_option( self::MIGRATION_FLAG, false );
	}

	/**
	 * Admin-post handler: migrate `played-on` terms into devices.
	 */
	public static function handle_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'mvb' ) );
		}

		check_admin_referer( self::MIGRATION_NONCE );

		$result = self::run_migration();

		$redirect = add_query_arg(
			array(
				'post_type'                => 'videogame',
				'page'                     => 'mvb-tools',
				'tab'                      => 'data-health',
				'mvb_devices_migrated'     => '1',
				'mvb_devices_count'        => (int) $result['devices'],
				'mvb_devices_links'        => (int) $result['links'],
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Convert every `played-on` term into a device, then re-link games.
	 *
	 * Idempotent: re-running simply reuses existing device posts matched by title.
	 *
	 * @return array{devices:int,links:int}
	 */
	public static function run_migration() {
		$devices_created = 0;
		$links_added     = 0;

		if ( ! taxonomy_exists( 'played-on' ) ) {
			update_option( self::MIGRATION_FLAG, 1 );
			return array(
				'devices' => 0,
				'links'   => 0,
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'played-on',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			update_option( self::MIGRATION_FLAG, 1 );
			return array(
				'devices' => 0,
				'links'   => 0,
			);
		}

		$user_id = get_current_user_id();

		foreach ( $terms as $term ) {
			$device_id = self::find_or_create_device( $term->name, $user_id );
			if ( $device_id <= 0 ) {
				continue;
			}

			if ( self::was_just_created( $device_id ) ) {
				++$devices_created;
			}

			$games = get_posts(
				array(
					'post_type'      => 'videogame',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'tax_query'      => array(
						array(
							'taxonomy' => 'played-on',
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						),
					),
					'fields'         => 'ids',
				)
			);

			foreach ( $games as $game_id ) {
				$existing   = (array) get_post_meta( $game_id, self::GAME_META_KEY, true );
				$normalized = array_map( 'intval', $existing );

				if ( in_array( $device_id, $normalized, true ) ) {
					continue;
				}

				$normalized[] = $device_id;
				update_post_meta( $game_id, self::GAME_META_KEY, self::sanitize_device_ids( $normalized ) );
				++$links_added;
			}
		}

		update_option( self::MIGRATION_FLAG, 1 );

		return array(
			'devices' => $devices_created,
			'links'   => $links_added,
		);
	}

	/**
	 * Return an existing device by title or create a new one for the given owner.
	 *
	 * @param string $title   Device title.
	 * @param int    $user_id Owner user ID.
	 * @return int
	 */
	private static function find_or_create_device( $title, $user_id ) {
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'title'          => $title,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			self::flag_created( (int) $existing[0], false );
			return (int) $existing[0];
		}

		$device_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_author' => $user_id,
			)
		);

		if ( is_wp_error( $device_id ) || ! $device_id ) {
			return 0;
		}

		self::flag_created( (int) $device_id, true );
		return (int) $device_id;
	}

	/**
	 * Tiny request-lifetime flag so callers can tell whether find_or_create_device
	 * inserted a new post or reused an existing one.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $created Whether it was just created.
	 */
	private static function flag_created( $post_id, $created ) {
		static $flags = array();

		$flags[ $post_id ] = (bool) $created;

		$GLOBALS['mvb_device_created_flags'] = $flags;
	}

	/**
	 * Read the request-lifetime creation flag.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function was_just_created( $post_id ) {
		$flags = isset( $GLOBALS['mvb_device_created_flags'] ) && is_array( $GLOBALS['mvb_device_created_flags'] )
			? $GLOBALS['mvb_device_created_flags']
			: array();

		return ! empty( $flags[ $post_id ] );
	}
}
