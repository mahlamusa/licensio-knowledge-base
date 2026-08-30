<?php
/**
 * @license GPL-2.0
 *
 * Modified by Lindeni Mahlalela using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Lisensio_KB\StellarWP\DB\QueryBuilder\Concerns;

use Lisensio_KB\StellarWP\DB\QueryBuilder\Clauses\Union;
use Lisensio_KB\StellarWP\DB\QueryBuilder\QueryBuilder;

/**
 * @since 1.0.0
 */
trait UnionOperator {
	/**
	 * @var array
	 */
	protected $unions = [];

	/**
	 * @param  QueryBuilder  $union
	 *
	 * @return $this
	 */
	public function union( ...$union ) {
		$this->unions = array_map( function ( QueryBuilder $builder ) {
			return new Union( $builder );
		}, $union );

		return $this;
	}

	/**
	 * @param  QueryBuilder  $union
	 *
	 * @return $this
	 */
	public function unionAll( ...$union ) {
		$this->unions = array_map( function ( QueryBuilder $builder ) {
			return new Union( $builder, true );
		}, $union );

		return $this;
	}

	/**
	 * @return array|string[]
	 */
	protected function getUnionSQL() {
		if ( empty( $this->unions ) ) {
			return [];
		}

		return array_map( function ( Union $union ) {
			return ( $union->all ? 'UNION ALL ' : 'UNION ' ) . $union->builder->getSQL();
		}, $this->unions );
	}
}
