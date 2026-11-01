<?php
/**
 * Summary routes: products list, summary read/edit/lock, regenerate, bulk + progress, preview.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Rest;

use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;
use Dev3\AiReviewSummarizer\Core3\Rest\Base_Controller;
use Dev3\AiReviewSummarizer\Frontend\Renderer;
use Dev3\AiReviewSummarizer\Jobs\Bulk_Job;
use Dev3\AiReviewSummarizer\Jobs\Scheduler;
use Dev3\AiReviewSummarizer\Reviews\Review_Repository;
use Dev3\AiReviewSummarizer\Settings;
use Dev3\AiReviewSummarizer\Summary\Summarizer;
use Dev3\AiReviewSummarizer\Summary\Summary_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3rs/v1.
 */
class Summary_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$moderate = array( $this, 'can_generate' );
		$id_arg   = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NS,
			'/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'products' ),
				'permission_callback' => $moderate,
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'status'   => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'none', 'fresh', 'stale', 'locked' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/summary/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'read_permission' ),
					'args'                => $id_arg + array(
						'context' => array(
							'type'    => 'string',
							'default' => 'view',
							'enum'    => array( 'view', 'edit' ),
						),
					),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_summary' ),
					'permission_callback' => $moderate,
					'args'                => $id_arg,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_summary' ),
					'permission_callback' => $moderate,
					'args'                => $id_arg,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/regenerate/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'regenerate' ),
				'permission_callback' => $moderate,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/bulk',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => $this->respond( Bulk_Job::progress() ),
					'permission_callback' => $moderate,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_start' ),
					'permission_callback' => $moderate,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => fn() => $this->respond( Bulk_Job::cancel() ),
					'permission_callback' => $moderate,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/bulk/step',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn() => $this->respond( Bulk_Job::step( 'bulk' ) ),
				'permission_callback' => $moderate,
			)
		);
		register_rest_route(
			self::NS,
			'/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $moderate,
			)
		);
	}

	/**
	 * GET /summary/{id}: public view for anyone (rate limited), full record with context=edit for moderators.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function read_permission( \WP_REST_Request $request ) {
		if ( 'edit' === $request->get_param( 'context' ) ) {
			return $this->can_generate( $request );
		}
		return $this->public_permission( $request, 'summary', 60 );
	}

	/**
	 * GET /products.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function products( \WP_REST_Request $request ) {
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$search   = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		$filter   = (string) $request->get_param( 'status' );
		$min      = (int) Settings_Store::get( 'min_reviews', 3 );

		$reviewed = Review_Repository::products_with_reviews( 1 );
		$statuses = Summary_Store::statuses( $reviewed );
		$counts   = Review_Repository::counts( $reviewed );
		$stats    = array(
			'reviewed' => count( $reviewed ),
			'eligible' => 0,
			'none'     => 0,
			'fresh'    => 0,
			'stale'    => 0,
			'locked'   => 0,
		);
		foreach ( $statuses as $id => $status ) {
			$eligible           = $counts[ $id ] >= $min;
			$stats['eligible'] += $eligible ? 1 : 0;
			if ( Summary_Store::STATUS_NONE !== $status || $eligible ) {
				++$stats[ $status ];
			}
		}

		$ids = $reviewed;
		if ( '' !== $filter ) {
			$ids = array_keys(
				array_filter(
					$statuses,
					static function ( $status, $id ) use ( $filter, $counts, $min ) {
						return $status === $filter && ( 'none' !== $filter || $counts[ $id ] >= $min );
					},
					ARRAY_FILTER_USE_BOTH
				)
			);
		}
		$items = array();
		$total = 0;
		if ( $ids ) {
			$query      = new \WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'post__in'       => $ids,
					's'              => $search,
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			);
			$total      = (int) $query->found_posts;
			$page_ids   = array_map( 'intval', $query->posts );
			$signatures = Review_Repository::signatures( $page_ids );
			foreach ( $page_ids as $id ) {
				$row            = Summary_Store::describe( $id, $signatures[ $id ] );
				$row['pending'] = Scheduler::pending( $id );
				$items[]        = $row;
			}
		}
		return $this->respond(
			array(
				'items'       => $items,
				'total'       => $total,
				'pages'       => (int) ceil( $total / $per_page ),
				'page'        => $page,
				'stats'       => $stats,
				'min_reviews' => $min,
				'bulk'        => Bulk_Job::progress(),
			)
		);
	}

	/**
	 * GET /summary/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_summary( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( 'edit' === $request->get_param( 'context' ) ) {
			if ( 'product' !== get_post_type( $id ) ) {
				return $this->not_found();
			}
			return $this->respond( Summary_Store::describe( $id ) );
		}
		$record = Summary_Store::get( $id );
		if ( ! $record || 'product' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || post_password_required( $id ) ) {
			return $this->not_found();
		}
		return $this->respond(
			array(
				'id'      => $id,
				'summary' => Summary_Store::public_view( $record ),
			)
		);
	}

	/**
	 * PUT /summary/{id}: edit (sanitised) + lock (default) or unlock.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_summary( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$params = (array) $request->get_json_params();
		$params = $params ? $params : $request->get_params();
		$lock   = array_key_exists( 'locked', $params ) ? Settings::to_bool( $params['locked'] ) : true;
		$fields = array_intersect_key( $params, array_flip( array( 'summary', 'pros', 'cons', 'sentiment', 'scores', 'keywords' ) ) );
		if ( ! $fields ) {
			if ( ! Summary_Store::get( $id ) ) {
				return $this->not_found();
			}
			Summary_Store::set_locked( $id, $lock );
			return $this->respond( Summary_Store::describe( $id ) );
		}
		$result = Summarizer::save_edit( $id, $fields, $lock );
		return is_wp_error( $result ) ? $this->respond( $result ) : $this->respond( Summary_Store::describe( $id ) );
	}

	/**
	 * DELETE /summary/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_summary( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( 'product' !== get_post_type( $id ) ) {
			return $this->not_found();
		}
		Summary_Store::delete( $id );
		return $this->respond( Summary_Store::describe( $id ) );
	}

	/**
	 * POST /regenerate/{id} { force = true, unlock = false }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function regenerate( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$force  = null === $request->get_param( 'force' ) ? true : Settings::to_bool( $request->get_param( 'force' ) );
		$unlock = Settings::to_bool( $request->get_param( 'unlock' ) );
		if ( $force && Summary_Store::is_locked( $id ) && ! $unlock ) {
			return $this->respond(
				\Dev3\AiReviewSummarizer\Core3\Api\Api_Error::make(
					'dev3rs_locked',
					__( 'This summary is locked.', 'dev3-ai-review-summarizer-for-woocommerce' ),
					__( 'Unlock it (or confirm "Regenerate and unlock") to replace it with a new AI summary.', 'dev3-ai-review-summarizer-for-woocommerce' ),
					409
				)
			);
		}
		$result = Summarizer::generate(
			$id,
			array(
				'force'   => $force,
				'unlock'  => $unlock,
				'context' => 'admin',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$out           = Summary_Store::describe( $id );
		$out['result'] = $result['result'];
		$out['reason'] = isset( $result['reason'] ) ? $result['reason'] : '';
		return $this->respond( $out );
	}

	/**
	 * POST /bulk { mode: missing|stale|selected, ids?: int[] }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk_start( \WP_REST_Request $request ) {
		$ids = $request->get_param( 'ids' );
		return $this->respond( Bulk_Job::start( sanitize_key( (string) $request->get_param( 'mode' ) ), is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * POST /preview { display, product_id? }: widget HTML for the live preview (unsaved display options).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview( \WP_REST_Request $request ) {
		$display = $request->get_param( 'display' );
		$display = Settings::sanitize_display( is_array( $display ) ? $display : array(), Settings::display() );
		$id      = (int) $request->get_param( 'product_id' );
		$record  = $id ? Summary_Store::get( $id ) : null;
		$html    = Renderer::record( $record ? $record : Renderer::sample(), $display, $id );
		return $this->respond(
			array(
				'html'   => $html,
				'sample' => ! $record,
			)
		);
	}

	/**
	 * 404 envelope.
	 *
	 * @return \WP_REST_Response
	 */
	private function not_found() {
		return $this->respond( \Dev3\AiReviewSummarizer\Core3\Api\Api_Error::make( 'dev3rs_not_found', __( 'No summary for this product.', 'dev3-ai-review-summarizer-for-woocommerce' ), '', 404 ) );
	}
}
