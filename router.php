<?php
/**
 * @package QueryRouter
 * @version 0.1
 */
/*
Plugin Name: Query Router
Plugin URI: git@github.com:EJEHardenberg/qroute.git
Description: A simple test plugin to work with routing and creating pages
Author: Ethan J. Eldridge
Version: 0.1
Author URI: http://ejehardenberg.github.io
*/

class Router{
	private static $instance;
	const queryVar = "route";
	const postType = "routed_page"; /*If you want to use a custom template try doing single-routed_page*/
	const slug = "routed"; 
	const title = "Custom Title";
	protected $baseDirectory = "";
	protected $placeHolderId = -1;
	protected $routeToMatch = "simple";
	protected $allowedViews = array("simple" );

	private function __construct() {
		/* Register our custom post type with wordpress */
		self::register_type();
		/* Don't cache us please, we need to change depending on the person viewing */
		self::do_not_cache(); 
		$this->baseDirectory = plugin_dir_path( __FILE__ );
		/*This is how we do our magic
		 * We hijack pre_get_posts to check for our query params
		 * then we use the_post action to display our view into the content of 
		 * the page. 
		 * Finally, we set the title on the page using the_title, and then set
		 * the browser title with the single_post_title filter. 
		*/
		add_action( 'pre_get_posts', array( $this, 'edit_query' ), 10, 1 );
		add_action( 'query_vars', array( $this, 'add_class_query_vars' ));
		add_action( 'the_post', array( $this, 'get_page' ), 10, 1 );
		add_filter( 'the_title', array( $this, 'get_title' ), 10, 2 );
		add_filter( 'single_post_title', array( $this, 'get_title' ), 10, 2 );
		/*Make sure we don't get redirected */
		add_filter( 'redirect_canonical', array( $this, 'override_redirect' ), 10, 2 );
		$this->allowedViews = get_option('allowedViews',$this->allowedViews);
	}

	public function edit_query( WP_Query $query ) {
		// we only care if this is the query to show our pages
		if ( isset( $query->query_vars[self::queryVar] ) && $query->query_vars[self::queryVar] ) {
			if(in_array($query->query_vars[self::queryVar], $this->allowedViews) ){
				$this->routeToMatch = $query->query_vars[self::queryVar];
				/* Create a placeholder post for routing if we need to */
				$this->placeHolderId = get_option("router_placeholder",$this->placeHolderId);
				if($this->placeHolderId == -1){
					$this->placeHolderId = self::make_post();
					add_option( "router_placeholder", $this->placeHolderId, "", "yes" );
				}
				$query->query_vars['post_type'] = self::postType;
				$query->query_vars['p'] = $this->placeHolderId;
				$query->is_single = TRUE;
				$query->is_singular = TRUE;
				$query->is_404 = FALSE;
				$query->is_home = FALSE;
				}
		}
	}

	/*Sets the title to be whatever our custom type wants
	 *via being called by the fitlers the_title and single_post_title
	*/
	public function get_title(  $title, $post_id  ) {
		$post = &get_post( $post_id );
		if ( $post->post_type == self::postType ) {
			return self::title;
		}
		return $title;
	}

	/* This actually loads your view page into the pages array so that
	 * it will be displayed by wordpress's looping stuff.
	*/
	public function get_page( $post ) {
		if ( $post->post_type == self::postType ) {
			remove_filter( 'the_content', 'wpautop' );
			$view = self::loadView( $this->routeToMatch, array(
				/* Any interesting variables to pass to your views 
				 * should go here, they'll be made available to
				 * your view.
				*/
				'example' => 'I show up on the page!'
			) );
			global $pages;
			$pages = array( $view );
		}
	}

	/*Attempt to load the view from the view name and base directory
	 *Note that we do surpress an error here if we can't find the page
	*/
	public function loadView($viewName, array $args){
		/* Add .php if we need to */
		if ( substr( $viewName, -4 ) != '.php' ) {
			$viewName .= '.php';
		}
		/* Make whatever arguments from the args array available*/
		if ( !empty( $args ) ) extract( $args );
		ob_start();
		@include $this->baseDirectory . 'views/' . $viewName;
		return ob_get_clean();	
	}

	/*Register our query parameters with wordpress so they're allowed
	 *through the query parsing done by wordpress.
	*/
	public function add_class_query_vars( $vars ){
  		$vars[] = self::queryVar;
  		return $vars;
	}
	

	/**
	 * Tell caching plugins not to cache the current page load
	 */
	public static function do_not_cache() {
		if ( !defined('DONOTCACHEPAGE') ) {
			define('DONOTCACHEPAGE', TRUE);
		}
	}

	/* Register our very quite post type that is our placeholder.
	 * If we wanted comments to show up on our custom pages we'd
	 * add supports =array('title', 'comments')
	*/
	private function register_type(){
		$args = array(
			'public' => FALSE,
			'show_ui' => FALSE,
			'exclude_from_search' => TRUE,
			'publicly_queryable' => TRUE,
			'show_in_menu' => FALSE,
			'show_in_nav_menus' => FALSE,
			'supports' => array( 'title' ),
			'has_archive' => TRUE,
			'rewrite' => array(
				'slug' => self::slug,
				'with_front' => FALSE,
				'feeds' => FALSE,
				'pages' => FALSE,
			)
		);
		register_post_type( self::postType, $args );
	}

	/*Plumbing for singleton object */
	private function __clone() {
		// cannot be cloned
		trigger_error( __CLASS__.' may not be cloned', E_USER_ERROR );
	}
	private function __sleep() {
		// cannot be serialized
		trigger_error( __CLASS__.' may not be serialized', E_USER_ERROR );
	}
	public static function getInstance() {
		if ( !( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* Creates the placeholder post for our plugin to work properly.
	*/
	private static function make_post() {
		$post = array(
			'post_title' => self::title,
			'post_status' => 'publish',
			'post_type' => self::postType,
			'post_content' => '',
			'post_author' => 1,
			'post_category' => array(8,39)
		);
		$id = wp_insert_post( $post );
		if ( is_wp_error( $id ) ) {
			error_log("There was a problem creating the placeholder post for the router plugin");
			return 0;
		}
		return $id;
	}

	/* If our query parameter is here, then we don't want wordpress to try to 
	 * redirect us anywhere
	*/
	public function override_redirect( $redirect_url, $requested_url ) {
		if ( get_query_var( self::queryVar ) ) {

			return FALSE;
		}
		return $redirect_url;
	}
	
}
//Turn on the plugin and poof here you go!
function routingStart(){
	$R = Router::getInstance();
}
add_action('init','routingStart');
?>