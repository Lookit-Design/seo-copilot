<?php
/**
 * Keyphrase Engine – Auto SEO for Yoast
 *
 * Generates related keyphrases using two free, zero-timeout methods:
 *
 *  Step 1 — Content extraction
 *    Strip the post content down to plain text, tokenise into bigrams and
 *    trigrams, score by frequency × proximity-to-title, and surface the
 *    top candidates.  Pure PHP, no HTTP calls.
 *
 *  Step 2 — Datamuse expansion (optional, ~100 ms)
 *    For each candidate phrase, hit the free Datamuse API
 *    (api.datamuse.com) to find semantically related terms that have
 *    realistic search intent.  No API key, no account, no cost.
 *
 * The two sets are merged, deduplicated, and the top $count are returned.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASY_Keyphrase_Engine {

	const DATAMUSE_URL = 'https://api.datamuse.com/words';

	// ── Public entry point ────────────────────────────────────────────────────

	/**
	 * Generate related keyphrases for a post.
	 *
	 * @param  WP_Post $post
	 * @param  int     $count   How many keyphrases to return (1–10).
	 * @return array|WP_Error   Array of keyphrase strings, or WP_Error.
	 */
	public static function get_related_keyphrases( WP_Post $post, $count = 3 ) {
		$count = max( 1, min( 10, (int) $count ) );

		// ── Step 1: extract top phrases from post content ─────────────────
		$content_phrases = self::extract_from_content( $post, $count * 3 );

		if ( empty( $content_phrases ) ) {
			// Fallback: use the title words as the seed
			$content_phrases = self::title_fallback( $post->post_title, $count );
		}

		// ── Step 2: expand with Datamuse ──────────────────────────────────
		$seed     = sanitize_text_field( $post->post_title );
		$expanded = self::datamuse_expand( $seed, $content_phrases, $count * 2 );

		// ── Merge, deduplicate, limit ─────────────────────────────────────
		$all = array_merge( $content_phrases, $expanded );
		$all = self::deduplicate( $all );

		// Don't repeat the focus keyphrase's words. Read the keyphrase actually
		// set on the post (Auto SEO sets it just before this runs); fall back to
		// the post title. Any related phrase that shares a significant (non-stop)
		// word with the focus is dropped, so related terms stay complementary
		// rather than echoing the main keyphrase.
		$focus_kw = (string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
		if ( '' === $focus_kw ) {
			$focus_kw = sanitize_text_field( $post->post_title );
		}
		$focus_words = self::significant_words( $focus_kw );
		$focus_full  = strtolower( trim( $focus_kw ) );

		$filtered = array_filter(
			$all,
			function ( $kp ) use ( $focus_words, $focus_full ) {
				$kp_l = strtolower( trim( $kp ) );
				if ( '' === $kp_l || $kp_l === $focus_full ) {
					return false;
				}
				foreach ( self::significant_words( $kp ) as $w => $_ ) {
					if ( isset( $focus_words[ $w ] ) ) {
						return false;
					}
				}
				return true;
			}
		);

		// If the word-overlap rule removed everything, relax to "not identical to
		// the focus" so we still return some related terms.
		if ( empty( $filtered ) ) {
			$filtered = array_filter(
				$all,
				function ( $kp ) use ( $focus_full ) {
					return strtolower( trim( $kp ) ) !== $focus_full;
				}
			);
		}

		$all = array_values( array_slice( $filtered, 0, $count ) );

		if ( empty( $all ) ) {
			return new WP_Error( 'asy_no_keyphrases', 'Could not extract keyphrases from post content.' );
		}

		return $all;
	}

	/**
	 * Public wrapper — lets other classes (e.g. the processor) get the best
	 * available plain text for a post, including Elementor fallback.
	 */
	public static function get_content_text( WP_Post $post ) {
		return self::get_plain_text( $post );
	}

	/**
	 * Public wrapper — exposes the stop word map for use outside this class.
	 */
	public static function get_stop_words() {
		return self::stop_words();
	}

	// ── Step 1: content extraction ────────────────────────────────────────────

	/**
	 * Extract high-value bigrams and trigrams from post content.
	 * Falls back to _elementor_data if post_content is sparse.
	 *
	 * Scoring:
	 *   - base score = frequency in body text
	 *   - ×2 if the phrase appears in the post title
	 *   - ×1.5 if the phrase appears in a heading (h2/h3)
	 */
	private static function extract_from_content( WP_Post $post, $limit = 9 ) {
		$title = strtolower( sanitize_text_field( $post->post_title ) );
		$raw   = self::get_plain_text( $post );

		if ( empty( $raw ) ) {
			return array();
		}

		// Grab heading text before final clean (headings may have been tagged in raw)
		$headings = array();
		if ( preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $raw, $m ) ) {
			foreach ( $m[1] as $h ) {
				$headings[] = strtolower( wp_strip_all_tags( $h ) );
			}
		}
		$heading_text = implode( ' ', $headings );

		// Clean to plain text
		$text = wp_strip_all_tags( $raw );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = strtolower( $text );
		$text = preg_replace( '/[^a-z0-9\s\'\-]/', ' ', $text );

		if ( mb_strlen( trim( $text ) ) < 20 ) {
			return array();
		}

		$stop_words = self::stop_words();
		$words      = preg_split( '/\s+/', trim( $text ) );
		$words      = array_values(
			array_filter(
				$words,
				function ( $w ) use ( $stop_words ) {
					return mb_strlen( $w ) > 2 && ! isset( $stop_words[ $w ] );
				}
			)
		);

		if ( count( $words ) < 2 ) {
			return array();
		}

		// Build bigrams and trigrams
		$ngrams = array();
		$n      = count( $words );
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$bigram            = $words[ $i ] . ' ' . $words[ $i + 1 ];
			$ngrams[ $bigram ] = ( $ngrams[ $bigram ] ?? 0 ) + 1;

			if ( $i < $n - 2 ) {
				$trigram            = $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ];
				$ngrams[ $trigram ] = ( $ngrams[ $trigram ] ?? 0 ) + 1;
			}
		}

		// Filter: minimum 2 occurrences OR appears in title/heading
		$scored = array();
		foreach ( $ngrams as $phrase => $freq ) {
			$in_title   = ( false !== strpos( $title, $phrase ) );
			$in_heading = ( false !== strpos( $heading_text, $phrase ) );

			if ( $freq < 2 && ! $in_title && ! $in_heading ) {
				continue;
			}

			$score = $freq;
			if ( $in_title ) {
				$score *= 2.0;
			}
			if ( $in_heading ) {
				$score *= 1.5;
			}

			$scored[ $phrase ] = $score;
		}

		arsort( $scored );
		$phrases = array_keys( array_slice( $scored, 0, $limit, true ) );

		return array_map( 'ucwords', $phrases );
	}

	/**
	 * Get the best available plain-text content for a post.
	 *
	 * Priority:
	 *   1. post_content — used by Classic Editor, Gutenberg, and most page builders
	 *   2. _elementor_data — Elementor stores its content as JSON; we decode it
	 *      and walk every widget to collect all text values
	 *
	 * Returns raw string (may still contain HTML — caller strips it).
	 */
	private static function get_plain_text( WP_Post $post ) {
		$content = $post->post_content;

		// If post_content has meaningful text (>100 chars after stripping), use it
		$stripped = trim( wp_strip_all_tags( $content ) );
		if ( mb_strlen( $stripped ) >= 100 ) {
			return $content;
		}

		// Fallback: try Elementor's JSON data
		$elementor_raw = get_post_meta( $post->ID, '_elementor_data', true );
		if ( empty( $elementor_raw ) ) {
			return $content; // nothing better available
		}

		// _elementor_data can be stored as a JSON string or already decoded
		if ( is_string( $elementor_raw ) ) {
			$elementor_data = json_decode( $elementor_raw, true );
		} else {
			$elementor_data = $elementor_raw;
		}

		if ( ! is_array( $elementor_data ) ) {
			return $content;
		}

		// Walk the entire Elementor element tree and collect all text
		$text_parts = array();
		self::walk_elementor_elements( $elementor_data, $text_parts );

		$elementor_text = implode( ' ', $text_parts );

		// Use whichever source has more content
		return mb_strlen( $elementor_text ) > mb_strlen( $stripped )
			? $elementor_text
			: $content;
	}

	/**
	 * Recursively walk Elementor's element tree and collect text content
	 * from all widget settings fields that are likely to contain real copy.
	 *
	 * Elementor stores content in widget->settings as key/value pairs.
	 * Text fields we care about: title, text, editor, description, content,
	 * caption, html, button_text, heading, sub_title, label, etc.
	 *
	 * @param array $elements  Array of Elementor element nodes.
	 * @param array $collected Reference array to append text fragments to.
	 */
	private static function walk_elementor_elements( array $elements, array &$collected ) {
		// Settings keys likely to contain meaningful visible text
		static $text_keys = array(
			'title',
			'text',
			'editor',
			'description',
			'content',
			'caption',
			'html',
			'button_text',
			'heading',
			'sub_title',
			'subtitle',
			'label',
			'body',
			'excerpt',
			'inner_text',
			'heading_text',
			'text_content',
			'item_description',
		);

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			// Collect text from this element's settings
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				foreach ( $text_keys as $key ) {
					if ( ! empty( $element['settings'][ $key ] ) && is_string( $element['settings'][ $key ] ) ) {
						$val = trim( $element['settings'][ $key ] );
						if ( mb_strlen( $val ) > 2 ) {
							$collected[] = $val;
						}
					}
				}
			}

			// Recurse into child elements
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk_elementor_elements( $element['elements'], $collected );
			}
		}
	}

	private static function title_fallback( $title, $count ) {
		$stop  = self::stop_words();
		$words = preg_split( '/\s+/', strtolower( sanitize_text_field( $title ) ) );
		$words = array_values(
			array_filter(
				$words,
				function ( $w ) use ( $stop ) {
					return mb_strlen( $w ) > 2 && ! isset( $stop[ $w ] );
				}
			)
		);

		if ( count( $words ) < 2 ) {
			return array( ucwords( $title ) );
		}

		// Sliding bigrams from title words
		$phrases    = array();
		$word_count = count( $words );
		for ( $i = 0; $i < $word_count - 1; $i++ ) {
			if ( count( $phrases ) >= $count ) {
				break;
			}
			$phrases[] = ucwords( $words[ $i ] . ' ' . $words[ $i + 1 ] );
		}
		return $phrases;
	}

	// ── Step 2: Datamuse expansion ────────────────────────────────────────────

	/**
	 * Use the Datamuse API to find semantically related search phrases.
	 *
	 * We query "words with meaning like $seed" and "words triggered by $seed",
	 * combine the results, filter to multi-word phrases, and return the best ones.
	 *
	 * Datamuse is free, needs no API key, and typically responds in < 200 ms.
	 */
	private static function datamuse_expand( $seed, $content_phrases, $limit = 6 ) {

		// Query 1: "means like" — semantically similar concepts
		$ml_results = self::datamuse_query(
			array(
				'ml'  => $seed,
				'max' => 20,
			)
		);
		// Query 2: "triggered by" — words/phrases strongly associated with seed.
		// Pass the raw seed; add_query_arg() URL-encodes it. Encoding it here too
		// would double-encode and make the query return nothing.
		$rel_results = self::datamuse_query(
			array(
				'rel_trg' => $seed,
				'max'     => 20,
			)
		);

		$candidates = array_merge( $ml_results, $rel_results );

		if ( empty( $candidates ) ) {
			return array();
		}

		// Prefer multi-word phrases (more useful as Yoast keyphrases)
		// Single words are still accepted but ranked lower
		$multi  = array();
		$single = array();
		foreach ( $candidates as $item ) {
			$word  = $item['word'] ?? '';
			$score = $item['score'] ?? 0;
			if ( empty( $word ) ) {
				continue;
			}
			if ( false !== strpos( $word, ' ' ) ) {
				$multi[] = array(
					'word'  => $word,
					'score' => $score * 1.5,
				);
			} else {
				// Pair with a content phrase word to make a bigram
				$single[] = array(
					'word'  => $word,
					'score' => $score,
				);
			}
		}

		// Build bigrams from single Datamuse words + top content word
		$seed_words = preg_split( '/\s+/', strtolower( $seed ) );
		$seed_word  = end( $seed_words ); // last meaningful word of the title
		$stop       = self::stop_words();
		foreach ( $single as $item ) {
			$w = strtolower( $item['word'] );
			if ( isset( $stop[ $w ] ) || mb_strlen( $w ) < 3 ) {
				continue;
			}
			$multi[] = array(
				'word'  => $seed_word . ' ' . $w,
				'score' => $item['score'],
			);
		}

		// Sort by score descending
		usort(
			$multi,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Collect phrases, skip any that duplicate content_phrases
		$existing = array_map( 'strtolower', $content_phrases );
		$results  = array();
		foreach ( $multi as $item ) {
			$phrase = ucwords( $item['word'] );
			if ( in_array( strtolower( $phrase ), $existing, true ) ) {
				continue;
			}
			$results[] = $phrase;
			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	private static function datamuse_query( $params ) {
		$url = add_query_arg( $params, self::DATAMUSE_URL );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => 'Auto SEO for Yoast/' . ASY_VERSION . ' (+' . get_site_url() . ')',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Split a phrase into its significant (non-stop, 3+ char) words, lowercased.
	 * Returned as a hash map (word => true) for O(1) membership checks. Used to
	 * keep related keyphrases from repeating the focus keyphrase's words.
	 *
	 * @param  string $phrase
	 * @return array<string,bool>
	 */
	private static function significant_words( $phrase ) {
		$stop  = self::stop_words();
		$norm  = strtolower( sanitize_text_field( (string) $phrase ) );
		$norm  = preg_replace( "/[^a-z0-9\s'-]/", ' ', $norm );
		$words = preg_split( '/\s+/', trim( (string) $norm ) );
		$out   = array();
		foreach ( (array) $words as $w ) {
			if ( '' === $w || mb_strlen( $w ) < 3 || isset( $stop[ $w ] ) ) {
				continue;
			}
			$out[ $w ] = true;
		}
		return $out;
	}

	private static function deduplicate( array $phrases ) {
		$seen   = array();
		$result = array();
		foreach ( $phrases as $phrase ) {
			$key = strtolower( trim( $phrase ) );
			if ( empty( $key ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[]     = sanitize_text_field( $phrase );
		}
		return $result;
	}

	/**
	 * English stop words — common words that carry no SEO value.
	 * Stored as a hash map for O(1) lookup.
	 */
	private static function stop_words() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}

		$words = array(
			'a',
			'about',
			'above',
			'after',
			'again',
			'against',
			'all',
			'am',
			'an',
			'and',
			'any',
			'are',
			'aren\'t',
			'as',
			'at',
			'be',
			'because',
			'been',
			'before',
			'being',
			'below',
			'between',
			'both',
			'but',
			'by',
			'can',
			'can\'t',
			'cannot',
			'could',
			'couldn\'t',
			'did',
			'didn\'t',
			'do',
			'does',
			'doesn\'t',
			'doing',
			'don\'t',
			'down',
			'during',
			'each',
			'few',
			'for',
			'from',
			'further',
			'get',
			'got',
			'had',
			'hadn\'t',
			'has',
			'hasn\'t',
			'have',
			'haven\'t',
			'having',
			'he',
			'he\'d',
			'he\'ll',
			'he\'s',
			'her',
			'here',
			'here\'s',
			'hers',
			'herself',
			'him',
			'himself',
			'his',
			'how',
			'how\'s',
			'i',
			'i\'d',
			'i\'ll',
			'i\'m',
			'i\'ve',
			'if',
			'in',
			'into',
			'is',
			'isn\'t',
			'it',
			'it\'s',
			'its',
			'itself',
			'let\'s',
			'me',
			'more',
			'most',
			'mustn\'t',
			'my',
			'myself',
			'no',
			'nor',
			'not',
			'of',
			'off',
			'on',
			'once',
			'only',
			'or',
			'other',
			'ought',
			'our',
			'ours',
			'ourselves',
			'out',
			'over',
			'own',
			'same',
			'shan\'t',
			'she',
			'she\'d',
			'she\'ll',
			'she\'s',
			'should',
			'shouldn\'t',
			'so',
			'some',
			'such',
			'than',
			'that',
			'that\'s',
			'the',
			'their',
			'theirs',
			'them',
			'themselves',
			'then',
			'there',
			'there\'s',
			'these',
			'they',
			'they\'d',
			'they\'ll',
			'they\'re',
			'they\'ve',
			'this',
			'those',
			'through',
			'to',
			'too',
			'under',
			'until',
			'up',
			'very',
			'was',
			'wasn\'t',
			'we',
			'we\'d',
			'we\'ll',
			'we\'re',
			'we\'ve',
			'were',
			'weren\'t',
			'what',
			'what\'s',
			'when',
			'when\'s',
			'where',
			'where\'s',
			'which',
			'while',
			'who',
			'who\'s',
			'whom',
			'why',
			'why\'s',
			'will',
			'with',
			'won\'t',
			'would',
			'wouldn\'t',
			'you',
			'you\'d',
			'you\'ll',
			'you\'re',
			'you\'ve',
			'your',
			'yours',
			'yourself',
			'yourselves',
			'also',
			'just',
			'like',
			'new',
			'one',
			'use',
			'used',
			'using',
			'well',
			'will',
			'may',
			'might',
			'shall',
			'even',
			'still',
			'now',
			'then',
			'here',
			'there',
			'back',
			'way',
			'take',
			'make',
			'good',
			'know',
			'think',
			'see',
			'come',
			'want',
			'give',
			'look',
			'first',
			'last',
			'long',
			'great',
			'little',
			'own',
			'right',
			'high',
			'place',
			'large',
			'next',
			'early',
			'young',
			'important',
			'public',
			'private',
			'real',
			'best',
			'free',
			'used',
			'need',
			'help',
			'find',
			'its',
			'our',
			'their',
			'has',
			'are',
			'was',
			'were',
			'been',
			'being',
			'have',
			'had',
			'does',
			'did',
			'able',
			'across',
			'already',
			'always',
			'another',
			'around',
			'became',
			'become',
			'becomes',
			'becoming',
			'behind',
			'beside',
			'besides',
			'beyond',
			'call',
			'came',
			'cannot',
			'certain',
			'changes',
			'clear',
			'come',
			'consider',
			'considered',
			'either',
			'else',
			'enough',
			'especially',
			'fact',
			'far',
			'felt',
			'form',
			'four',
			'full',
			'general',
			'given',
			'goes',
			'got',
			'hand',
			'heard',
			'hence',
			'however',
			'including',
			'indeed',
			'kept',
			'kind',
			'known',
			'later',
			'least',
			'less',
			'let',
			'likely',
			'made',
			'main',
			'many',
			'maybe',
			'means',
			'might',
			'moved',
			'much',
			'must',
			'near',
			'never',
			'non',
			'nothing',
			'often',
			'old',
			'once',
			'open',
			'order',
			'part',
			'perhaps',
			'point',
			'possible',
			'probably',
			'put',
			'quite',
			'rather',
			'really',
			'said',
			'second',
			'seen',
			'set',
			'several',
			'she',
			'side',
			'since',
			'small',
			'something',
			'sometimes',
			'soon',
			'stand',
			'still',
			'sure',
			'take',
			'taken',
			'though',
			'three',
			'thus',
			'together',
			'told',
			'towards',
			'tried',
			'true',
			'try',
			'turn',
			'two',
			'upon',
			'usually',
			'various',
			'without',
			'yet',
		);

		$map = array_fill_keys( $words, true );
		return $map;
	}

	// ── Legacy: save keyphrases to Yoast meta ─────────────────────────────────
	// (kept here so the processor only needs to call one class)

	/**
	 * Write related keyphrases into Yoast's _yoast_wpseo_focuskeywords meta field.
	 *
	 * @param int   $post_id
	 * @param array $keyphrases  Plain array of keyphrase strings.
	 */
	public static function save_related_keyphrases( $post_id, array $keyphrases, $exclude = '' ) {
		if ( empty( $keyphrases ) ) {
			return;
		}

		// Never let a related keyphrase repeat the focus keyphrase. Use the
		// phrase passed in (e.g. one staged in the Bulk Editor) or, failing that,
		// the focus keyphrase saved on the post. Also de-duplicate the list.
		$focus      = ( '' !== $exclude )
			? $exclude
			: (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		$focus_norm = strtolower( trim( preg_replace( '/\s+/', ' ', $focus ) ) );

		$seen    = array();
		$objects = array();
		foreach ( $keyphrases as $kp ) {
			$kp   = sanitize_text_field( $kp );
			$norm = strtolower( trim( preg_replace( '/\s+/', ' ', $kp ) ) );
			if ( '' === $norm || $norm === $focus_norm || isset( $seen[ $norm ] ) ) {
				continue;
			}
			$seen[ $norm ] = true;
			$objects[]     = array(
				'keyword' => $kp,
				'score'   => '',
			);
		}

		if ( empty( $objects ) ) {
			return; // Everything collided with the focus keyphrase — leave existing related untouched.
		}

		update_post_meta( $post_id, '_yoast_wpseo_focuskeywords', wp_json_encode( $objects ) );
	}
}
