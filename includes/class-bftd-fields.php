<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders and saves the fields a section declares in BFTD_Schema, and records
 * a field-level before and after for every save.
 *
 * One renderer for every section of every report means a new section is a
 * schema entry and nothing else, and it means the rule about empty fields is
 * enforced in one place rather than in each report's own template.
 *
 * Nothing here decides what a section contains. It only knows how to draw a
 * field of each type, how to read one back safely, and how to describe what
 * changed.
 */
class BFTD_Fields {

	/* ------------------------------------------------------------------ */
	/* Reading                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * A stand-in set of values, used by the sample report.
	 *
	 * The sample has to look exactly like a real report, and the only way to
	 * guarantee that is for it to BE a real report as far as the renderer is
	 * concerned. So rather than a second template that would drift from the
	 * first the week after it was written, the sample supplies values here
	 * and everything downstream runs unchanged.
	 *
	 * Never set during a normal request, and cleared the moment the sample
	 * has rendered.
	 */
	private static $fixture = null;

	public static function use_fixture( $data ) {
		self::$fixture = is_array( $data ) ? $data : null;
	}

	public static function clear_fixture() {
		self::$fixture = null;
	}

	public static function fixture_active() {
		return null !== self::$fixture;
	}

	public static function get( $post_id, $section_id, $field_key, $field = array() ) {
		// A fixed field is part of the task rather than part of the record.
		// It is answered here rather than stored, so a report written before
		// somebody changed it does not go on quoting the old figure, and so
		// there is no way for the number a parent reads to disagree with the
		// number the bar is worked out from.
		if ( isset( $field['fixed'] ) ) return $field['fixed'];

		// A derived field is arithmetic somebody would otherwise do in their
		// head between two boxes on the same screen. Doing it here means the
		// answer cannot disagree with the figures it came from: correcting an
		// error count after the fact updates the result, where a typed figure
		// would quietly keep the old one.
		if ( isset( $field['derived']['count'] ) ) {
			return self::count_answers( $post_id, $section_id, $field['derived']['count'] );
		}
		if ( isset( $field['derived'] ) ) {
			$out = self::derive( $post_id, $section_id, $field['derived'] );
			if ( '' === $out ) $out = self::derive_else( $post_id, $section_id, $field['derived'] );
			return $out;
		}

		if ( null !== self::$fixture ) {
			// A sample can carry other records too, such as the lessons a
			// progress report is built from, keyed by their own id.
			if ( isset( self::$fixture['__posts'][ $post_id ][ $section_id ][ $field_key ] ) ) {
				return self::$fixture['__posts'][ $post_id ][ $section_id ][ $field_key ];
			}
			if ( isset( self::$fixture['__posts'][ $post_id ] ) ) {
				$fb = self::fallback( $section_id, $field_key, $field );
				return ( null === $fb ) ? '' : $fb;
			}

			$val = isset( self::$fixture[ $section_id ][ $field_key ] ) ? self::$fixture[ $section_id ][ $field_key ] : '';

			// A sample resolves an unfilled field exactly as a real report
			// does, through fallback(), rather than through $field['default']
			// alone. Standing wording lives under 'shipped' and is read back
			// through the practice's own setting, so a fixture that only
			// knew about defaults showed the sample with its introductions
			// and its standing answers missing entirely — which is what
			// happened, silently, to the section that had already been
			// converted. The sample is what gets shown to families and to
			// new tutors, so it has to resolve the way the real thing does.
			if ( ! self::has_value( $val ) ) {
				$fb = self::fallback( $section_id, $field_key, $field );
				if ( null !== $fb ) return $fb;
			}
			return $val;
		}

		$val = get_post_meta( $post_id, BFTD_Schema::meta_key( $section_id, $field_key ), true );

		// A field that has never been saved holds '', and one saved from an
		// untouched editor holds <p></p> or a stray newline. Both are empty
		// to the person looking at them, so both get the standing wording
		// back. Testing for '' alone meant the wording appeared once and was
		// gone for good the first time anybody pressed Update.
		if ( self::has_value( $val ) ) return $val;

		$fallback = self::fallback( $section_id, $field_key, $field );
		return ( null === $fallback ) ? $val : $fallback;
	}

	/**
	 * What a field says when nobody has written anything into it.
	 *
	 * Standing wording is the practice's, so it is read through the settings
	 * that own it rather than from the schema: an administrator who changes
	 * the practice's explanation should see it change on every report that
	 * has not been given its own version, not only on ones created afterwards.
	 *
	 * A plain default is just a default.
	 */
	/**
	 * Work out a derived figure from other fields in the same section.
	 *
	 * Blank in, blank out. If either figure has not been entered yet there is
	 * no answer, and reporting one anyway would mean a card appearing for a
	 * child nobody has assessed. A result below zero means somebody typed more
	 * errors than words, which is a slip rather than a finding, so it is held
	 * at zero here and pointed out on the tutor's screen rather than printed
	 * for a parent as a negative.
	 */
	private static function derive( $post_id, $section_id, $rule ) {
		$section = BFTD_Schema::section( $section_id );
		if ( ! $section || empty( $rule['minus'] ) || count( $rule['minus'] ) < 2 ) return '';

		$parts = array();
		foreach ( $rule['minus'] as $key ) {
			$def = isset( $section['fields'][ $key ] ) ? $section['fields'][ $key ] : array();
			$val = self::get( $post_id, $section_id, $key, $def );
			if ( ! self::has_value( $val ) ) return '';
			$parts[] = (float) $val;
		}

		$out = array_shift( $parts );
		foreach ( $parts as $n ) $out -= $n;
		return (string) max( 0, $out );
	}

	/**
	 * Count answers across one or more checklists.
	 *
	 * With a value named, it counts the rows answered that way. Without one,
	 * it counts every row anybody answered, which is what the first figure is
	 * measured against. Both come from the same walk, so the numerator can
	 * never be counted out of a different set than the denominator.
	 *
	 * Nothing answered means no figure at all, rather than nought out of
	 * nought, because a section nobody has filled in is not a finding of zero.
	 */
	private static function count_answers( $post_id, $section_id, $rule ) {
		$section = BFTD_Schema::section( $section_id );
		if ( ! $section || empty( $rule['fields'] ) ) return '';

		$want = isset( $rule['value'] ) ? (array) $rule['value'] : array();
		$n    = 0;
		$any  = false;

		foreach ( (array) $rule['fields'] as $key ) {
			$def  = isset( $section['fields'][ $key ] ) ? $section['fields'][ $key ] : array();
			$rows = self::get( $post_id, $section_id, $key, $def );
			if ( ! is_array( $rows ) ) continue;
			foreach ( $rows as $row ) {
				$v = ( is_array( $row ) && isset( $row['value'] ) ) ? (string) $row['value'] : '';
				if ( '' === $v ) continue;
				$any = true;
				if ( ! $want || in_array( $v, $want, true ) ) $n++;
			}
		}
		return $any ? (string) $n : '';
	}

	/**
	 * Where a derived figure comes from when the sum has nothing to work with.
	 *
	 * A student reading at Kindergarten level does a word list rather than a
	 * passage, so there are no words-read and errors to subtract. The figure
	 * the card reports is then the one typed against that assessment instead.
	 * Without this the section simply has no score, and a child who was
	 * assessed drops off the summary cards entirely.
	 */
	private static function derive_else( $post_id, $section_id, $rule ) {
		if ( empty( $rule['else'] ) ) return '';
		$section = BFTD_Schema::section( $section_id );
		$def     = isset( $section['fields'][ $rule['else'] ] ) ? $section['fields'][ $rule['else'] ] : array();
		return self::get( $post_id, $section_id, $rule['else'], $def );
	}

	/** Did the figures a derived field was worked out from not make sense? */
	public static function derive_warning( $post_id, $section_id, $field ) {
		if ( empty( $field['derived']['minus'] ) ) return '';
		$section = BFTD_Schema::section( $section_id );
		$vals    = array();
		foreach ( $field['derived']['minus'] as $key ) {
			$def = isset( $section['fields'][ $key ] ) ? $section['fields'][ $key ] : array();
			$v   = self::get( $post_id, $section_id, $key, $def );
			if ( ! self::has_value( $v ) ) return '';
			$vals[ $key ] = (float) $v;
		}
		$keys = array_keys( $vals );
		if ( $vals[ $keys[1] ] > $vals[ $keys[0] ] ) {
			return 'There are more ' . strtolower( $section['fields'][ $keys[1] ]['label'] )
				. ' than ' . strtolower( $section['fields'][ $keys[0] ]['label'] ) . '. Check both figures.';
		}
		return '';
	}

