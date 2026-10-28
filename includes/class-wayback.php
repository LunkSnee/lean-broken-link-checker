<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_BLC_Wayback {

	/**
	 * Query the Wayback Machine Availability API for an archived snapshot.
	 */
	public static function lookup_snapshot( $url ) {
		// Validate URL before outbound request
		if ( ! wp_http_validate_url( $url ) ) {
			return null;
		}

		$api_endpoint = 'https://archive.org/wayback/available?url=' . rawurlencode( $url );

		$response = wp_remote_get(
			$api_endpoint,
			array(
				'timeout'    => 5,
				'user-agent' => 'WordPress/Lean-Link-Checker; ' . home_url(),
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['archived_snapshots']['closest']['available'] ) && ! empty( $data['archived_snapshots']['closest']['url'] ) ) {
			$snapshot_url = esc_url_raw( $data['archived_snapshots']['closest']['url'] );
			// Enforce HTTPS on web.archive.org links
			if ( strpos( $snapshot_url, 'http://web.archive.org' ) === 0 ) {
				$snapshot_url = str_replace( 'http://web.archive.org', 'https://web.archive.org', $snapshot_url );
			}
			// Only ever offer a snapshot on the archive itself as a replacement link
			if ( strpos( $snapshot_url, 'https://web.archive.org/' ) !== 0 ) {
				return null;
			}

			return array(
				'url'       => $snapshot_url,
				'timestamp' => sanitize_text_field( $data['archived_snapshots']['closest']['timestamp'] ),
				'status'    => intval( $data['archived_snapshots']['closest']['status'] ),
			);
		}

		return null;
	}

	/**
	 * Replace a broken link in a post with a replacement URL (e.g. Wayback snapshot).
	 */
	public static function replace_link_in_post( $post_id, $old_url, $new_url ) {
		$post_id = intval( $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'unauthorized', 'You do not have permission to edit this post.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', 'Post not found.' );
		}

		// Security: Validate new URL protocol strictly (only http/https)
		$scheme = wp_parse_url( $new_url, PHP_URL_SCHEME );
		if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_protocol', 'Replacement URL must use HTTP or HTTPS protocol.' );
		}

		$content = $post->post_content;
		$new_url = esc_url_raw( $new_url );

		// Replace only whole href="..." / src="..." values. The scanner stores URLs with
		// entities decoded, while post content usually has "&amp;", so match both forms.
		$forms = array_unique( array( $old_url, esc_attr( $old_url ), str_replace( '&', '&#038;', $old_url ) ) );
		$alternation = implode( '|', array_map( function ( $form ) {
			return preg_quote( $form, '/' );
		}, $forms ) );
		$updated_content = preg_replace_callback(
			'/((?:href|src)=)([\'"])(?:' . $alternation . ')\2/i',
			function ( $m ) use ( $new_url ) {
				return $m[1] . $m[2] . esc_attr( $new_url ) . $m[2];
			},
			$content
		);

		if ( null === $updated_content || $updated_content === $content ) {
			return new WP_Error( 'link_not_found', 'The specified URL was not found in a link or image in the post content.' );
		}

		// wp_update_post() expects slashed data; without wp_slash() every backslash in the
		// post (e.g. "\u0022" escapes in block attributes) would be stripped.
		$update_result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $updated_content,
				)
			),
			true
		);

		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		Lean_BLC_DB::delete_link( $post_id, $old_url );
		return true;
	}
}
