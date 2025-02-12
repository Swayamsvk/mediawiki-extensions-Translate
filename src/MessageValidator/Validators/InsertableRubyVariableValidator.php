<?php
/**
 * @file
 * @author Abijeet Patro
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extensions\Translate\MessageValidator\Validators;

/**
 * An insertable Ruby variable validator that also acts as an InsertableSuggester
 * @since 2019.06
 */
class InsertableRubyVariableValidator extends InsertableRegexValidator {
	public function __construct() {
		parent::__construct( '/%{[a-zA-Z_]+}/' );
	}
}
