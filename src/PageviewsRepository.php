<?php
declare( strict_types=1 );

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use GuzzleRetry\GuzzleRetryMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PageviewsRepository handles interaction with the Wikimedia Pageviews API.
 * Much of this code was borrowed from wikimedia/eventmetrics, licensed under GPL-3.0-or-later.
 * Requests that get a 429 and 503 response are automatically retried by
 * caseyamcl/guzzle_retry_middleware, waiting a exponentially longer amount of time based on
 * the number of retries.
 */
class PageviewsRepository {
	private const REQUEST_TIMEOUT = 3;
	private const CONNECT_TIMEOUT = 3;

	/** @var int This defines the delay between *groups* of requests. */
	private const REQUEST_DELAY = 500;

	/** @var string Base URL for the REST endpoint. */
	protected string $endpointUrl = 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article';

	/** @var string The domain of the project. */
	protected string $domain;

	/** @var Client The GuzzleHttp client. */
	private Client $client;

	/**
	 * @param string $domain
	 */
	public function __construct( string $domain ) {
		$this->domain = $domain;
		$stack = HandlerStack::create();
		$stack->push( GuzzleRetryMiddleware::factory() );

		/**
		 * Listen for retry events and log them.
		 * @param int $attemptNumber
		 * @param float $delay
		 * @param RequestInterface $request
		 * @param array $options
		 * @param ResponseInterface|null $response
		 */
		$retryNotifier = function (
			int $attemptNumber,
			float $delay,
			RequestInterface $request,
			array $options,
			?ResponseInterface $response
		): void {
			$msg = sprintf(
				"Attempt #%s to retry request to %s. Server responded with %s. Waiting %s seconds.",
				$attemptNumber,
				$request->getUri()->getPath(),
				$response ? $response->getStatusCode() : 'no response',
				number_format( $delay, 2 )
			);
			wfLogToFile( $msg, $this->domain );
		};

		$this->client = new Client( [
			'timeout' => self::REQUEST_TIMEOUT,
			'connect_timeout' => self::CONNECT_TIMEOUT,
			'delay' => self::REQUEST_DELAY,
			'handler' => $stack,
			'retry_on_timeout' => true,
			'on_retry_callback' => $retryNotifier,
		] );
	}

	/**
	 * Get the combined pageviews of the given articles.
	 * @param string[][] $batch Keys are target name, values are arrays of target + redirects.
	 * @param string $start
	 * @param string $end
	 * @return array
	 */
	public function getPageviews( array $batch, string $start, string $end ): array {
		$targetTitles = array_keys( $batch );

		// Set up pageviews array with zero values.
		$pageviews = [];
		foreach ( $targetTitles as $targetPage ) {
			$pageviews[$targetPage] = 0;
		}

		// All page titles to be processed with this batch.
		$titles = array_unique( array_merge( ...array_values( $batch ) ) );

		// Queue promises.
		$promises = [];
		foreach ( $titles as $title ) {
			$promises[] = $this->get( $title, $start, $end );
		}

		// Make all requests at self::REQUEST_DELAY (ms) intervals.
		$responses = Utils::settle( $promises )->wait();

		foreach ( $responses as $response ) {
			if ( $response['state'] !== 'fulfilled' ) {
				/** @var GuzzleHttp\Exception\ClientException $reason */
				$reason = $response['reason'];

				if ( $reason->getCode() === 404 ) {
					// No data available; okay to omit this page from the report.
					continue;
				}

				// Do nothing, API didn't have data most likely.
				// Note that 429s are captured by the retry handler.
				continue;
			}

			/** @var Response $value */
			$value = $response['value'];
			$result = json_decode( $value->getBody()->getContents(), true );
			[ $page, $count ] = $this->processResponse( $result );

			foreach ( $targetTitles as $targetPage ) {
				if ( in_array( $page, $batch[$targetPage] ) ) {
					$pageviews[$targetPage] += $count;
					break;
				}
			}
		}

		return $pageviews;
	}

	/**
	 * Given a Mediawiki article and a date range, returns a daily timeseries of its pageviews.
	 * @param string $article Page title with underscores. Will be URL-encoded.
	 * @param string $start
	 * @param string $end
	 * @return PromiseInterface
	 */
	public function get( string $article, string $start, string $end ): PromiseInterface {
		$article = rawurlencode( $article );
		$url = "$this->endpointUrl/$this->domain/all-access/user/$article/monthly/$start/$end";
		return $this->client->getAsync( $url );
	}

	/**
	 * Parse the given Pageviews API response, returning the sum, and average if requested.
	 * @param array[][] $response
	 * @return array|null [Article name, number of pageviews] or null if there were no pageviews.
	 */
	private function processResponse( array $response ): ?array {
		if ( empty( $response['items'] ) ) {
			return null;
		}

		$article = null;
		$pageviews = 0;
		foreach ( array_reverse( $response['items'] ) as $item ) {
			$pageviews += (int)$item['views'];
			$article = str_replace( '_', ' ', $item['article'] );
		}

		return [ $article, $pageviews ];
	}
}
