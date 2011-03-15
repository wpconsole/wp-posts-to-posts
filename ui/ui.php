<?php

abstract class P2P_Box {
	public $from;
	public $to;

	protected $reversed;
	protected $direction;

	protected $box_id;

	abstract function box( $post_id );

	function setup() {}


// Internal stuff


	public function __construct( $args, $direction, $box_id ) {
		foreach ( $args as $key => $value )
			$this->$key = $value;

		$this->box_id = $box_id;

		$this->direction = $direction;

		$this->reversed = ( 'to' == $direction );

		if ( $this->reversed )
			list( $this->to, $this->from ) = array( $this->from, $this->to );

		$this->setup();
	}

	function _register( $from ) {
		$title = $this->title; 

		if ( empty( $title ) )
			$title = sprintf( __( 'Connected %s', 'posts-to-posts' ), get_post_type_object( $this->to )->labels->name );

		add_meta_box(
			'p2p-connections-' . $this->box_id,
			$title,
			array( $this, '_box' ),
			$from,
			'side',
			'default'
		);
	}

	function _box( $post ) {
		$this->box( $post->ID );
	}
}


class P2P_Connection_Types {

	private static $ctypes = array();

	static public function register( $args ) {
		$args = wp_parse_args( $args, array(
			'from' => '',
			'to' => '',
			'fields' => array(),
			'box' => 'P2P_Box_Multiple',
			'title' => '',
			'reciprocal' => false
		) );

		self::$ctypes[] = $args;
	}

	static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, '_register' ) );

		add_action( 'wp_ajax_p2p_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_p2p_connections', array( __CLASS__, 'ajax_connections' ) );
	}

	static function _register( $from ) {
		foreach ( self::filter_ctypes( $from ) as $ctype ) {
			$ctype->_register( $from );
		}
	}

	function ajax_connections() {
		$box = self::ajax_make_box();

		$ptype_obj = get_post_type_object( $box->from );
		if ( !current_user_can( $ptype_obj->cap->edit_posts ) )
			die(-1);

		$subaction = $_POST['subaction'];

		$box->$subaction();
	}

	function ajax_disconnect() {
		$box = self::ajax_make_box();

		$box->disconnect();
	}

	function ajax_search() {
		add_filter( 'posts_search', array( __CLASS__, '_search_by_title' ) );

		$box = self::ajax_make_box();

		$posts = get_posts( $box->get_search_args( $_GET['q'], $_GET['post_id'] ) );

		$results = array();
		foreach ( $posts as $post ) {
			$GLOBALS['post'] = $post;
			$results[ $post->ID ] = apply_filters( 'the_title', $post->post_title );
		}

		die( json_encode( $results ) );
	}

	function _search_by_title( $sql ) {
		remove_filter( current_filter(), array( __CLASS__, __FUNCTION__ ) );

		list( $sql ) = explode( ' OR ', $sql, 2 );

		return $sql . '))';
	}

	private static function ajax_make_box() {
		$box_id = absint( $_REQUEST['box_id'] );
		$reversed = (bool) $_REQUEST['reversed'];

		if ( !isset( self::$ctypes[ $box_id ] ) )
			die(0);

		$args = self::$ctypes[ $box_id ];

		return new $args['box']($args, $reversed, $box_id);	
	}

	private static function filter_ctypes( $post_type ) {
		$r = array();
		foreach ( self::$ctypes as $box_id => $args ) {
			$direction = false;

			if ( $args['reciprocal'] && $args['from'] == $args['to'] ) {
				$direction = 'any';		
			} elseif ( $args['reciprocal'] && $post_type == $args['to'] ) {
				$direction = 'to';
			} elseif ( $post_type == $args['from'] ) {
				$direction = 'from';
			} else {
				continue;			
			}

			if ( !$direction )
				continue;

			$r[ $box_id ] = new $args['box']($args, $direction, $box_id);
		}

		return $r;
	}
}

