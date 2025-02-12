<?php
/**
 * Contains a generic job class
 *
 * @file
 * @author Abijeet Patro
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extensions\Translate\Jobs;

use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Generic Job class extended by other jobs. Provides logging functionality.
 * @since 2019.08
 */
abstract class GenericTranslateJob extends \Job {
	/**
	 * A logger instance
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * Channel name to be used during logging
	 * @var string
	 */
	const CHANNEL_NAME = 'Translate.Jobs';

	/**
	 * Returns a logger instance with the channel name. Can have only a single
	 * channel per job, so once instantiated, the same instance is returned.
	 * @return LoggerInterface
	 */
	protected function getLogger() {
		if ( $this->logger ) {
			return $this->logger;
		}

		$this->logger = LoggerFactory::getInstance( self::CHANNEL_NAME );
		return $this->logger;
	}

	protected function getLogPrefix() {
		return '[Job: ' . $this->getType() . '][Request ID: ' . $this->getRequestId() .
			'][Title: ' . $this->title->getPrefixedText() . '] ';
	}

	protected function logInfo( $msg, $context = [] ) {
		$this->getLogger()->info( $this->getLogPrefix() . $msg, $context );
	}

	protected function logDebug( $msg, $context = [] ) {
		$this->getLogger()->debug( $this->getLogPrefix() . $msg, $context );
	}

	protected function logError( $msg, $context = [] ) {
		$this->getLogger()->error( $this->getLogPrefix() . $msg, $context );
	}

	protected function logWarning( $msg, $context = [] ) {
		$this->getLogger()->warning( $this->getLogPrefix() . $msg, $context );
	}
}
