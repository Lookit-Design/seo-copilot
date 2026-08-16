<?php
/**
 * OpenRouter API handler – Auto SEO for Yoast
 *
 * Fetches related keyphrases from OpenRouter using the post title + content.
 * Uses openrouter/free as the default model (auto-selects from all active
 * free models). User can override with any specific :free model ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASY_OpenRouter {

	const API_URL       = 'https://openrouter.ai/api/v1/chat/completions';
	const DEFAULT_MODEL = 'meta-llama/llama-3.3-70b-instruct:free';

	/**
	 * Ensure a model ID is always a free-tier model.
	 * If someone selected 'openrouter/auto' (which can route to paid models),
	 * or a model without the :free suffix, fall back to the default free model.
	 */
	private static function ensure_free_model( $model ) {
		// These routing aliases can select paid models — never allow them
		$disallowed = array( 'openrouter/auto', 'openrouter/free', 'auto' );
		if ( in_array( $model, $disallowed, true ) ) {
			return self::DEFAULT_MODEL;
		}
		// Any model without :free suffix could be paid — force the suffix
		if ( strpos( $model, ':free' ) === false ) {
			return self::DEFAULT_MODEL;
		}
		return $model;
	}

	/**
	 * Fetch related keyphrases for a post.
	 *
	 * @param WP_Post $post
	 * @param int     $count   Number of related keyphrases to return (1–5).
	 * @param string  $model   OpenRouter model ID.
	 * @return array|WP_Error  Array of keyphrase strings, or WP_Error on failure.
	 */
	public static function get_related_keyphrases( WP_Post $post, $count = 3, $model = self::DEFAULT_MODEL ) {
		$api_key = get_option( 'asy_openrouter_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'asy_no_api_key', 'OpenRouter API key is not set.' );
		}

		// Always enforce free models — never charge the user
		$model = self::ensure_free_model( $model );

		$count   = max( 1, min( 5, (int) $count ) );
		$title   = sanitize_text_field( $post->post_title );
		$content = wp_trim_words( wp_strip_all_tags( $post->post_content ), 300, '' );

		// If there's no content, just use the title
		$context = ! empty( $content )
			? "Title: {$title}\n\nContent excerpt:\n{$content}"
			: "Title: {$title}";

		$prompt  = "You are an SEO expert. Based on the following blog post, return exactly {$count} related SEO keyphrases that would complement the main focus keyword.\n\n";
		$prompt .= "Rules:\n";
		$prompt .= "- Each keyphrase should be 2–4 words\n";
		$prompt .= "- They should be semantically related to the main topic\n";
		$prompt .= "- They should have realistic search intent\n";
		$prompt .= "- Return ONLY a JSON array of strings, nothing else — no explanation, no markdown, no code fences\n";
		$prompt .= "- Example format: [\"keyphrase one\",\"keyphrase two\",\"keyphrase three\"]\n\n";
		$prompt .= "Post:\n{$context}";

		$body = wp_json_encode(
			array(
				'model'       => $model,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'max_tokens'  => 150,
				'temperature' => 0.3,
			)
		);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => get_site_url(),
					'X-Title'       => get_bloginfo( 'name' ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code !== 200 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "HTTP {$code}";
			return new WP_Error( 'asy_api_error', $msg );
		}

		$text = isset( $data['choices'][0]['message']['content'] )
			? trim( $data['choices'][0]['message']['content'] )
			: '';

		if ( empty( $text ) ) {
			return new WP_Error( 'asy_empty_response', 'OpenRouter returned an empty response.' );
		}

		// Strip any accidental markdown code fences (handles multiline and inline fences)
		$text = preg_replace( '/```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/```/', '', $text );
		$text = trim( $text );

		// First attempt: direct JSON decode of the whole text
		$keyphrases = json_decode( $text, true );

		// Second attempt: extract a JSON array from anywhere in the response.
		// Some models prefix their output with prose like "Here are your keyphrases:".
		if ( ! is_array( $keyphrases ) ) {
			if ( preg_match( '/\[.*?\]/s', $text, $matches ) ) {
				$keyphrases = json_decode( $matches[0], true );
			}
		}

		if ( ! is_array( $keyphrases ) || empty( $keyphrases ) ) {
			return new WP_Error( 'asy_parse_error', 'Could not parse keyphrases from response: ' . $text );
		}

		// Sanitize and limit
		$keyphrases = array_slice(
			array_map( 'sanitize_text_field', array_filter( $keyphrases ) ),
			0,
			$count
		);

		return $keyphrases;
	}

	/**
	 * Write related keyphrases into Yoast's meta field.
	 *
	 * Yoast Premium stores additional/related keyphrases under the meta key
	 * _yoast_wpseo_focuskeywords as a JSON-encoded string.
	 *
	 * The correct format is a JSON array of objects, each with:
	 *   { "keyword": "some phrase", "score": "" }
	 *
	 * Score is left empty — Yoast recomputes it in JS the next time the post
	 * is opened and saved in the editor.
	 *
	 * @param int   $post_id
	 * @param array $keyphrases  Plain array of keyphrase strings.
	 */
	public static function save_related_keyphrases( $post_id, array $keyphrases ) {
		if ( empty( $keyphrases ) ) {
			return;
		}

		$objects = array();
		foreach ( $keyphrases as $kp ) {
			$objects[] = array(
				'keyword' => sanitize_text_field( $kp ),
				'score'   => '',
			);
		}

		// Yoast expects a JSON-encoded string, not a serialized PHP array
		update_post_meta( $post_id, '_yoast_wpseo_focuskeywords', wp_json_encode( $objects ) );
	}

	/**
	 * Returns the list of well-known free model IDs for the settings dropdown.
	 * The plugin also fetches live free models from the OpenRouter API when
	 * an API key is saved — this is just the hardcoded fallback list.
	 *
	 * @return array  slug => label
	 */
	public static function get_known_free_models() {
		return array(
			'meta-llama/llama-3.3-70b-instruct:free'      => 'Meta: Llama 3.3 70B Instruct (free) — Recommended',
			'deepseek/deepseek-chat-v3-0324:free'         => 'DeepSeek: V3 0324 (free)',
			'deepseek/deepseek-r1:free'                   => 'DeepSeek: R1 (free)',
			'qwen/qwen3-235b-a22b:free'                   => 'Qwen3 235B A22B (free)',
			'qwen/qwen3-30b-a3b:free'                     => 'Qwen3 30B A3B (free)',
			'mistralai/mistral-7b-instruct:free'          => 'Mistral: 7B Instruct (free)',
			'google/gemma-3-27b-it:free'                  => 'Google: Gemma 3 27B (free)',
			'nvidia/llama-3.1-nemotron-70b-instruct:free' => 'NVIDIA: Nemotron 70B (free)',
		);
	}
}
