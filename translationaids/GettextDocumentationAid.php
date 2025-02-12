<?php
/**
 * Translation aid provider.
 *
 * @file
 * @author Niklas Laxström
 * @copyright Copyright © 2013, Niklas Laxström
 * @license GPL-2.0-or-later
 */

use MediaWiki\MediaWikiServices;

/**
 * Translation aid which gives Gettext documentation.
 *
 * @ingroup TranslationAids
 * @since 2013-01-01
 */
class GettextDocumentationAid extends TranslationAid {
	public function getData() {
		// We need to get the primary group to get the correct file
		// So $group can be different from $this->group
		$group = $this->handle->getGroup();
		if ( !$group instanceof FileBasedMessageGroup ) {
			throw new TranslationHelperException( 'Not a Gettext group' );
		}

		$ffs = $group->getFFS();
		if ( !$ffs instanceof GettextFFS ) {
			throw new TranslationHelperException( 'Not a Gettext group' );
		}

		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$mykey = $contLang->lcfirst( $this->handle->getKey() );
		$mykey = str_replace( ' ', '_', $mykey );
		$data = $ffs->read( $group->getSourceLanguage() );

		// $mykey can be unset if the source file has changed since last import.
		// FIXME: the template should be cached in message group source cache to
		// avoid frequent re-parsing and this issue.
		// See https://phabricator.wikimedia.org/T39168
		$help = $data['TEMPLATE'][$mykey]['comments'] ?? [];

		$conf = $group->getConfiguration();
		if ( isset( $conf['BASIC']['codeBrowser'] ) ) {
			$pattern = $conf['BASIC']['codeBrowser'];
			$pattern = str_replace( '%FILE%', '\1', $pattern );
			$pattern = str_replace( '%LINE%', '\2', $pattern );
			$pattern = "[$pattern \\1:\\2]";
		} else {
			$pattern = "\\1:\\2";
		}

		$out = '';
		foreach ( $help as $type => $lines ) {
			if ( $type === ':' ) {
				$files = '';
				foreach ( $lines as $line ) {
					$files .= ' ' . preg_replace( '/([^ :]+):(\d+)/', $pattern, $line );
				}
				$out .= "<nowiki>#:</nowiki> $files<br />";
			} else {
				foreach ( $lines as $line ) {
					$out .= "<nowiki>#$type</nowiki> $line<br />";
				}
			}
		}

		$html = $this->context->getOutput()->parseAsContent( $out );

		return [
			'language' => $contLang->getCode(),
			// @todo Provide raw data when possible
			// 'value' => $help,
			'html' => $html,
		];
	}
}
