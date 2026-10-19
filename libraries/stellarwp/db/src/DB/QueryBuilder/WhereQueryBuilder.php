<?php
/**
 * @license GPL-2.0
 *
 * Modified by Lindeni Mahlalela using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Licensio_KB\StellarWP\DB\QueryBuilder;

use Licensio_KB\StellarWP\DB\QueryBuilder\Concerns\WhereClause;

/**
 * @since 1.0.0
 */
class WhereQueryBuilder {
	use WhereClause;

	/**
	 * @return string[]
	 */
	public function getSQL() {
		return $this->getWhereSQL();
	}
}
