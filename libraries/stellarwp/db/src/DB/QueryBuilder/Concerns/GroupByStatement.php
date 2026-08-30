<?php
/**
 * @license GPL-2.0
 *
 * Modified by Lindeni Mahlalela using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Lisensio_KB\StellarWP\DB\QueryBuilder\Concerns;

use Lisensio_KB\StellarWP\DB\DB;

/**
 * @since 1.0.0
 */
trait GroupByStatement {
	/**
	 * @var array
	 */
	protected $groupByColumns = [];

	/**
	 * @return $this
	 */
	public function groupBy( $tableColumn ) {
		if ( ! in_array( $tableColumn, $this->groupByColumns, true ) ) {
			$this->groupByColumns[] = DB::prepare( '%1s', $tableColumn );
		}

		return $this;
	}

	protected function getGroupBySQL() {
		return ! empty( $this->groupByColumns )
			? [ 'GROUP BY ' . implode( ',', $this->groupByColumns ) ]
			: [];
	}
}
