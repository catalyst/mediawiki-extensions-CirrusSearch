<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Maintenance;
use Elastica\Client;

class IndexAllAliasValidator extends IndexAliasValidator {
	/**
	 * @var string prefix of names of indices that should be removed
	 */
	protected $shouldRemovePrefix;

	public function __construct( Client $client, $aliasName, $specificIndexName, $startOver, $type, Maintenance $out = null ) {
		parent::__construct( $client, $aliasName, $specificIndexName, $startOver, $out );
		$this->shouldRemovePrefix = $type;
	}

	/**
	 * @param array $add
	 * @param array $remove
	 * @return \Status
	 */
	protected function updateIndices( array $add, array $remove ) {
		$data = array();

		$this->output( "alias not already assigned to this index..." );

		// We'll remove the all alias from the indices that we're about to delete while
		// we add it to this index.  Elastica doesn't support this well so we have to
		// build the request to Elasticsearch ourselves.

		foreach ( $add as $indexName ) {
			$data['action'][] = array( 'add' => array( 'index' => $indexName, 'alias' => $this->aliasName ) );
		}

		foreach ( $remove as $indexName ) {
			$data['action'][] = array( 'remove' => array( 'index' => $indexName, 'alias' => $this->aliasName ) );
		}

		$this->client->request( '_aliases', \Elastica\Request::POST, $data );
		$this->output( "corrected\n" );

		return parent::updateIndices($add, $remove);
	}

	protected function shouldRemoveFromAlias( $name ) {
		// Only if the name starts with the type being processed otherwise we'd
		// remove the content index from the all alias.
		return strpos( $name, "$this->shouldRemovePrefix" ) === 0;
	}
}