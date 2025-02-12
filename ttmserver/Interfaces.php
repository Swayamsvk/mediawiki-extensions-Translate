<?php
/**
 * TTMServer - The Translate extension translation memory interface
 *
 * @file
 * @author Niklas Laxström
 * @copyright Copyright © 2012-2013, Niklas Laxström
 * @license GPL-2.0-or-later
 * @ingroup TTMServer
 */

/**
 * Interface for TTMServer that can be queried (=all of them).
 * @ingroup TTMServer
 * @since 2012-06-27
 */
interface ReadableTTMServer {
	/**
	 * Fetches all relevant suggestions for given text.
	 *
	 * @param string $sourceLanguage language code for the provide text
	 * @param string $targetLanguage language code for the suggestions
	 * @param string $text the text for which to search suggestions
	 * @return array List: unordered suggestions, which each has fields:
	 *   - source: String: the original text of the suggestion
	 *   - target: String: the suggestion
	 *   - context: String: title of the page where the suggestion comes from
	 *   - quality: Float: the quality of suggestion, 1 is perfect match
	 */
	public function query( $sourceLanguage, $targetLanguage, $text );

	/**
	 * Determines if the suggestion returned by this TTMServer comes
	 * from this wiki or any other wiki.
	 * @param array $suggestion
	 * @return Bool
	 */
	public function isLocalSuggestion( array $suggestion );

	/**
	 * Given suggestion returned by this TTMServer, constructs fully
	 * qualified URL to the location of the translation.
	 * @param array $suggestion
	 * @return String URL
	 */
	public function expandLocation( array $suggestion );
}

/**
 * Interface for TTMServer that can be updated.
 * @ingroup TTMServer
 * @since 2012-06-27
 */
interface WritableTTMServer {
	/**
	 * Shovels the new translation into translation memory.
	 * Use this for single updates (=after message edit).
	 * If no text is provided, entry will be removed from the translation
	 * memory.
	 *
	 * @param MessageHandle $handle
	 * @param string|null $targetText Use null to only delete.
	 */
	public function update( MessageHandle $handle, $targetText );

	/**
	 * Called when starting to fill the translation memory.
	 * Set up necessary variables and remove old content
	 * from the server.
	 */
	public function beginBootstrap();

	/**
	 * Called before every batch (MessageGroup).
	 */
	public function beginBatch();

	/**
	 * Called multiple times per batch if necessary.
	 *
	 * @param array $batch
	 */
	public function batchInsertDefinitions( array $batch );

	/**
	 * Called multiple times per batch if necessary.
	 *
	 * @param array $batch
	 */
	public function batchInsertTranslations( array $batch );

	/**
	 * Called before every batch (MessageGroup).
	 */
	public function endBatch();

	/**
	 * Do any cleanup, optimizing etc.
	 */
	public function endBootstrap();

	/**
	 * Get the list of services to duplicate writes to make them "mirrors"
	 * of this service.
	 *
	 * @since 2017.04
	 * @return string[]
	 */
	public function getMirrors();

	/**
	 * Check if the service is frozen, attempting to write to
	 * a frozen service may lead to errors or unexpected behaviors.
	 *
	 * @since 2017.04
	 * @return bool true if the service is frozen
	 */
	public function isFrozen();

	/**
	 * Instruct the service to fully wipe the index and start from scratch.
	 * @since 2020.01
	 */
	public function setDoReIndex();
}

/**
 * Interface for TTMServer that can act as backend for translation search.
 * @ingroup TTMServer
 * @since 2014.04
 */
interface SearchableTTMServer {
	/**
	 * Performs a search in the translation database.
	 *
	 * @param string $queryString String to search for.
	 * @param array $opts Query options like language.
	 * @param array $highlight Tags for highlighting.
	 * @return mixed Result set
	 */
	public function search( $queryString, $opts, $highlight );

	/**
	 * @param mixed $resultset
	 * @return array[]
	 */
	public function getFacets( $resultset );

	/**
	 * @param mixed $resultset
	 * @return int
	 */
	public function getTotalHits( $resultset );

	/**
	 * @param mixed $resultset
	 * @return array[]
	 */
	public function getDocuments( $resultset );
}
