<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * What the practice's libraries have in common.
 *
 * There are two now — the numbered activity programme and the skills those
 * activities teach — and they are the same shape: a short list of records
 * that senior managers maintain, that tutors point at from a lesson, and that
 * must never be typed out by hand because the same thing typed twice is two
 * things on a family's report.
 *
 * Only the picker lives here, and only because it is the part with the
 * behaviour: the search, the list of matches, the difference between looking
 * at something and taking it. Two copies of that would be two sets of
 * keyboard handling to keep in step, and the second one would be the one
 * nobody tested.
 *
 * The libraries themselves stay separate classes. An activity has a number
 * and a place in a sequence; a skill does not. Folding those into one
 * parameterised type would mean every reader asking which kind it is.
 */
class BFTD_Library {

	/**
	 * One record chosen from a library.
	 *
	 * $cfg: name, selected, options (id => label), noun, placeholder, add_url.
	 *
	 * The markup is deliberately the same for every library, because the
	 * script that drives it is matched on these class names and a second set
	 * would be a second thing to keep working.
	 */
	public static function picker( $cfg ) {
		$cfg = wp_parse_args( $cfg, array(
			'name'        => '',
			'selected'    => 0,
			'options'     => array(),
			'noun'        => 'item',
			'placeholder' => 'Search',
			'add_url'     => '',
			'id_attr'     => '',

			// An optional second axis to narrow by. 'groups' says which bucket
			// each option id is in, 'buckets' names them in the order they
			// should be offered, and 'bucket_label' is what the row of buttons
			// is called to somebody using a screen reader.
			'groups'       => array(),
			'buckets'      => array(),
			'bucket_label' => 'Group',

			// Where a library numbers its records, the number of each one by
			// id. Given, the search can tell a number from a word: typing 12
			// then means activity twelve rather than every label with the
			// digits one and two somewhere in it. Left out, the search is
			// words only, which is right for a library that has no numbers.
			'numbers'      => array(),

			// And the name on its own, without the number in front of it,
			// so searching for words does not match digits and searching
			// for digits does not match words.
			'names'        => array(),

			// What the chosen one is called once it is settled. The list
			// leaves the bucket off every line to keep the names readable;
			// the one that has been taken has to carry it, because it is on
			// the record now and has to say which of two twelves it is.
			'chosen_label' => '',
		) );

		$options  = (array) $cfg['options'];
		$selected = (int) $cfg['selected'];
		$chosen   = isset( $options[ $selected ] ) ? $options[ $selected ] : '';
		if ( '' !== $chosen && '' !== (string) $cfg['chosen_label'] ) $chosen = (string) $cfg['chosen_label'];
		$noun     = $cfg['noun'];
		$groups   = (array) $cfg['groups'];
		$buckets  = (array) $cfg['buckets'];
		$numbers  = (array) $cfg['numbers'];
		$names    = (array) $cfg['names'];

		ob_start();
		?>
		<div class="bftd-actpick<?php echo '' !== $chosen ? ' is-chosen' : ''; ?>">
			<?php
			/*
			 * What has been chosen, once something has.
			 *
			 * Searching is not choosing. Somebody typing three letters and
			 * seeing the list narrow to one has not decided anything yet, and
			 * a screen that decided for them would quietly attach a lesson's
			 * notes and a child's work to something nobody picked. So the
			 * search stands aside the moment a record is taken, and what is
			 * left is the record itself with a way back to the list.
			 */
			?>
			<div class="bftd-actpick-is"<?php echo '' === $chosen ? ' hidden' : ''; ?>>
				<span class="bftd-actpick-name"><?php echo esc_html( $chosen ); ?></span>
				<button type="button" class="button-link bftd-actpick-change">Change</button>
			</div>

			<div class="bftd-actpick-find"<?php echo '' !== $chosen ? ' hidden' : ''; ?>>
				<?php if ( count( $buckets ) > 1 ) : ?>
					<?php
					/*
					 * Narrowing by something other than the words.
					 *
					 * The activity library is two programmes, and each numbers
					 * its own hundred and forty from one. So there are two
					 * twelves, and a tutor who knows they want the twelfth of
					 * Track 1 cannot say so by typing: "12" matches both, and
					 * also 112, 120 and 121. The track has to be a separate
					 * thing to say.
					 *
					 * Buttons rather than a dropdown, because there are two of
					 * them plus "all" and the whole point is to be one click
					 * away while typing. "All" is first and on by default: a
					 * tutor who does not know which track something is in must
					 * not have to guess before they can search at all.
					 */
					?>
					<div class="bftd-actpick-b" role="group" aria-label="<?php echo esc_attr( $cfg['bucket_label'] ); ?>">
						<button type="button" class="bftd-actpick-t is-on" data-bucket="" aria-pressed="true">All</button>
						<?php foreach ( $buckets as $key => $label ) : ?>
							<button type="button" class="bftd-actpick-t" data-bucket="<?php echo esc_attr( $key ); ?>"
								aria-pressed="false"><?php echo esc_html( $label ); ?></button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php
				/*
				 * The search box and the list of matches under it, in a box of
				 * their own.
				 *
				 * The list is positioned against this wrapper rather than
				 * against the whole picker, because the whole picker now has a
				 * row of track buttons above the input and a list measured
				 * from the top of that would come down on top of the box it is
				 * meant to hang below.
				 */
				?>
				<div class="bftd-actpick-box">
					<input type="search" class="bftd-actpick-q" placeholder="<?php echo esc_attr( $cfg['placeholder'] ); ?>"
						aria-label="<?php echo esc_attr( 'Search ' . self::plural( $noun ) ); ?>"
						autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list">
					<?php
					/*
					 * Where the matches are listed as somebody types.
					 *
					 * Empty in the markup and filled by the script. A list
					 * rendered here would be a second copy of the library on
					 * the page for every row on the screen, and it would be
					 * the state of the search before anybody had searched.
					 */
					?>
					<ul class="bftd-actpick-r" role="listbox" aria-label="<?php echo esc_attr( 'Matching ' . self::plural( $noun ) ); ?>" hidden></ul>
				</div>
				<select name="<?php echo esc_attr( $cfg['name'] ); ?>" class="bftd-actpick-s"<?php
					echo $cfg['id_attr'] ? ' id="' . esc_attr( $cfg['id_attr'] ) . '"' : ''; ?>>
					<option value="">Choose <?php echo esc_html( self::an( $noun ) ); ?></option>
					<?php
					/*
					 * With the script blocked this is the whole field, so the
					 * buckets are optgroups: a long list a browser can still
					 * be walked through by section. With the script running
					 * they are also what it filters on, read off data-bucket,
					 * because an optgroup label is a translated string and the
					 * key is not.
					 */
					$open = null;
					foreach ( $options as $id => $label ) :
						$bucket = isset( $groups[ $id ] ) ? (string) $groups[ $id ] : '';
						if ( $buckets && $bucket !== $open ) {
							if ( null !== $open ) echo '</optgroup>';
							$open = $bucket;
							echo '<optgroup label="' . esc_attr(
								isset( $buckets[ $bucket ] ) ? $buckets[ $bucket ] : 'Other'
							) . '">';
						}
						?>
						<option value="<?php echo (int) $id; ?>"<?php
							echo $buckets ? ' data-bucket="' . esc_attr( $bucket ) . '"' : '';
							if ( isset( $numbers[ $id ] ) && '' !== (string) $numbers[ $id ] ) {
								echo ' data-number="' . esc_attr( $numbers[ $id ] ) . '"';
							}
							if ( isset( $names[ $id ] ) && '' !== (string) $names[ $id ] ) {
								echo ' data-name="' . esc_attr( $names[ $id ] ) . '"';
							}
							?> <?php selected( $selected, (int) $id ); ?>><?php
							echo esc_html( $label );
						?></option>
					<?php endforeach;
					if ( $buckets && null !== $open ) echo '</optgroup>';
					?>
				</select>
				<?php if ( ! $options ) : ?>
					<p class="description bftd-actpick-none"><?php
						echo esc_html( 'No ' . self::plural( $noun ) . ' in the library yet.' );
						if ( $cfg['add_url'] && BFTD_Roles::can_manage_team() ) {
							echo ' <a href="' . esc_url( $cfg['add_url'] ) . '">Add the first one</a>.';
						} else {
							echo ' A senior manager adds them.';
						}
					?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/** "an activity", "a skill". Only ever used on words we ship. */
	private static function an( $noun ) {
		return ( in_array( strtolower( substr( $noun, 0, 1 ) ), array( 'a', 'e', 'i', 'o', 'u' ), true ) ? 'an ' : 'a ' ) . $noun;
	}

	/** "activities", "skills". Screen readers were being told "activitys". */
	private static function plural( $noun ) {
		return ( 'y' === substr( $noun, -1 ) && ! in_array( substr( $noun, -2, 1 ), array( 'a', 'e', 'i', 'o', 'u' ), true ) )
			? substr( $noun, 0, -1 ) . 'ies'
			: $noun . 's';
	}
}
