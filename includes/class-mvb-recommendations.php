<?php
/**
 * Recommendations engine and admin UI.
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class MVB_Recommendations
 */
class MVB_Recommendations {

	/**
	 * Recommendation action nonce.
	 */
	const ACTION_NONCE = 'mvb_set_playing';

	/**
	 * Initialize recommendation hooks.
	 */
	public static function init() {
		add_action( 'admin_post_mvb_set_playing', array( __CLASS__, 'handle_set_playing' ) );
	}

	/**
	 * Render recommendations page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$length_pref = isset( $_GET['length_pref'] ) ? sanitize_key( wp_unslash( $_GET['length_pref'] ) ) : 'balanced';
		$length_pref = self::sanitize_length_preference( $length_pref );

		$profile         = self::build_player_profile( false );
		$recommendations = self::get_recommendations( $length_pref, 9 );
		$updated         = isset( $_GET['mvb_recommendation_updated'] );

		?>
		<div class="wrap mvb-recommendations">
			<h1><?php esc_html_e( 'Next Game Recommendations', 'mvb' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Recommendations are based on your finished games, preferred platforms, completion pace, and HowLongToBeat durations.', 'mvb' ); ?>
			</p>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Game moved to Playing.', 'mvb' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="mvb-stats-grid">
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( (int) $profile['finished_count'] ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Finished Games Used', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value">
						<?php
						echo null === $profile['avg_length_hours'] ?
							esc_html__( 'N/A', 'mvb' ) :
							esc_html( number_format_i18n( $profile['avg_length_hours'], 1 ) . 'h' );
						?>
					</div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Average Finished Length', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value">
						<?php
						echo null === $profile['avg_completion_days'] ?
							esc_html__( 'N/A', 'mvb' ) :
							esc_html( number_format_i18n( $profile['avg_completion_days'], 1 ) . 'd' );
						?>
					</div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Average Time Between Finishes', 'mvb' ); ?></div>
				</div>
			</div>

			<form method="get" class="mvb-recommendation-filters">
				<input type="hidden" name="post_type" value="videogame" />
				<input type="hidden" name="page" value="mvb-tools" />
				<input type="hidden" name="tab" value="recommendations" />
				<label for="mvb-length-pref"><?php esc_html_e( 'Length Preference', 'mvb' ); ?></label>
				<select id="mvb-length-pref" name="length_pref">
					<option value="balanced" <?php selected( $length_pref, 'balanced' ); ?>><?php esc_html_e( 'Balanced', 'mvb' ); ?></option>
					<option value="short" <?php selected( $length_pref, 'short' ); ?>><?php esc_html_e( 'Short', 'mvb' ); ?></option>
					<option value="medium" <?php selected( $length_pref, 'medium' ); ?>><?php esc_html_e( 'Medium', 'mvb' ); ?></option>
					<option value="long" <?php selected( $length_pref, 'long' ); ?>><?php esc_html_e( 'Long', 'mvb' ); ?></option>
				</select>
				<?php submit_button( __( 'Refresh', 'mvb' ), 'secondary', '', false ); ?>
			</form>

			<?php if ( empty( $recommendations ) ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'No recommendations yet. Add some backlog/wishlist games and mark more completed games to train the profile.', 'mvb' ); ?></p>
				</div>
			<?php else : ?>
				<div class="mvb-recommendation-grid">
					<?php foreach ( $recommendations as $recommendation ) : ?>
						<?php
						$post_id      = (int) $recommendation['post_id'];
						$thumbnail    = self::get_cover_url( $post_id, 'medium' );
						$status_label = ! empty( $recommendation['status'] ) ? ucfirst( $recommendation['status'] ) : __( 'Untracked', 'mvb' );
						?>
						<div class="mvb-recommendation-card">
							<div class="mvb-recommendation-cover">
								<?php if ( $thumbnail ) : ?>
									<img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>" />
								<?php else : ?>
									<div class="mvb-recommendation-placeholder"><?php esc_html_e( 'No Cover', 'mvb' ); ?></div>
								<?php endif; ?>
							</div>
							<div class="mvb-recommendation-body">
								<h3>
									<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>">
										<?php echo esc_html( get_the_title( $post_id ) ); ?>
									</a>
								</h3>
								<div class="mvb-recommendation-meta">
									<span><?php echo esc_html( $status_label ); ?></span>
									<span>
										<?php
										echo null === $recommendation['hltb_hours'] ?
											esc_html__( 'Length unknown', 'mvb' ) :
											esc_html( number_format_i18n( $recommendation['hltb_hours'], 1 ) . 'h' );
										?>
									</span>
									<span><?php echo esc_html( sprintf( __( 'Score %d', 'mvb' ), (int) round( $recommendation['score'] ) ) ); ?></span>
								</div>

								<?php if ( ! empty( $recommendation['platform_names'] ) ) : ?>
									<div class="mvb-recommendation-tags">
										<?php foreach ( $recommendation['platform_names'] as $platform_name ) : ?>
											<span><?php echo esc_html( $platform_name ); ?></span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>

								<ul class="mvb-recommendation-reasons">
									<?php foreach ( array_slice( $recommendation['reasons'], 0, 3 ) as $reason ) : ?>
										<li><?php echo esc_html( $reason ); ?></li>
									<?php endforeach; ?>
								</ul>

								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="mvb_set_playing" />
									<input type="hidden" name="game_id" value="<?php echo esc_attr( $post_id ); ?>" />
									<input type="hidden" name="length_pref" value="<?php echo esc_attr( $length_pref ); ?>" />
									<?php wp_nonce_field( self::ACTION_NONCE, 'mvb_set_playing_nonce' ); ?>
									<button type="submit" class="button button-primary">
										<?php esc_html_e( 'Set to Playing', 'mvb' ); ?>
									</button>
								</form>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Resolve a game cover URL with a fallback to the SCF `videogame_cover` attachment.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $size    Image size.
	 * @return string
	 */
	public static function get_cover_url( $post_id, $size = 'medium' ) {
		$url = get_the_post_thumbnail_url( $post_id, $size );
		if ( $url ) {
			return $url;
		}

		$attachment_id = (int) get_post_meta( $post_id, 'videogame_cover', true );
		if ( $attachment_id > 0 ) {
			$fallback = wp_get_attachment_image_url( $attachment_id, $size );
			if ( $fallback ) {
				return $fallback;
			}
		}

		return '';
	}