	public static function fallback( $section_id, $field_key, $field = array() ) {
		if ( isset( $field['shipped'] ) ) {
			return BFTD_Schema::standing_default( $section_id, $field_key );
		}
		return isset( $field['default'] ) ? $field['default'] : null;
	}

	/**
	 * May this person change this field?
	 *
	 * Most fields, anybody who can edit the record. A few carry the practice's
	 * standing wording rather than one child's results, and those are held
	 * higher up: a paragraph explaining what the assessment measures is the
	 * same promise on every report the practice sends, so it is not one
	 * tutor's to reword on a Tuesday.
	 *
	 * The check is by rank, so it needs saying once rather than per role.
	 */
	public static function may_edit( $field, $user_id = null ) {
		// Nobody edits a fixed or a derived field, whatever their rank. Hiding
		// the input is not the rule; the rule is that the value does not come
		// from the form, so a posted one is ignored on the way in as well.
		if ( isset( $field['fixed'] ) ) return false;
		if ( isset( $field['derived'] ) ) return false;
		if ( empty( $field['edit_rank'] ) ) return true;
		if ( ! class_exists( 'BFTD_Roles' ) ) return true;
		$user_id = $user_id ? $user_id : get_current_user_id();
		return BFTD_Roles::rank( $user_id ) >= (int) $field['edit_rank'];
	}

	/**
	 * Write one field.
	 *
	 * Used by the CRM merge, which has a value in hand rather than a posted
	 * form. Goes through the same key builder as everything else, so a synced
	 * value and a typed value land in exactly the same place.
	 */
	public static function set( $post_id, $section_id, $field_key, $value ) {
		return update_post_meta( (int) $post_id, BFTD_Schema::meta_key( $section_id, $field_key ), $value );
	}

	/** Every field of a section, as a flat map. */
	public static function get_section( $post_id, $section_id ) {
		$section = BFTD_Schema::section( $section_id );
		if ( ! $section ) return array();
		$out = array();
		foreach ( $section['fields'] as $key => $field ) {
			$out[ $key ] = self::get( $post_id, $section_id, $key, $field );
		}
		return $out;
	}

