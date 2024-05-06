<?php
/*
Plugin Name: RS Admin Bar
Description: Customizes the WordPress admin bar providing quick links to manage post types, users, plugins, etc.
Version: 1.2.1
Author: Radley Sustaire
Author URI: https://radleysustaire.com
GitHub Plugin URI: https://github.com/RadGH/RS-Admin-Bar
*/

class RS_Admin_Bar {
	
	public $version = '1.2.1';
	
	public function __construct() {
		
		// Make some additional customizations to the admin bar, and add the Manage node to it
		add_action( 'admin_bar_menu', array( $this, 'customize_admin_bar' ), 50 );
		
		// Add nodes to the "Manage" section after most plugins have added their own nodes
		add_action( 'wp_before_admin_bar_render', array( $this, 'add_custom_nodes' ), 20000 );
		
		// Enqueue admin bar CSS whenever admin bar is displayed
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_css' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_admin_bar_css' ) );
		
		// When displaying the admin menu, cache the list of top-level menu items to use in the admin bar later
		add_action( 'admin_menu', array( $this, 'cache_admin_menu' ), 20000 );
		
		// Clear cached posts whenever a post of the same type is saved or trashed
		add_action( 'save_post', array( $this, 'clear_cached_post_queries' ) );
		
	}
	
	public function enqueue_admin_bar_css() {
		wp_enqueue_style( 'rs-admin-bar', plugin_dir_url( __FILE__ ) . 'admin-bar.css', array(), $this->version );
	}
	
	/**
	 * Customize the admin bar
	 *
	 * @return void
	 */
	public function customize_admin_bar() {
		global $wp_admin_bar;
		if ( ! isset($wp_admin_bar) || ! $wp_admin_bar instanceof WP_Admin_Bar ) return;
		
		$is_admin = is_admin();
		$is_frontend = ! $is_admin;
		$is_woocommerce = false;
		
		if ( class_exists('WooCommerce') && (is_shop() || is_product() || is_product_category() || is_product_tag()) ) {
			$is_woocommerce = true;
			$is_frontend = false;
		}
		
		// Remove the logo
		$wp_admin_bar->remove_node( 'wp-logo' );
		
		// Remove the "New" dropdown
		$wp_admin_bar->remove_node( 'new-content' );
		
		// Always add a link to Visit Site under the Site Name menu
		$wp_admin_bar->remove_node( 'view-site' );
		$wp_admin_bar->add_menu(array(
			'parent' => 'site-name',
			'id'     => 'rs-view-site',
			'title'  => __( 'Visit Site' ) . ($is_frontend ? ' (Current)' : ''),
			'href'   => home_url( '/' ),
			'meta'   => array(
				'class' => $is_frontend ? 'rs-node-description' : '',
			),
		));
		
		// If WooCommerce is installed, re-add the link to Visit Store to keep it in the same order
		if ( function_exists('wc_get_page_permalink') ) {
			$store_url = wc_get_page_permalink( 'shop' );
			if ( $store_url ) {
				$wp_admin_bar->remove_node('view-store');
				$wp_admin_bar->add_menu(array(
					'parent' => 'site-name',
					'id'     => 'view-store',
					'title'  => __( 'Visit Store', 'woocommerce' ) . ($is_woocommerce ? ' (Current)' : ''),
					'href'   => wc_get_page_permalink( 'shop' ),
					'meta'   => array(
						'class' => $is_woocommerce ? 'rs-node-description' : '',
					),
				));
			}
		}
		
		// Always add a link to the dashboard under the Site Name menu
		$wp_admin_bar->remove_node( 'dashboard' );
		$wp_admin_bar->add_menu( array(
			'parent' => 'site-name',
			'id'     => 'rs-dashboard',
			'title'  => 'Dashboard' . ($is_admin ? ' (Current)' : ''),
			'href'   => admin_url(),
			'meta'   => array(
				'class' => $is_admin ? 'rs-node-description' : '',
			),
		) );
		
		// Add the "Manage" group
		$wp_admin_bar->add_menu( array(
			'parent' => 'site-name',
			'group' => true,
			'id'    => 'rs-manage',
			'title' => 'Manage',
			'meta'  => array(
				'title' => __( 'Manage various site settings' ),
			),
		) );
		
	}
	
	public function add_custom_nodes() {
		global $wp_admin_bar;
		if ( ! isset($wp_admin_bar) || ! $wp_admin_bar instanceof WP_Admin_Bar ) return;
		
		// Remove the logo
		$wp_admin_bar->remove_node( 'wp-logo' );
		
		// Remove the "New" dropdown
		$wp_admin_bar->remove_node( 'new-content' );
		
		$added_items = 0;
		
		// Add groups to separate by category: site, users, post types, plugins
		if ( $this->add_nodes__site( $wp_admin_bar, 'rs-manage-site') ) {
			$added_items += 1;
			$wp_admin_bar->add_menu( array(
				'id'     => 'rs-manage-site',
				'parent' => 'rs-manage',
				'group'   => true,
			));
		}
	
		if ( $this->add_nodes__settings( $wp_admin_bar, 'rs-manage-settings') ) {
			$added_items += 1;
			$wp_admin_bar->add_menu( array(
				'id'     => 'rs-manage-settings',
				'parent' => 'rs-manage',
				'group'   => true,
			) );
		}
	
		if ( $this->add_nodes__content( $wp_admin_bar, 'rs-manage-content') ) {
			$added_items += 1;
			$wp_admin_bar->add_menu( array(
				'id'     => 'rs-manage-content',
				'parent' => 'rs-manage',
				'group'   => true,
			) );
		}
		
		if ( $this->add_nodes__third_party( $wp_admin_bar, 'rs-manage-third-party') ) {
			$added_items += 1;
			$wp_admin_bar->add_menu( array(
				'id'     => 'rs-manage-third-party',
				'parent' => 'rs-manage',
				'group'   => true,
			) );
		}
		
		// If any items were added to the Manage menu, add the menu to the admin bar.
		if ( $added_items < 1 ) {
			$wp_admin_bar->remove_node( 'rs-manage' );
		}
		
	}
	
	
	/**
	 * Adds the Edit Site section to the menu
	 *
	 * @param $wp_admin_bar
	 * @param $parent
	 *
	 * @return bool
	 */
	private function add_nodes__site( $wp_admin_bar, $parent ) {
		$added_items = 0;
		
		// Add a link to Edit Site
		if ( wp_is_block_theme() ) {
			$added_items += 1;
			
			$edit_site_url = admin_url( 'site-editor.php' );
			$edit_site_node = (array) $wp_admin_bar->get_node( 'site-editor' );
			
			if ( $edit_site_node ) {
				$wp_admin_bar->remove_node($edit_site_node['id']);
				
				if ( !empty($edit_site_node['href']) ) {
					$edit_site_url = $edit_site_node['href'];
				}
			}
			
			$wp_admin_bar->add_menu( array(
				'parent' => $parent,
				'id'     => 'rs-site-editor',
				'title'  => 'Edit Site',
				'href'   => $edit_site_url,
			) );
			
			// If there was already a link to edit site for the current page, add that as the first link
			if ( $edit_site_node ) {
				// Get the postType and postID from the $edit_site_url
				$url = wp_parse_url( $edit_site_url, PHP_URL_QUERY ); // postType=wp_template&postId=zingmap-2024//page-no-title
				$post_type = '';
				$template = '';
				foreach( explode('&', $url) as $part ) {
					$part = explode('=', $part);
					switch( $part[0] ) {
						case 'postType':
							$post_type = $part[1];
							break;
						case 'postId':
							$template = str_replace( '//', '/', $part[1] );
							break;
					}
				}
				
				$edit_site_title = 'Current Template';
				
				if ( $post_type && $template ) {
					$edit_site_title .= ': <div class="rs-text-node rs-node-description">' . $template . '</div>';
				}
				
				$wp_admin_bar->add_node(array(
					'parent' => 'rs-site-editor',
					'id'     => 'rs-site-editor-current',
					'group' => true,
				));
				
				$wp_admin_bar->add_node(array(
					'parent' => 'rs-site-editor-current',
					'id'     => 'rs-site-editor-current-page',
					'title'  => $edit_site_title,
					'href'   => $edit_site_url,
					'meta'   => array(
						'class' => 'rs-has-text-node',
					),
				));
			}
			
			// Add sub links to the site editor screens for: Navigation, Styles, Pages, Templates, Patterns
			$wp_admin_bar->add_menu( array(
				'parent' => 'rs-site-editor',
				'id'     => 'rs-site-editor-navigation',
				'title'  => 'Navigation',
				'href'   => admin_url( 'site-editor.php?path=%2Fnavigation' ),
			) );
			
			// Get custom navigation parts and add a link to edit each under the Navigations menu
			// (Technically these are listed under Patterns > Navigation Parts in the editor, but that's weird)
			$navigation_parts = $this->query_post_type( 'wp_navigation' );
			
			if ( $navigation_parts ) {
				// Add a link to all navigation items, same as the parent node
				$wp_admin_bar->add_node(array(
					'parent' => 'rs-site-editor-navigation',
					'id'     => 'rs-site-editor-navigation-all',
					'title'  => 'All Navigation Menus',
					'href'   => admin_url( 'site-editor.php?path=%2Fnavigation' ),
				));
				
				// Group navigation items together
				$wp_admin_bar->add_node(array(
					'parent' => 'rs-site-editor-navigation',
					'id'     => 'rs-site-editor-navigation-items',
					'group'  => true,
				));
				
				foreach( $navigation_parts as $p ) {
					$post_id = $p->ID;
					$title = $p->post_title;
					$edit_url = $p->edit_url;
					
					$wp_admin_bar->add_node(array(
						'parent' => 'rs-site-editor-navigation-items',
						'id'     => 'rs-site-editor-navigation-' . $post_id,
						'title'  => $title,
						'href'   => $edit_url,
					));
				}
			}
			
			$wp_admin_bar->add_menu( array(
				'parent' => 'rs-site-editor',
				'id'     => 'rs-site-editor-styles',
				'title'  => 'Styles',
				'href'   => admin_url( 'site-editor.php?path=%2Fwp_global_styles' ),
			) );
			
			$wp_admin_bar->add_menu( array(
				'parent' => 'rs-site-editor',
				'id'     => 'rs-site-editor-pages',
				'title'  => 'Pages',
				'href'   => admin_url( 'site-editor.php?path=%2Fpage' ),
			) );
			
			$wp_admin_bar->add_menu( array(
				'parent' => 'rs-site-editor',
				'id'     => 'rs-site-editor-templates',
				'title'  => 'Templates',
				'href'   => admin_url( 'site-editor.php?path=%2Fwp_template' ),
			) );
			
			// Get custom template parts and add a link to edit each under the Templates menu
			// (Technically these are listed under Patterns > Template Parts in the editor, but that's weird)
			$template_parts = $this->query_post_type( 'wp_template_part' );
			
			if ( $template_parts ) {
				// Add a link to all navigation items, same as the parent node
				$wp_admin_bar->add_node(array(
					'parent' => 'rs-site-editor-templates',
					'id'     => 'rs-site-editor-templates-all',
					'title'  => 'All Templates',
					'href'   => admin_url( 'site-editor.php?path=%2Fwp_template' ),
				));
				
				// Add a link to All Template Parts (which is actually under the Patterns menu)
				$wp_admin_bar->add_node(array(
					'parent' => 'rs-site-editor-templates',
					'id'     => 'rs-site-editor-templates-parts',
					'title'  => 'All Template Parts',
					'href'   => admin_url( 'site-editor.php?path=%2Fwp_template_part%2Fall' ),
				));
				
				// Group navigation items together
				$wp_admin_bar->add_node(array(
					'parent' => 'rs-site-editor-templates',
					'id'     => 'rs-site-editor-templates-items',
					'group'  => true,
				));
				
				foreach( $template_parts as $p ) {
					$post_id = $p->ID;
					$title = $p->post_title;
					$edit_url = $p->edit_url;
					
					$wp_admin_bar->add_node(array(
						'parent' => 'rs-site-editor-templates-items',
						'id'     => 'rs-site-editor-templates-items-' . $post_id,
						'title'  => $title,
						'href'   => $edit_url,
					));
				}
			}
			
			$wp_admin_bar->add_menu( array(
				'parent' => 'rs-site-editor',
				'id'     => 'rs-site-editor-patterns',
				'title'  => 'Patterns',
				'href'   => admin_url( 'site-editor.php?path=%2Fpatterns' ),
			) );
			
			// Get custom patterns and add them under the Patterns section
			$pattern_posts = $this->query_post_type( 'wp_block' );
			
			if ( $pattern_posts ) {
				// Add a link to all navigation items, same as the parent node
				$wp_admin_bar->add_node(array(
					'parent' => 'rs-site-editor-patterns',
					'id'     => 'rs-site-editor-patterns-all',
					'title'  => 'All Patterns',
					'href'   => admin_url( 'site-editor.php?path=%2Fpatterns' ),
				));
				
				// Group navigation items together
				$wp_admin_bar->add_node(array(
					'parent' => 'rs-site-editor-patterns',
					'id'     => 'rs-site-editor-patterns-items',
					'group'  => true,
				));
				
				foreach( $pattern_posts as $p ) {
					$post_id = $p->ID;
					$title = $p->post_title;
					$edit_url = $p->edit_url;
					
					$wp_admin_bar->add_node(array(
						'parent' => 'rs-site-editor-patterns-items',
						'id'     => 'rs-site-editor-pattern-' . $post_id,
						'title'  => $title,
						'href'   => $p->edit_url,
					));
				}
			}
		}
		
		// Check if the theme supports navigation menus
		if ( current_theme_supports( 'menus' ) ) {
			$added_items += 1;
			$wp_admin_bar->add_menu( array(
				'parent' => $parent,
				'id'     => 'manage-menus',
				'title'  => 'Menus',
				'href'   => admin_url( 'nav-menus.php' ),
			) );
		}
		
		// Check if the theme supports widgets
		if ( current_theme_supports( 'widgets' ) ) {
			$added_items += 1;
			$wp_admin_bar->add_menu( array(
				'parent' => $parent,
				'id'     => 'manage-widgets',
				'title'  => 'Widgets',
				'href'   => admin_url( 'widgets.php' ),
			) );
		}
		
		// Remove some default nodes which are handled differently by this plugin
		$wp_admin_bar->remove_node( 'comments' );
		$wp_admin_bar->remove_node( 'plugins' );
		$wp_admin_bar->remove_node( 'appearance' );
		
		return $added_items > 0;
	}
	
	/**
	 * Adds the Settings section to the menu
	 *
	 * @param $wp_admin_bar
	 * @param $parent
	 *
	 * @return bool
	 */
	private function add_nodes__settings( $wp_admin_bar, $parent ) {
		$items_added = 0;
		
		// Add a completely custom Settings node using cached pages from the backend, if available
		$settings_menu = $this->get_settings_menu();
		
		if ( $settings_menu ) foreach( $settings_menu as $slug => $menu_item ) {
			if ( $this->add_nodes__settings__menu_item( $wp_admin_bar, $parent, $slug, $menu_item ) ) {
				$items_added += 1;
			}
		}
		
		return $items_added > 0;
	}
	
	/**
	 * Add nodes to the "Settings" section of the admin bar using a menu item from the cached settings menu
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 * @param string $parent
	 * @param string $slug
	 * @param array $menu_item {
	 *     @type string $title
	 *     @type string $href
	 *     @type string $capability
	 *     @type array[]|void $children {
	 *         @type string $title
	 *         @type string $href
	 *         @type string $capability
	 *     }
	 * }
	 *
	 * @return bool
	 */
	private function add_nodes__settings__menu_item( $wp_admin_bar, $parent, $slug, $menu_item ) {
		$items_added = 0;
		
		// Check capability
		if ( !empty($menu_item['capability']) && !current_user_can($menu_item['capability']) ) {
			return false;
		}
		
		$wp_admin_bar->add_node(array(
			'parent' => $parent,
			'id'     => $parent . '-' . $slug,
			'title'  => $menu_item['title'],
			'href'   => $menu_item['href'],
		));
		
		if ( !empty($menu_item['children']) ) {
			foreach( $menu_item['children'] as $child_slug => $child ) {
				$this->add_nodes__settings__menu_item( $wp_admin_bar, $parent . '-' . $slug, $child_slug, $child );
				$items_added += 1;
			}
		}
		
		return $items_added > 0;
	}
	
	/**
	 * Get the settings menu that was cached by:
	 * @see self::cache_admin_menu()
	 *
	 * @return array[] {
	 *     @type string $title
	 *     @type string $href
	 *     @type string $capability
	 *     @type array[] $children {
	 *         @type string $title
	 *         @type string $href
	 *         @type string $capability
	 *     }
	 */
	private function get_settings_menu() {
		$cached = get_transient('rs_admin_bar_settings_menu');
		
		if ( $cached && !empty($cached['menu_items']) ) {
			return $cached['menu_items'];
		}
		
		// If not cached, just use a single link to the settings page
		return array(
			array(
				'title' => 'Settings',
				'href'  => admin_url( 'options-general.php' ),
			),
		);
	}
	
	/**
	 * When displaying the admin menu, cache the list of top-level menu items to use in the admin bar later
	 * The menu is cached for 30 days. To keep it updated, it will be refreshed every hour by an admin visiting the dashboard
	 */
	public function cache_admin_menu() {
		global $menu, $submenu;
		
		$refresh_time_seconds = HOUR_IN_SECONDS;
		$cache_expire_time = 30 * DAY_IN_SECONDS;
		
		// Skip if the menu was cached within the last hour.
		$cached = get_transient('rs_admin_bar_settings_menu');
		if ( $cached && $cached['time'] > time() - $refresh_time_seconds ) return;
		
		// Only generate menu structure for admins
		if ( ! current_user_can( 'administrator' ) ) return;
		
		/*
		 * $menu[0] = array( 'Dashboard', 'read', 'index.php', '', 'menu-top menu-top-first menu-icon-dashboard', 'menu-dashboard', 'dashicons-dashboard' );
		 *
		 * $menu[0][0] = Menu title
		 * $menu[0][1] = Required capability
		 * $menu[0][2] = URL slug
		 * $menu[0][3] = (blank for everything except ACF. Seems to be an alternate page/menu title)
		 * $menu[0][4] = CSS classes
		 * $menu[0][5] = Menu slug
		 */
		
		$ignored_titles = array(
			// Separators:
			'',
			
			// Old:
			'Links',
			
			// Already in admin bar:
			'Dashboard',
			'Media',
			'Posts',
		);
		
		$menu_items = array();
		
		// Get managed post types that already appear in the admin bar. Remove these from the list.
		$managed_post_types = $this->get_admin_menu_post_types();
		
		// Function: Remove HTML (and its content) from the title
		$__clean_menu_title = function( $title ) {
			$title = preg_replace( '@<(\w+)\b.*?>.*?</\1>@si', '', $title );
			$title = preg_replace( '/<[^>]*>/', '', $title );
			$title = trim($title);
			return $title;
		};
		
		
		foreach( $menu as $m ) {
			$title = $m[0];
			$capability = $m[1];
			$slug = $m[2];
			
			// Remove HTML (and its content) from the title
			// This removes the "count bubbles" like you see in the Plugins menu when an update is available
			$title = $__clean_menu_title($title);
			
			// Skip menu items with an ignored title
			if ( in_array($title, $ignored_titles, true) ) continue;
			
			// Ignore post types that are already managed by the admin bar
			if ( str_contains($slug, '?post_type=')) {
				$post_type = preg_match( '/post_type=([^&]+)/', $slug, $matches ) ? $matches[1] : '';
				if ( $post_type && in_array($post_type, $managed_post_types, true) ) continue;
			}
			
			// Store the menu item
			$menu_items[ $slug ] = array(
				'title' => esc_html($title),
				'href'  => admin_url( $slug ),
				'capability' => $capability,
				'children' => array(),
			);
		}
		
		// Loop through submenu items to add them as children to their parent menu items
		foreach( $submenu as $parent_slug => $sub_items ) {
			if ( ! isset($menu_items[$parent_slug]) ) continue;
			
			// Add the submenu items as children
			foreach( $sub_items as $sub ) {
				/*
				 * $sub[0] = Menu title
				 * $sub[1] = Required capability
				 * $sub[2] = URL slug
				 */
				$title = $sub[0];
				$capability = $sub[1];
				$slug = $sub[2];
				
				// Remove HTML (and its content) from the title
				$title = $__clean_menu_title($title);
				
				// If the slug includes ".php", it's a submenu item that should be linked directly
				// Otherwise it should use the parent slug and add ?page=slug to the url
				if ( ! str_contains($slug, '.php') ) {
					$href = admin_url( $parent_slug . '?page=' . $slug );
				}else{
					$href = admin_url( $slug );
				}
				
				// Store the submenu item
				$menu_items[$parent_slug]['children'][ $slug ] = array(
					'title' => esc_html($title),
					'href'  => $href,
					'capability' => $capability,
				);
			}
		}
		
		$cache_data = array(
			'time' => time(),
			'menu_items' => $menu_items,
		);
		
		// Cache the menu
		set_transient( 'rs_admin_bar_settings_menu', $cache_data, $cache_expire_time );
	}
	
	/**
	 * Adds the Content section to the menu
	 *
	 * @param $wp_admin_bar
	 * @param $parent
	 *
	 * @return bool
	 */
	private function add_nodes__content( $wp_admin_bar, $parent ) {
		$added_items = 0;
		
		$post_types = $this->get_admin_menu_post_types();
		
		// Add each post type to the admin bar
		if ( $post_types ) foreach( $post_types as $post_type ) {
			if ( $this->add_single_post_type_node( $wp_admin_bar, $parent, $post_type ) ) {
				$added_items += 1;
			}
		}
		
		return $added_items > 0;
		
	}
	
	/**
	 * Get the post types that should appear in the admin menu
	 *
	 * @return array
	 */
	private function get_admin_menu_post_types() {
		// First, display built-in post types: Posts, pages, media
		$args = array(
			'public'   => true,
			'show_ui'  => true,
			'_builtin' => true,
		);
		
		$builtin_post_types = get_post_types( $args );
		
		// Second, display custom post types
		$args = array(
			'public'   => true,
			'show_ui'  => true,
			'_builtin' => false,
		);
		
		$custom_post_types = get_post_types( $args );
		
		// Combine the two, keeping built-in post types on top
		return array_merge( $builtin_post_types, $custom_post_types );
	}
	
	
	/**
	 * Add a single post type to the admin bar
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 * @param string $parent
	 * @param string $post_type
	 *
	 * @return bool
	 */
	private function add_single_post_type_node( $wp_admin_bar, $parent, $post_type ) {
		
		// Get the post type object
		$obj = get_post_type_object( $post_type );
		
		// Check if the user can edit this post type
		if ( ! current_user_can( $obj->cap->edit_posts ) ) {
			return false;
		}
		
		$post_title = $obj->labels->menu_name; // Posts
		$all_title = 'All ' . $obj->labels->name; // All Posts
		$add_new_title = 'Add New'; // Add New
		
		// For media, change some titles
		if ( $post_type === 'attachment' ) {
			$post_title = 'Media';
			$all_title = 'Library';
		}
		
		// Add the post type to the admin bar
		$wp_admin_bar->add_menu( array(
			'parent' => $parent,
			'id'     => 'post-type-' . $post_type,
			'title'  => $post_title,
			'href'   => admin_url( 'edit.php?post_type=' . $post_type ),
			'meta'   => array(
				'title' => $obj->labels->menu_name,
				'class' => 'post-type-node post-type-node-' . $post_type,
			),
		) );
		
		
		// Add a sub link to view all posts of this type
		$wp_admin_bar->add_menu( array(
			'parent' => 'post-type-' . $post_type,
			'id'     => 'all-posts-type-' . $post_type,
			'title'  => $all_title,
			'href'   => admin_url( 'edit.php?post_type=' . $post_type ),
		) );
		
		// If the user can add new posts of this type, add a link to add a new post
		if ( current_user_can( $obj->cap->create_posts ) ) {
			$wp_admin_bar->add_menu( array(
				'parent' => 'post-type-' . $post_type,
				'id'     => 'new-post-type-' . $post_type,
				'title'  => $add_new_title,
				'href'   => admin_url( 'post-new.php?post_type=' . $post_type ),
			) );
		}
		
		// Show a link for each public taxonomy associated with this post type
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		
		if ( $taxonomies ) {
			$added_taxonomies = 0;
			
			// Add each public taxonomy
			foreach( $taxonomies as $taxonomy ) {
				if ( $taxonomy->public ) {
					$added_taxonomies += 1;
					$wp_admin_bar->add_menu( array(
						'parent' => 'taxonomies-' . $post_type,
						'id'     => 'taxonomy-' . $taxonomy->name,
						'title'  => $taxonomy->labels->name,
						'href'   => admin_url( 'edit-tags.php?taxonomy=' . $taxonomy->name . '&post_type=' . $post_type ),
					) );
				}
			}
			
			// Add a group to store the taxonomies separately
			if ( $added_taxonomies ) {
				$wp_admin_bar->add_menu( array(
					'parent' => 'post-type-' . $post_type,
					'id'     => 'taxonomies-' . $post_type,
					'title'  => 'Taxonomies',
					'group'  => true,
				) );
			}
		}
		
		return true;
	}
	
	
	/**
	 * Adds the Third Party section to the menu
	 *
	 * @param $wp_admin_bar
	 * @param $parent
	 *
	 * @return bool
	 */
	private function add_nodes__third_party( $wp_admin_bar, $parent ) {
		$added_items = 0;
		
		// Gravity Forms
		$gf_node = (array) $wp_admin_bar->get_node( 'gform-forms' );
		if ( $gf_node ) {
			$added_items += 1;
			$wp_admin_bar->remove_node( $gf_node['id'] );
			$gf_node['parent'] = $parent;
			$gf_node['title'] = 'Gravity Forms';
			$wp_admin_bar->add_node( $gf_node );
		}
		
		return $added_items > 0;
	}
	
	
	
	/**
	 * Query a post type for posts
	 *
	 * @param string $post_type
	 * @param array $custom_args
	 *
	 * @return array {
	 *     @type int $ID
	 *     @type string $post_title
	 *     @type string $edit_url
	 * }
	 */
	private function query_post_type( $post_type, $custom_args = array() ) {
		// Generate a cache key based on post type
		if ( empty($custom_args) ) {
			$cache_key = 'rs_admin_bar_query_' . $post_type;
		}else{
			$cache_key = 'rs_admin_bar_query_' . $post_type . '_' . md5( serialize($custom_args) );
		}
		
		// Use cached value if available
		$cached = get_transient( $cache_key );
		if ( $cached ) return $cached;
		
		// Query the post type
		$args = wp_parse_args(array(
			'post_type' => $post_type,
		));
		
		// Get all posts of this type
		$raw_posts = get_posts($args);
		
		// Store only the ID and title, discard the rest for efficiency
		$posts = array();
		
		foreach( $raw_posts as $p ) {
			$posts[] = (object) array(
				'ID'         => $p->ID,
				'post_title' => $p->post_title,
				'edit_url'   => get_edit_post_link( $p->ID ),
			);
		}
		
		// Remove duplicates if they have the same edit_url
		// (Because there may be multiple footers that go to the same edit screen, for example)
		$edit_urls = array();
		
		foreach( $posts as $k => $p ) {
			if ( isset($edit_urls[$p->edit_url]) ) {
				unset($posts[$k]);
			}else{
				$edit_urls[$p->edit_url] = true;
			}
		}
		
		// Store the results for 1 hour
		set_transient( $cache_key, $posts, HOUR_IN_SECONDS );
		
		return $posts;
	}
	
	/**
	 * Clear cached post queries when a post is saved or trashed
	 *
	 * @param int $post_id
	 *
	 * @return void
	 */
	public function clear_cached_post_queries( $post_id ) {
		$post_type = get_post_type($post_id);
		$cachable_post_types = array(
			'wp_navigation',
			'wp_template_part',
			'wp_block',
		);
		
		if ( in_array( $post_type, $cachable_post_types, true ) ) {
			$cache_key = 'rs_admin_bar_query_' . $post_type;
			delete_transient( $cache_key );
		}
	}
	
}

global $RS_Admin_Bar;
$RS_Admin_Bar = new RS_Admin_Bar();