<?php
/**
 * Reusable Persian RTL document presentation: Article, Policy and General/Help pages.
 *
 * The system is presentation-only. It derives deterministic heading anchors, a table of contents,
 * reading time and publication metadata from authored content and existing WordPress data. It stores
 * nothing, queries no domain service and performs no protected reads.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The content presentation modes.
 *
 * @return string[]
 */
function dzn_theme_content_page_modes() {
	return array( 'article', 'policy', 'general' );
}

/**
 * The reusable presentation contract for one mode.
 *
 * @param string $mode Presentation mode.
 * @return array
 */
function dzn_theme_content_page_presentation( $mode ) {
	$presentations = array(
		'article' => array(
			'mode'                => 'article',
			'document_class'      => 'dzn-document--article',
			'label'               => 'مقاله',
			'show_categories'     => true,
			'show_publication'    => true,
			'show_reading_time'   => true,
			'show_featured_image' => true,
			'show_related'        => true,
			'show_previous_next'  => true,
			'show_print_hint'     => false,
		),
		'policy'  => array(
			'mode'                => 'policy',
			'document_class'      => 'dzn-document--policy',
			'label'               => 'سند سیاست',
			'show_categories'     => false,
			'show_publication'    => true,
			'show_reading_time'   => false,
			'show_featured_image' => false,
			'show_related'        => false,
			'show_previous_next'  => false,
			'show_print_hint'     => true,
		),
		'general' => array(
			'mode'                => 'general',
			'document_class'      => 'dzn-document--general',
			'label'               => 'راهنما',
			'show_categories'     => false,
			'show_publication'    => false,
			'show_reading_time'   => false,
			'show_featured_image' => false,
			'show_related'        => false,
			'show_previous_next'  => false,
			'show_print_hint'     => false,
		),
	);

	$mode = in_array( $mode, dzn_theme_content_page_modes(), true ) ? $mode : 'general';

	return $presentations[ $mode ];
}

/**
 * Headings that participate in the document outline.
 *
 * @return int[]
 */
function dzn_theme_content_page_heading_levels() {
	return array( 2, 3 );
}

/**
 * How many outline sections justify a table of contents.
 *
 * @return int
 */
function dzn_theme_content_page_toc_minimum() {
	return 3;
}

/**
 * Reading speed used for the presentation-only reading time, in words per minute.
 *
 * @return int
 */
function dzn_theme_content_page_words_per_minute() {
	return 200;
}

/**
 * Strip markup from one fragment, robust to PHP's `strip_tags()` pathology.
 *
 * PHP's `strip_tags()` returns an empty string for markup whose quoted attribute values contain `>`
 * and whose closing quote is no longer a literal quote (a shape WordPress texturisation can produce
 * from raw HTML content). The fallback removes tags and any residual angle brackets so a heading still
 * contributes its visible text instead of silently disappearing.
 *
 * @param string $html Rendered fragment.
 * @return string
 */
function dzn_theme_content_page_strip_tags( $html ) {
	$html = (string) $html;
	$text = wp_strip_all_tags( $html );

	if ( '' === trim( $text ) && '' !== trim( $html ) ) {
		$text = preg_replace( '/<[^<>]*>/', ' ', $html );
		$text = str_replace( array( '<', '>' ), ' ', (string) $text );
	}

	return (string) $text;
}

/**
 * Visible text of one rendered fragment: tags stripped, entities decoded, whitespace collapsed.
 *
 * The outline displays this text, and the table of contents escapes it again at output time, so a
 * heading containing `&amp;` or `&nbsp;` is shown as authored rather than as a literal entity.
 *
 * @param string $html Rendered fragment.
 * @return string
 */
