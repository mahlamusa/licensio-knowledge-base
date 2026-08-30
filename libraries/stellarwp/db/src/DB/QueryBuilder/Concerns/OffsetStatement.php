<?php
/**
 * @license GPL-2.0
 *
 * Modified by Lindeni Mahlalela using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Lisensio_KB\StellarWP\DB\QueryBuilder\Concerns;

/**
 * @since 1.0.0
 */
trait OffsetStatement {
	/**
	 * @var int
	 */
	protected $offset;

	/**
	 * @param  int  $offset
	 *
	 * @return $this
	 */
	public function offset( $offset ) {
		$this->offset = (int) $offset;

		return $this;
	}

	protected function getOffsetSQL() {
		return $this->limit && $this->offset
			? [ "OFFSET {$this->offset}" ]
			: [];
	}
}
