<?php
/**
 * Script to export translations of one message group to file(s).
 *
 * @author Niklas Laxström
 * @author Siebrand Mazeland
 * @copyright Copyright © 2008-2013, Niklas Laxström, Siebrand Mazeland
 * @license GPL-2.0-or-later
 * @file
 */

// Standard boilerplate to define $IP
if ( getenv( 'MW_INSTALL_PATH' ) !== false ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$dir = __DIR__;
	$IP = "$dir/../../..";
}
require_once "$IP/maintenance/Maintenance.php";

class CommandlineExport extends Maintenance {
	const EXPORT_LOG_FILE = 'translation-exports';

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Message exporter.' );
		$this->addOption(
			'group',
			'Comma separated list of group IDs (can use * as wildcard)',
			true, /*required*/
			true /*has arg*/
		);
		$this->addOption(
			'lang',
			'Comma separated list of language codes or *',
			true, /*required*/
			true /*has arg*/
		);
		$this->addOption(
			'target',
			'Target directory for exported files',
			true, /*required*/
			true /*has arg*/
		);
		$this->addOption(
			'skip',
			'(optional) Languages to skip, comma separated list',
			false, /*required*/
			true /*has arg*/
		);
		$this->addOption(
			'skipgroup',
			'(optional) Comma separated list of group IDs that should not be exported',
			false, /*required*/
			true /*has arg*/
		);
		$this->addOption(
			'threshold',
			'(optional) Do not export under this percentage translated',
			false, /*required*/
			true /*has arg*/
		);
		$this->addOption(
			'hours',
			'(optional) Only export languages with changes in the last given number of hours',
			false, /*required*/
			true /*has arg*/
		);
		$this->addOption(
			'ppgettext',
			'(optional) Group root path for checkout of product. "msgmerge" will post ' .
			'process on the export result based on the current source file ' .
			'in that location (from sourcePattern or definitionFile)',
			false, /*required*/
			true /*has arg*/
		);
		$this->addOption(
			'no-location',
			'(optional) Only used combined with "ppgettext". This option will rebuild ' .
			'the gettext file without location information',
			false, /*required*/
			false /*has arg*/
		);
		$this->addOption(
			'no-fuzzy',
			'(optional) Do not include any messages marked as fuzzy/outdated',
			false, /*required*/
			false /*has arg*/
		);
		$this->addOption(
			'codemaponly',
			'(optional) Only export languages that have a codeMap entry',
			false, /*required*/
			false /*has arg*/
		);
		$this->requireExtension( 'Translate' );
	}

	public function execute() {
		wfDebugLog( self::EXPORT_LOG_FILE, 'Starting exports for groups - '
			. $this->getOption( 'group' ) . '... ' );
		$exportStartTime = microtime( true );

		$target = $this->getOption( 'target' );
		if ( !is_writable( $target ) ) {
			$this->fatalError( "Target directory is not writable ($target)." );
		}

		$threshold = $this->getOption( 'threshold' );
		$noFuzzy = $this->hasOption( 'no-fuzzy' );

		$noLocation = '';
		if ( $this->hasOption( 'no-location' ) ) {
			$noLocation = '--no-location ';
		}

		$skip = [];
		if ( $this->hasOption( 'skip' ) ) {
			$skip = array_map( 'trim', explode( ',', $this->getOption( 'skip' ) ) );
		}

		$reqLangs = TranslateUtils::parseLanguageCodes( $this->getOption( 'lang' ) );
		$reqLangs = array_flip( $reqLangs );
		foreach ( $skip as $skipLang ) {
			unset( $reqLangs[$skipLang] );
		}
		$reqLangs = array_flip( $reqLangs );

		$codemapOnly = $this->hasOption( 'codemaponly' );

		$groupIds = explode( ',', trim( $this->getOption( 'group' ) ) );
		$groupIds = MessageGroups::expandWildcards( $groupIds );
		$groups = MessageGroups::getGroupsById( $groupIds );
		'@phan-var FileBasedMessageGroup[] $groups';

		/** @var FileBasedMessageGroup $group */
		foreach ( $groups as $groupId => $group ) {
			if ( $group->isMeta() ) {
				$this->output( "Skipping meta message group $groupId.\n" );
				unset( $groups[$groupId] );
				continue;
			}

			if ( !$group instanceof FileBasedMessageGroup ) {
				$this->output( "EE2: Unexportable message group $groupId.\n" );
				unset( $groups[$groupId] );
				continue;
			}
		}

		if ( !count( $groups ) ) {
			$this->fatalError( 'EE1: No valid message groups identified.' );
		}

		$changeFilter = false;
		$hours = $this->getOption( 'hours' );
		if ( $hours ) {
			$namespaces = [];

			/** @var FileBasedMessageGroup $group */
			foreach ( $groups as $group ) {
				$namespaces[$group->getNamespace()] = true;
			}

			$namespaces = array_keys( $namespaces );
			$bots = true;

			$changeFilter = [];
			$rows = TranslateUtils::translationChanges( $hours, $bots, $namespaces );
			foreach ( $rows as $row ) {
				$title = Title::makeTitle( $row->rc_namespace, $row->rc_title );
				$handle = new MessageHandle( $title );
				$code = $handle->getCode();
				if ( !$code ) {
					continue;
				}
				$groupIds = $handle->getGroupIds();
				foreach ( $groupIds as $groupId ) {
					$changeFilter[$groupId][$code] = true;
				}
			}
		}

		$skipGroups = [];
		if ( $this->hasOption( 'skipgroup' ) ) {
			$skipGroups = array_map( 'trim', explode( ',', $this->getOption( 'skipgroup' ) ) );
		}

		foreach ( $groups as $groupId => $group ) {
			if ( in_array( $groupId, $skipGroups ) ) {
				$this->output( "Group $groupId is in skipgroup.\n" );
				continue;
			}

			// No changes to this group at all
			if ( is_array( $changeFilter ) && !isset( $changeFilter[$groupId] ) ) {
				$this->output( "No recent changes to $groupId.\n" );
				continue;
			}

			$langs = $reqLangs;

			if ( $codemapOnly ) {
				foreach ( $langs as $index => $code ) {
					if ( $group->mapCode( $code ) === $code ) {
						unset( $langs[$index] );
					}
				}
			}

			if ( $threshold ) {
				wfDebugLog( self::EXPORT_LOG_FILE, "Calculating stats for group $groupId" );
				$tStartTime = microtime( true );
				$stats = MessageGroupStats::forGroup( $groupId );
				$emptyLangs = [];
				foreach ( $langs as $index => $code ) {
					if ( !isset( $stats[$code] ) ) {
						unset( $langs[$index] );
						continue;
					}

					$total = $stats[$code][MessageGroupStats::TOTAL];
					$translated = $stats[$code][MessageGroupStats::TRANSLATED];

					if ( $total === 0 ) {
						$emptyLangs[] = $code;
						unset( $langs[$index] );
						continue;
					}

					if ( $translated / $total * 100 < $threshold ) {
						unset( $langs[$index] );
					}
				}

				if ( $emptyLangs !== [] ) {
					$this->output(
						"Message group $groupId doesn't contain messages in language(s): " .
						implode( ', ', $emptyLangs ) . "."
					);
				}

				$tEndTime = microtime( true );
				wfDebugLog( self::EXPORT_LOG_FILE,
					"Finished calculating stats for group $groupId. Time: "
						. ( $tEndTime - $tStartTime ) . ' secs.' );
			}

			// Filter out unchanged languages from requested languages
			if ( is_array( $changeFilter ) ) {
				$langs = array_intersect( $langs, array_keys( $changeFilter[$groupId] ) );
			}

			if ( !count( $langs ) ) {
				continue;
			}

			$this->output( 'Exporting ' . count( $langs ) . " languages for group $groupId" );

			$ffs = $group->getFFS();
			$ffs->setWritePath( $target );
			$sourceLanguage = $group->getSourceLanguage();
			$collection = $group->initCollection( $sourceLanguage );

			$definitionFile = false;

			if ( $this->hasOption( 'ppgettext' ) && $ffs instanceof GettextFFS ) {
				global $wgMaxShellMemory, $wgTranslateGroupRoot;

				// Need more shell memory for msgmerge.
				$wgMaxShellMemory = 402400;

				$path = $group->getSourceFilePath( $sourceLanguage );
				$definitionFile = str_replace(
					$wgTranslateGroupRoot,
					$this->getOption( 'ppgettext' ),
					$path
				);
			}

			$whitelist = $group->getTranslatableLanguages();

			wfDebugLog(
				self::EXPORT_LOG_FILE, 'Exporting languages ('
				. count( $langs ) . ") for group - $groupId."
			);

			$langExportTimes = [
				'collection' => 0,
				'ffs' => 0,
				'definitionFile' => 0
			];
			$langStartTime = microtime( true );
			foreach ( $langs as $lang ) {
				// Do not export languages that are blacklisted (or not whitelisted).
				// Also check that whitelist is not null, which means that all
				// languages are allowed for translation and export.
				if ( is_array( $whitelist ) && !isset( $whitelist[$lang] ) ) {
					continue;
				}

				$startTime = microtime( true );
				$collection->resetForNewLanguage( $lang );
				$collection->loadTranslations();
				// Don't export ignored, unless it is the source language
				// or message documentation
				global $wgTranslateDocumentationLanguageCode;
				if ( $lang !== $wgTranslateDocumentationLanguageCode
					&& $lang !== $sourceLanguage
				) {
					$collection->filter( 'ignored' );
				}

				if ( $noFuzzy ) {
					$collection->filter( 'fuzzy' );
				}
				$endTime = microtime( true );
				$langExportTimes['collection'] += ( $endTime - $startTime );

				$startTime = microtime( true );
				$ffs->write( $collection );
				$endTime = microtime( true );
				$langExportTimes['ffs'] += ( $endTime - $startTime );

				// Do post processing if requested.
				if ( $definitionFile ) {
					$startTime = microtime( true );
					if ( is_file( $definitionFile ) ) {
						$targetFileName = $ffs->getWritePath() .
							'/' . $group->getTargetFilename( $collection->code );
						$cmd = 'msgmerge --quiet ' . $noLocation . '--output-file=' .
							$targetFileName . ' ' . $targetFileName . ' ' . $definitionFile;
						wfShellExec( $cmd, $ret );

						// Report on errors.
						if ( $ret ) {
							$this->error( "ERROR: $ret" );
						}
					} else {
						$this->fatalError( "$definitionFile does not exist for group $groupId." );
					}
					$endTime = microtime( true );
					$langExportTimes['definitionFile'] += ( $endTime - $startTime );
				}
			}
			$langEndTime = microtime( true );

			wfDebugLog(
				self::EXPORT_LOG_FILE,
				"Done exporting languages for group - $groupId. " .
				'Time taken - ' . ( $langEndTime - $langStartTime ) . ' secs.'
			);

			foreach ( $langExportTimes as $type => $time ) {
				wfDebugLog(
					self::EXPORT_LOG_FILE,
					"Time taken by '$type' for group $groupId - $time secs."
				);
			}
		}

		$exportEndTime = microtime( true );
		wfDebugLog(
			self::EXPORT_LOG_FILE, 'Finished export process for groups - ' .
			$this->getOption( 'group' ) .
			'. Time: ' . ( $exportEndTime - $exportStartTime ) . ' secs.'
		);
	}
}

$maintClass = CommandlineExport::class;
require_once RUN_MAINTENANCE_IF_MAIN;
