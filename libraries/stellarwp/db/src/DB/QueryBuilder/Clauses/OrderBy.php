<?php
/**
 * @license GPL-2.0
 *
 * Modified by Lindeni Mahlalela using {@see https://github.com/BrianHenryIE/strauss}.
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages name SQL operators and reach the browser only as JSON or escaped text.

namespace Licensio_KB\StellarWP\DB\QueryBuilder\Clauses;

use InvalidArgumentException;

/**
 * @since 1.0.0
 */
class OrderBy {
	/**
	 * @var string
	 */
	public $column;

	/**
	 * @var string
	 */
	public $direction;

	/**
	 * @param $column
	 * @param $direction
	 */
	public function __construct( $column, $direction ) {
		$this->column	= trim( $column );
		$this->direction = $this->getSortDirection( $direction );
	}

	/**
	 * @param  string  $direction
	 *
	 * @return string
	 */
	private function getSortDirection( $direction ) {
		$direction  = strtoupper( $direction );
		$directions = ['ASC', 'DESC'];

		if ( ! in_array( $direction, $directions, true ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Unsupported sort direction %s. Please use one of the (%s)',
					$direction,
					implode( ',', $directions )
				)
			);
		}

		return $direction;
	}
}