	/**
	 * The single rule about empty content, applied to every field type. A
	 * section, row or value that has nothing in it is never rendered to a
	 * family — a half-written report shows the parts that are written, not a
	 * grid of blanks.
	 */
	public static function has_value( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( self::has_value( $v ) ) return true;
			}
			return false;
		}
		// What a person sees, not what the string is. An editor left alone
		// still posts a paragraph, and a paragraph holding one non-breaking
		// space is a blank box to everybody except strlen().
		$plain = wp_strip_all_tags( (string) $value );
		$plain = str_replace( array( '&nbsp;', "\xc2\xa0", '&#160;' ), ' ', $plain );
		return '' !== trim( $plain );
	}

	/** Does a section have anything at all worth showing? */
	public static function section_has_content( $post_id, $section_id ) {
		$section = BFTD_Schema::section( $section_id );

		/*
		 * A section built from the lessons has no stored fields of its own, so
		 * reading them found nothing and the pill announced "Empty, so not
		 * shown to the family" over a table the family could plainly see.
		 * Asked of the thing that builds it, the answer is the truth.
		 */
		if ( class_exists( 'BFTD_Derived' ) && BFTD_Derived::is_derived( $section_id ) ) {
			return BFTD_Derived::has( $section_id, $post_id );
		}
		foreach ( self::get_section( $post_id, $section_id ) as $key => $v ) {
			$field = $section && isset( $section['fields'][ $key ] ) ? $section['fields'][ $key ] : array();

			// A value the schema supplies is not evidence that anybody did
			// the assessment. Section two's denominator is a fixed 36, so an
			// untouched section counted as started on the strength of a
			// number no tutor typed, and the pill told them a blank section
			// was ready to publish. A derived figure is the same: it is only
			// as real as the fields underneath it, which are checked here in
			// their own right.
			if ( ! empty( $field['fixed'] ) || ! empty( $field['derived'] ) ) continue;

			// Standing text a person never typed is not a report. A section
			// whose only content is its own boilerplate has not been started,
			// and showing it to a family would be showing them an explanation
			// of an assessment nobody did.
			$fb = $section ? self::fallback( $section_id, $key, $section['fields'][ $key ] ) : null;
			if ( null !== $fb && $v === $fb ) continue;
			if ( self::has_value( $v ) ) return true;
		}
		return false;
	}

	/** Rows whose meaningful cells are all empty are dropped. */
	public static function visible_rows( $rows, $required = array() ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) continue;
			if ( $required ) {
				$ok = false;
				foreach ( $required as $k ) {
					if ( isset( $row[ $k ] ) && self::has_value( $row[ $k ] ) ) { $ok = true; break; }
				}
				if ( ! $ok ) continue;
			} elseif ( ! self::has_value( $row ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering the editor                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * What the header pill says about a section: empty, hidden, waiting, live.
	 *
	 * One definition, because two places need it. The header draws it when the
	 * page loads, and an autosave hands it back afterwards so the pill is not
	 * left describing the record as it was before the tutor started typing.
	 *
	 * The alternative was working it out again in the browser, which means a
	 * second copy of "does standing wording count as content" living somewhere
	 * it cannot be tested against the one that decides what a family sees.
	 *
	 * The record's own status is part of the answer. A family is only ever
	 * served a published report, so on a draft nothing is live no matter how
	 * complete the section is, and a pill reading "Live for the family" over
	 * an unpublished report is telling a tutor the family can already see
	 * work the family cannot reach. The draft says so in as many words.
	 */
	public static function pill_state( $post_id, $section_id ) {
		if ( ! self::section_has_content( $post_id, $section_id ) ) {
			return array( 'tone' => 'quiet', 'text' => 'Empty, so not shown to the family' );
		}
		if ( ! self::is_visible( $post_id, $section_id ) ) {
			return array( 'tone' => 'clay', 'text' => 'Hidden from the family' );
		}
		if ( ! self::reaches_family( $post_id ) ) {
			return array( 'tone' => 'purple', 'text' => 'Not live' );
		}
		return array( 'tone' => 'sage', 'text' => 'Live for the family' );
	}

	/**
	 * Can a family reach this record at all?
	 *
	 * Only a published one. The portal asks for published reports and nothing
	 * else, so draft, pending, private and auto-draft are all the same answer
	 * from the family's side of the glass: there is nothing there.
	 *
	 * A scheduled report counts, because the publish is already booked and
	 * the only thing between the family and it is the clock.
	 */
	public static function reaches_family( $post_id ) {
		$status = get_post_status( $post_id );
		return in_array( $status, array( 'publish', 'future' ), true );
	}

	public static function render_section( $post_id, $section_id ) {
		$section = BFTD_Schema::section( $section_id );
		if ( ! $section ) return;

		$has     = self::section_has_content( $post_id, $section_id );
		$visible = self::is_visible( $post_id, $section_id );
		?>
		<div class="bftd-section <?php echo $has ? 'has-content' : 'is-empty'; ?>" id="bftd-<?php echo esc_attr( $section_id ); ?>" data-section="<?php echo esc_attr( $section_id ); ?>">
			<div class="bftd-section-head">
				<div class="bftd-section-title">
					<span class="bftd-eyebrow"><?php echo esc_html( isset( $section['eyebrow'] ) ? $section['eyebrow'] : '' ); ?></span>
					<?php
					/*
					 * The heading is the control. A diagnostic is six of these
					 * panels and each one is a screenful, so folding one away
					 * is how a tutor gets back to the others.
					 *
					 * The button is inside the heading rather than around it:
					 * a <button> may only hold phrasing content, so wrapping
					 * the heading, the eyebrow and the description in one
					 * would be markup that browsers tolerate and assistive
					 * technology reads unpredictably. The whole header row is
					 * still clickable, through the script, which is what makes
					 * it feel like the whole thing is the control.
					 */
					?>
					<h3>
						<button type="button" class="bftd-section-toggle"
							aria-expanded="true"
							aria-controls="bftd-body-<?php echo esc_attr( $section_id ); ?>">
							<span class="bftd-chev" aria-hidden="true">
								<svg viewBox="0 0 16 16"><path d="M4 6l4 4 4-4"/></svg>
							</span>
							<?php echo esc_html( $section['label'] ); ?>
						</button>
					</h3>
					<?php if ( ! empty( $section['summary'] ) ) : ?>
						<p class="description"><?php echo esc_html( $section['summary'] ); ?></p>
					<?php endif; ?>
				</div>
				<div class="bftd-section-meta">
					<span class="bftd-where">Shows on <?php echo esc_html( BFTD_Schema::screen_label( $section['screen'] ) ); ?></span>
					<?php $pill = self::pill_state( $post_id, $section_id ); ?>
					<span class="bftd-pill bftd-pill-<?php echo esc_attr( $pill['tone'] ); ?>"><?php echo esc_html( $pill['text'] ); ?></span>
					<?php // The hidden partner always posts, so unticking means hide rather than absent. See save_post(). ?>
					<input type="hidden" name="bftd_visible[<?php echo esc_attr( $section_id ); ?>]" value="0">
					<label class="bftd-vis"><input type="checkbox" name="bftd_visible[<?php echo esc_attr( $section_id ); ?>]" value="1" <?php checked( $visible ); ?>> Show this section</label>
				</div>
			</div>

			<div class="bftd-section-body" id="bftd-body-<?php echo esc_attr( $section_id ); ?>">
				<?php if ( ! empty( $section['derived'] ) ) : ?>
					<p class="bftd-derived"><?php echo esc_html( $section['derived'] ); ?></p>
				<?php endif; ?>

				<?php foreach ( $section['fields'] as $key => $field ) : ?>
					<?php self::render_field( $post_id, $section_id, $key, $field ); ?>
				<?php endforeach; ?>

				<?php if ( ! empty( $section['thread'] ) ) : self::render_thread_note( $post_id, $section_id ); endif; ?>
			</div>
		</div>
		<?php
	}

	private static function name( $section_id, $field_key ) {
		return 'bftd[' . esc_attr( $section_id ) . '][' . esc_attr( $field_key ) . ']';
	}

	/**
	 * One field on an edit screen.
	 *
	 * $value is passed only where the value does not live in the section
	 * store this method would otherwise read: the activity library keeps its
	 * skills and its texts in meta of its own, and drawing them through the
	 * same rows editor is what keeps those tables behaving like every other
	 * table on the site.
	 */
	public static function render_field( $post_id, $section_id, $key, $field, $value = null ) {
		// The far half of a pair is drawn by the near half, not on its own.
		if ( ! empty( $field['in_pair'] ) ) return;

		// A figure that exists only to be divided by. Drawing it would put a
		// number on the screen that answers a question nobody asked.
		if ( ! empty( $field['hidden'] ) ) return;

		$type = isset( $field['type'] ) ? $field['type'] : 'text';

		// A figure worked out from two boxes above it is not a box anybody
		// fills in. It is shown rather than asked for, because an empty input
		// that silently ignores what is typed into it is worse than no input.
		if ( isset( $field['derived'] ) ) {
			self::render_derived( $post_id, $section_id, $key, $field );
			return;
		}

		// A table drawn entirely from figures already entered is not a field
		// anybody fills in. Showing it here as a read-only preview is worth
		// more than showing nothing, because a tutor can see the numbers
		// landed where they meant them to.
		if ( 'score_table' === $type || 'figures' === $type ) {
			self::render_score_preview( $post_id, $section_id, $field, $key );
			return;
		}
		if ( 'checklist' === $type ) {
			self::render_checklist( $post_id, $section_id, $key, $field );
			return;
		}
		if ( 'criteria' === $type ) {
			self::render_criteria( $post_id, $section_id, $key, $field );
			return;
		}

		if ( ! self::may_edit( $field ) ) {
			self::render_locked( $post_id, $section_id, $key, $field );
			return;
		}

		if ( null === $value ) $value = self::get( $post_id, $section_id, $key, $field );
		$id    = 'bftd_' . str_replace( '-', '_', $section_id ) . '_' . $key;
		$name  = self::name( $section_id, $key );

		if ( ! empty( $field['pair'] ) ) {
			self::render_pair( $post_id, $section_id, $key, $field );
			return;
		}
		?>
		<div class="bftd-field bftd-field-<?php echo esc_attr( $type ); ?><?php
			// An extra class a caller can hang behaviour off, for a field that
			// is only shown in some states. Nothing here decides when: that is
			// the screen's business, and keeping it out of the schema stops a
			// data definition quietly becoming a layout one.
			if ( ! empty( $field['class'] ) ) echo ' ' . esc_attr( $field['class'] );
		?>">
			<label class="bftd-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<?php
			switch ( $type ) {

				case 'textarea':
					echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . $name . '">' . esc_textarea( (string) $value ) . '</textarea>';
					break;

				case 'rich':
					wp_editor( (string) $value, $id, array(
						'textarea_name' => 'bftd[' . $section_id . '][' . $key . ']',
						'textarea_rows' => 10,
						'media_buttons' => true,
						'teeny'         => false,
					) );
					// Standing wording can be edited for one report and put
					// back again. Without a way back, the only way to undo a
					// reword is to find another report and copy from it.
					$fallback = self::fallback( $section_id, $key, $field );
					if ( null !== $fallback ) self::render_reset( $id, $fallback, $value );
					break;

				case 'select':
					/*
					 * A stored value the option list does not contain is kept
					 * as an option of its own, selected, rather than dropped.
					 *
					 * Without this the browser has nothing to select and falls
					 * back to the first option, and the next save writes that
					 * first option over the record. The value is not blanked,
					 * which would at least be visible: it is quietly replaced
					 * with a different, valid-looking one. A session stored as
									 * something this build no longer offers came back as the
					 * first status in the list, and on a draft the autosave
					 * made that permanent within three seconds of opening the
					 * record, with nobody told.
					 *
					 * It happens whenever an option key is renamed or retired,
					 * which is an ordinary thing to do and must not cost data.
					 * Shown with its raw value so somebody can see that it is
					 * not one of the current choices and decide what it should
					 * be, rather than the screen pretending it already is one.
					 */
					$opts = (array) $field['options'];
					echo '<select id="' . esc_attr( $id ) . '" name="' . $name . '">';
					if ( '' !== (string) $value && ! array_key_exists( (string) $value, $opts ) ) {
						echo '<option value="' . esc_attr( (string) $value ) . '" selected>'
							. esc_html( (string) $value ) . ' (not one of the current choices)</option>';
					}
					foreach ( $opts as $k => $label ) {
						echo '<option value="' . esc_attr( $k ) . '" ' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
					break;

				case 'staff':
					// A person, stored as an id. The picker is shared with
					// the schedule, so searching works the same way here.
					BFTD_MetaBoxes::person_picker( $name, BFTD_Roles::staff_users(), (int) $value, array(
						'id'          => $id,
						'aria'        => $field['label'],
						'placeholder' => 'Search tutors by name or email',
						'clear_label' => 'Clear ' . strtolower( $field['label'] ),
					) );
					break;

				case 'date':
					echo '<input type="date" id="' . esc_attr( $id ) . '" name="' . $name . '" value="' . esc_attr( (string) $value ) . '">';
					break;

				case 'time':
					echo '<input type="time" id="' . esc_attr( $id ) . '" name="' . $name . '" value="' . esc_attr( (string) $value ) . '">';
					break;

				case 'number':
					echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . $name . '" value="' . esc_attr( (string) $value ) . '">';
					break;

				case 'media':
					self::render_media( $id, $name, (int) $value );
					break;

				case 'gallery':
					self::render_gallery( $id, $name, is_array( $value ) ? $value : array() );
					break;

				case 'rows':
					// No sentinel needed: render_rows always draws at least one
					// blank row, so a rows field on the form always posts.
					self::render_rows( $section_id, $key, $field, is_array( $value ) ? $value : array() );
					break;

				default:
					echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . $name . '" value="' . esc_attr( (string) $value ) . '">';
			}
			?>
			<?php if ( ! empty( $field['help'] ) ) : ?>
				<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_media( $id, $name, $attach_id ) {
		$src = $attach_id ? wp_get_attachment_image_url( $attach_id, 'medium' ) : '';
		echo '<div class="bftd-media" data-target="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . $name . '" value="' . (int) $attach_id . '">';
		echo '<div class="bftd-media-prev">' . ( $src ? '<img alt="" src="' . esc_url( $src ) . '">' : '' ) . '</div>';
		echo '<button type="button" class="button bftd-media-pick">Choose file</button> ';
		echo '<button type="button" class="button-link bftd-media-clear">Remove</button>';
		echo '</div>';
	}

	/**
	 * Work samples: a box to drop photos into.
	 *
	 * What a tutor has at this moment is a photo of a child's handwriting page
	 * on their desktop. A single button asks them to open the media library,
	 * upload, find the file again in a grid of hundreds and insert it, which
	 * is four steps for something already under their cursor. A box big enough
	 * to be a target, saying what it takes, is one.
	 *
	 * The button stays, because a file already in the media library is picked
	 * rather than dropped, and because a drop target nobody can click is not
	 * reachable from a keyboard.
	 */
	/**
	 * Two numbers that are one answer.
	 *
	 * Twenty-five correct out of twenty-six written is a fraction, and a
	 * tutor who meets it as two boxes stacked under two separate labels has
	 * to work out which is which. Side by side with a slash between, it is
	 * the thing they wrote on the page in front of them.
	 */
	private static function render_pair( $post_id, $section_id, $key, $field ) {
		$other = $field['pair']['key'];
		$sep   = isset( $field['pair']['sep'] ) ? $field['pair']['sep'] : '/';
		$fields = BFTD_Schema::section( $section_id );
		$far    = isset( $fields['fields'][ $other ] ) ? $fields['fields'][ $other ] : array( 'type' => 'number', 'label' => '' );

		$id_a = 'bftd_' . str_replace( '-', '_', $section_id ) . '_' . $key;
		$id_b = 'bftd_' . str_replace( '-', '_', $section_id ) . '_' . $other;
		?>
		<div class="bftd-field bftd-field-pair">
			<label class="bftd-label" for="<?php echo esc_attr( $id_a ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<div class="bftd-pair">
				<input type="number" class="small-text bftd-pair-n" id="<?php echo esc_attr( $id_a ); ?>"
					name="<?php echo self::name( $section_id, $key ); ?>"
					value="<?php echo esc_attr( (string) self::get( $post_id, $section_id, $key, $field ) ); ?>"
					aria-label="<?php echo esc_attr( $field['label'] ); ?>">
				<span class="bftd-pair-sep" aria-hidden="true"><?php echo esc_html( $sep ); ?></span>
				<?php if ( isset( $far['fixed'] ) ) : ?>
					<?php /* The task is always out of this, so it is stated rather than asked for. */ ?>
					<span class="bftd-pair-fixed"><?php echo esc_html( (string) $far['fixed'] ); ?></span>
					<span class="screen-reader-text"><?php echo esc_html( $far['label'] ); ?></span>
				<?php else : ?>
					<input type="number" class="small-text bftd-pair-n" id="<?php echo esc_attr( $id_b ); ?>"
						name="<?php echo self::name( $section_id, $other ); ?>"
						value="<?php echo esc_attr( (string) self::get( $post_id, $section_id, $other, $far ) ); ?>"
						aria-label="<?php echo esc_attr( $far['label'] ); ?>">
				<?php endif; ?>
				<?php if ( ! empty( $fields['benchmark'] ) ) : ?>
					<span class="bftd-pair-goal">Goal <?php echo (int) $fields['benchmark']; ?><?php
						if ( ! empty( $fields['unit'] ) ) echo ' ' . esc_html( $fields['unit'] );
					?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $field['help'] ) ) : ?>
				<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A way back to the wording everybody else's report uses.
	 *
	 * The text is put in the page rather than in an attribute, because a
	 * paragraph of HTML inside an attribute inside more HTML is a quoting
	 * problem waiting for the day somebody puts an apostrophe in it.
	 *
	 * Pressing it fills the editor and nothing else. Saving is still the
	 * person's decision, so they can read what came back before committing
	 * to it.
	 */
	private static function render_reset( $id, $default, $value ) {
		$same = trim( (string) $value ) === trim( (string) $default );
		?>
		<p class="bftd-reset-row">
			<button type="button" class="button-link bftd-reset" data-editor="<?php echo esc_attr( $id ); ?>"
				<?php disabled( $same, true ); ?>>Put the standard wording back</button>
			<span class="bftd-reset-note"><?php echo esc_html( $same
				? 'This is the standard wording.'
				: 'This report has been reworded.' ); ?></span>
			<script type="text/template" class="bftd-reset-src" data-for="<?php echo esc_attr( $id ); ?>"><?php
				echo $default;   // read by script, never printed into the page
			?></script>
		</p>
		<?php
	}

	/**
	 * Standing wording, shown but not offered.
	 *
	 * A tutor still needs to read it, because it is part of the report they
	 * are writing and they have to know what the family will see above their
	 * own notes. What they do not get is a box, and the save ignores it
	 * whatever arrives in the post.
	 */
	private static function render_locked( $post_id, $section_id, $key, $field ) {
		$value = self::get( $post_id, $section_id, $key, $field );
		$who   = self::rank_label( (int) $field['edit_rank'] );
		?>
		<div class="bftd-field bftd-field-locked">
			<label class="bftd-label"><?php echo esc_html( $field['label'] ); ?>
				<span class="bftd-locked-tag">Set by <?php echo esc_html( $who ); ?></span>
			</label>
			<div class="bftd-locked-box">
				<?php echo wp_kses_post( wpautop( $value ) ); ?>
			</div>
			<p class="description">This wording is the same on every report, so it is not edited here.</p>
		</div>
		<?php
	}

	/** "a senior manager", for saying who owns a piece of standing wording. */
	private static function rank_label( $rank ) {
		if ( ! class_exists( 'BFTD_Roles' ) ) return 'a manager';
		$best = 'an administrator';
		foreach ( BFTD_Roles::tiers() as $tier ) {
			if ( (int) $tier['rank'] === $rank ) $best = strtolower( $tier['label'] );
		}
		return ( 0 === strpos( $best, 'a' ) || 0 === strpos( $best, 'e' ) ? 'an ' : 'a ' ) . $best;
	}

	/**
	 * The results table, as the family will see it.
	 *
	 * Read only, because every figure in it was entered further up. A tutor
	 * who cannot see it has to publish and open the report to find out
	 * whether 25 and 26 went in the right way round.
	 */
	/**
	 * A figure the screen works out, shown where the input used to be.
	 *
	 * It updates as the figures above it are typed, before anything is saved,
	 * because a number that only appears after a save reads as a box that is
	 * not listening. The server is still the one that decides what it is: this
	 * is the same arithmetic, done twice, and the saved record only ever holds
	 * the two figures it came from.
	 */
	private static function render_derived( $post_id, $section_id, $key, $field ) {
		$value = self::get( $post_id, $section_id, $key, $field );
		$warn  = self::derive_warning( $post_id, $section_id, $field );

		// There is more than one way to work a figure out, and this drew only
		// the subtraction. Handed a counted one it read a key that was not
		// there and took the whole edit screen down with it, which is a fatal
		// error on a page nobody can get past rather than a wrong number.
		$sec   = BFTD_Schema::section( $section_id );
		$label = function ( $k ) use ( $sec ) {
			return isset( $sec['fields'][ $k ]['label'] ) ? strtolower( $sec['fields'][ $k ]['label'] ) : $k;
		};

		$watch = array();
		$from  = '';

		if ( ! empty( $field['derived']['minus'] ) ) {
			// "total words read errors" is what joining these with a space
			// gives, and it reads as one garbled phrase rather than a sum.
			$watch = (array) $field['derived']['minus'];
			$names = array_map( $label, $watch );
			$from  = array_shift( $names );
			if ( $names ) $from .= ' less the ' . implode( ' and the ', $names );
		} elseif ( ! empty( $field['derived']['count']['fields'] ) ) {
			$watch = (array) $field['derived']['count']['fields'];
			$from  = 'the ' . implode( ' and the ', array_map( $label, $watch ) ) . ' below';
		}
		?>
		<div class="bftd-field bftd-field-calc"
			data-calc="<?php echo esc_attr( implode( ',', $watch ) ); ?>"
			data-section="<?php echo esc_attr( $section_id ); ?>">
			<span class="bftd-label"><?php echo esc_html( $field['label'] ); ?></span>
			<output class="bftd-calc"<?php echo self::has_value( $value ) ? '' : ' data-empty="1"'; ?>>
				<?php echo self::has_value( $value ) ? esc_html( $value ) : '&mdash;'; ?>
			</output>
			<p class="bftd-calc-warn"<?php echo $warn ? '' : ' hidden'; ?>><?php echo esc_html( $warn ); ?></p>
			<p class="description">
				<?php echo esc_html( isset( $field['help'] ) ? $field['help'] : '' ); ?>
				<?php echo esc_html( $from
					? ( ( isset( $field['help'] ) ? ' ' : '' ) . 'Worked out from ' . $from . ', so there is nothing to type.' )
					: '' ); ?>
			</p>
		</div>
		<?php
	}

	private static function render_score_preview( $post_id, $section_id, $field, $field_key = null ) {
		$values = self::get_section( $post_id, $section_id );
		?>
		<div class="bftd-field bftd-field-derived">
			<label class="bftd-label"><?php echo esc_html( $field['label'] ); ?></label>
			<div class="bftd-derived-box">
				<?php
				echo ( isset( $field['type'] ) && 'figures' === $field['type'] )
					? BFTD_Schema::figures_html( $section_id, $values, $field_key )
					: BFTD_Schema::score_table_html( $section_id, $values, $field_key );
				?>
				<?php
				// Not every drawn table is drawn from the score. One that
				// explains how a scale is read is the same on every report,
				// and telling a tutor it was worked out from a figure above it
				// is telling them something untrue about their own screen.
				$note = isset( $field['derived_note'] )
					? $field['derived_note']
					: 'Worked out from the score above and the fixed goal. Nothing here is typed.';
				?>
				<p class="description"><?php echo esc_html( $note ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * The marking rules, with room to say something about any of them.
	 *
	 * The rules themselves are not editable, because they are how the task is
	 * marked rather than an opinion about one child. A tutor who could edit
	 * them would eventually have two reports marked by different rules and no
	 * way to tell which.
	 *
	 * Comments are stored against a row's id rather than its wording, so
	 * rewording a rule never orphans what somebody wrote about it.
	 */
	/**
	 * A fixed list of findings, each answered from the same short list.
	 *
	 * The rows are the marking scheme for the assessment, not something a
	 * tutor writes out each time: typed from memory they came out worded
	 * differently on every report, and two reports for the same child could
	 * not be read side by side. So the wording is the schema's and the answer
	 * is the tutor's.
	 *
	 * A row left unanswered is left out of the family's report, which is the
	 * empty-field rule applied to a row. The note beside it is optional and is
	 * what turns "yes" into something a parent can picture.
	 */
	private static function render_checklist( $post_id, $section_id, $key, $field ) {
		$saved = self::get( $post_id, $section_id, $key, $field );
		$saved = is_array( $saved ) ? $saved : array();
		$name  = self::name( $section_id, $key );
		$head  = isset( $field['headings'] ) ? $field['headings'] : array( 'value' => 'Result', 'item' => $field['label'] );

		// The column of findings is named by the block's own label directly
		// above it. Where the column heading only says that again it is left
		// off, rather than printing the same line twice.
		$item_head = BFTD_Schema::heading_repeats( $head['item'], $field['label'] ) ? '' : $head['item'];
		?>
		<div class="bftd-field bftd-field-checklist">
			<label class="bftd-label"><?php echo esc_html( $field['label'] ); ?></label>
			<?php if ( ! empty( $field['help'] ) ) : ?>
				<p class="description" style="margin:0 0 9px"><?php echo esc_html( $field['help'] ); ?></p>
			<?php endif; ?>

			<div class="bftd-cl">
				<div class="bftd-cl-head">
					<span><?php echo esc_html( $item_head ); ?></span>
					<span><?php echo esc_html( $head['value'] ); ?></span>
					<span>Notes</span>
				</div>
				<?php foreach ( $field['rows'] as $rid => $label ) :
					$id   = 'bftd_' . str_replace( '-', '_', $section_id ) . '_' . $key . '_' . $rid;
					$row  = isset( $saved[ $rid ] ) && is_array( $saved[ $rid ] ) ? $saved[ $rid ] : array();
					$val  = isset( $row['value'] ) ? (string) $row['value'] : '';
					$note = isset( $row['note'] ) ? (string) $row['note'] : '';
					?>
					<div class="bftd-cl-row">
						<label class="bftd-cl-item" for="<?php echo esc_attr( $id ); ?>"><?php echo wp_kses_post( $label ); ?></label>
						<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo $name; ?>[<?php echo esc_attr( $rid ); ?>][value]">
							<?php foreach ( $field['options'] as $ov => $ol ) : ?>
								<option value="<?php echo esc_attr( $ov ); ?>" <?php selected( $val, $ov ); ?>><?php echo esc_html( $ol ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" name="<?php echo $name; ?>[<?php echo esc_attr( $rid ); ?>][note]"
							value="<?php echo esc_attr( $note ); ?>" placeholder="Optional note">
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function render_criteria( $post_id, $section_id, $key, $field ) {
		$saved = self::get( $post_id, $section_id, $key, $field );
		$saved = is_array( $saved ) ? $saved : array();
		$name  = self::name( $section_id, $key );
		?>
		<div class="bftd-field bftd-field-criteria">
			<label class="bftd-label"><?php echo esc_html( $field['label'] ); ?></label>
			<?php if ( ! empty( $field['help'] ) ) : ?>
				<p class="description" style="margin:0 0 9px"><?php echo esc_html( $field['help'] ); ?></p>
			<?php endif; ?>

			<div class="bftd-crit">
				<?php foreach ( $field['groups'] as $gid => $group ) : ?>
					<div class="bftd-crit-head bftd-crit-<?php echo esc_attr( $group['tone'] ); ?>">
						<?php echo esc_html( $group['label'] ); ?>
					</div>
					<?php foreach ( $group['rows'] as $rid => $rule ) :
						$label = is_array( $rule ) ? $rule['label'] : $rule;
						// The placeholder is what the family will actually see
						// if this is left alone, so it says that rather than
						// something generic a tutor has to guess the effect of.
						$blank = ( is_array( $rule ) && isset( $rule['empty'] ) ) ? $rule['empty'] : 'Nothing to report, shown as a tick';
						$id    = 'bftd_' . str_replace( '-', '_', $section_id ) . '_' . $key . '_' . $rid;
						$val   = isset( $saved[ $rid ] ) ? (string) $saved[ $rid ] : '';
						?>
						<div class="bftd-crit-row">
							<label class="bftd-crit-rule" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
							<input type="text" id="<?php echo esc_attr( $id ); ?>"
								name="<?php echo $name; ?>[<?php echo esc_attr( $rid ); ?>]"
								value="<?php echo esc_attr( $val ); ?>"
								placeholder="<?php echo esc_attr( $blank ); ?>">
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>

				<?php if ( ! empty( $field['footer'] ) ) : ?>
					<div class="bftd-crit-foot">
						<b><?php echo esc_html( $field['footer']['label'] ); ?></b>
						<span><?php echo esc_html( $field['footer']['value'] ); ?></span>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function render_gallery( $id, $name, $ids ) {
		echo '<div class="bftd-gallery" data-target="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . $name . '" value="' . esc_attr( implode( ',', array_map( 'absint', $ids ) ) ) . '">';
		echo '<div class="bftd-gallery-prev">';
		foreach ( $ids as $aid ) {
			$src = wp_get_attachment_image_url( $aid, 'thumbnail' );
			if ( $src ) echo '<span class="bftd-thumb"><img alt="" src="' . esc_url( $src ) . '"><button type="button" class="bftd-thumb-x" data-id="' . (int) $aid . '" aria-label="Remove">&times;</button></span>';
		}
		echo '</div>';
		// Focusable, so a screenshot can be pasted here without a mouse.
		echo '<div class="bftd-gallery-box" tabindex="0" role="group" aria-label="Add work samples by dragging or pasting">';
		echo '<button type="button" class="button bftd-gallery-pick">Add work samples</button>';
		echo '<span class="bftd-gallery-hint">Drag files to attach, or paste a screenshot.</span>';
		echo '</div>';
		echo '<div class="bftd-gallery-note" role="status"></div>';
		echo '</div>';
	}

	/**
	 * A repeatable table. Rows are reorderable and removable; a row left blank
	 * is dropped on save rather than stored as an empty record, which is what
	 * keeps the family view free of empty rows without anyone tidying up.
	 */
	private static function render_rows( $section_id, $key, $field, $rows ) {
		$cols  = $field['columns'];
		$stack = isset( $field['layout'] ) && 'stack' === $field['layout'];
		$rows  = $rows ? $rows : array( array() );

		echo '<div class="bftd-rows' . ( $stack ? ' is-stacked' : '' ) . '" data-section="' . esc_attr( $section_id ) . '" data-field="' . esc_attr( $key ) . '">';

		if ( $stack ) {
			// One block per row instead of one line per row.
			//
			// A row here is not three values side by side, it is a thing with
			// a paragraph and some photographs attached to it. In a table the
			// editor and the gallery are squeezed into a third of the width
			// each, and they are the parts a tutor spends the time in. Stacked,
			// each row reads the way it is filled in: which activity, then what
			// happened in it, then the child's work from it.
			echo '<div class="bftd-rows-body bftd-stack">';
		} else {
			echo '<table class="widefat bftd-rows-table"><thead><tr><th class="bftd-drag" aria-hidden="true"></th>';
			foreach ( $cols as $ck => $col ) echo '<th>' . esc_html( $col['label'] ) . '</th>';
			echo '<th class="bftd-rm" aria-hidden="true"></th></tr></thead><tbody class="bftd-rows-body">';
		}

		foreach ( array_values( $rows ) as $i => $row ) {
			self::render_row( $section_id, $key, $cols, $row, $i, $stack );
		}

		echo $stack ? '</div>' : '</tbody></table>';

		// Named after what it adds. "Add a row" is true of every table on
		// every screen and tells a tutor nothing about this one.
		$add = isset( $field['add_label'] ) ? $field['add_label'] : 'Add a row';
		echo '<button type="button" class="button bftd-row-add">' . esc_html( $add ) . '</button>';
		echo '<script type="text/template" class="bftd-row-tpl">';
		self::render_row( $section_id, $key, $cols, array(), '__i__', $stack );
		echo '</script>';
		echo '</div>';
	}

	/** One cell's control, whichever layout it is sitting in. */
	private static function render_cell( $section_id, $key, $ck, $col, $val, $i ) {
		$name = 'bftd_rows[' . esc_attr( $section_id ) . '][' . esc_attr( $key ) . '][' . $i . '][' . esc_attr( $ck ) . ']';

		if ( 'activity' === $col['type'] ) {
			echo BFTD_Activities::picker( $name, (int) $val );   // escaped where it is built
		} elseif ( 'skill' === $col['type'] ) {
			echo BFTD_Skills::picker( $name, (int) $val );       // the same
		} elseif ( 'select' === $col['type'] ) {
			echo '<select name="' . $name . '">';
			foreach ( (array) $col['options'] as $ok => $olabel ) {
				echo '<option value="' . esc_attr( $ok ) . '" ' . selected( (string) $val, (string) $ok, false ) . '>' . esc_html( $olabel ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'rich' === $col['type'] ) {
			self::render_row_panel(
				$name, (string) $val, 'rich',
				self::row_uid( $section_id, $key, $ck, $i ),
				'' !== trim( wp_strip_all_tags( (string) $val ) ),
				'notes'
			);
		} elseif ( 'gallery' === $col['type'] ) {
			$ids = is_array( $val ) ? array_values( array_filter( array_map( 'absint', $val ) ) ) : array();
			self::render_row_panel(
				$name, $ids, 'gallery',
				self::row_uid( $section_id, $key, $ck, $i ),
				(bool) $ids,
				'work samples'
			);
		} elseif ( 'textarea' === $col['type'] ) {
			echo '<textarea rows="2" name="' . $name . '">' . esc_textarea( (string) $val ) . '</textarea>';
		} elseif ( 'date' === $col['type'] ) {
			echo '<input type="date" name="' . $name . '" value="' . esc_attr( (string) $val ) . '">';
		} elseif ( 'number' === $col['type'] ) {
			echo '<input type="number" name="' . $name . '" value="' . esc_attr( (string) $val ) . '">';
		} else {
			echo '<input type="text" name="' . $name . '" value="' . esc_attr( (string) $val ) . '">';
		}
	}

	/**
	 * Does this value point at a record that is actually there?
	 *
	 * "Not empty" is not the same question. A row holding the id of a trashed
	 * activity is not empty and is not chosen either, and the difference is
	 * what decides whether a tutor is offered somewhere to write.
	 */
	private static function choice_exists( $type, $value ) {
		$id = (int) $value;
		if ( ! $id ) return false;
		if ( 'activity' === $type ) return class_exists( 'BFTD_Activities' ) && null !== BFTD_Activities::get( $id );
		if ( 'skill' === $type )    return class_exists( 'BFTD_Skills' ) && '' !== BFTD_Skills::label( $id );
		return true;
	}

	/**
	 * A row that is kept but not shown.
	 *
	 * Every value the row holds, posted straight back. Nothing here is a
	 * control: there is nothing for a tutor to do with a row whose subject is
	 * in the trash except put the subject back, and that is done from the
	 * library rather than from here.
	 */
	private static function render_row_kept( $section_id, $key, $cols, $row, $i ) {
		$stem = 'bftd_rows[' . esc_attr( $section_id ) . '][' . esc_attr( $key ) . '][' . $i . ']';

		// Wrapped, and wrapped as a row. A div holding nothing but hidden
		// inputs draws nothing, so the wrapper costs no space — but the
		// script renumbers rows by walking .bftd-row, and a set of inputs
		// outside that walk keeps whatever index it was printed with. Add a
		// row, or drag one, and two rows end up posting under the same index:
		// PHP reads the second over the first and one of them is gone. The
		// class is what keeps this row in the count.
		echo '<div class="bftd-row bftd-rowkept">';
		foreach ( $cols as $ck => $col ) {
			$val = isset( $row[ $ck ] ) ? $row[ $ck ] : '';
			$nm  = $stem . '[' . esc_attr( $ck ) . ']';

			// A gallery posts a list. Kept as one, or a lesson's work samples
			// come back as the word "Array" and are lost on the same save
			// this exists to survive.
			if ( is_array( $val ) ) {
				if ( 'gallery' === $col['type'] ) {
					$ids = array_values( array_filter( array_map( 'absint', $val ) ) );
					echo '<input type="hidden" name="' . $nm . '" value="' . esc_attr( implode( ',', $ids ) ) . '">';
					continue;
				}
				foreach ( $val as $vk => $vv ) {
					if ( is_array( $vv ) ) continue;
					echo '<input type="hidden" name="' . $nm . '[' . esc_attr( $vk ) . ']" value="' . esc_attr( (string) $vv ) . '">';
				}
				continue;
			}
			echo '<input type="hidden" name="' . $nm . '" value="' . esc_attr( (string) $val ) . '">';
		}
		echo '</div>';
	}

	private static function render_row( $section_id, $key, $cols, $row, $i, $stack = false ) {
		$val = function ( $ck ) use ( $row ) { return isset( $row[ $ck ] ) ? $row[ $ck ] : ''; };

		if ( $stack ) {
			$keys  = array_keys( $cols );
			$first = array_shift( $keys );   // the row's subject, on the header line

			// Nothing hangs off a row until it has a subject. Notes and work
			// samples belong to an activity, and offering them before one is
			// chosen offers a tutor somewhere to type that the save will
			// throw away: a row with no activity is not a record of anything,
			// so sanitize_rows drops it, notes and all. Empty here means the
			// links are simply not there yet; choosing an activity brings
			// them out.
			$needs = in_array( $cols[ $first ]['type'], array( 'activity', 'skill' ), true );
			$chose = $needs ? trim( (string) $val( $first ) ) : '';
			$blank = $needs && '' === $chose;

			/*
			 * Chosen, but pointing at something the library no longer offers.
			 *
			 * Trashing an activity leaves every lesson that used it holding
			 * its id. It is gone from the family's report already, because
			 * BFTD_Activities::resolve answers nothing for it, so the row here
			 * is describing something nobody can see — and it showed as an
			 * empty picker offering Add notes and Add work samples, which is
			 * an invitation to write about an activity that is not there.
			 *
			 * So the row is not drawn. It is not dropped either: trash can be
			 * undone, and the tutor's notes and work samples have to still be
			 * there when it is. The values ride along as hidden inputs so a
			 * save puts back exactly what it found, and untrashing the
			 * activity brings the whole row back with its writing intact.
			 */
			if ( $needs && '' !== $chose && ! self::choice_exists( $cols[ $first ]['type'], $chose ) ) {
				self::render_row_kept( $section_id, $key, $cols, $row, $i );
				return;
			}
			?>
			<div class="bftd-row bftd-stackrow<?php echo $blank ? ' is-blank' : ''; ?>"<?php echo $needs ? ' data-needs-lead="1"' : ''; ?>>
				<div class="bftd-stackrow-h">
					<span class="bftd-drag dashicons dashicons-menu-alt2" aria-hidden="true"></span>
					<div class="bftd-stackrow-lead"><?php self::render_cell( $section_id, $key, $first, $cols[ $first ], $val( $first ), $i ); ?></div>
					<button type="button" class="button-link bftd-row-rm" aria-label="Remove">&times;</button>
				</div>
				<?php foreach ( $keys as $ck ) : ?>
					<div class="bftd-stackrow-part">
						<?php self::render_cell( $section_id, $key, $ck, $cols[ $ck ], $val( $ck ), $i ); ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
			return;
		}

		echo '<tr class="bftd-row"><td class="bftd-drag"><span class="dashicons dashicons-menu-alt2"></span></td>';
		foreach ( $cols as $ck => $col ) {
			echo '<td>';
			self::render_cell( $section_id, $key, $ck, $col, $val( $ck ), $i );
			echo '</td>';
		}
		echo '<td class="bftd-rm"><button type="button" class="button-link bftd-row-rm" aria-label="Remove row">&times;</button></td></tr>';
	}

	/**
	 * A DOM id for one cell, unique to the row and never reused.
	 *
	 * TinyMCE and the media picker both key on an element id: the editor
	 * registry is global, and the gallery finds its hidden input through
	 * data-target. A cloned row carrying a copy of an id that is already
	 * spoken for is how the second row ends up stealing the first one's
	 * editor, or silently getting nothing. The template's placeholder is
	 * filled in by the script, from a counter that only ever goes up.
	 */
	private static function row_uid( $section_id, $key, $col_key, $i ) {
		// In the template, one placeholder per COLUMN rather than one per
		// row. A gallery writes its id twice, on the hidden input and on the
		// wrapper that finds it, and those two must come back as the same
		// value while the notes editor beside them gets a different one. A
		// single shared placeholder gave the whole row one id; replacing
		// every occurrence separately broke the gallery's own pair.
		if ( '__i__' === (string) $i ) {
			return 'bftd_rc___uid' . substr( md5( (string) $col_key ), 0, 6 ) . '__';
		}
		$stem = strtolower( $section_id . '_' . $key . '_' . $col_key );
		return 'bftd_rc_' . preg_replace( '/[^a-z0-9]+/', '_', $stem ) . '_' . $i;
	}

	/**
	 * A panel inside a repeatable row, built on the click that opens it.
	 *
	 * Neither a rich editor nor a media gallery can simply be drawn once per
	 * row. wp_editor() cannot be cloned at all, for the id reason above, and
	 * a lesson with eight activities would be eight TinyMCE iframes loading
	 * before a tutor had typed a word. So what the form posts is here from
	 * the start, a textarea or a hidden field, and the interface on top of it
	 * is built when somebody asks for it.
	 *
	 * With the script blocked the row is still a textarea somebody can type
	 * into, which is the failure everybody can recover from.
	 */
	private static function render_row_panel( $name, $value, $kind, $uid, $open, $noun ) {
		?>
		<div class="bftd-rowpanel<?php echo $open ? ' is-open' : ''; ?>">
			<button type="button" class="button-link bftd-rowpanel-t" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>">
				<?php echo esc_html( $open ? ucfirst( $noun ) : 'Add ' . $noun ); ?>
			</button>
			<div class="bftd-rowpanel-b"<?php echo $open ? '' : ' hidden'; ?>>
				<?php if ( 'gallery' === $kind ) : ?>
					<?php self::render_gallery( $uid, $name, (array) $value ); ?>
				<?php else : ?>
					<textarea id="<?php echo esc_attr( $uid ); ?>" class="bftd-rowpanel-a" rows="5"
						name="<?php echo $name; ?>"><?php echo esc_textarea( (string) $value ); ?></textarea>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function render_thread_note( $post_id, $section_id ) {
		$thread = BFTD_Threads::find_thread( $post_id, $section_id );
		echo '<div class="bftd-thread-note">';
		if ( $thread ) {
			$n    = BFTD_Threads::reply_count( $thread->comment_ID );
			$last = BFTD_Threads::last_message( $thread->comment_ID );
			$who  = $last && $last->user_id ? get_userdata( $last->user_id ) : null;
			echo '<span class="dashicons dashicons-format-chat"></span> ';
			printf(
				/* translators: 1: number of messages, 2: who wrote last */
				esc_html__( '%1$s messages here. Last from %2$s.', 'bftd' ),
				esc_html( (string) $n ),
				esc_html( $who ? $who->display_name : 'someone' )
			);
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . BFTD_Admin::INBOX_SLUG . '&thread=' . (int) $thread->comment_ID ) ) . '">Open the conversation</a>';
		} else {
			echo '<span class="dashicons dashicons-format-chat"></span> Families can ask about this section. Nothing asked yet.';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Visibility                                                          */
	/* ------------------------------------------------------------------ */

	public static function visibility_key( $section_id ) {
		return '_bftd_visible__' . str_replace( '-', '_', $section_id );
	}

	public static function is_visible( $post_id, $section_id ) {
		// A sample has no stored visibility, and every section it supplies
		// content for is one it means to show.
		//
		// A section nobody writes has nothing to supply, though, so this hid
		// every derived section on the sample that had no explainer typed
		// against it — a section whose whole content is built would never have
		// a fixture entry to find. For those the question is the same one the
		// pill asks: did it build anything.
		if ( null !== self::$fixture ) {
			if ( isset( self::$fixture[ $section_id ] ) ) return true;
			return class_exists( 'BFTD_Derived' )
				&& BFTD_Derived::is_derived( $section_id )
				&& BFTD_Derived::has( $section_id, $post_id );
		}

		$v = get_post_meta( $post_id, self::visibility_key( $section_id ), true );
		return ( '' === $v ) ? true : (bool) $v; // shown unless someone turned it off
	}

	/* ------------------------------------------------------------------ */
	/* Saving                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Save every section that belongs to this post type, and log exactly what
	 * changed. Returns the number of sections that actually changed.
	 */
	public static function save_post( $post_id, $post_type ) {
		$sections = BFTD_Schema::sections_for_post_type( $post_type );
		$changed  = 0;

		$posted_fields = isset( $_POST['bftd'] ) ? (array) wp_unslash( $_POST['bftd'] ) : array();
		$posted_rows   = isset( $_POST['bftd_rows'] ) ? (array) wp_unslash( $_POST['bftd_rows'] ) : array();
		$posted_vis    = isset( $_POST['bftd_visible'] ) ? (array) wp_unslash( $_POST['bftd_visible'] ) : array();

		foreach ( $sections as $section_id => $section ) {
			$before = self::get_section( $post_id, $section_id );
			$after  = array();
			$labels = array();

			foreach ( $section['fields'] as $key => $field ) {
				$labels[ $key ] = $field['label'];
				$type = isset( $field['type'] ) ? $field['type'] : 'text';

				// A field somebody may not edit is left exactly as it was.
				// The screen does not offer it to them, but a screen is not a
				// permission: without this, posting the form by hand would
				// change it anyway.
				if ( ! self::may_edit( $field ) ) {
					$after[ $key ] = $before[ $key ];
					continue;
				}

				/*
				 * A field the request did not carry is left exactly as it was.
				 *
				 * This is the difference between "somebody cleared this" and
				 * "this form did not have this field", and everything else in
				 * here depends on it. Every field used to be rewritten from the
				 * request whether the request mentioned it or not, so a form
				 * arriving without a field emptied it: a derived figure that is
				 * never drawn, the far half of a paired field, a section the
				 * screen does not render, a request PHP cut short. Each one
				 * silently erased work nobody had touched.
				 *
				 * Clearing still works, because a cleared field arrives with an
				 * empty value rather than not arriving. The one input that used
				 * to break that rule was the visibility checkbox, which posts
				 * nothing when unticked, and it now has a hidden partner that
				 * always posts. That is the whole trick, and it is the reason
				 * WordPress's own fields never had this problem.
				 */
				$carried = ( 'rows' === $type )
					? isset( $posted_rows[ $section_id ][ $key ] )
					: array_key_exists( $key, (array) ( $posted_fields[ $section_id ] ?? array() ) );

				if ( ! $carried ) {
					$after[ $key ] = $before[ $key ];
					continue;
				}

				if ( 'rows' === $type ) {
					$raw   = isset( $posted_rows[ $section_id ][ $key ] ) ? (array) $posted_rows[ $section_id ][ $key ] : array();
					$after[ $key ] = self::sanitize_rows( $raw, $field['columns'] );
				} else {
					$raw   = isset( $posted_fields[ $section_id ][ $key ] ) ? $posted_fields[ $section_id ][ $key ] : '';
					$field['stored'] = $before[ $key ];
					$after[ $key ] = self::sanitize_value( $raw, $field );

					// Standing wording saved back untouched is stored as
					// nothing, so the report stays joined to the practice's
					// copy of it. Store the words and a later change to the
					// practice's wording would reach every future report and
					// none of the ones already written.
					$fallback = self::fallback( $section_id, $key, $field );
					if ( null !== $fallback && $after[ $key ] === $fallback ) {
						$after[ $key ] = '';
					}
				}

				update_post_meta( $post_id, BFTD_Schema::meta_key( $section_id, $key ), $after[ $key ] );
			}

			// Visibility, tracked as its own event because hiding a section
			// from a family is a different act from editing it.
			if ( ( ! empty( $section['fields'] ) || ! empty( $section['thread'] ) )
				&& array_key_exists( $section_id, $posted_vis ) ) {
				$was = self::is_visible( $post_id, $section_id );
				$now = ! empty( $posted_vis[ $section_id ] );
				if ( $was !== $now ) {
					update_post_meta( $post_id, self::visibility_key( $section_id ), $now ? 1 : 0 );
					BFTD_Audit::log( $now ? 'section_published' : 'section_hidden', array(
						'post_id'    => $post_id,
						'section_id' => $section_id,
						'summary'    => $section['label'] . ( $now ? ' is now shown to the family.' : ' is now hidden from the family.' ),
					) );
				}
			}

			// The history is read by people, so a field that stores an id is
			// written out as the name it stands for. Storage is untouched:
			// only what the log says changes.
			$diff = BFTD_Audit::diff(
				self::readable( $before, $section['fields'] ),
				self::readable( $after, $section['fields'] ),
				$labels
			);
			if ( $diff ) {
				$changed++;
				BFTD_Audit::log( 'section_updated', array(
					'post_id'    => $post_id,
					'section_id' => $section_id,
					'summary'    => $section['label'] . ' was edited.',
					'changes'    => $diff,
				) );
			}
		}

		return $changed;
	}

	/** Values as a person would read them, for the change history. */
	public static function readable( $values, $fields ) {
		foreach ( (array) $values as $key => $value ) {
			$type = isset( $fields[ $key ]['type'] ) ? $fields[ $key ]['type'] : 'text';
			if ( 'staff' !== $type || '' === $value ) continue;
			$u = get_userdata( (int) $value );
			$values[ $key ] = $u ? $u->display_name : '';
		}
		return $values;
	}

	public static function sanitize_value( $raw, $field ) {
		$type = isset( $field['type'] ) ? $field['type'] : 'text';
		switch ( $type ) {
			case 'rich':
				return wp_kses_post( $raw );
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'number':
				return ( '' === trim( (string) $raw ) ) ? '' : (string) floatval( $raw );
			case 'select':
				/*
				 * An unrecognised value is normally nothing, because a select
				 * can only post what it was given. The exception is the value
				 * already on the record: the renderer keeps it as an option so
				 * it survives a save, and blanking it here would undo that on
				 * the way back in. Anything else unrecognised is still nothing.
				 */
				$raw = sanitize_text_field( $raw );
				if ( array_key_exists( $raw, (array) $field['options'] ) ) return $raw;
				if ( '' !== $raw && isset( $field['stored'] ) && (string) $field['stored'] === $raw ) return $raw;
				return '';
			case 'staff':
				// Empty rather than zero, so "nobody chosen" reads as absent
				// everywhere that asks whether a field has a value.
				$id = absint( $raw );
				return $id ? (string) $id : '';
			case 'date':
				$raw = sanitize_text_field( $raw );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
			case 'time':
				// 24 hour, as the browser's own time input posts it. Anything
				// else is a typed value nobody can sort a day by.
				$raw = sanitize_text_field( $raw );
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $raw ) ? $raw : '';
			case 'activity':
				// A pointer at a record that is really there — which is not
				// the same as one the library will offer. A trashed activity
				// is still there, and the lessons that used it are still
				// holding its id with notes and work samples hanging off it.
				//
				// This asked the library question, so trashing an activity
				// and then saving any lesson that used it destroyed the row:
				// the id came back empty, sanitize_rows read that as a note
				// against no activity, and a term of a tutor's writing went
				// with it. Untrashing brought back nothing, because there was
				// nothing left to point at it.
				//
				// A posted id for a post of another kind, or for one that has
				// been emptied out of the trash, is still dropped.
				$id = absint( $raw );
				return ( $id && BFTD_Activities::exists( $id ) ) ? (string) $id : '';
			case 'skill':
				$id = absint( $raw );
				return ( $id && BFTD_Skills::exists( $id ) ) ? (string) $id : '';
			case 'score_table':
				// Every figure in it came from somewhere else, so there is
				// nothing posted and nothing to keep.
				return '';
			case 'checklist':
				// Only the rows the schema declares, and only the answers it
				// offers. Nothing posted can add a finding to a family's
				// report or answer one with a word nobody chose.
				$out = array();
				foreach ( (array) $raw as $rid => $row ) {
					if ( ! isset( $field['rows'][ $rid ] ) || ! is_array( $row ) ) continue;
					$val  = isset( $row['value'] ) ? sanitize_text_field( $row['value'] ) : '';
					$note = isset( $row['note'] ) ? sanitize_text_field( $row['note'] ) : '';
					if ( ! isset( $field['options'][ $val ] ) ) $val = '';
					if ( '' === $val && '' === trim( $note ) ) continue;
					$out[ $rid ] = array( 'value' => $val, 'note' => trim( $note ) );
				}
				return $out;
			case 'criteria':
				// Only rows the schema declares, so a posted key nobody has
				// heard of is dropped rather than stored forever.
				$known = array();
				foreach ( (array) $field['groups'] as $group ) {
					foreach ( array_keys( (array) $group['rows'] ) as $rid ) $known[ $rid ] = true;
				}
				// A rule is either a label or a label with its own wording for
				// a blank; either way the id is what a note hangs off.
				$out = array();
				foreach ( (array) $raw as $rid => $note ) {
					if ( ! isset( $known[ $rid ] ) ) continue;
					$note = sanitize_text_field( $note );
					if ( '' !== trim( $note ) ) $out[ $rid ] = $note;
				}
				return $out;
			case 'media':
				return (int) $raw;
			case 'gallery':
				$ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
				return array_values( array_filter( array_map( 'absint', $ids ) ) );
			default:
				return sanitize_text_field( $raw );
		}
	}

	/** Rows come back reindexed, blank rows dropped, cells sanitised per column. */
	public static function sanitize_rows( $raw, $columns ) {
		$out = array();
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) continue;
			$clean = array();
			$orphan = false;
			foreach ( $columns as $ck => $col ) {
				$clean[ $ck ] = self::sanitize_value( isset( $row[ $ck ] ) ? $row[ $ck ] : '', $col );

				// A note against no activity is a note about nothing. It
				// would otherwise survive as a row the coverage grid has to
				// skip and the tutor cannot see the shape of.
				if ( in_array( $col['type'], array( 'activity', 'skill' ), true ) && '' === $clean[ $ck ] ) $orphan = true;
			}
			if ( $orphan ) continue;
			if ( ! self::has_value( $clean ) ) continue;
			$out[] = $clean;
		}
		return $out;
	}
}
