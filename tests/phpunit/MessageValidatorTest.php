<?php
/**
 * Tests for Message Validator and ValidatorResult.
 *
 * @file
 * @author Abijeet Patro
 * @license GPL-2.0-or-later
 */

/**
 * @covers \MessageValidator
 * @covers \MediaWiki\Extensions\Translate\MessageValidator\ValidationResult
 * @group TranslationValidators
 */
class MessageValidatorTest extends MediaWikiTestCase {

	/**
	 * @var MessageGroup
	 */
	protected function setUp() : void {
		parent::setUp();

		$this->setTemporaryHook(
			'TranslatePostInitGroups',
			[ $this, 'getTestGroups' ]
		);

		$mg = MessageGroups::singleton();
		$mg->setCache( new WANObjectCache( [ 'cache' => wfGetCache( 'hash' ) ] ) );
		$mg->recache();

		MessageIndex::setInstance( new HashMessageIndex() );
		MessageIndex::singleton()->rebuild();
	}

	public function getTestGroups( &$list ) {
		$messages = [
			'translated' => 'bunny',
			'untranslated' => 'fanny',
			'testing-key' => 'test this!',
			'regex-key-test' => 'regex test',
			'non-matching-key' => 'non matching key'
		];

		$list['test-group'] = new MockWikiValidationMessageGroup( 'test-group', $messages );
	}

	public function testValidateMessage() {
		$group = MessageGroups::getGroup( 'test-group' );
		$collection = $group->initCollection( 'en-gb' );
		$collection->loadTranslations();

		$msgValidator = $group->getValidator();
		$validationResult = $msgValidator->validateMessage( $collection[ 'translated' ],
			'en-gb' );

		$this->assertTrue( $validationResult->hasErrors(),
			'errors are correctly identified.' );
		$this->assertTrue( $validationResult->hasWarnings(),
			'warnings are correctly identified.' );

		$this->assertCount( 1, $validationResult->getWarnings(),
			'there is 1 warning returned as per the validator.' );
		$this->assertCount( 2, $validationResult->getErrors(),
			'there are 2 errors returned as per the validator.' );

		$validationResult = $msgValidator->validateMessage( $collection[ 'translated' ],
			'en-gb', true );
		$this->assertTrue( $validationResult->hasErrors(),
			'errors are correctly identified if ignore warnings is set.' );
		$this->assertFalse( $validationResult->hasWarnings(),
			'warnings are ignored if ignore warnings is set.' );
	}

	public function testQuickValidate() {
		$group = MessageGroups::getGroup( 'test-group' );
		$collection = $group->initCollection( 'en-gb' );
		$collection->loadTranslations();

		$msgValidator = $group->getValidator();
		$validationResult = $msgValidator->quickValidate( $collection[ 'translated' ], 'en-gb' );

		$this->assertTrue( $validationResult->hasErrors() || $validationResult->hasWarnings(),
			'either errors or warnings are set.' );
		$this->assertFalse( $validationResult->hasWarnings() && $validationResult->hasErrors(),
			'either error or warnings are set, but not both.' );

		$this->assertCount( 1,
			$validationResult->getWarnings() + $validationResult->getErrors(),
			'there is a single warning or error returned as per the validator.' );

			$validationResult = $msgValidator->quickValidate( $collection[ 'translated' ],
				'en-gb', true );

		$this->assertTrue( $validationResult->hasErrors(),
			'errors are identified if ignore warnings is set.' );
		$this->assertFalse( $validationResult->hasWarnings(),
			'warnings are not identified if ignore warnings is set.' );
	}

	public function testDescriptiveMessage() {
		$group = MessageGroups::getGroup( 'test-group' );
		$collection = $group->initCollection( 'en-gb' );
		$collection->loadTranslations();

		$msgValidator = $group->getValidator();
		$validationResult = $msgValidator->validateMessage( $collection[ 'translated' ],
			'en-gb' );

		$requestContext = new RequestContext();
		$requestContext->setLanguage( 'en' );

		$this->assertCount( count( $validationResult->getErrors() ),
			$validationResult->getDescriptiveErrors( $requestContext ),
			'the number of descriptive errors messages matches the number of errors.'
		);
		$this->assertCount( count( $validationResult->getWarnings() ),
			$validationResult->getDescriptiveWarnings( $requestContext ),
			'the number of descriptive warnings messages matches the number of warnings.'
		);

		$this->assertIsString( $validationResult->getDescriptiveWarnings( $requestContext )[0],
			'warning messages are of type string.'
		);
		$this->assertIsString( $validationResult->getDescriptiveErrors( $requestContext )[0],
			'error messages are of type string'
		);
	}

	public function testCheckBlacklist() {
		$this->setMwGlobals( [
			'wgTranslateCheckBlacklist' => __DIR__ . '/data/check-blacklist.php'
		] );

		$group = MessageGroups::getGroup( 'test-group' );
		$collection = $group->initCollection( 'en-gb' );
		$collection->loadTranslations();

		$collectionFr = $group->initCollection( 'fr' );
		$collectionFr->loadTranslations();

		$msgValidator = $group->getValidator();
		$msgValidator::reloadCheckBlacklist();

		$validationResult = $msgValidator->validateMessage( $collection[ 'translated' ],
			'en-gb' );
		$this->assertCount( 1,
			$validationResult->getWarnings() + $validationResult->getErrors(),
			'warnings or errors are filtered as per check-blacklist.' );

		$validationResult = $msgValidator->validateMessage( $collectionFr[ 'translated' ],
			'fr' );
		$this->assertGreaterThan( 1,
			count( $validationResult->getWarnings() + $validationResult->getErrors() ),
			'warnings or errors are filtered as per check-blacklist only for specific language code.' );

		$validationResult = $msgValidator->quickValidate( $collection['translated'],
			'en-gb' );
		$this->assertCount( 1,
			$validationResult->getWarnings() + $validationResult->getErrors(),
			'warnings or errors are filtered as per check-blacklist.' );

		$validationResult = $msgValidator->quickValidate( $collectionFr[ 'translated' ],
			'fr' );
		$this->assertCount( 1,
			$validationResult->getWarnings() + $validationResult->getErrors(),
			'warnings or errors are filtered as per check-blacklist only for specific language code.' );
	}

	public function testKeyMatching() {
		$group = MessageGroups::getGroup( 'test-group' );
		$collection = $group->initCollection( 'en-gb' );
		$collection->loadTranslations();

		$msgValidator = $group->getValidator();
		$validationResult = $msgValidator->validateMessage( $collection['testing-key'], 'en-gb' );
		$this->assertCount(
			0,
			$validationResult->getErrors(),
			'no errors are raised for a non matching key!'
		);

		$validationResult = $msgValidator->validateMessage( $collection['regex-key-test'], 'en-gb' );
		$this->assertCount(
			2,
			$validationResult->getErrors(),
			'errors are raised for a matching key matched via regex!'
		);

		$validationResult = $msgValidator->validateMessage( $collection['translated'], 'en-gb' );
		$this->assertCount(
			2,
			$validationResult->getErrors(),
			'errors are raised for a matching key matched via a direct match!'
		);
	}
}