function dzn_theme_content_page_text( $html ) {
	$text = dzn_theme_content_page_strip_tags( $html );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF" ), ' ', $text );

	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Persian-aware, deterministic anchor base for one heading.
 *
 * Accents and punctuation collapse to single dashes, the zero-width non-joiner is dropped so the
 * anchor stays URL-stable, and non-letter/non-number scripts (including Persian) are preserved.
 *
 * @param string $text Heading text.
 * @return string
 */
function dzn_theme_content_page_slug( $text ) {
	$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = str_replace( array( "\xE2\x80\x8C", "\xE2\x80\x8D" ), '', $text );
	$text = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $text );
	if ( ! is_string( $text ) ) {
		return '';
	}
	$text = trim( $text, '-' );
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );

	return $text;
}

/**
 * Resolve one anchor id deterministically against the ids already used in this document.
 *
 * Authored ids are preserved verbatim; a repeated id (authored or derived) receives the next free
 * numeric suffix in document order, so the result never depends on hash order or on the request.
 *
 * @param string $candidate Preferred anchor.
 * @param array  $used      Already assigned anchors, keyed by anchor.
 * @param int    $position  One-based heading position, used for the empty fallback.
 * @return string
 */
function dzn_theme_content_page_unique_anchor( $candidate, array &$used, $position ) {
	$candidate = trim( (string) $candidate );

	if ( '' === $candidate ) {
		$candidate = 'section-' . (int) $position;
	}

	$anchor = $candidate;
	$suffix = 2;

	while ( isset( $used[ $anchor ] ) ) {
		$anchor = $candidate . '-' . $suffix;
		$suffix++;
	}

	$used[ $anchor ] = true;

	return $anchor;
}

/**
 * Insert or replace the id attribute of one heading opening tag.
 *
 * @param string $attributes Existing attribute string without the tag name.
 * @param string $anchor     Resolved anchor id.
 * @return string
 */
