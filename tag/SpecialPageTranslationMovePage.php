<?php
/**
 * Contains class to override Special:MovePage for page translation.
 *
 * @file
 * @author Niklas Laxström
 * @license GPL-2.0-or-later
 */

/**
 * Overrides Special:Movepage to to allow renaming a page translation page and
 * all related translations and derivative pages.
 *
 * @ingroup SpecialPage PageTranslation
 */
class SpecialPageTranslationMovePage extends MovePageForm {
	// Basic form parameters both as text and as titles
	protected $newText, $oldText;

	// Other form parameters
	/**
	 * 'check' or 'perform'
	 */
	protected $subaction;

	/**
	 * @var TranslatablePage instance.
	 */
	protected $page;

	/**
	 * Whether MovePageForm extends SpecialPage
	 */
	protected $old;

	/**
	 * @var Title[] Cached list of translation pages. Not yet loaded if null.
	 */
	protected $translationPages;

	/**
	 * @var Title[] Cached list of section pages. Not yet loaded if null.
	 */
	protected $sectionPages;

	/**
	 * Partially copies from SpecialMovepage.php, because it cannot be
	 * extended in other ways.
	 *
	 * @param string|null $par null if subpage not provided, string otherwise
	 * @throws PermissionsError
	 */
	public function execute( $par ) {
		$request = $this->getRequest();
		$user = $this->getUser();
		$this->addHelpLink( 'Help:Moving a page' );

		// Yes, the use of getVal() and getText() is wanted, see bug T22365
		$this->oldText = $request->getVal( 'wpOldTitle', $request->getVal( 'target', $par ) );
		$this->newText = $request->getText( 'wpNewTitle' );

		$this->oldTitle = Title::newFromText( $this->oldText ?? '' );
		$this->newTitle = Title::newFromText( $this->newText );

		$this->reason = $request->getText( 'reason' );
		// Checkboxes that default being checked are tricky
		$this->moveSubpages = $request->getBool( 'subpages', !$request->wasPosted() );

		// This will throw exceptions if there is an error.
		$this->doBasicChecks();

		// Real stuff starts here
		$page = TranslatablePage::newFromTitle( $this->oldTitle );
		if ( $page->getMarkedTag() !== false ) {
			$this->page = $page;

			$this->getOutput()->setPageTitle( $this->msg( 'pt-movepage-title', $this->oldText ) );

			if ( !$user->isAllowed( 'pagetranslation' ) ) {
				throw new PermissionsError( 'pagetranslation' );
			}

			// Is there really no better way to do this?
			$subactionText = $request->getText( 'subaction' );
			switch ( $subactionText ) {
				case $this->msg( 'pt-movepage-action-check' )->text():
					$subaction = 'check';
					break;
				case $this->msg( 'pt-movepage-action-perform' )->text():
					$subaction = 'perform';
					break;
				case $this->msg( 'pt-movepage-action-other' )->text():
					$subaction = '';
					break;
				default:
					$subaction = '';
			}

			if ( $subaction === 'check' && $this->checkToken() && $request->wasPosted() ) {
				$blockers = $this->checkMoveBlockers( $user );
				if ( count( $blockers ) ) {
					$this->showErrors( $blockers );
					$this->showForm( [] );
				} else {
					$this->showConfirmation();
				}
			} elseif ( $subaction === 'perform' && $this->checkToken() && $request->wasPosted() ) {
				$this->performAction();
			} else {
				$this->showForm( [] );
			}
		} else {
			// Delegate... don't want to reimplement this
			$sp = new MovePageForm();
			$sp->execute( $par );
		}
	}

