<?php

namespace CirrusSearch;

use Elastica;
use CirrusSearch;
use CirrusSearch\BuildDocument\SuggestBuilder;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\SearchSuggestion;
use CirrusSearch\Search\SearchSuggestionSet;
use ConfigFactory;
use MediaWiki\Logger\LoggerFactory;
use Status;
use User;
use Elastica\Request;

/**
 * Performs search as you type queries using Completion Suggester.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
 * Completion Suggester Searcher
 *
 * NOTES:
 * The CompletionSuggester is built on top of the ElasticSearch Completion
 * Suggester.
 * (https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters-completion.html).
 *
 * This class is used at query time, see
 * CirrusSearch\BuildDocument\SuggestBuilder for index time logic.
 *
 * Document model: Cirrus documents are indexed with 2 suggestions:
 *
 * 1. The title suggestion (and close redirects).
 * This helps to avoid displaying redirects with typos (e.g. Albert Enstein,
 * Unietd States) where we make the assumption that if the redirect is close
 * enough it's likely a typo and it's preferable to display the canonical title.
 * This decision is made at index-time in SuggestBuilder::extractTitleAndSimilarRedirects.
 *
 * 2. The redirect suggestions
 * Because the same canonical title can be returned twice we support fetch_limit_factor
 * in suggest profiles to fetch more than what the use asked. Because the list of redirects
 * can be very large we cannot store all of them in the index (see limitations). We run a second
 * pass query on the main cirrus index to fetch them, then we try to detect which one is the closest
 * to the user query (see Util::chooseBestRedirect).
 *
 * LIMITATIONS:
 * A number of hacks are required in Cirrus to workaround some limitations in
 * the elasticsearch completion suggester implementation:
 * - It is a _suggest API, unlike classic "query then fetch" there is no fetch
 *   phase here.
 * - Payloads are stored in memory within the FST: we try to avoid them, but
 *   this forces us to implement a second pass query to fetch redirect titles
 *   from the cirrus main index.
 * - Fuzzy suggestions are ranked by index-time score: we allow to set
 *   'discount' param in the suggest profile (profiles/SuggestProfiles.php). The
 *   default profile includes a fuzzy and non-fuzzy suggestion query. This is to
 *   avoid having fuzzy suggestions ranked higher than exact suggestion.
 * - The suggestion string cannot be expanded to more than 255 strings at
 *   index time: we limit the number of generated tokens in the analysis config
 *   (see includes/Maintenance/SuggesterAnalysisConfigBuilder.php) but we can't
 *   workaround this problem for geosuggestion  (suggestions will be prepended by
 *   geohash prefixes, one per precision step)
 *
 * @todo: investigate new features in elasticsearch completion suggester v2 to remove
 * some workarounds (https://github.com/elastic/elasticsearch/issues/10746).
 */
class CompletionSuggester extends ElasticsearchIntermediary {
	const VARIANT_EXTRA_DISCOUNT = 0.0001;
	/**
	 * @var string term to search.
	 */
	private $term;

	/**
	 * @var string[]|null search variants
	 */
	private $variants;

	/**
	 * Currently very limited (see LIMITATIONS) and only works
	 * for geo context
	 * @var array|null context for contextualized suggestions
	 */
	private $context;

	/**
	 * @var integer maximum number of result
	 */
	private $limit;

	/**
	 * @var string index base name to use
	 */
	private $indexBaseName;

	/**
	 * Search environment configuration
	 * @var SearchConfig
	 * Specified as public because of closures. When we move to non-anicent PHP version, can be made protected.
	 */
	public $config;

	/**
	 * @var string Query type (comp_suggest_geo or comp_suggest)
	 */
	public $queryType;

	/**
	 * @var SearchContext
	 */
	private $searchContext;

	/**
	 * Constructor
	 * @param Connection $conn
	 * @param int $limit Limit the results to this many
	 * @param SearchConfig $config Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|boolean $index Base name for index to search from, defaults to wfWikiId()
	 * @throws \ConfigException
	 */
	public function __construct( Connection $conn, $limit, SearchConfig $config = null, array $namespaces = null,
		User $user = null, $index = false ) {

		if ( is_null( $config ) ) {
			// @todo connection has an embeded config ... reuse that? somehow should
			// at least ensure they are the same.
			$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
		}

		parent::__construct( $conn, $user, $config->get( 'CirrusSearchSlowSearch' ) );
		$this->config = $config;
		$this->limit = $limit;
		$this->indexBaseName = $index ?: $config->getWikiId();
		$this->searchContext = new SearchContext( $this->config, $namespaces );
	}

