<?php
/**
 * Contains code for special page Special:PagePreparation
 *
 * @file
 * @author Pratik Lahoti
 * @copyright Copyright © 2014 Pratik Lahoti
 * @license GPL-2.0-or-later
 */

class SpecialPagePreparation extends SpecialPage {
	public function __construct() {
		parent::__construct( 'PagePreparation', 'pagetranslation' );
	}

	protected function getGroupName() {
		return 'translation';
	}

	public function execute( $par ) {
		$request = $this->getRequest();
		$output = $this->getOutput();
		$this->setHeaders();
		$this->checkPermissions();

		$inputValue = htmlspecialchars( $request->getText( 'page', $par ) );
		$pagenamePlaceholder = $this->msg( 'pp-pagename-placeholder' )->escaped();
		$prepareButtonValue = $this->msg( 'pp-prepare-button-label' )->escaped();
		$saveButtonValue = $this->msg( 'pp-save-button-label' )->escaped();
		$cancelButtonValue = $this->msg( 'pp-cancel-button-label' )->escaped();
		$summaryValue = $this->msg( 'pp-save-summary' )->inContentLanguage()->escaped();
		$output->addModules( 'ext.translate.special.pagepreparation' );
		$output->addModuleStyles( [
			'ext.translate.special.pagepreparation.styles',
			'jquery.uls.grid'
		] );

		$out = '';
		$diff = new DifferenceEngine( $this->getContext() );
		$diffHeader = $diff->addHeader( ' ', $this->msg( 'pp-diff-old-header' )->escaped(),
			$this->msg( 'pp-diff-new-header' )->escaped() );

		$out = <<<HTML
<div class="grid">
	<form class="mw-tpp-sp-form row" name="mw-tpp-sp-input-form" action="">
		<input id="pp-summary" type="hidden" value="{$summaryValue}" />
		<input name="page" id="page" class="mw-searchInput mw-ui-input"
			placeholder="{$pagenamePlaceholder}" value="{$inputValue}"/>
		<button id="action-prepare" class="mw-ui-button mw-ui-progressive" type="button">
			{$prepareButtonValue}</button>
		<button id="action-save" class="mw-ui-button mw-ui-progressive hide" type="button">
			{$saveButtonValue}</button>
		<button id="action-cancel" class="mw-ui-button mw-ui-quiet hide" type="button">
			{$cancelButtonValue}</button>
	</form>
	<div class="messageDiv hide"></div>
	<div class="divDiff hide">
		{$diffHeader}
	</div>
</div>
HTML;
		$output->addHTML( $out );

		$nojs = Html::element(
			'div',
			[ 'class' => 'tux-nojs errorbox' ],
			$this->msg( 'tux-nojs' )->plain()
		);
		$output->addHTML( $nojs );
	}
}