	/**
	 * Do the basic checks whether moving is possible and whether
	 * the input looks anywhere near sane.
	 * @throws PermissionsError|ErrorPageError|ReadOnlyError|ThrottledError
	 */
	protected function doBasicChecks() {
		$this->checkReadOnly();

		if ( $this->oldTitle === null ) {
			throw new ErrorPageError( 'notargettitle', 'notargettext' );
		}

		if ( !$this->oldTitle->exists() ) {
			throw new ErrorPageError( 'nopagetitle', 'nopagetext' );
		}

		if ( $this->getUser()->pingLimiter( 'move' ) ) {
			throw new ThrottledError;
		}

		// Check rights
		$permErrors = $this->oldTitle->getUserPermissionsErrors( 'move', $this->getUser() );
		if ( count( $permErrors ) ) {
			throw new PermissionsError( 'move', $permErrors );
		}
	}

	/**
	 * Checks token. Use before real actions happen. Have to use wpEditToken
	 * for compatibility for SpecialMovepage.php.
	 *
	 * @return bool
	 */
	protected function checkToken() {
		return $this->getUser()->matchEditToken( $this->getRequest()->getVal( 'wpEditToken' ) );
	}

	/**
	 * Pretty-print the list of errors.
	 * @param SplObjectStorage $errors Array with message key and parameters
	 */
	protected function showErrors( SplObjectStorage $errors ) {
		$out = $this->getOutput();

		$out->addHtml( Html::openElement( 'div', [ 'class' => 'errorbox' ] ) );
		$out->addWikiMsg(
			'pt-movepage-blockers',
			$this->getLanguage()->formatNum( count( $errors ) )
		);

		// If there are many errors, for performance reasons we must parse them all at once
		$s = '';
		$context = 'pt-movepage-error-placeholder';
		foreach ( $errors as $title ) {
			// @phan-suppress-next-line PhanTypeSuspiciousStringExpression
			$s .= "'''$title'''\n\n";
			$s .= $errors[ $title ]->getWikiText( false, $context );
		}

		$out->addWikiTextAsInterface( $s );
		$out->addHtml( Html::closeElement( 'div' ) );
	}