	/**
	 * Produce a set of completion suggestions for text using _suggest
	 * See https://www.elastic.co/guide/en/elasticsearch/reference/1.6/search-suggesters-completion.html
	 *
	 * WARNING: experimental API
	 *
	 * @param string $text Search term
	 * @param string[]|null $variants Search term variants
	 * (usually issued from $wgContLang->autoConvertToAllVariants( $text ) )
	 * @param array $context
	 * @return Status
	 */
	public function suggest( $text, $variants = null, $context = null ) {
		$this->setTermAndVariants( $text, $variants );
		$this->context = $context;

		list( $profiles, $suggest ) = $this->buildQuery();
		$queryOptions = array();
		$queryOptions[ 'timeout' ] = $this->config->getElement( 'CirrusSearchSearchShardTimeout', 'default' );
		$this->connection->setTimeout( $queryOptions[ 'timeout' ] );

		$index = $this->connection->getIndex( $this->indexBaseName, Connection::TITLE_SUGGEST_TYPE );
		$logContext = array(
			'query' => $text,
			'queryType' => $this->queryType,
		);
		$searcher = $this;
		$limit = $this->limit;
		$result = Util::doPoolCounterWork(
			'CirrusSearch-Completion',
			$this->user,
			function() use( $searcher, $index, $suggest, $logContext, $queryOptions,
					$profiles, $text , $limit ) {
				$description = "{queryType} search for '{query}'";
				$searcher->start( $description, $logContext );
				try {
					$result = $index->request( "_suggest", Request::POST, $suggest, $queryOptions );
					if( $result->isOk() ) {
						$result = $searcher->postProcessSuggest( $result,
							$profiles, $limit );
						return $searcher->success( $result );
					}
					return $result;
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $searcher->failure( $e );
				}
			}
		);
		return $result;
	}

	/**
	 * protected for tests
	 */
	protected function setTermAndVariants( $term, $variants = null ) {
		$this->term = $term;
		if ( empty( $variants ) ) {
			$this->variants = null;
			return;
		}
		$variants = array_diff( array_unique( $variants ), array( $term ) );
		if ( empty( $variants ) ) {
			$this->variants = null;
		} else {
			$this->variants = $variants;
		}
	}

	/**
	 * Builds the suggest queries and profiles.
	 * Use with list( $profiles, $suggest ).
	 * @return array the profiles and suggest queries
	 */
	protected function buildQuery() {
		if ( mb_strlen( $this->term ) > SuggestBuilder::MAX_INPUT_LENGTH ) {
			// Trim the query otherwise we won't find results
			$this->term = mb_substr( $this->term, 0, SuggestBuilder::MAX_INPUT_LENGTH );
		}

		$queryLen = mb_strlen( trim( $this->term ) ); // Avoid cheating with spaces
		$this->queryType = "comp_suggest";

		$profiles = $this->config->get( 'CirrusSearchCompletionSettings' );
		if ( $this->context != null && isset( $this->context['geo']['lat'] )
			&& isset( $this->context['geo']['lon'] ) && is_numeric( $this->context['geo']['lat'] )
			&& is_numeric( $this->context['geo']['lon'] )
		) {
			$profiles = $this->prepareGeoContextSuggestProfiles();
			$this->queryType = "comp_suggest_geo";
		}

		$suggest = $this->buildSuggestQueries( $profiles, $this->term, $queryLen );

		// Handle variants, update the set of profiles and suggest queries
		if ( !empty( $this->variants ) ) {
			list( $addProfiles, $addSuggest ) = $this->handleVariants( $profiles, $queryLen );
			$profiles += $addProfiles;
			$suggest += $addSuggest;
		}
		return array( $profiles, $suggest );
	}

	/**
	 * Builds a set of suggest query by reading the list of profiles
	 * @param array $profiles
	 * @param string $query
	 * @param int $queryLen the length to use when checking min/max_query_len
	 * @return array a set of suggest queries ready to for elastic
	 */
	protected function buildSuggestQueries( array $profiles, $query, $queryLen ) {
		$suggest = array();
		foreach($profiles as $name => $config) {
			$sugg = $this->buildSuggestQuery( $config, $query, $queryLen );
			if(!$sugg) {
				continue;
			}
			$suggest[$name] = $sugg;
		}
		return $suggest;
	}

