<?php
/**
 * Strengths and Areas for growth as tick boxes (Karl, 1.215, from the
 * "NEW Portal Updates" document).
 *
 * "We want all the strengths and weaknesses to be shown to start, check box
 * style. Then they check off what is observed." Every item of the list is
 * shown, under its heading, with a tutor guidance column the family never
 * sees. Rows whose guidance says "Pick one" are levels of the same skill:
 * ticking one unticks the others. The report shows only what was ticked,
 * under the heading it belongs to.
 *
 * The lists are the practice's, edited under Settings, Report wording, as
 * nested bullets: a heading, and under it one bullet per item written
 * "what the family reads | guidance for the tutor". A run of items whose
 * guidance starts "Pick one" is one group.
 *
 * What a report stores is the heading and wording of each item ticked, so a
 * report keeps what it said when the list is reworded later. Strengths and
 * areas typed before 1.215 stay on their reports, after the ticked items.
 */
defined( 'ABSPATH' ) || exit;

class BFTD_Picks {

	/** The heading, items and pick-one groups of one list. */
	public static function catalog( $section_id, $key ) {
		$out   = array();
		$group = 0;
		foreach ( BFTD_Schema::master_lines( $section_id, $key ) as $hi => $line ) {
			list( $heading, $items ) = self::split_heading( $line );
			if ( '' === $heading && ! $items ) continue;
			$rows = array();
			$run  = false;
			foreach ( $items as $item ) {
				$parts = array_map( 'trim', explode( '|', $item, 2 ) );
				$text  = $parts[0];
				$guide = isset( $parts[1] ) ? $parts[1] : '';
				if ( '' === $text ) continue;
				$pick = 0 === stripos( $guide, 'pick one' );
				if ( $pick && ! $run ) $group++;
				$run = $pick;
				$rows[] = array( 'id' => BFTD_Schema::line_key( $text ), 'text' => $text, 'guide' => $guide, 'group' => $pick ? $group : 0 );
			}
			$out[] = array( 'heading' => $heading, 'items' => $rows );
		}
		return $out;
	}

	/** A top-level bullet: its own words (the heading) and the bullets under it, as text. */
	private static function split_heading( $html ) {
		$items = array();
		if ( preg_match_all( '#<li[^>]*>(.*?)</li>#is', (string) $html, $m ) ) {
			foreach ( $m[1] as $li ) $items[] = self::text( $li );
		}
		$own = preg_replace( '#<(ul|ol)[^>]*>.*$#is', '', (string) $html );
		return array( self::text( $own ), $items );
	}

