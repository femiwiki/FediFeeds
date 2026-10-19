<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\Sanitizer;

/**
 * Finds the items of a section feed in a rendering. An item is a heading at the feed's unit
 * level, named by its path: the texts of the headings above it and its own. A path that occurs
 * more than once is told apart by an ordinal, the n-th time the path appeared.
 */
class Sections {

	/**
	 * Every heading of the rendering in order, with its level, text, anchor and link anchor.
	 *
	 * @return array{level: int, text: string, anchor: string, link: string}[]
	 */
	public static function getHeadings( ParserOutput $output ): array {
		$headings = [];
		foreach ( $output->getTOCData()?->getSections() ?? [] as $section ) {
			$headings[] = [
				'level' => $section->hLevel,
				'text' => trim( Sanitizer::stripAllTags( $section->line ) ),
				'anchor' => $section->anchor,
				'link' => $section->linkAnchor,
			];
		}
		return $headings;
	}

	/**
	 * The headings at the unit level in order, each with its path. Headings above the unit
	 * level make the path, and headings below it belong to the item above them.
	 *
	 * @param array{level: int, text: string, anchor: string, link: string}[] $headings
	 * @param int $unit
	 * @return array{path: string[], base: string, anchor: string, link: string}[]
	 */
	public static function getOccurrences( array $headings, int $unit ): array {
		$stack = [];
		$occurrences = [];
		foreach ( $headings as $heading ) {
			if ( $heading['level'] > $unit ) {
				continue;
			}
			while ( $stack && end( $stack )['level'] >= $heading['level'] ) {
				array_pop( $stack );
			}
			if ( $heading['level'] < $unit ) {
				$stack[] = $heading;
				continue;
			}
			$path = array_merge( array_column( $stack, 'text' ), [ $heading['text'] ] );
			$occurrences[] = [
				'path' => $path,
				// Heading texts hold no line breaks
				'base' => implode( "\n", $path ),
				'anchor' => $heading['anchor'],
				'link' => $heading['link'],
			];
		}
		return $occurrences;
	}

	/**
	 * The key of an item in fi_key: its path one heading per line, then for a path that
	 * appeared again a line of a space and the ordinal, which no trimmed heading text can be.
	 * A key longer than the column is its SHA-256 instead.
	 */
	public static function getKey( string $base, int $ordinal ): string {
		$key = $ordinal > 1 ? "$base\n $ordinal" : $base;
		return strlen( $key ) > Items::MAX_KEY_LENGTH ? 'sha256:' . hash( 'sha256', $key ) : $key;
	}

	/**
	 * The occurrences of the new revision that are new items, with their ordinals. Paths are
	 * counted as a multiset: a path that occurs more often than in the parent has that many
	 * new items, numbered after the parent's count. Which of its occurrences are the new ones
	 * is found by aligning the two revisions' sequences of paths, so it does not matter
	 * whether the page adds sections above the old ones or below them.
	 *
	 * @param array{base: string}[] $old The parent's occurrences
	 * @param array{base: string}[] $new The new revision's occurrences
	 * @return array<int,int> Ordinal by index in $new
	 */
	public static function findNew( array $old, array $new ): array {
		$a = array_column( $old, 'base' );
		$b = array_column( $new, 'base' );
		$oldCounts = array_count_values( $a );
		$newCounts = array_count_values( $b );
		$matched = self::align( $a, $b );
		$assigned = [];
		$found = [];
		foreach ( $b as $i => $base ) {
			$extra = $newCounts[$base] - ( $oldCounts[$base] ?? 0 );
			$assigned[$base] ??= 0;
			if ( !isset( $matched[$i] ) && $assigned[$base] < $extra ) {
				$assigned[$base]++;
				$found[$i] = ( $oldCounts[$base] ?? 0 ) + $assigned[$base];
			}
		}
		return $found;
	}

	/**
	 * The indexes of $b that a longest common subsequence with $a keeps. The common start and
	 * end are matched first, so an edit in one place compares only what lies between.
	 *
	 * @param string[] $a
	 * @param string[] $b
	 * @return array<int,true>
	 */
	private static function align( array $a, array $b ): array {
		$matched = [];
		$n = count( $a );
		$m = count( $b );
		$start = 0;
		while ( $start < $n && $start < $m && $a[$start] === $b[$start] ) {
			$matched[$start++] = true;
		}
		$end = 0;
		while ( $end < $n - $start && $end < $m - $start && $a[$n - 1 - $end] === $b[$m - 1 - $end] ) {
			$matched[$m - 1 - $end++] = true;
		}
		$a = array_slice( $a, $start, $n - $start - $end );
		$b = array_slice( $b, $start, $m - $start - $end );
		$n = count( $a );
		$m = count( $b );
		// What lies between is short unless the page was rewritten, which is left unmatched
		if ( !$n || !$m || $n * $m > 1_000_000 ) {
			return $matched;
		}
		$lengths = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$lengths[$i][$j] = $a[$i] === $b[$j]
					? $lengths[$i + 1][$j + 1] + 1
					: max( $lengths[$i + 1][$j], $lengths[$i][$j + 1] );
			}
		}
		for ( $i = 0, $j = 0; $i < $n && $j < $m; ) {
			if ( $a[$i] === $b[$j] ) {
				$matched[$start + $j] = true;
				$i++;
				$j++;
			} elseif ( $lengths[$i + 1][$j] >= $lengths[$i][$j + 1] ) {
				$i++;
			} else {
				$j++;
			}
		}
		return $matched;
	}
}