	/**
	 * Builds a suggest query from a profile
	 * @param array $config Profile
	 * @param string $query
	 * @param int $queryLen the length to use when checking min/max_query_len
	 * @return array|null suggest query ready to for elastic or null
	 */
	protected function buildSuggestQuery( array $config, $query, $queryLen ) {
		// Do not remove spaces at the end, the user might tell us he finished writing a word
		$query = ltrim( $query );
		if ( $config['min_query_len'] > $queryLen ) {
			return null;
		}
		if ( isset( $config['max_query_len'] ) && $queryLen > $config['max_query_len'] ) {
			return null;
		}
		$field = $config['field'];
		$suggest = array(
			'text' => $query,
			'completion' => array(
				'field' => $field,
				'size' => $this->limit * $config['fetch_limit_factor']
			)
		);
		if ( isset( $config['fuzzy'] ) ) {
			$suggest['completion']['fuzzy'] = $config['fuzzy'];
		}
		if ( isset( $config['context'] ) ) {
			$suggest['completion']['context'] = $config['context'];
		}
		return $suggest;
	}

	/**
	 * Update the suggest queries and return additional profiles flagged the 'fallback' key
	 * with a discount factor = originalDiscount * 0.0001/(variantIndex+1).
	 * @param array $profiles the default profiles
	 * @param int $queryLen the original query length
	 * @return array new variant profiles
	 */
	 protected function handleVariants( array $profiles, $queryLen ) {
		$variantIndex = 0;
		$allVariantProfiles = array();
		$allSuggestions = array();
		foreach( $this->variants as $variant ) {
			$variantIndex++;
			foreach ( $profiles as $name => $profile ) {
				$variantProfName = $name . '-variant-' . $variantIndex;
				$allVariantProfiles[$variantProfName] = $this->buildVariantProfile( $profile, self::VARIANT_EXTRA_DISCOUNT/$variantIndex );
				$allSuggestions[$variantProfName] = $this->buildSuggestQuery(
							$allVariantProfiles[$variantProfName], $variant, $queryLen
						);
			}
		}
		return array( $allVariantProfiles, $allSuggestions );
	}

	/**
	 * Creates a copy of $profile[$name] with a custom '-variant-SEQ' suffix.
	 * And applies an extra discount factor of 0.0001.
	 * The copy is added to the profiles container.
	 * @param array $profile profile to copy
	 * @param float $extraDiscount extra discount factor to rank variant suggestion lower.
	 * @return array
	 */
	protected function buildVariantProfile( array $profile, $extraDiscount = 0.0001 ) {
		// mark the profile as a fallback query
		$profile['fallback'] = true;
		$profile['discount'] *= $extraDiscount;
		return $profile;
	}

	/**
	 * prepare the list of suggest requests used for geo context suggestions
	 * This method will merge $this->config->get( 'CirrusSearchCompletionSettings and
	 * $this->config->get( 'CirrusSearchCompletionGeoContextSettings
	 * @return array of suggest request profiles
	 */
	private function prepareGeoContextSuggestProfiles() {
		$profiles = array();
		foreach ( $this->config->get( 'CirrusSearchCompletionGeoContextSettings' ) as $geoname => $geoprof ) {
			foreach ( $this->config->get( 'CirrusSearchCompletionSettings' ) as $sugname => $sugprof ) {
				if ( !in_array( $sugname, $geoprof['with'] ) ) {
					continue;
				}
				$profile = $sugprof;
				$profile['field'] .= $geoprof['field_suffix'];
				$profile['discount'] *= $geoprof['discount'];
				$profile['context'] = array(
					'location' => array(
						'lat' => $this->context['geo']['lat'],
						'lon' => $this->context['geo']['lon'],
						'precision' => $geoprof['precision']
					)
				);
				$profiles["$sugname-$geoname"] = $profile;
			}
		}
		return $profiles;
	}