	private static function text( $html ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * What may be saved: items the list offers or the report already has,
	 * one per pick-one group, in the list's order. Each kept as its heading
	 * and wording.
	 */
	public static function sanitize( $raw, $field ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$posted = array();
		foreach ( (array) ( $raw['items'] ?? array() ) as $id ) $posted[ BFTD_Schema::line_key( (string) $id ) ] = true;
		$cat    = self::catalog( $field['picks_of'][0] ?? '', $field['picks_of'][1] ?? '' );
		$out    = array();
		$groups = array();
		$seen   = array();
		foreach ( $cat as $h ) {
			foreach ( $h['items'] as $it ) {
				if ( ! isset( $posted[ $it['id'] ] ) || isset( $seen[ $it['id'] ] ) ) continue;
				if ( $it['group'] ) {
					if ( isset( $groups[ $it['group'] ] ) ) continue;
					$groups[ $it['group'] ] = true;
				}
				$seen[ $it['id'] ] = true;
				$out[] = array( 'h' => $h['heading'], 't' => $it['text'] );
			}
		}
		// Ticked on this report before the list was reworded: kept if still ticked.
		foreach ( (array) ( $field['stored'] ?? array() ) as $was ) {
			$id = BFTD_Schema::line_key( $was['t'] ?? '' );
			if ( '' === $id || isset( $seen[ $id ] ) || ! isset( $posted[ $id ] ) ) continue;
			$seen[ $id ] = true;
			$out[] = array( 'h' => (string) ( $was['h'] ?? '' ), 't' => sanitize_text_field( (string) $was['t'] ) );
		}
		return $out;
	}

	/** The edit screen: every item, ticked or not, under its heading. */
	public static function render( $post_id, $section_id, $key, $field ) {
		$saved = BFTD_Fields::get( $post_id, $section_id, $key, $field );
		$saved = is_array( $saved ) ? $saved : array();
		$name  = 'bftd[' . esc_attr( $section_id ) . '][' . esc_attr( $key ) . ']';
		$base  = 'bftd_' . str_replace( '-', '_', $section_id ) . '_' . $key;
		$on    = array();
		foreach ( $saved as $s ) $on[ BFTD_Schema::line_key( $s['t'] ?? '' ) ] = $s;
		$cat   = self::catalog( $section_id, $key );
		$known = array();
		foreach ( $cat as $h ) foreach ( $h['items'] as $it ) $known[ $it['id'] ] = true;
		$i = 0;
		?>
		<div class="bftd-field bftd-picks" data-goal="<?php echo esc_attr( $field['goal'] ?? '' ); ?>">
			<label class="bftd-label"><?php echo esc_html( $field['label'] ); ?></label>
			<?php if ( ! empty( $field['instructions'] ) ) : ?>
				<div class="bftd-picks-how"><strong>For tutors.</strong> <?php echo esc_html( $field['instructions'] ); ?> The family sees only what is ticked, under its heading; the guidance column is never shown to them.</div>
			<?php endif; ?>
			<p class="bftd-picks-count" aria-live="polite"></p>
			<?php // Always posts, so unticking everything is saved as none rather than ignored. ?>
			<input type="hidden" name="<?php echo $name; ?>[on]" value="1">
			<table class="widefat bftd-picks-table">
				<thead><tr><th scope="col" class="bftd-picks-tick"><span class="screen-reader-text">Ticked</span></th><th scope="col">Shown to the family</th><th scope="col">Tutor guidance</th></tr></thead>
				<tbody>
				<?php foreach ( $cat as $h ) : ?>
					<?php if ( '' !== $h['heading'] ) : ?><tr class="bftd-picks-h"><th scope="rowgroup" colspan="3"><?php echo esc_html( $h['heading'] ); ?></th></tr><?php endif; ?>
					<?php foreach ( $h['items'] as $it ) : $id = $base . '_' . ( $i++ ); ?>
						<tr<?php echo $it['group'] ? ' class="is-pick-one"' : ''; ?>>
							<td class="bftd-picks-tick"><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo $name; ?>[items][]" value="<?php echo esc_attr( $it['text'] ); ?>"<?php echo $it['group'] ? ' data-group="' . (int) $it['group'] . '"' : ''; ?><?php checked( isset( $on[ $it['id'] ] ) ); ?>></td>
							<td><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $it['text'] ); ?></label></td>
							<td class="bftd-picks-guide"><?php echo esc_html( $it['guide'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				<?php $gone = array_filter( $on, function ( $s, $k ) use ( $known ) { return ! isset( $known[ $k ] ); }, ARRAY_FILTER_USE_BOTH ); ?>
				<?php if ( $gone ) : ?>
					<tr class="bftd-picks-h"><th scope="rowgroup" colspan="3">No longer on the list</th></tr>
					<?php foreach ( $gone as $s ) : $id = $base . '_' . ( $i++ ); ?>
						<tr><td class="bftd-picks-tick"><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo $name; ?>[items][]" value="<?php echo esc_attr( $s['t'] ); ?>" checked></td>
							<td><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $s['t'] ); ?></label></td>
							<td class="bftd-picks-guide">Ticked before the list changed. Untick to remove it.</td></tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Ticked items grouped by heading, in the order saved: heading => items. */
	public static function grouped( $saved ) {
		$out = array();
		foreach ( (array) $saved as $s ) {
			$t = trim( (string) ( $s['t'] ?? '' ) );
			if ( '' === $t ) continue;
			$out[ (string) ( $s['h'] ?? '' ) ][] = $t;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The lists as shipped, from Karl's document                          */
	/* ------------------------------------------------------------------ */

	private static function to_html( $lists ) {
		$out = '<ul>';
		foreach ( $lists as $heading => $items ) {
			$out .= '<li>' . esc_html( $heading ) . '<ul>';
			foreach ( $items as $it ) $out .= '<li>' . esc_html( $it[0] . ( isset( $it[1] ) && '' !== $it[1] ? ' | ' . $it[1] : '' ) ) . '</li>';
			$out .= '</ul></li>';
		}
		return $out . '</ul>';
	}

	public static function strengths_shipped() {
		return self::to_html( array(
			'Handwriting' => array(
				array( 'Handwriting automaticity exceeds benchmark', 'Timed letter-writing task above benchmark.' ),
				array( 'No letter reversals — None of the letters were mirrored or flipped. e.g. b/d, p/q, n/u, s, z, j, J, N, / e, g, k, a', 'Covers reversals in all writing tasks, including spelling. Not repeated under Spelling.' ),
				array( 'Forms letters correctly — moves from top to bottom and left to right', 'Stroke formation within each letter.' ),
				array( 'Writes the alphabet in the correct order', 'Letter sequence when writing the alphabet.' ),
				array( 'Prints all letters in lowercase', '' ),
			),
			'Phonemic Awareness' => array(
				array( 'Perfect phoneme segmentation score (36/36) — identifies every sound in a word, in order, with nothing added or left out', 'Pick one of the first three. A perfect score already covers simple words and blends, so don\'t add the blends item.' ),
				array( 'Separates simple three-sound words (CVC) into sounds with complete accuracy: e.g., sat, rug, win', 'Pick one. Every CVC word correct, but not a perfect overall score.' ),
				array( 'Separates simple three-sound words (CVC) into sounds with high accuracy', 'Pick one. Most CVC words correct, a few errors.' ),
				array( 'Separates words with consonant blends into sounds accurately: e.g., fast, school', 'Use with one of the two CVC items. Skip if the perfect score is checked.' ),
			),
			'Word Reading Level (San Diego Quick Assessment)' => array(
				array( 'Reads word lists independently at enrolled grade level', 'Pick one.' ),
				array( 'Reads word lists independently above enrolled grade level', 'Pick one.' ),
				array( 'No confusion of letter names with sounds', '' ),
			),
			'Reading Fluency' => array(
				array( 'Reading rate (words correct per minute) at enrolled grade level', 'Pick one.' ),
				array( 'Reading rate (words correct per minute) above enrolled grade level', 'Pick one.' ),
				array( 'Self-corrects reading errors', '' ),
				array( 'Reads with expression and natural phrasing (prosody)', '' ),
			),
			'Spelling' => array(
				array( 'Spells every sound in simple three-sound words (CVC) when slowing down to listen', 'Pick one. Accuracy on CVC words.' ),
				array( 'Spells every sound in simple three-sound words (CVC)', 'Pick one. Accuracy on CVC words' ),
				array( 'Writes a spelling for every sound: no sounds left out', 'Completeness, not accuracy. Use even if some spellings are wrong.' ),
				array( 'Writes the spellings for each sound in the correct order', 'Sequence only.' ),
				array( 'Spells short vowel sounds correctly, as in bat, bet, bit, bot, but', '' ),
				array( 'Spells common two-letter spellings correctly: th, sh, wh, ck, ch', 'Secure on these five.' ),
				array( 'Shows some familiarity with spellings that use 2, 3, or 4 letters for one sound, e.g., sh, igh, ough', 'Emerging. Use only if 3- and 4-letter spellings showed up. Otherwise the item above covers it.' ),
				array( 'Attempts every spelling word', 'Pick one.' ),
				array( 'Attempts most spelling words', 'Pick one.' ),
			),
			'Writing' => array(
				array( 'Comes up with a writing idea independently', 'Moved from Personal.' ),
				array( 'Writes complete, logical sentences. Each has a subject and an action', 'Merges "Logical sentence structure," "Clear sentence structure," and the noun + action item.' ),
				array( 'Uses correct grammar in writing', 'Grammar only. Sentence completeness is the item above.' ),
				array( 'Varies sentence length and structure', 'Was "Variety in text structure."' ),
				array( 'Leaves consistent spaces between words', 'Merges the two spacing items.' ),
				array( 'Writing has a clear beginning, middle, and end', '' ),
				array( 'Uses descriptive words to add detail', '' ),
				array( 'Uses multisyllable words correctly in writing', 'Pick one. Longer words used and spelled correctly.' ),
				array( 'Attempts longer, multisyllable words in writing', 'Pick one. Tried longer words; spelling not yet accurate.' ),
			),
			'Personal' => array(
				array( 'Recognizes and can explain where reading becomes confusing', 'Self-awareness. Self-correcting errors is under Reading Fluency.' ),
				array( 'Highly motivated — eager to begin and keen to learn', '' ),
				array( 'Reported learning something new during the assessment', '' ),
				array( 'Approaches tasks with confidence — starts without hesitation', '' ),
				array( 'Keeps going when tasks get difficult', 'Persistence.' ),
				array( 'Willing to try unfamiliar tasks', '' ),
				array( 'Cooperative and fully engaged', '' ),
				array( 'Positive, upbeat attitude', '' ),
				array( 'Polite and respectful', '' ),
			),
		) );
	}

	public static function growth_shipped() {
		return self::to_html( array(
			'Handwriting' => array(
				array( 'Handwriting automaticity. Letter-writing speed is below benchmark', '' ),
				array( 'Letter reversals. One or more letters were mirrored or flipped, e.g. b/d, p/q, n/u, s, z, j, J, N, / e, g, k, a', '' ),
				array( 'Writing letters in alphabet in order. Some letters skipped or out of sequence', '' ),
				array( 'Letter formation. Some letters formed from bottom to top.', 'Stroke formation within each letter.' ),
				array( 'Letter formation: Some letters formed from right to left.', 'Stroke formation within each letter.' ),
				array( 'Lowercase letter formation. Some letters not yet shaped correctly', 'Letter shape, not stroke direction.' ),
				array( 'Mixes up upper-case and lower-case letters', 'Pick one. Unsure which form is which in Alphabet HW' ),
				array( 'Knows the difference between upper-case and lower-case letters but does not know when to use them.', 'Pick one. Prints lower-case in Alphabet HW, but mis-applies or doesn\'t apply capitals in writing assessment.' ),
			),
			'Phonemic Awareness' => array(
				array( 'Early sound awareness — beginning to hear separate sounds in words', 'Use alone when most sound tasks were difficult. Skip the specific items below.' ),
				array( 'Separating simple three-sound words (CVC) into sounds — e.g., sat, rug, win', '' ),
				array( 'Separating words with consonant blends into sounds — e.g., fast, school', '' ),
				array( 'Separating longer, multisyllable words into sounds', '' ),
				array( 'Leaving out sounds within words', '' ),
				array( 'Adding extra sounds to words', '' ),
			),
			'Word Reading Level (San Diego Quick Assessment)' => array(
				array( 'Reads word lists below enrolled grade level', 'Pick one. Suggested cut-off: about one grade below.' ),
				array( 'Reads word lists well below enrolled grade level', 'Pick one. Suggested cut-off: two or more grades below.' ),
			),
			'Reading Accuracy' => array(
				array( 'Uses letter names instead of sounds when reading words', '' ),
				array( 'Guesses words from context instead of reading every sound', '' ),
				array( 'Reading word endings — e.g., <ed>, <tion>, <ous>, <ate>', 'Same struggle may appear in spelling and writing' ),
			),
			'Reading Fluency' => array(
				array( 'Reading rate (words correct per minute) below enrolled grade level', 'Pick one.' ),
				array( 'Reading rate (words correct per minute) well below enrolled grade level', 'Pick one.' ),
				array( 'Noticing and correcting reading errors', '' ),
				array( 'Reading with expression and natural phrasing (prosody)', '' ),
			),
			'Spelling' => array(
				array( 'Spelling every sound in simple three-sound words (CVC)', '' ),
				array( 'Leaving out sounds when spelling', '' ),
				array( 'Adding extra sounds when spelling', '' ),
				array( 'Writing spellings in the order the sounds are heard', '' ),
				array( 'Hearing and spelling short vowel sounds — as in bat, bet, bit, bot, but', '' ),
				array( 'Basic code — spellings such as <th>, <sh>, <ch>, <wh>, <ck>, <tch>', '' ),
				array( 'Spellings that use 2, 3, or 4 letters for one sound — e.g., <ee>, <igh>, <ough>', '' ),
				array( 'Spelling conventions — e.g., <c> or <k> for /k/ at the start of a word, <k> or <ck> at the end, silent e', '' ),
				array( 'Spelling word endings — e.g., <ed>, <tion>, <ous>, <ate>', 'Spelling only. Can pair with the reading version.' ),
				array( 'Attempting every spelling word — some words skipped', '' ),
			),
			'Writing' => array(
				array( 'Coming up with a writing idea independently', '' ),
				array( 'Writing complete sentences — each with a subject and an action', '' ),
				array( 'Capital letters — at the start of sentences and for names', '' ),
				array( 'End punctuation — periods and question marks', '' ),
				array( 'Grammar in writing', '' ),
				array( 'Varying sentence length and structure', '' ),
				array( 'Consistent spacing between words', '' ),
				array( 'Organizing writing with a clear beginning, middle, and end', '' ),
				array( 'Using descriptive words to add detail', '' ),
				array( 'Trying longer, multisyllable words in writing', '' ),
			),
			'Personal' => array(
				array( 'Sustained attention and focus', '' ),
				array( 'Noticing when reading stops making sense', '' ),
				array( 'Confidence — hesitant to begin new tasks', '' ),
				array( 'Persistence when tasks get difficult', '' ),
				array( 'Willingness to try unfamiliar tasks', '' ),
				array( 'Motivation and buy-in for reading', '' ),
				array( 'Cooperation during structured tasks', '' ),
				array( 'Positive attitude toward reading and writing tasks', '' ),
			),
		) );
	}
}
