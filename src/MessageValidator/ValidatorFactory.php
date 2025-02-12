<?php
/**
 * @file
 * @author Abijeet Patro
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extensions\Translate\MessageValidator;

use InvalidArgumentException;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\BraceBalanceValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\EscapeCharacterValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\GettextNewlineValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\GettextPluralValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\InsertableRegexValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\InsertableRubyVariableValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\MatchSetValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\MediaWikiMiscValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\MediaWikiPageNameValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\MediaWikiPluralValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\NewlineValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\PrintfValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\PythonInterpolationValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\SmartFormatPluralValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\UnicodePluralValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\WikiLinkValidator;
use MediaWiki\Extensions\Translate\MessageValidator\Validators\WikiParameterValidator;
use RuntimeException;

/**
 * A factory class used to instantiate instances of pre-provided Validators
 * @since 2019.06
 */
class ValidatorFactory {
	/**
	 * @var string[]
	 */
	protected static $validators = [
		'BraceBalance' => BraceBalanceValidator::class,
		'EscapeCharacter' => EscapeCharacterValidator::class,
		'GettextNewline' => GettextNewlineValidator::class,
		'GettextPlural' => GettextPluralValidator::class,
		'InsertableRegex' => InsertableRegexValidator::class,
		'InsertableRubyVariable' => InsertableRubyVariableValidator::class,
		'MatchSet' => MatchSetValidator::class,
		'MediaWikiMisc' => MediaWikiMiscValidator::class,
		'MediaWikiPageName' => MediaWikiPageNameValidator::class,
		'MediaWikiPlural' => MediaWikiPluralValidator::class,
		'Newline' => NewlineValidator::class,
		'Printf' => PrintfValidator::class,
		'PythonInterpolation' => PythonInterpolationValidator::class,
		'SmartFormatPlural' => SmartFormatPluralValidator::class,
		'UnicodePlural' => UnicodePluralValidator::class,
		'WikiParameter' => WikiParameterValidator::class,
		'WikiLink' => WikiLinkValidator::class
	];

	/**
	 * Returns a validator instance based on the Id specified
	 * @param string $id Id of the pre-defined validator class
	 * @param mixed|null $params
	 * @return Validator
	 */
	public static function get( $id, $params = null ) {
		if ( isset( self::$validators[ $id ] ) ) {
			// @phan-suppress-next-line PhanParamTooMany Not all sub classes using the arg
			return new self::$validators[ $id ]( $params );
		}

		throw new InvalidArgumentException( "Could not find validator with id - '$id'. " );
	}

	/**
	 * Takes a Validator class name, and returns an instance of that class.
	 * Ensures that the class implements the Validator interface.
	 *
	 * @param string $class Custom validator class name
	 * @param mixed|null $params
	 * @throws InvalidArgumentException
	 * @return Validator
	 */
	public static function loadInstance( $class, $params = null ) {
		if ( class_exists( $class ) ) {
			$validator = new $class( $params );

			if ( !$validator instanceof Validator ) {
				// Note that this checks for the class in the global namespace and not
				// the current namespace.
				throw new InvalidArgumentException(
					"Validator '$class' does not implement the Validator interface."
				);
			}

			return $validator;
		}

		throw new InvalidArgumentException( "Could not find validator class - '$class'. " );
	}

	/**
	 * Adds / Updates available list of validators
	 * @param string $id Id of the validator
	 * @param string $validator Validator class name
	 * @param string $ns
	 */
	public static function set( $id, $validator, $ns = '\\' ) {
		if ( !class_exists( $ns . $validator ) ) {
			throw new RuntimeException( 'Could not find validator class - ' . $ns . $validator );
		}

		self::$validators[ $id ] = $ns . $validator;
	}
}