	/**
	 * merge top level multi-queries and resolve returned pageIds into Title objects.
	 *
	 * WARNING: experimental API
	 *
	 * @param \Elastica\Response $response Response from elasticsearch _suggest api
	 * @param array $profiles the suggestion profiles
	 * @param int $limit Maximum suggestions to return, -1 for unlimited
	 * @return SearchSuggestionSet a set of Suggestions
	 */
	protected function postProcessSuggest( \Elastica\Response $response, $profiles, $limit = -1 ) {
		$this->logContext['elasticTookMs'] = intval( $response->getQueryTime() * 1000 );
		$data = $response->getData();
		unset( $data['_shards'] );

		$suggestions = array();
		foreach ( $data as $name => $results  ) {
			$discount = $profiles[$name]['discount'];
			foreach ( $results  as $suggested ) {
				foreach ( $suggested['options'] as $suggest ) {
					$output = SuggestBuilder::decodeOutput( $suggest['text'] );
					if ( $output === null ) {
						// Ignore broken output
						continue;
					}
					$pageId = $output['id'];
					$type = $output['type'];

					$score = $discount * $suggest['score'];
					if ( !isset( $suggestions[$pageId] ) ||
						$score > $suggestions[$pageId]->getScore()
					) {
						$suggestion = new SearchSuggestion( $score, null, null, $pageId );
						// If it's a title suggestion we have the text
						if ( $type === SuggestBuilder::TITLE_SUGGESTION ) {
							$suggestion->setText( $output['text'] );
						}
						$suggestions[$pageId] = $suggestion;
					}
				}
			}
		}

		// simply sort by existing scores
		uasort( $suggestions, function ( SearchSuggestion $a, SearchSuggestion $b ) {
			return $b->getScore() - $a->getScore();
		} );

		$this->logContext['hitsTotal'] = count( $suggestions );

		if ( $limit > 0 ) {
			$suggestions = array_slice( $suggestions, 0, $limit, true );
		}

		$this->logContext['hitsReturned'] = count( $suggestions );
		$this->logContext['hitsOffset'] = 0;

		// we must fetch redirect data for redirect suggestions
		$missingText = array();
		foreach ( $suggestions as $id => $suggestion ) {
			if ( $suggestion->getText() === null ) {
				$missingText[] = $id;
			}
		}

		if ( !empty ( $missingText ) ) {
			// Experimental.
			//
			// Second pass query to fetch redirects.
			// It's not clear if it's the best option, this will slowdown the whole query
			// when we hit a redirect suggestion.
			// Other option would be to encode redirects as a payload resulting in a
			// very big index...

			// XXX: we support only the content index
			$type = $this->connection->getPageType( $this->indexBaseName, Connection::CONTENT_INDEX_TYPE );
			// NOTE: we are already in a poolCounterWork
			// Multi get is not supported by elastica
			$redirResponse = null;
			try {
				$redirResponse = $type->request( '_mget', 'GET',
					array( 'ids' => $missingText ),
					array( '_source_include' => 'redirect' ) );
				if ( $redirResponse->isOk() ) {
					$this->logContext['elasticTook2PassMs'] = intval( $redirResponse->getQueryTime() * 1000 );
					$docs = $redirResponse->getData();
					foreach ( $docs['docs'] as $doc ) {
						if ( empty( $doc['_source']['redirect'] ) ) {
							continue;
						}
						// We use the original query, we should maybe use the variant that generated this result?
						$text = Util::chooseBestRedirect( $this->term, $doc['_source']['redirect'] );
						if( !empty( $suggestions[$doc['_id']] ) ) {
							$suggestions[$doc['_id']]->setText( $text );
						}
					}
				} else {
					LoggerFactory::getInstance( 'CirrusSearch' )->warning(
						'Unable to fetch redirects for suggestion {query} with results {ids} : {error}',
						array( 'query' => $this->term,
							'ids' => serialize( $missingText ),
							'error' => $redirResponse->getError() ) );
				}
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					'Unable to fetch redirects for suggestion {query} with results {ids} : {error}',
					array( 'query' => $this->term,
						'ids' => serialize( $missingText ),
						'error' => $this->extractMessage( $e ) ) );
			}
		}

		return new SearchSuggestionSet( array_filter(
			$suggestions,
			function ( SearchSuggestion $suggestion ) {
				// text should be not empty for suggestions
				return $suggestion->getText() != null;
			}
		) );
	}

	/**
	 * Set the max number of results to extract.
	 * @param int $limit
	 */
	public function setLimit( $limit ) {
		$this->limit = $limit;
	}
}