function dzn_theme_content_page_heading_attributes( $attributes, $anchor ) {
	$escaped    = esc_attr( $anchor );
	$attributes = (string) $attributes;
	$pattern    = '/\bid\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'<>`]+)/i';

	if ( preg_match( $pattern, $attributes ) ) {
		return preg_replace( $pattern, 'id="' . $escaped . '"', $attributes, 1 );
	}

	return ' id="' . $escaped . '"' . $attributes;
}

/**
 * The ids this feature itself emits on a document, without touching WordPress state.
 *
 * @return string[]
 */
function dzn_theme_content_page_owned_ids() {
	return array( 'dzn-toc-title-desktop', 'dzn-toc-title-mobile', 'dzn-related-title' );
}

/**
 * Every id the document template and its wrappers emit before heading anchors are assigned.
 *
 * This is the single contract for template-owned ids: the document wrappers (`main-content` and the
 * `post-{ID}` article wrapper) plus the ids this feature emits itself (the desktop and mobile outline
 * titles and the related-content title). A future wrapper id must be added here, which is why the
 * anchoring path consumes this function instead of a literal list.
 *
 * @param int|WP_Post|null $post Post object or ID. Defaults to the current post.
 * @return string[]
 */
function dzn_theme_content_page_reserved_ids( $post = null ) {
	$post = $post ? get_post( $post ) : get_post();
	$ids  = array_merge( array( 'main-content' ), dzn_theme_content_page_owned_ids() );

	if ( $post ) {
		$ids[] = 'post-' . (int) $post->ID;
	}

	return array_values( array_unique( array_filter( array_map( 'strval', $ids ), 'strlen' ) ) );
}

/**
 * Maximum byte length of a trustworthy opening-tag lexeme.
 *
 * An opening tag whose lexeme (from its opening `<` through its closing `>`) exceeds this length is
 * classified malformed and left byte-stable, so an implausibly large authored tag can never be
 * accepted or rewritten. This restores the fail-closed bound established by correction rounds 1/2.
 *
 * @return int
 */
function dzn_theme_content_page_max_tag_bytes() {
	return 2048;
}

/**
 * Scan one opening tag starting at `$start` and return its closing `>` or the boundary of its
 * malformed lexeme.
 *
 * Quoted attribute values are honoured, so a legal `>` or `<` inside a title/data attribute can never
 * truncate the tag. When the tag cannot be scanned confidently, `end` is false and `malformed_end` is
 * the first offset from which tokenization may safely resume. Pseudo-tags embedded in a malformed
 * lexeme are never tokenized as markup.
 *
 * A tag whose lexeme exceeds `dzn_theme_content_page_max_tag_bytes()` before a trustworthy closing
 * boundary is also malformed. Its recovery boundary follows the same defensible rule as a nested `<`:
 * tokenization resumes only after the first `>` outside quotes at or after the point where the limit
 * was exceeded.
 *
 * @param string $html  Rendered content.
 * @param int    $start Offset of the `<` that opens the tag.
 * @return array{end:int|false,malformed_end:int|null}
 */
function dzn_theme_content_page_tag_scan( $html, $start ) {
	$length       = strlen( $html );
	$max_open_end = (int) $start + dzn_theme_content_page_max_tag_bytes() - 1;
	$quote        = '';
	$oversized    = false;

	for ( $index = (int) $start; $index < $length; $index++ ) {
		if ( ! $oversized && $index > $max_open_end ) {
			$oversized = true;
		}

		$character = $html[ $index ];

		if ( '' !== $quote ) {
			if ( $character === $quote ) {
				$quote = '';
			}
			continue;
		}

		if ( '"' === $character || "'" === $character ) {
			$quote = $character;
			continue;
		}

		// A new tag starting while this one is still open means it was never closed (the opening `<`
		// of this very tag is not a nested tag). Once an oversized lexeme is detected, however, an
		// inner `<` is just more content of the oversized tag, not a separate recovery boundary.
		if ( '<' === $character && $index > (int) $start && ! $oversized ) {
			return array(
				'end'          => false,
				'malformed_end' => dzn_theme_content_page_tag_gt_boundary( $html, $index ),
			);
		}

		if ( '>' === $character ) {
			if ( $oversized ) {
				return array(
					'end'          => false,
					'malformed_end' => $index + 1,
				);
			}

			return array(
				'end'          => $index,
				'malformed_end' => null,
			);
		}
	}

	// Unterminated quoted attribute, unclosed tag, or oversized lexeme with no trustworthy boundary:
	// the malformed lexeme consumes the remainder.
	return array(
		'end'          => false,
		'malformed_end' => $length,
	);
}

/**
 * The exclusive offset just past the first `>` outside quotes at or after `$start`.
 *
 * When a tag is malformed because a nested `<` appeared before its closing `>`, this is the structural
 * boundary at which tokenization may resume without guessing inside the malformed lexeme.
 *
 * @param string $html  Rendered content.
 * @param int    $start Offset of the nested `<`.
 * @return int Exclusive boundary offset.
 */
function dzn_theme_content_page_tag_gt_boundary( $html, $start ) {
	$length = strlen( $html );
	$quote  = '';

	for ( $index = (int) $start; $index < $length; $index++ ) {
		$character = $html[ $index ];

		if ( '' !== $quote ) {
			if ( $character === $quote ) {
				$quote = '';
			}
			continue;
		}

		if ( '"' === $character || "'" === $character ) {
			$quote = $character;
			continue;
		}

		if ( '>' === $character ) {
			return $index + 1;
		}
	}

	return $length;
}

/**
 * The offset of the `>` that closes the opening tag starting at `$start`.
 *
 * Kept as the single quote-aware boundary helper for callers that only need the successful end.
 *
 * @param string $html  Rendered content.
 * @param int    $start Offset of the `<` that opens the tag.
 * @return int|false
 */
function dzn_theme_content_page_tag_end( $html, $start ) {
	$scan = dzn_theme_content_page_tag_scan( $html, $start );

	return false === $scan['end'] ? false : $scan['end'];
}

/**
 * One attribute value from an opening-tag attribute string, honouring quoting.
 *
 * @param string $attributes Attribute string.
 * @param string $name       Attribute name.
 * @return string|null The value, or null when the attribute is absent.
 */
function dzn_theme_content_page_attribute( $attributes, $name ) {
	$pattern = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'<>`]+))/i';

	if ( preg_match( $pattern, (string) $attributes, $matches ) ) {
		foreach ( array( 1, 2, 3 ) as $group ) {
			if ( isset( $matches[ $group ] ) && '' !== $matches[ $group ] ) {
				return $matches[ $group ];
			}
		}

		return '';
	}

	return null;
}