	/**
	 * Handle "Set to Playing" action.
	 */
	public static function handle_set_playing() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'mvb' ) );
		}

		check_admin_referer( self::ACTION_NONCE, 'mvb_set_playing_nonce' );

		$game_id = isset( $_POST['game_id'] ) ? absint( $_POST['game_id'] ) : 0;
		if ( $game_id > 0 && current_user_can( 'edit_post', $game_id ) && 'videogame' === get_post_type( $game_id ) ) {
			if ( class_exists( 'MVB_Data_Health' ) ) {
				MVB_Data_Health::set_status_taxonomy( $game_id, 'playing' );
				MVB_Data_Health::sync_status_for_post( $game_id, true );
			} else {
				wp_set_object_terms( $game_id, 'playing', 'mvb_game_status', false );
			}
		}

		$length_pref = isset( $_POST['length_pref'] ) ? sanitize_key( wp_unslash( $_POST['length_pref'] ) ) : 'balanced';
		$length_pref = self::sanitize_length_preference( $length_pref );

		$redirect = add_query_arg(
			array(
				'post_type'                   => 'videogame',
				'page'                        => 'mvb-tools',
				'tab'                         => 'recommendations',
				'length_pref'                 => $length_pref,
				'mvb_recommendation_updated'  => '1',
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Build recommendations list.
	 *
	 * @param string $length_pref Length preference.
	 * @param int    $limit Number of recommendations.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_recommendations( $length_pref, $limit ) {
		$profile    = self::build_player_profile( true );
		$candidates = get_posts(
			array(
				'post_type'      => 'videogame',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$recommendations = array();
		$fetch_budget    = 6;

		foreach ( $candidates as $candidate ) {
			$post_id = (int) $candidate->ID;
			$status  = self::get_primary_status( $post_id );

			if ( in_array( $status, array( 'finished', 'playing' ), true ) ) {
				continue;
			}

			$platform_slugs = wp_get_post_terms( $post_id, 'mvb_platform', array( 'fields' => 'slugs' ) );
			$platform_names = wp_get_post_terms( $post_id, 'mvb_platform', array( 'fields' => 'names' ) );
			$hltb_hours     = self::get_existing_hltb_hours( $post_id );

			if ( null === $hltb_hours && $fetch_budget > 0 ) {
				--$fetch_budget;
				$hltb_hours = self::get_or_fetch_hltb_hours( $post_id, $candidate->post_title );
			}

			$score_data = self::score_candidate(
				array(
					'status'         => $status,
					'hltb_hours'     => $hltb_hours,
					'platform_slugs' => is_array( $platform_slugs ) ? $platform_slugs : array(),
					'platform_names' => is_array( $platform_names ) ? $platform_names : array(),
				),
				$profile,
				$length_pref
			);

			$recommendations[] = array(
				'post_id'        => $post_id,
				'score'          => $score_data['score'],
				'reasons'        => $score_data['reasons'],
				'status'         => $status,
				'hltb_hours'     => $hltb_hours,
				'platform_names' => is_array( $platform_names ) ? $platform_names : array(),
			);
		}

		usort(
			$recommendations,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $recommendations, 0, absint( $limit ) );
	}

	/**
	 * Build user play profile from completed games.
	 *
	 * @param bool $allow_hltb_fetch Whether to fetch missing HLTB data.
	 * @return array<string, mixed>
	 */
	private static function build_player_profile( $allow_hltb_fetch ) {
		$games = get_posts(
			array(
				'post_type'      => 'videogame',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$finished_count        = 0;
		$length_hours          = array();
		$platform_counts       = array();
		$completion_timestamps = array();
		$fetch_budget          = 3;

		foreach ( $games as $game ) {
			$post_id = (int) $game->ID;
			if ( ! self::is_finished( $post_id ) ) {
				continue;
			}

			++$finished_count;

			$hours = self::get_existing_hltb_hours( $post_id );
			if ( null === $hours && $allow_hltb_fetch && $fetch_budget > 0 ) {
				--$fetch_budget;
				$hours = self::get_or_fetch_hltb_hours( $post_id, $game->post_title );
			}
			if ( null !== $hours ) {
				$length_hours[] = $hours;
			}

			$platform_slugs = wp_get_post_terms( $post_id, 'mvb_platform', array( 'fields' => 'slugs' ) );
			if ( is_array( $platform_slugs ) ) {
				foreach ( $platform_slugs as $slug ) {
					if ( ! isset( $platform_counts[ $slug ] ) ) {
						$platform_counts[ $slug ] = 0;
					}
					++$platform_counts[ $slug ];
				}
			}

			$completion_value = get_post_meta( $post_id, 'videogame_completion_date', true );
			$timestamp        = self::completion_value_to_timestamp( $completion_value );
			if ( $timestamp > 0 ) {
				$completion_timestamps[] = $timestamp;
			}
		}

		$avg_length_hours = self::average( $length_hours );
		sort( $completion_timestamps );

		$interval_days = array();
		for ( $i = 1; $i < count( $completion_timestamps ); ++$i ) {
			$diff = ( $completion_timestamps[ $i ] - $completion_timestamps[ $i - 1 ] ) / DAY_IN_SECONDS;
			if ( $diff > 0 ) {
				$interval_days[] = $diff;
			}
		}

		$avg_completion_days = self::average( $interval_days );
		$avg_hours_per_day   = null;
		if ( null !== $avg_length_hours && null !== $avg_completion_days && $avg_completion_days > 0 ) {
			$avg_hours_per_day = $avg_length_hours / $avg_completion_days;
		}

		arsort( $platform_counts );
		$max_platform_count = ! empty( $platform_counts ) ? max( $platform_counts ) : 0;
		$platform_weights   = array();
		foreach ( $platform_counts as $slug => $count ) {
			$platform_weights[ $slug ] = $max_platform_count > 0 ? ( $count / $max_platform_count ) : 0;
		}

		$top_platform_names = array();
		foreach ( array_slice( array_keys( $platform_counts ), 0, 3 ) as $top_slug ) {
			$term = get_term_by( 'slug', $top_slug, 'mvb_platform' );
			if ( $term && ! is_wp_error( $term ) ) {
				$top_platform_names[] = $term->name;
			}
		}

		return array(
			'finished_count'      => $finished_count,
			'avg_length_hours'    => $avg_length_hours,
			'avg_completion_days' => $avg_completion_days,
			'avg_hours_per_day'   => $avg_hours_per_day,
			'platform_weights'    => $platform_weights,
			'top_platform_names'  => $top_platform_names,
		);
	}

	/**
	 * Score a candidate game.
	 *
	 * @param array<string, mixed> $candidate Candidate game data.
	 * @param array<string, mixed> $profile User profile.
	 * @param string               $length_pref Length preference.
	 * @return array<string, mixed>
	 */
	private static function score_candidate( $candidate, $profile, $length_pref ) {
		$score   = 20.0;
		$reasons = array();

		$status = isset( $candidate['status'] ) ? (string) $candidate['status'] : '';
		if ( 'backlog' === $status ) {
			$score    += 12;
			$reasons[] = __( 'Backlog game: ready to start now.', 'mvb' );
		} elseif ( 'wishlist' === $status ) {
			$score    += 6;
			$reasons[] = __( 'Wishlist fit based on your profile.', 'mvb' );
		} else {
			$score += 3;
		}

		$platform_weights = isset( $profile['platform_weights'] ) && is_array( $profile['platform_weights'] ) ? $profile['platform_weights'] : array();
		$platform_slugs   = isset( $candidate['platform_slugs'] ) && is_array( $candidate['platform_slugs'] ) ? $candidate['platform_slugs'] : array();
		$platform_names   = isset( $candidate['platform_names'] ) && is_array( $candidate['platform_names'] ) ? $candidate['platform_names'] : array();

		$platform_fit = 0;
		foreach ( $platform_slugs as $slug ) {
			if ( isset( $platform_weights[ $slug ] ) ) {
				$platform_fit += 22 * $platform_weights[ $slug ];
			}
		}
		if ( $platform_fit > 0 ) {
			$score += min( 24, $platform_fit );
			if ( ! empty( $platform_names ) ) {
				$reasons[] = sprintf(
					/* translators: %s: platform names. */
					__( 'Matches your most played platform(s): %s.', 'mvb' ),
					implode( ', ', array_slice( $platform_names, 0, 2 ) )
				);
			}
		}

		$hltb_hours = isset( $candidate['hltb_hours'] ) ? $candidate['hltb_hours'] : null;
		$avg_length = $profile['avg_length_hours'];
		if ( null !== $hltb_hours && null !== $avg_length ) {
			$distance   = abs( $hltb_hours - $avg_length ) / max( 1, $avg_length );
			$length_fit = max( 0, 30 * ( 1 - $distance ) );
			$score     += $length_fit;
			$reasons[]  = sprintf(
				/* translators: 1: game length, 2: average length. */
				__( 'Length fit: %1$.1fh vs your average %2$.1fh.', 'mvb' ),
				$hltb_hours,
				$avg_length
			);
		} elseif ( null === $hltb_hours ) {
			$score    -= 7;
			$reasons[] = __( 'HLTB length missing, confidence is lower.', 'mvb' );
		}

		if ( null !== $hltb_hours ) {
			$target_hours = self::get_length_target( $length_pref );
			if ( null !== $target_hours ) {
				$distance  = abs( $hltb_hours - $target_hours ) / max( 1, $target_hours );
				$pref_fit  = max( 0, 20 * ( 1 - $distance ) );
				$score    += $pref_fit;
				$reasons[] = sprintf(
					/* translators: %s: selected preference. */
					__( 'Matches your current "%s" length preference.', 'mvb' ),
					$length_pref
				);
			}
		}

		$hours_per_day = $profile['avg_hours_per_day'];
		$avg_days      = $profile['avg_completion_days'];
		if ( null !== $hltb_hours && null !== $hours_per_day && null !== $avg_days && $hours_per_day > 0 ) {
			$expected_days = $hltb_hours / max( 0.25, $hours_per_day );
			$distance      = abs( $expected_days - $avg_days ) / max( 1, $avg_days );
			$pace_fit      = max( 0, 18 * ( 1 - $distance ) );
			$score        += $pace_fit;
			$reasons[]     = sprintf(
				/* translators: %s: expected days to finish. */
				__( 'At your pace, estimated completion time is about %s days.', 'mvb' ),
				number_format_i18n( $expected_days, 1 )
			);
		}

		$score = max( 0, min( 100, round( $score, 1 ) ) );

		if ( empty( $reasons ) ) {
			$reasons[] = __( 'General fit based on your finished games.', 'mvb' );
		}

		return array(
			'score'   => $score,
			'reasons' => array_values( array_unique( $reasons ) ),
		);
	}

	/**
	 * Get or fetch HLTB hours for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title Game title.
	 * @return float|null
	 */
	private static function get_or_fetch_hltb_hours( $post_id, $title ) {
		$hours = self::get_existing_hltb_hours( $post_id );
		if ( null !== $hours ) {
			return $hours;
		}

		if ( empty( $title ) || ! class_exists( 'MVB_HLTB_API' ) ) {
			return null;
		}

		$cache_key = 'mvb_hltb_lookup_' . md5( strtolower( trim( $title ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			if ( 'none' === $cached ) {
				return null;
			}
			return (float) $cached;
		}

		$result = MVB_HLTB_API::search_game( $title );
		if ( is_wp_error( $result ) ) {
			set_transient( $cache_key, 'none', DAY_IN_SECONDS );
			return null;
		}

		$hours = self::extract_hltb_hours_from_api_result( $result );
		if ( null === $hours ) {
			set_transient( $cache_key, 'none', DAY_IN_SECONDS );
			return null;
		}

		update_post_meta( $post_id, 'hltb_main_story', $hours );
		set_transient( $cache_key, $hours, WEEK_IN_SECONDS );
		return $hours;
	}

	/**
	 * Read existing HLTB hours from meta.
	 *
	 * @param int $post_id Post ID.
	 * @return float|null
	 */
	private static function get_existing_hltb_hours( $post_id ) {
		$value = get_post_meta( $post_id, 'hltb_main_story', true );
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$value = (float) $value;
		if ( $value <= 0 ) {
			return null;
		}

		// If old values were saved in minutes, normalize to hours.
		if ( $value > 200 ) {
			$value = $value / 60;
		}

		return round( $value, 1 );
	}

	/**
	 * Extract HLTB main story duration from API result.
	 *
	 * @param array<string, mixed> $result API result.
	 * @return float|null
	 */
	private static function extract_hltb_hours_from_api_result( $result ) {
		$candidates = array( 'comp_main', 'main_story', 'mainStory', 'gameplayMain' );

		foreach ( $candidates as $key ) {
			if ( isset( $result[ $key ] ) && is_numeric( $result[ $key ] ) ) {
				$value = (float) $result[ $key ];
				if ( $value > 10000 ) {
					$value = $value / 3600;
				} elseif ( $value > 80 ) {
					$value = $value / 60;
				}

				if ( $value > 0 ) {
					return round( $value, 1 );
				}
			}
		}

		return null;
	}

	/**
	 * Determine primary status for a game.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_primary_status( $post_id ) {
		if ( class_exists( 'MVB_Data_Health' ) ) {
			return MVB_Data_Health::get_status_slug( $post_id );
		}

		$slugs = wp_get_post_terms( $post_id, 'mvb_game_status', array( 'fields' => 'slugs' ) );
		if ( empty( $slugs ) || is_wp_error( $slugs ) ) {
			return '';
		}

		return sanitize_title( (string) reset( $slugs ) );
	}

	/**
	 * Check if a post is finished.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_finished( $post_id ) {
		return 'finished' === self::get_primary_status( $post_id );
	}

	/**
	 * Convert completion date values to timestamp.
	 *
	 * @param mixed $value Completion date.
	 * @return int
	 */
	private static function completion_value_to_timestamp( $value ) {
		if ( class_exists( 'MVB_Data_Health' ) ) {
			return MVB_Data_Health::completion_value_to_timestamp( $value );
		}

		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		if ( preg_match( '/^[0-9]{8}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'Ymd', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		if ( preg_match( '/^[0-9]{10}$/', $value ) ) {
			return (int) $value;
		}

		if ( preg_match( '/^[0-9]{2}\/[0-9]{2}\/[0-9]{4}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'd/m/Y', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		if ( preg_match( '/^[0-9]{4}\/[0-9]{2}\/[0-9]{2}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'Y/m/d', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? 0 : (int) $timestamp;
	}

	/**
	 * Get average from numeric array.
	 *
	 * @param array<int, float|int> $values Values.
	 * @return float|null
	 */
	private static function average( $values ) {
		if ( empty( $values ) ) {
			return null;
		}

		return array_sum( $values ) / count( $values );
	}

	/**
	 * Sanitize length preference value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_length_preference( $value ) {
		$allowed = array( 'balanced', 'short', 'medium', 'long' );
		return in_array( $value, $allowed, true ) ? $value : 'balanced';
	}

	/**
	 * Map length preference to target hours.
	 *
	 * @param string $length_pref Length preference.
	 * @return float|null
	 */
	private static function get_length_target( $length_pref ) {
		switch ( $length_pref ) {
			case 'short':
				return 12.0;
			case 'medium':
				return 25.0;
			case 'long':
				return 45.0;
			default:
				return null;
		}
	}
}