	/**
	 * The query form.
	 *
	 * @param array $err Unused.
	 * @param bool $isPermError Unused.
	 */
	public function showForm( $err, $isPermError = false ) {
		$this->getOutput()->addWikiMsg( 'pt-movepage-intro' );

		$formDescriptor = [
			'wpOldTitle' => [
				'type' => 'text',
				'name' => 'wpOldTitle',
				'label' => $this->msg( 'pt-movepage-current' )->text(),
				'size' => 30,
				'default' => $this->oldText,
				'readonly' => true,
			],
			'wpNewTitle' => [
				'type' => 'text',
				'name' => 'wpNewTitle',
				'label' => $this->msg( 'pt-movepage-new' )->text(),
				'size' => 30,
				'default' => $this->newText,
			],
			'reason' => [
				'type' => 'text',
				'name' => 'reason',
				'label' => $this->msg( 'pt-movepage-reason' )->text(),
				'size' => 45,
				'default' => $this->reason,
			],
			'subpages' => [
				'type' => 'check',
				'name' => 'subpages',
				'id' => 'mw-subpages',
				'label' => $this->msg( 'pt-movepage-subpages' )->text(),
				'default' => $this->moveSubpages,
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->addHiddenField( 'wpEditToken', $this->getUser()->getEditToken() )
			->setMethod( 'post' )
			->setAction( $this->getPageTitle( $this->oldText )->getLocalURL() )
			->setSubmitName( 'subaction' )
			->setSubmitTextMsg( 'pt-movepage-action-check' )
			->setWrapperLegendMsg( 'pt-movepage-legend' )
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * Shortcut for keeping the code at least a bit readable. Adds label and
	 * input into $form array.
	 *
	 * @param string[] &$form Array where input element and label is appended.
	 * @param string $label Label text.
	 * @param string $name Name attribute.
	 * @param bool|int $size Size attribute of the input element. Default false.
	 * @param bool|string $text Text of the value attribute. Default false.
	 * @param array $attribs Extra attributes. Default empty array.
	 */
	protected function addInputLabel( &$form, $label, $name, $size = false, $text = false,
		array $attribs = []
	) {
		$br = Html::element( 'br' );
		list( $label, $input ) = Xml::inputLabelSep(
			$label,
			$name,
			$name,
			$size,
			$text,
			$attribs
		);
		$form[] = $label . $br;
		$form[] = $input . $br;
	}

	/**
	 * The second form, which still allows changing some things.
	 * Lists all the action which would take place.
	 */
	protected function showConfirmation() {
		$out = $this->getOutput();

		$out->addWikiMsg( 'pt-movepage-intro' );

		$base = $this->oldTitle->getPrefixedText();
		$target = $this->newTitle;
		$count = 0;

		$types = [
			'pt-movepage-list-pages' => [ $this->oldTitle ],
			'pt-movepage-list-translation' => $this->getTranslationPages(),
			'pt-movepage-list-section' => $this->getSectionPages(),
			'pt-movepage-list-translatable' => $this->getTranslatableSubpages(),
			'pt-movepage-list-other' => $this->getNormalSubpages(),
		];

		foreach ( $types as $type => $pages ) {
			$out->wrapWikiMsg( '=== $1 ===', [ $type, count( $pages ) ] );
			if ( $type === 'pt-movepage-list-translatable' ) {
				$out->addWikiMsg( 'pt-movepage-list-translatable-note' );
			}

			$lines = [];
			foreach ( $pages as $old ) {
				$toBeMoved = true;

				// These pages need specific checks
				if ( $type === 'pt-movepage-list-other' ) {
					$toBeMoved = $this->moveSubpages;
				}

				if ( $type === 'pt-movepage-list-translatable' ) {
					$toBeMoved = false;
				}

				if ( $toBeMoved ) {
					$count++;
				}

				$lines[] = $this->getChangeLine( $base, $old, $target, $toBeMoved );
			}

			$out->addWikiTextAsInterface( implode( "\n", $lines ) );
		}

		$out->addWikiTextAsInterface( "----\n" );
		$out->addWikiMsg( 'pt-movepage-list-count', $this->getLanguage()->formatNum( $count ) );

		$br = Html::element( 'br' );
		$readonly = [ 'readonly' => 'readonly' ];
		$subaction = [ 'name' => 'subaction' ];
		$formParams = [
			'method' => 'post',
			'action' => $this->getPageTitle( $this->oldText )->getLocalURL()
		];

		$form = [];
		$form[] = Xml::fieldset( $this->msg( 'pt-movepage-legend' )->text() );
		$form[] = Html::openElement( 'form', $formParams );
		$form[] = Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() );
		$this->addInputLabel(
			$form,
			$this->msg( 'pt-movepage-current' )->text(),
			'wpOldTitle',
			30,
			$this->oldText,
			$readonly
		);
		$this->addInputLabel(
			$form,
			$this->msg( 'pt-movepage-new' )->text(),
			'wpNewTitle',
			30,
			$this->newText,
			$readonly
		);
		$this->addInputLabel(
			$form,
			$this->msg( 'pt-movepage-reason' )->text(),
			'reason',
			60,
			$this->reason
		);
		$form[] = Html::hidden( 'subpages', $this->moveSubpages );
		$form[] = Xml::checkLabel(
			$this->msg( 'pt-movepage-subpages' )->text(),
			'subpagesFake',
			'mw-subpages',
			$this->moveSubpages,
			$readonly
		) . $br;
		$form[] = Xml::submitButton( $this->msg( 'pt-movepage-action-perform' )->text(), $subaction );
		$form[] = Xml::submitButton( $this->msg( 'pt-movepage-action-other' )->text(), $subaction );
		$form[] = Xml::closeElement( 'form' );
		$form[] = Xml::closeElement( 'fieldset' );
		$out->addHTML( implode( "\n", $form ) );
	}

	/**
	 * @param string $base
	 * @param Title $old
	 * @param Title $target
	 * @param bool $enabled
	 * @return string
	 */
	protected function getChangeLine( $base, Title $old, Title $target, $enabled = true ) {
		$to = $this->newPageTitle( $base, $old, $target );

		if ( $enabled ) {
			return '* ' . $old->getPrefixedText() . ' → ' . $to;
		} else {
			return '* ' . $old->getPrefixedText();
		}
	}

	protected function performAction() {
		$target = $this->newTitle;
		$base = $this->oldTitle->getPrefixedText();

		$moves = [];
		$moves[$base] = $target->getPrefixedText();

		foreach ( $this->getTranslationPages() as $from ) {
			$to = $this->newPageTitle( $base, $from, $target );
			$moves[$from->getPrefixedText()] = $to->getPrefixedText();
		}

		foreach ( $this->getSectionPages() as $from ) {
			$to = $this->newPageTitle( $base, $from, $target );
			$moves[$from->getPrefixedText()] = $to->getPrefixedText();
		}

		if ( $this->moveSubpages ) {
			$subpages = $this->getNormalSubpages();
			foreach ( $subpages as $from ) {
				$to = $this->newPageTitle( $base, $from, $target );
				$moves[$from->getPrefixedText()] = $to->getPrefixedText();
			}
		}

		$summary = $this->msg( 'pt-movepage-logreason', $base )->inContentLanguage()->text();
		$job = TranslatablePageMoveJob::newJob(
			$this->oldTitle, $this->newTitle, $moves, $summary, $this->getUser()
		);

		JobQueueGroup::singleton()->push( $job );

		$this->getOutput()->addWikiMsg( 'pt-movepage-started' );
	}

	protected function checkMoveBlockers( User $user ) {
		$blockers = new SplObjectStorage();

		$source = $this->oldTitle;
		$target = $this->newTitle;

		if ( !$target ) {
			$blockers[$source] = Status::newFatal( 'pt-movepage-block-base-invalid' );

			return $blockers;
		}

		if ( $target->inNamespaces( NS_MEDIAWIKI, NS_TRANSLATIONS ) ) {
			$blockers[$source] = Status::newFatal(
				'immobile-target-namespace', $target->getNsText()
			);

			return $blockers;
		}

		if ( $target->exists() ) {
			$blockers[$source] = Status::newFatal(
				'pt-movepage-block-base-exists', $target->getPrefixedText()
			);
		} else {
			$movePage = new MovePage( $this->oldTitle, $target );
			$status = $movePage->isValidMove();
			$status->merge( $movePage->checkPermissions( $user, $this->reason ) );
			if ( !$status->isOK() ) {
				$blockers[$source] = $status;
			}
		}

		// Don't spam the same errors for all pages if base page fails
		if ( count( $blockers ) ) {
			return $blockers;
		}

		// Collect all the old and new titles for checcks
		$titles = [];
		$base = $this->oldTitle->getPrefixedText();
		$pages = $this->getTranslationPages();
		foreach ( $pages as $old ) {
			$titles['tp'][] = [ $old, $this->newPageTitle( $base, $old, $target ) ];
		}

		$subpages = $this->moveSubpages ? $this->getNormalSubpages() : [];
		foreach ( $subpages as $old ) {
			$titles['subpage'][] = [ $old, $this->newPageTitle( $base, $old, $target ) ];
		}

		$pages = $this->getSectionPages();
		foreach ( $pages as $old ) {
			$titles['section'][] = [ $old, $this->newPageTitle( $base, $old, $target ) ];
		}

		// Check that all new titles are valid
		$lb = new LinkBatch();
		foreach ( $titles as $type => $list ) {
			// Give grep a chance to find the usages:
			// pt-movepage-block-tp-invalid, pt-movepage-block-section-invalid,
			// pt-movepage-block-subpage-invalid
			foreach ( $list as $pair ) {
				list( $old, $new ) = $pair;
				if ( $new === null ) {
					$blockers[$old] = Status::newFatal(
						"pt-movepage-block-$type-invalid",
						$old->getPrefixedText()
					);
					continue;
				}
				$lb->addObj( $old );
				$lb->addObj( $new );
			}
		}

		if ( count( $blockers ) ) {
			return $blockers;
		}

		// Check that there are no move blockers
		$lb->execute();
		foreach ( $titles as $type => $list ) {
			// Give grep a chance to find the usages:
			// pt-movepage-block-tp-exists, pt-movepage-block-section-exists,
			// pt-movepage-block-subpage-exists
			foreach ( $list as $pair ) {
				list( $old, $new ) = $pair;
				if ( $new->exists() ) {
					$blockers[$old] = Status::newFatal(
						"pt-movepage-block-$type-exists",
						$old->getPrefixedText(),
						$new->getPrefixedText()
					);
				} else {
					/* This method has terrible performance:
					 * - 2 queries by core
					 * - 3 queries by lqt
					 * - and no obvious way to preload the data! */
					$movePage = new MovePage( $old, $target );
					$status = $movePage->isValidMove();
					// Do not check for permissions here, as these pages are not editable/movable
					// in regular use
					if ( !$status->isOK() ) {
						$blockers[$old] = $status;
					}

					/* Because of the poor performance, check only one of the possibly thousands
					 * of section pages and assume rest are fine. This assumes section pages are
					 * listed last in the array. */
					if ( $type === 'section' ) {
						break;
					}
				}
			}
		}

		return $blockers;
	}

	/**
	 * Makes old title into a new title by replacing $base part of old title
	 * with $target.
	 * @param string $base Title::getPrefixedText() of the base page.
	 * @param Title $old The title to convert.
	 * @param Title $target The target title for the base page.
	 * @return Title
	 */
	protected function newPageTitle( $base, Title $old, Title $target ) {
		$search = preg_quote( $base, '~' );

		if ( $old->inNamespace( NS_TRANSLATIONS ) ) {
			$new = $old->getText();
			$new = preg_replace( "~^$search~", $target->getPrefixedText(), $new, 1 );

			return Title::makeTitleSafe( NS_TRANSLATIONS, $new );
		} else {
			$new = $old->getPrefixedText();
			$new = preg_replace( "~^$search~", $target->getPrefixedText(), $new, 1 );

			return Title::newFromText( $new );
		}
	}

	/**
	 * Returns all section pages, including those which are currently not active.
	 * @return Title[]
	 */
	protected function getSectionPages() {
		if ( !isset( $this->sectionPages ) ) {
			$this->sectionPages = $this->page->getTranslationUnitPages( 'all' );
		}

		return $this->sectionPages;
	}

	/**
	 * Returns only translation subpages.
	 * @return Title[]
	 */
	protected function getTranslationPages() {
		if ( !isset( $this->translationPages ) ) {
			$this->translationPages = $this->page->getTranslationPages();
		}

		return $this->translationPages;
	}

	/**
	 * Returns all subpages, if the namespace has them enabled.
	 * @return Title[]
	 */
	protected function getSubpages() {
		$pages = $this->page->getTitle()->getSubpages();
		if ( $pages instanceof Traversable ) {
			$pages = iterator_to_array( $pages );
		}

		return $pages;
	}

	private function getNormalSubpages() {
		return array_filter(
			$this->getSubpages(),
			function ( $page ) {
				return !(
					TranslatablePage::isTranslationPage( $page ) ||
					TranslatablePage::isSourcePage( $page )
				);
			}
		);
	}

	private function getTranslatableSubpages() {
		return array_filter(
			$this->getSubpages(),
			function ( $page ) {
				return TranslatablePage::isSourcePage( $page );
			}
		);
	}
}