/**
 * Count every id attribute in one rendered document.
 *
 * @param string $html Rendered content.
 * @return array<string,int>
 */
function dzn_theme_content_page_ids( $html ) {
	$ids = array();

	if ( preg_match_all( '/\bid\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'<>`]+))/i', (string) $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$value = '';

			foreach ( array( 1, 2, 3 ) as $group ) {
				if ( isset( $match[ $group ] ) && '' !== $match[ $group ] ) {
					$value = $match[ $group ];
					break;
				}
			}

			$value = trim( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$ids[ $value ] = isset( $ids[ $value ] ) ? $ids[ $value ] + 1 : 1;
		}
	}

	return $ids;
}

/**
 * Tokenize H2/H3 opening and closing tags in document order, then pair them structurally.
 *
 * Each opening heading is NOT searched independently for the next same-level closing tag: the tags are
 * read in order, so a mismatched closing tag can never be absorbed by a later valid heading. A tag
 * whose attributes cannot be scanned confidently is not a token at all and stays untouched.
 *
 * @param string $content Rendered content.
 * @return array{pairs:array<int,array<string,mixed>>,malformed:array<int,array<int,int>>}
 */
function dzn_theme_content_page_heading_tokens( $content ) {
	$content   = (string) $content;
	$length    = strlen( $content );
	$tokens    = array();
	$malformed = array();
	$offset    = 0;

	while ( $offset < $length ) {
		$start = strpos( $content, '<', $offset );

		if ( false === $start ) {
			break;
		}

		$next = $start + 1 < $length ? $content[ $start + 1 ] : '';
		$tag_like = '' !== $next && ( '/' === $next || ( $next >= 'a' && $next <= 'z' ) || ( $next >= 'A' && $next <= 'Z' ) );

		if ( ! $tag_like ) {
			$offset = $start + 1;
			continue;
		}

		if ( ! preg_match( '/\G<(\/?)h([1-6])(?=[\s\/>])/i', $content, $heading, 0, $start ) ) {
			// A non-heading tag can still contain a heading-looking substring inside its malformed
			// attribute/text, so its lexeme boundary must be scanned before tokenization resumes.
			$scan = dzn_theme_content_page_tag_scan( $content, $start );

			if ( false === $scan['end'] ) {
				$malformed[] = array( $start, $scan['malformed_end'] );
				$offset      = $scan['malformed_end'];
			} else {
				$offset = $scan['end'] + 1;
			}

			continue;
		}

		$closing = '/' === $heading[1];
		$level   = (int) $heading[2];
		$scan    = dzn_theme_content_page_tag_scan( $content, $start );

		if ( false === $scan['end'] ) {
			$malformed[] = array( $start, $scan['malformed_end'] );
			$offset      = $scan['malformed_end'];
			continue;
		}

		$tokens[] = array(
			'type'  => $closing ? 'close' : 'open',
			'level' => $level,
			'start' => $start,
			'end'   => $scan['end'],
		);

		$offset = $scan['end'] + 1;
	}

	$pairs = array();
	$stack = array();

	foreach ( $tokens as $token ) {
		if ( 'open' === $token['type'] ) {
			$entry = array(
				'level'    => $token['level'],
				'start'    => $token['start'],
				'open_end' => $token['end'],
				'poisoned' => false,
			);

			if ( $stack ) {
				// Nested headings are ambiguous: neither the inner nor the enclosing heading may be
				// rewritten or described by the outline.
				$entry['poisoned'] = true;
				$stack[ count( $stack ) - 1 ]['poisoned'] = true;
			}

			$stack[] = $entry;
			continue;
		}

		if ( ! $stack ) {
			$malformed[] = array( $token['start'], $token['end'] + 1 );
			continue;
		}

		$entry = array_pop( $stack );

		if ( $entry['level'] !== $token['level'] || $entry['poisoned'] ) {
			$malformed[] = array( $entry['start'], $token['end'] + 1 );
			continue;
		}

		$pairs[] = array(
			'level'       => $entry['level'],
			'start'       => $entry['start'],
			'open_end'    => $entry['open_end'],
			'close_start' => $token['start'],
			'close_end'   => $token['end'] + 1,
		);
	}

	// An opening tag that never closes is malformed too.
	foreach ( $stack as $entry ) {
		$malformed[] = array( $entry['start'], $entry['open_end'] + 1 );
	}

	// A pair that overlaps any malformed region stays untouched and contributes no outline entry.
	$overlaps = static function ( array $pair ) use ( $malformed ) {
		foreach ( $malformed as $range ) {
			if ( $pair['start'] < $range[1] && $range[0] < $pair['close_end'] ) {
				return true;
			}
		}

		return false;
	};

	$pairs = array_values( array_filter( $pairs, static function ( $pair ) use ( $overlaps ) {
		return ! $overlaps( $pair );
	} ) );

	// Defensive: only non-overlapping, document-ordered pairs may be rewritten.
	usort( $pairs, static function ( $left, $right ) {
		return $left['start'] <=> $right['start'];
	} );

	$ordered = array();
	$end     = -1;

	foreach ( $pairs as $pair ) {
		if ( $pair['start'] < $end ) {
			continue;
		}

		$end       = $pair['close_end'];
		$ordered[] = $pair;
	}

	return array( 'pairs' => $ordered, 'malformed' => $malformed );
}

/**
 * Anchor every outline heading in rendered content and describe the document outline.
 *
 * Headings are paired by an ordered tokenizer, so well-formed H2/H3 headings are anchored while any
 * mismatched close, nesting, crossing structure, stray close or unclosed opening stays byte-stable and
 * contributes no outline entry. Anchoring resumes after a malformed region, anchors are assigned
 * against every id already present in the document plus the ids this feature reserves (so the result
 * never emits a duplicate id), and the function is idempotent.
 *
 * @param string   $content  Rendered content.
 * @param string[] $reserved Extra ids that must never be used as an anchor.
 * @return array{content:string,sections:array<int,array<string,mixed>>}
 */
function dzn_theme_content_page_anchor_content( $content, array $reserved = array() ) {
	$content = (string) $content;

	if ( '' === $content ) {
		return array( 'content' => $content, 'sections' => array() );
	}

	$tokenized = dzn_theme_content_page_heading_tokens( $content );
	$headings  = array();

	$levels = dzn_theme_content_page_heading_levels();

	foreach ( $tokenized['pairs'] as $pair ) {
		// Only the outline levels are rewritten; every other heading is read for structure only.
		if ( ! in_array( (int) $pair['level'], $levels, true ) ) {
			continue;
		}

		$headings[] = array(
			'level'       => $pair['level'],
			'start'       => $pair['start'],
			'open_end'    => $pair['open_end'],
			'close_end'   => $pair['close_end'],
			'attributes'  => substr( $content, $pair['start'] + 3, $pair['open_end'] - ( $pair['start'] + 3 ) ),
			'inner'       => substr( $content, $pair['open_end'] + 1, $pair['close_start'] - ( $pair['open_end'] + 1 ) ),
		);
	}

	if ( ! $headings ) {
		return array( 'content' => $content, 'sections' => array() );
	}

	// Everything already carrying an id in this document is off limits for a generated anchor.
	$existing       = dzn_theme_content_page_ids( $content );
	$heading_ids    = array();
	$authored       = array();
	$reserved_names = array();

	foreach ( $headings as $position => $heading ) {
		$value = dzn_theme_content_page_attribute( $heading['attributes'], 'id' );
		$value = null === $value ? '' : trim( (string) $value );

		$authored[ $position ] = $value;

		if ( '' !== $value ) {
			$heading_ids[ $value ] = isset( $heading_ids[ $value ] ) ? $heading_ids[ $value ] + 1 : 1;
		}
	}

	foreach ( array_merge( $reserved, dzn_theme_content_page_owned_ids() ) as $name ) {
		$name = trim( (string) $name );

		if ( '' !== $name ) {
			$reserved_names[ $name ] = true;
		}
	}

	$assigned = array();
	$sections = array();

	foreach ( $headings as $position => $heading ) {
		$number = $position + 1;
		$text   = dzn_theme_content_page_text( $heading['inner'] );
		$value  = $authored[ $position ];
		// Ids used by anything other than this heading must never be reused.
		$other_uses = ( $existing[ $value ] ?? 0 ) - ( $heading_ids[ $value ] ?? 0 );

		if ( '' !== $value && ! isset( $reserved_names[ $value ] ) && $other_uses < 1 && ! isset( $assigned[ $value ] ) ) {
			$anchor = $value;
		} else {
			$base   = '' !== $value ? $value : dzn_theme_content_page_slug( $text );
			$anchor = '' !== $base ? $base : 'section-' . $number;
			$suffix = 2;

			while ( isset( $existing[ $anchor ] ) || isset( $assigned[ $anchor ] ) || isset( $reserved_names[ $anchor ] ) ) {
				$anchor = $base . '-' . $suffix;
				$suffix++;
			}
		}

		$assigned[ $anchor ] = true;
		$sections[]          = array(
			'level'    => (int) $heading['level'],
			'anchor'   => $anchor,
			'text'     => $text,
			'authored' => '' !== $value,
		);
	}

	$result = $content;

	for ( $index = count( $headings ) - 1; $index >= 0; $index-- ) {
		$heading     = $headings[ $index ];
		$replacement = '<h' . $heading['level']
			. dzn_theme_content_page_heading_attributes( $heading['attributes'], $sections[ $index ]['anchor'] )
			. '>' . $heading['inner'] . '</h' . $heading['level'] . '>';

		$result = substr( $result, 0, $heading['start'] ) . $replacement . substr( $result, $heading['close_end'] );
	}

	return array(
		'content'  => $result,
		'sections' => $sections,
	);
}

/**
 * Presentation-only reading time in whole minutes (never a domain fact).
 *
 * @param string $content Rendered or authored content.
 * @return int Zero for content without readable text, otherwise whole minutes, at least one.
 */
function dzn_theme_content_page_reading_minutes( $content ) {
	$text = trim( preg_replace( '/\s+/u', ' ', dzn_theme_content_page_strip_tags( $content ) ) );

	if ( '' === $text ) {
		return 0;
	}

	$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$count = is_array( $words ) ? count( $words ) : 0;

	if ( $count < 1 ) {
		return 0;
	}

	return max( 1, (int) ceil( $count / dzn_theme_content_page_words_per_minute() ) );
}

/**
 * Resolve the presentation mode for a post.
 *
 * Posts are always Articles. Pages are General/Help unless they explicitly select the Policy page
 * template, which keeps authoring inside the standard WordPress page-template mechanism.
 *
 * @param int|WP_Post|null $post Post object or ID. Defaults to the current post.
 * @return string
 */
function dzn_theme_content_page_mode( $post = null ) {
	$post = get_post( $post );

	if ( ! $post ) {
		return 'general';
	}

	if ( 'post' === $post->post_type ) {
		return 'article';
	}

	$template = get_post_meta( (int) $post->ID, '_wp_page_template', true );

	if ( is_string( $template ) && 'page-templates/content-policy.php' === $template ) {
		return 'policy';
	}

	return 'general';
}

/**
 * Anchor one rendered content string once per post and remember its document outline.
 *
 * Presentation-only memoisation: the same rendered content always produces the same anchored content
 * and outline, so the table of contents and the body can never disagree.
 *
 * @param WP_Post|null $post    Post the content belongs to.
 * @param string       $content Rendered content.
 * @return array{content:string,sections:array<int,array<string,mixed>>,minutes:int}
 */
function dzn_theme_content_page_document( $post, $content ) {
	static $documents = array();

	$content = (string) $content;
	$key     = ( $post ? (int) $post->ID : 0 ) . ':' . md5( $content );

	if ( ! isset( $documents[ $key ] ) ) {
		$anchored = dzn_theme_content_page_anchor_content( $content, dzn_theme_content_page_reserved_ids( $post ) );

		$documents[ $key ] = array(
			'content'  => $anchored['content'],
			'sections' => $anchored['sections'],
			'minutes'  => dzn_theme_content_page_reading_minutes( $content ),
		);
	}

	return $documents[ $key ];
}

/**
 * The document contract for one post: anchored content, outline, reading time and table of contents.
 *
 * The anchoring happens here, inside document rendering only. Nothing is registered on the global
 * `the_content` pipeline, so the front page, archives, widgets, feeds, REST responses, secondary
 * plugin calls and the Student/Teacher Portal surfaces keep the untouched WordPress output.
 *
 * @param int|WP_Post|null $post Post object or ID. Defaults to the current post.
 * @return array
 */
function dzn_theme_content_page_data( $post = null ) {
	$post = get_post( $post );

	if ( ! $post ) {
		return array(
			'content'      => '',
			'sections'     => array(),
			'minutes'      => 0,
			'paginated'    => false,
			'requires_toc' => false,
		);
	}

	if ( false !== strpos( (string) $post->post_content, '<!--nextpage-->' ) ) {
		return array(
			'content'      => '',
			'sections'     => array(),
			'minutes'      => dzn_theme_content_page_reading_minutes( $post->post_content ),
			'paginated'    => true,
			'requires_toc' => false,
		);
	}

	$document = dzn_theme_content_page_document( $post, apply_filters( 'the_content', $post->post_content ) );
	$sections = $document['sections'];

	return array(
		'content'      => $document['content'],
		'sections'     => $sections,
		'minutes'      => $document['minutes'],
		'paginated'    => false,
		'requires_toc' => count( $sections ) >= dzn_theme_content_page_toc_minimum(),
	);
}

/**
 * Whether the current response actually renders the document system.
 *
 * This is the single predicate behind the print marker and it mirrors what the templates do: single
 * posts always render the document template, and pages render it only through the theme's default
 * `page.php` or the Policy template. The front page, archives and non-singular requests never do, the
 * Student and Teacher Portals are excluded explicitly, and a custom or plugin page template outside
 * this system is excluded because its resolved template is not one of ours.
 *
 * @param int|WP_Post|null $post Post object or ID. Defaults to the current post.
 * @return bool
 */
function dzn_theme_content_page_is_document_response( $post = null ) {
	$post = $post ? get_post( $post ) : get_post();

	if ( ! $post || ! is_singular() || is_front_page() ) {
		return false;
	}

	if ( function_exists( 'dzn_theme_is_portal_template' ) && dzn_theme_is_portal_template() ) {
		return false;
	}

	if ( function_exists( 'dzn_theme_is_teacher_portal_template' ) && dzn_theme_is_teacher_portal_template() ) {
		return false;
	}

	if ( 'post' === $post->post_type ) {
		return true;
	}

	if ( 'page' !== $post->post_type ) {
		return false;
	}

	$resolved = function_exists( 'get_page_template' ) ? (string) get_page_template() : '';

	return in_array( basename( $resolved ), array( 'page.php', 'content-policy.php' ), true );
}

/**
 * Mark document-rendered responses so document-scoped print rules cannot leak to other surfaces.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function dzn_theme_content_page_body_class( $classes ) {
	if ( is_admin() ) {
		return $classes;
	}

	if ( ! dzn_theme_content_page_is_document_response() ) {
		return $classes;
	}

	$classes[] = 'dzn-document-body';

	return $classes;
}
add_filter( 'body_class', 'dzn_theme_content_page_body_class' );
