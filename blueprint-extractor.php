<?php
/**
 * Plugin Name: Blueprint Extractor for WordPress Playground
 * Description: Generate a blueprint for the current install.
 * Version: 1.0
 * Author: Alex Kirk
 */

namespace BlueprintExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Query;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;
use ZipArchive;

class BlueprintExtractor {
	private $ignored_plugins = array();
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		// add a button to the master bar so that you can quickly get to the blueprint page
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_button' ), 100 );
	}

	public function get_plugin_resource( $slug ) {
		$cache_key = 'blueprint_extractor_plugin_zip';
		$cache = get_transient( $cache_key );
		if ( false === $cache ) {
			$cache = array();
		}

		if ( substr( $slug, 0, 18 ) === 'blueprint-extractor' ) {
			$slug = 'blueprint-extractor';
		}

		if ( ! isset( $cache[ $slug ] ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			$response = \plugins_api(
				'plugin_information',
				(object) array(
					'slug' => $slug,
				)
			);
			$cache[ $slug ] = false;

			if ( ! is_wp_error( $response ) && isset( $response->download_link ) ) {
				if ( 0 === strpos( $response->download_link, 'https://downloads.wordpress.org/plugin/' ) ) {
					$cache[ $slug ] = array(
						'resource' => 'wordpress.org/plugins',
						'slug'     => $slug,
					);
				} elseif ( preg_match( '#https://github\.com/([^/]+/[^/]+)/archive/refs/(heads|tags)/([^/]+)\.zip#', $response->download_link, $matches ) ) {
					$cache[ $slug ] = array(
						'resource' => 'url',
						'url'      => "https://github-proxy.com/proxy/?repo={$matches[1]}&release={$matches[3]}",
					);
				}
			}

			set_transient( $cache_key, $cache, DAY_IN_SECONDS );

		}
		return $cache[ $slug ];
	}

	public function check_theme_exists( $slug ) {
		$cache_key = 'expose_blueprints_theme_exists';
		$cache = get_transient( $cache_key );
		if ( false === $cache ) {
			$cache = array();
		}

		if ( ! isset( $cache[ $slug ] ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			$response = \themes_api(
				'theme_information',
				(object) array(
					'slug' => $slug,
				)
			);
			$cache[ $slug ] = ! is_wp_error( $response );
			set_transient( $cache_key, $cache, DAY_IN_SECONDS );

		}
		return $cache[ $slug ];
	}

	public function generate_media_step() {
		return array(
			'step'          => 'unzip',
			'zipFile'       => array(
				'resource' => 'url',
				'url'      => 'https://playground.wordpress.net/cors-proxy.php?MEDIA_ZIP_URL',
			),
			'extractToPath' => '/wordpress/wp-content/uploads',
		);
	}

	public function get_wp_plugin_options() {
		$plugins = get_option( 'active_plugins' );
		$plugin_options = array();
		$core_options = array_flip(
			array(
				'home',
				'WPLANG',
				'blogname',
				'blogdescription',
				'site_icon',
				'siteurl',
				'stylesheet',
				'start_of_week',
				'timezone_string',
				'date_format',
				'time_format',
				'gmt_offset',
				'permalink_structure',
				'rss_use_excerpt',
				'comment_registration',
				'blog_charset',
				'posts_per_page',
				'rewrite_rules',
				'sidebars_widgets',
				'admin_email',
				'page_on_front',
				'page_for_posts',
				'show_on_front',
				'active_plugins',
			)
		);
		foreach ( $plugins as $plugin ) {
			$slug = explode( '/', $plugin )[0];
			if ( in_array( $slug, $this->ignored_plugins ) ) {
				continue;
			}
			$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin;
			foreach ( get_plugin_files( $plugin ) as $file ) {
				if ( substr( $file, -4 ) !== '.php' ) {
					continue;
				}
				$first_dir = strtok( $file, '/' );
				if ( 'node_modules' === $first_dir || 'vendor' === $first_dir ) {
					continue;
				}

				$file_content = file_get_contents( WP_PLUGIN_DIR . '/' . $file );
				if ( preg_match_all( '#get_option\(\s*[\'"]([^\'"]+)[\'"]\s*\)#', $file_content, $matches ) ) {
					foreach ( array_unique( $matches[1] ) as $option_name ) {
						if ( isset( $core_options[ $option_name ] ) ) {
							continue;
						}
						if ( substr( $option_name, 0, 3 ) === 'wp_' || substr( $option_name, 0, 1 ) === '_' ) {
							continue;
						}
						$value = get_option( $option_name );
						if ( ! $value ) {
							continue;
						}
						if ( ! isset( $plugin_options[ $slug ] ) ) {
							$plugin_options[ $slug ] = array();
						}
						$plugin_options[ $slug ][ $option_name ] = maybe_serialize( $value );
					}
				}
			}
		}
		return $plugin_options;
	}

	public function get_wp_config_constants() {
		$tokens = \token_get_all( file_get_contents( ABSPATH . '/wp-config.php' ) );
		$constants = array();
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_STRING === $token[0] && 'define' === $token[1] ) {
				$next_token = next( $tokens );
				if ( is_array( $next_token ) && T_CONSTANT_ENCAPSED_STRING === $next_token[0] ) {
					$name = trim( $next_token[1], '"' );
					$next_token = next( $tokens );
					if ( is_array( $next_token ) && T_CONSTANT_ENCAPSED_STRING === $next_token[0] ) {
						$value = trim( $next_token[1], '"' );
						$constants[ $name ] = $value;
					}
				}
			}
		}

		unset( $constants['DB_NAME'] );
		unset( $constants['DB_USER'] );
		unset( $constants['DB_PASSWORD'] );
		unset( $constants['DB_HOST'] );
		unset( $constants['DB_CHARSET'] );
		unset( $constants['DB_COLLATE'] );

		return $constants;
	}

	public function generate_blueprint() {
		global $wp_version;
		$steps = array();

		$plugins = get_option( 'active_plugins' );
		$plugin_steps = array();
		$ignore = array();
		$ignore_all_plugins = false;
		$ignore_theme = false;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ignore'] ) ) {
			// This is just a comma separated list of plugin slugs that is queried.
			$ignore = explode( ',', wp_unslash( $_GET['ignore'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		if ( isset( $_GET['ignore_all_plugins'] ) ) {
			$ignore_all_plugins = true;
		}
		if ( isset( $_GET['ignore_theme'] ) ) {
			$ignore_theme = true;
		}
		$ignore[] = 'blueprint-extractor';
		$ignore[] = 'blueprint-extractor-main';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$plugin_steps = array();
		$dependent_upon = array();
		foreach ( $plugins as $plugin ) {
			$slug = explode( '/', $plugin )[0];
			if ( in_array( $slug, $ignore ) || $ignore_all_plugins ) {
				continue;
			}
			$plugin_resource = $this->get_plugin_resource( $slug );

			if ( $plugin_resource ) {
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
				$plugin_steps[ $slug ] = array(
					'step'       => 'installPlugin',
					'pluginData' => $plugin_resource,
					'name'       => $plugin_data['Name'],
					'slug'       => $slug,
					'info'       => '',
				);

				if ( isset( $plugin_data['RequiresPlugins'] ) && ! empty( $plugin_data['RequiresPlugins'] ) ) {
					foreach ( explode( ',', $plugin_data['RequiresPlugins'] ) as $dependent ) {
						if ( ! isset( $dependent_upon[ $dependent ] ) ) {
							$dependent_upon[ $dependent ] = array();
						}
						$dependent_upon[ $dependent ][] = $slug;
					}
				}
			} else {
				$this->ignored_plugins[] = $slug;
			}
		}

		foreach ( $dependent_upon as $plugin => $dependents ) {
			if ( ! isset( $plugin_steps[ $plugin ] ) ) {
				continue;
			}
			$plugin_steps[ $plugin ]['info'] = ' (prioritized because of ' . implode(
				', ',
				array_map(
					function ( $dependent ) use ( $plugin_steps ) {
						return $plugin_steps[ $dependent ]['name'];
					},
					$dependents
				)
			) . ')';
			$steps[] = $plugin_steps[ $plugin ];
			unset( $plugin_steps[ $plugin ] );
		}
		foreach ( $plugin_steps as $plugin => $step ) {
			$steps[] = $step;
		}

		$theme = wp_get_theme();
		if ( ! in_array( $theme->get( 'TextDomain' ), $ignore ) && $this->check_theme_exists( $theme->get( 'TextDomain' ) ) && ! $ignore_theme ) {
			$steps[] = array(
				'step'         => 'installTheme',
				'themeZipFile' => array(
					'resource' => 'wordpress.org/themes',
					'slug'     => $theme->get( 'TextDomain' ),
				),
			);
		} else {
			$this->ignored_plugins[] = $theme->get( 'TextDomain' );
		}

		$site_options = array();
		foreach ( array(
			'blogname',
			'blogdescription',
			'permalink_structure',
		) as $name ) {
			$site_options[ $name ] = get_option( $name );
		}

		$steps[] = array(
			'step'    => 'setSiteOptions',
			'options' => $site_options,
		);

		$steps[] = array(
			'step' => 'runPHP',
			'code' => '<' . '?php require_once \'/wordpress/wp-load.php\'; foreach ( array( \'post\', \'page\', \'attachment\', \'revision\', \'nav_menu_item\' ) as $post_type ) { $posts = get_posts( array(\'posts_per_page\' => -1, \'post_type\' => $post_type ) ); foreach ($posts as $post) wp_delete_post($post->ID, true); }',
		);

		$blueprint = array(
			'landingPage'         => get_option( 'blueprint_extractor_initial_landing_page', '/' ),
			'preferredVersions'   => array(
				'php' => substr( phpversion(), 0, 3 ),
				'wp'  => $wp_version,
			),
			'phpExtensionBundles' => array( 'kitchen-sink' ),
			'features'            => array(
				'networking' => true,
			),
			'login'               => true,
			'steps'               => $steps,
		);

		return $blueprint;
	}

	public function render_admin_page() {
		$blueprint = $this->generate_blueprint();

		$checked = get_option( 'blueprint_extractor_default_checked' ) ? ' checked' : '';
		$name = get_option( 'blueprint_extractor_name', get_bloginfo( 'name' ) );

		if ( preg_match( '/\d+$/', $name, $m ) ) {
			$name = preg_replace( '/\d+$/', $m[0] + 1, $name );
		} else {
			$name .= ' V2';
		}

		?><div class="wrap">
		<h1>Blueprint</h1>
			<style>
				details summary  {
					cursor: pointer;
					font-weight: bold;
					margin-top: 10px;
				}
				details label {
					cursor: pointer;
				}
				details ul li span {
					margin-right: 5px;
					cursor: pointer;
				}
				details ul li div.options {
					margin-left: 2em;
					display: none;
				}
				details ul li div.options li {
					margin-left: 2em;
				}
				details ul li.checked div.options {
					display: block;
				}
				details ul li span:hover {
					color: #f00;
					text-decoration: line-through;
				}
				#select-users label.password {
					display: none;
				}
				#select-users input[type="checkbox"]:checked + label + label.password {
					display: inline;
				}
				.wp-core-ui .button.button-destructive {
					color: #a00;
					border-color: #b00;
				}
				.wp-core-ui .button.button-destructive:hover {
					background-color: #b00;
					color: #fff;
				}
			</style>
			<form target="_blank" action="https://blueprintlibrary.wordpress.com/" method="post">
			Name: <input type="text" id="blueprint-name" name="name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" /><br>
			Landing Page: <input type="text" id="landing-page" value="<?php echo esc_attr( $blueprint['landingPage'] ); ?>" onchange="updateBlueprint()" onkeyup="updateBlueprint()" /><br>

			<details id="select-pages">
				<summary>Pages <span class="checked"></span></summary>
					<?php foreach ( get_pages( array() ) as $page ) : ?>
					<label><input type="checkbox" <?php echo $checked; ?> data-id="<?php echo esc_attr( $page->ID ); ?>" onchange="updateBlueprint()" onkeyup="updateBlueprint()" data-post_name="<?php echo esc_attr( $page->post_name ); ?>" data-post_type="<?php echo esc_attr( $page->post_type ); ?>" data-post_title="<?php echo esc_attr( $page->post_title ); ?>" data-post_content="<?php echo esc_attr( str_replace( PHP_EOL, '\n', $page->post_content ) ); ?>" /> <?php echo esc_html( $page->post_title ); ?></label><br/>
				<?php endforeach; ?>
			</details>

			<details id="select-templates">
				<summary>Templates <span class="checked"></span></summary>
					<?php
					foreach ( get_posts(
						array(
							'post_type'   => 'wp_template',
							'numberposts' => -1,
							'taxonomy'    => 'wp_theme',
							'term'        => wp_get_theme()->get_stylesheet(),
						)
					) as $template ) :
						?>
					<label><input type="checkbox" <?php echo $checked; ?> data-id="<?php echo esc_attr( $template->ID ); ?>" onchange="updateBlueprint()" onkeyup="updateBlueprint()" data-post_title="<?php echo esc_attr( $template->post_title ); ?>" data-post_name="<?php echo esc_attr( $template->post_name ); ?>" data-post_content="<?php echo esc_attr( str_replace( PHP_EOL, '\n', $template->post_content ) ); ?>"/> <?php echo esc_html( $template->post_title ); ?></label><br/>

						<?php endforeach; ?>
			</details>

			<details id="select-template-parts">
				<summary>Template Parts <span class="checked"></span></summary>
						<?php
						foreach ( get_posts(
							array(
								'post_type'   => 'wp_template_part',
								'numberposts' => -1,
								'taxonomy'    => 'wp_theme',
								'term'        => wp_get_theme()->get_stylesheet(),
							)
						) as $template_part ) :
							$references = array();
							$nav_items = array();
							preg_match_all( '#<!-- wp:navigation\s+(.*?) /-->#', $template_part->post_content, $matches );
							foreach ( $matches[1] as $match ) {
								$match = json_decode( $match, true );
								if ( isset( $match['ref'] ) && is_numeric( $match['ref'] ) ) {
									$reference_id = count( $references );
									$p = get_post( $match['ref'] );

									if ( ! $p ) {
										continue;
									}

									preg_match_all( '#<!-- wp:navigation-link\s+(.*?) /-->#', $p->post_content, $nav_matches );
									foreach ( $nav_matches[1] as $nav_match ) {
										$nav_match = json_decode( $nav_match, true );
										if ( isset( $nav_match['id'] ) && is_numeric( $nav_match['id'] ) ) {
											$nav_item = get_post( $nav_match['id'] );
											if ( $nav_item ) {
												$nav_items[ $nav_item->ID ] = array( $nav_item->post_name, $nav_item->post_type );
												$p->post_content = str_replace(
													$nav_match['id'],
													'NAV_ITEM_' . $nav_item->ID,
													$p->post_content
												);
											}
										}
									}


									$references[ $reference_id ] = array(
										'id'           => $match['ref'],
										'post_title'   => $p->post_title,
										'post_content' => $p->post_content,
										'post_type'    => $p->post_type,
										'post_name'    => $p->post_name,
									);
									$template_part->post_content = str_replace(
										$match['ref'],
										'REFERENCE_' . $reference_id,
										$template_part->post_content
									);
								}
							}
							?>
					<label><input type="checkbox" <?php echo $checked; ?> data-id="<?php echo esc_attr( $template_part->ID ); ?>" onchange="updateBlueprint()" onkeyup="updateBlueprint()" data-post_title="<?php echo esc_attr( $template_part->post_title ); ?>" data-post_name="<?php echo esc_attr( $template_part->post_name ); ?>" data-post_content="<?php echo esc_attr( str_replace( PHP_EOL, '\n', $template_part->post_content ) ); ?>" data-references="<?php echo esc_attr( str_replace( PHP_EOL, '\n', json_encode( $references ) ) ); ?>" data-nav-items="<?php echo esc_attr( str_replace( PHP_EOL, '\n', json_encode( $nav_items ) ) ); ?>"/> <?php echo esc_html( $template_part->post_title ); ?></label><br/>

						<?php endforeach; ?>
			</details>

			<details>
				<summary>Media</summary>
				<a href="?media_zip_download" download="media-files.zip">Download the ZIP file of all media</a> and then upload it to somewhere web accessible.<br>
				Then, enter the URL of the uploaded ZIP file: <input type="url" id="zip-url" value="" />. The blueprint below will update.<br>
			</details>

			<?php $constants = $this->get_wp_config_constants(); ?>
			<details id="select-constants">
				<summary>Constants <span class="checked"></span></summary>
				<ul id="additionalconstants">
				</ul>
				<datalist id="constants">
						<?php foreach ( $constants as $name => $value ) : ?>
						<option label="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>" />
					<?php endforeach; ?>
				</datalist>
				<datalist id="constant-values">
							<?php foreach ( $constants as $name => $value ) : ?>
						<option label="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php endforeach; ?>
				</datalist>
				<input type="text" id="constant-name" list="constants" placeholder="Constant Name" size="30" onchange="updateConstantValue()" oninput="updateConstantValue()" onkeyup="updateConstantValue()"/>
				<span id="constant-value"></span>
				<button onclick="addConstantToBlueprint()">Add</button>
			</details>

			<?php $plugin_options = $this->get_wp_plugin_options(); ?>
			<details id="select-options">
				<summary>Options <span class="checked"></span></summary>
				<ul id="additionaloptions">
					<?php foreach ( $blueprint['steps'] as $k => $step ) : ?>
						<?php if ( 'setSiteOptions' === $step['step'] ) : ?>
							<?php foreach ( $step['options'] as $name => $value ) : ?>
								<li><input type="text" name="key" value="<?php echo esc_attr( $name ); ?>" placeholder="Key" onchange="updateBlueprint()" onkeyup="updateBlueprint()" /> <input type="text" name="value" value="<?php echo esc_attr( $value ); ?>" placeholder="Value" onchange="updateBlueprint()" onkeyup="updateBlueprint()" /> (automatically included)</li>
							<?php endforeach; ?>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
				<datalist id="options">
					<?php foreach ( $plugin_options as $plugin => $options ) : ?>
						<?php foreach ( $options as $name => $value ) : ?>
						<option label="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>" />
					<?php endforeach; ?>
					<?php endforeach; ?>
				</datalist>
				<datalist id="option-values">
					<?php foreach ( $plugin_options as $plugin => $options ) : ?>
						<?php foreach ( $options as $name => $value ) : ?>
						<option label="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php endforeach; ?>
					<?php endforeach; ?>
				</datalist>
				<input type="text" id="option-name" list="options" placeholder="Option Name" size="30" onchange="updateOptionValue()" oninput="updateOptionValue()" onkeyup="updateOptionValue()"/>
				<span id="option-value"></span>
				<button class="add-option">Add</button>
			</details>

			<details id="select-plugins">
				<summary>Plugins <span class="checked"></span></summary>
					<a href="" id="select-all-plugins">Select all</a> <a href="" id="select-none-plugins">Select none</a>
					<ul>
							<?php foreach ( $blueprint['steps'] as $k => $step ) : ?>
								<?php if ( 'installPlugin' === $step['step'] ) : ?>
						<li class="plugin checked" id="plugin_<?php echo esc_attr( $k ); ?>"><label><input type="checkbox" id="use_plugin_<?php echo esc_attr( $k ); ?>" checked onchange="updateBlueprint()" value="<?php echo esc_attr( $k ); ?>" /> <?php echo esc_html( $step['name'] . $step['info'] ); ?></label>
									<?php if ( ! empty( $plugin_options[ $step['slug'] ] ) ) : ?>
								<div class="options">
									<strong>Options</strong> <a href="" id="add-all-plugin-options">Add all</a> <a href="" id="remove-all-plugin-options">Remove all</a></summary>
									<ul>
										<?php foreach ( array_keys( $plugin_options[ $step['slug'] ] ) as $name ) : ?>
											<li><label><input type="checkbox" class="plugin-option" value="<?php echo esc_attr( $name ); ?>" checked><?php echo esc_html( $name ); ?></label></li>
										<?php endforeach; ?>
									</ul>
										</div>
							<?php endif; ?>
					</li>
					<?php endif; ?>
					<?php endforeach; ?>
					</ul>
			</details>

			<details id="select-theme">
				<?php $theme = wp_get_theme(); ?>
				<summary>Theme</summary>
				<label><input type="radio" name="ignore_theme" id="ignore-theme" onclick="updateBlueprint()"> Use Default Theme</label><br>
				<label><input type="radio" name="ignore_theme" checked onclick="updateBlueprint()"> Use Current Theme: <?php echo esc_html( $theme ); ?></label><br>
				<?php
				$global_styles = get_posts( array(
					'post_type'   => 'wp_global_styles',
					'taxonomy'    => 'wp_theme',
					'numberposts' => 1,
					'term'        => wp_get_theme()->get_stylesheet(),
				) );

				if ( $global_styles ) :
					?>
					<input type="checkbox" <?php echo $checked; ?> id="global-styles" data-post_content="<?php echo esc_attr( str_replace( PHP_EOL, '\n', $global_styles[0]->post_content ) ); ?>" data-post_name="<?php echo esc_attr( $global_styles[0]->post_name ); ?>" data-post_title="<?php echo esc_attr( $global_styles[0]->post_title ); ?>" /> <label for="global-styles">Include Global Styles</label><br>
				<?php endif; ?>
			</details>
			<details id="select-users">
				<summary>Users <span class="checked"></span></summary>
				<ul>
							<?php foreach ( get_users() as $u ) : ?>
								<?php if ( 'admin' !== $u->user_login ) : ?>
							<li>
								<input type="checkbox" <?php echo $checked; ?> data-login="<?php echo esc_attr( $u->user_login ); ?>" data-name="<?php echo esc_attr( $u->display_name ); ?>" data-role="<?php echo esc_attr( $u->roles[0] ); ?>" onchange="updateBlueprint()" id="user_<?php echo esc_attr( $u->user_login ); ?>" /> <label for="user_<?php echo esc_attr( $u->user_login ); ?>"><?php echo esc_html( $u->display_name ); ?></label>
								<label class="password">Password: <input type="text" value="" placeholder="Set a password in the blueprint" onchange="updateBlueprint()"/></label><br/>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</details>

			<br>
			→ <a id="playground-link" href="https://playground.wordpress.net/?blueprint-url=data:application/json;base64,<?php echo esc_attr( base64_encode( wp_json_encode( $blueprint, JSON_UNESCAPED_SLASHES ) ) ); ?>" target="_blank">Open a new WordPress Playground with the blueprint below</a>
			&nbsp;<label><input type="checkbox" id="include-blueprint-extractor" checked onchange="updateBlueprint()" />Include the Blueprint Extractor plugin</label>
			<br/>
			<br/>
			<button id="copy-blueprint" class="button">Copy the blueprint to clipboard</button>
			<button class="button">Submit the blueprint to WordPress.com</button>
			<button id="clear-local-storage" class="button button-destructive" style="margin-left: 10em">Reset Previous Selections</button>
			<br>
			<br>

			<textarea id="blueprint" name="blueprint" cols="120" rows="50" style="font-family: monospace"><?php echo esc_html( wp_json_encode( $blueprint, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ); ?></textarea>
			</form>
			<script>
				const originaBlueprint = document.getElementById('blueprint').value;
				const home_url = '<?php echo esc_js( home_url() ); ?>';
				function encodeStringAsBase64(str) {
					return encodeUint8ArrayAsBase64(new TextEncoder().encode(str));
				}

				function encodeUint8ArrayAsBase64(bytes) {
					const binString = String.fromCodePoint(...bytes);
					return btoa(binString);
				}

				let blueprint = JSON.parse( originaBlueprint );
				const ignorePlugins = JSON.parse( localStorage.getItem( 'blueprint_extractor_ignore_plugins' ) || '[]' );
				for ( let i = 0; i < blueprint.steps.length; i++ ) {
					if ( blueprint.steps[i].step === 'installPlugin' &&  ignorePlugins.includes( blueprint.steps[i].name ) ) {
						document.getElementById('use_plugin_' + i).checked = false;
						document.getElementById('use_plugin_' + i).querySelectorAll('input[type="checkbox"]').forEach( function ( checkbox ) {
							checkbox.checked = false;
						} );
						document.getElementById('use_plugin_' + i).closest('li').classList.remove( 'checked' );
					}
					if ( blueprint.steps[i].step === 'installTheme' && localStorage.getItem( 'blueprint_extractor_ignore_theme' ) ) {
						document.getElementById('ignore-theme').checked = true;
						document.getElementById('select-theme').open = true;
					}
				}
				let additionalOptions = JSON.parse( localStorage.getItem( 'blueprint_extractor_additional_options' ) || '<?php echo wp_json_encode( get_option( 'blueprint_extractor_initial_options', new stdClass() ) ); ?>' );
				const additionalOptionsList = document.getElementById('additionaloptions');
				for ( const optionKey in additionalOptions ) {
					if ( additionalOptions.hasOwnProperty( optionKey ) ) {
						const li = document.createElement('li');
						const key = document.createElement('input');
						key.type = 'text';
						key.name = 'key';
						key.placeholder = 'Key';
						key.value = optionKey;
						li.appendChild(key);
						const value = document.createElement('input');
						value.type = 'text';
						value.name = 'value';
						value.placeholder = 'Value';
						value.value = additionalOptions[optionKey];
						li.appendChild(value);
						additionalOptionsList.appendChild(li);

					}
				}
				const users = JSON.parse( localStorage.getItem( 'blueprint_extractor_users' ) || '[]' );
				document.querySelectorAll( '#select-users input[type="checkbox"]' ).forEach( function ( checkbox ) {
					if ( users.includes( checkbox.getAttribute('data-login') ) ) {
						checkbox.checked = true;
					}
				} );
				const constants = JSON.parse( localStorage.getItem( 'blueprint_extractor_constants' ) || '<?php echo wp_json_encode( get_option( 'blueprint_extractor_initial_constants', new stdClass() ) ); ?>' );
				const constantsList = document.getElementById('additionalconstants');
				for ( const constantKey in constants ) {
					if ( constants.hasOwnProperty( constantKey ) ) {
						const checkbox = document.querySelector( '#select-constants input[type="checkbox"][value="' + constantKey + '"]' );
						if ( checkbox ) {
							checkbox.checked = true;
							if ( typeof constants[constantKey] === 'string' ) {
								checkbox.nextElementSibling.value = constants[constantKey];
							}
						} else {
							const li = document.createElement('li');
							const key = document.createElement('input');
							key.type = 'text';
							key.name = 'key';
							key.placeholder = 'Key';
							key.value = constantKey;
							li.appendChild(key);
							const value = document.createElement('input');
							value.type = 'text';
							value.name = 'value';
							value.placeholder = 'Value';
							value.value = constants[constantKey];
							li.appendChild(value);
							constantsList.appendChild(li);
						}
					}
				}
				const pages = JSON.parse( localStorage.getItem( 'blueprint_extractor_pages' ) || '[]' );
				document.querySelectorAll( '#select-pages input[type="checkbox"]' ).forEach( function ( checkbox ) {
					if ( pages.includes( checkbox.getAttribute('data-id') ) ) {
						checkbox.checked = true;
					}
				} );
				const templates = JSON.parse( localStorage.getItem( 'blueprint_extractor_templates' ) || '[]' );
				document.querySelectorAll( '#select-templates input[type="checkbox"]' ).forEach( function ( checkbox ) {
					if ( templates.includes( checkbox.getAttribute('data-id') ) ) {
						checkbox.checked = true;
					}
				} );
				const template_parts = JSON.parse( localStorage.getItem( 'blueprint_extractor_template_parts' ) || '[]' );
				document.querySelectorAll( '#select-template-parts input[type="checkbox"]' ).forEach( function ( checkbox ) {
					if ( template_parts.includes( checkbox.getAttribute('data-id') ) ) {
						checkbox.checked = true;
					}
				} );
				const zipUrl = localStorage.getItem( 'blueprint_extractor_zip_url' );
				if ( zipUrl ) {
					document.getElementById('zip-url').value = zipUrl;
				}
				document.getElementById('zip-url').addEventListener('change', function (event) {
					localStorage.setItem( 'blueprint_extractor_zip_url', event.target.value );
				} );
				updateBlueprint();

				function updateBlueprint() {
					let blueprint = JSON.parse( originaBlueprint );
					blueprint.landingPage = document.getElementById('landing-page').value;
					if ( document.getElementById('zip-url').value ) {
						blueprint.steps.push( {
							'step'          : 'unzip',
							'zipFile'       : {
								'resource' : 'url',
								'url'      : 'https://playground.wordpress.net/cors-proxy.php?' + document.getElementById('zip-url').value,
							},
							'extractToPath' : '/wordpress/wp-content/uploads',
						} );
					}
					const includeBlueprintExtractor = document.getElementById('include-blueprint-extractor').checked;
					const steps = [], plugins = [], ignore_plugins = [];
					for ( let i = 0; i < blueprint.steps.length; i++ ) {
						if ( blueprint.steps[i].step === 'installPlugin' ) {
						if ( ! document.getElementById('use_plugin_' + i).checked ) {
								ignore_plugins.push( blueprint.steps[i].name );
								document.getElementById('use_plugin_' + i).closest('li').classList.remove('checked');
								continue;
							}
							document.getElementById('use_plugin_' + i).closest('li').classList.add('checked');
							delete blueprint.steps[i].name;
							delete blueprint.steps[i].info;
							plugins.push( blueprint.steps[i].slug );
							delete blueprint.steps[i].slug;
						}
						if ( blueprint.steps[i].step === 'setSiteOptions' ) {
							additionalOptions = {};
							document.querySelectorAll( '#select-options input[name=key]' ).forEach( function ( checkbox ) {
								if ( checkbox.value ) {
									if ( checkbox.getAttribute('type') === 'checkbox' ) {
										additionalOptions[checkbox.value] = checkbox.checked;
									} else if ( checkbox.nextElementSibling?.tagName === 'INPUT' ) {
										additionalOptions[checkbox.value] = checkbox.nextElementSibling.value;
									}
									blueprint.steps[i].options[checkbox.value] = additionalOptions[checkbox.value];
								}
							} );
							if ( Object.values(additionalOptions).length ) {
								localStorage.setItem( 'blueprint_extractor_additional_options', JSON.stringify( additionalOptions ) );
							} else {
								localStorage.removeItem( 'blueprint_extractor_additional_options' );
							}
							if ( Array.isArray( blueprint.steps[i].options ) ) {
								if ( ! blueprint.steps[i].options.length ) {
									continue;
								}
							} else if ( ! Object.keys( blueprint.steps[i].options ).length ) {
								continue;
							}
						}
						if ( blueprint.steps[i].step === 'installTheme' ) {

							if ( document.getElementById('ignore-theme').checked ) {
								localStorage.setItem( 'blueprint_extractor_ignore_theme', true );
								continue;
							}
							localStorage.removeItem( 'blueprint_extractor_ignore_theme' );
							const global_styles = document.getElementById('global-styles');
							if ( global_styles && global_styles.checked ) {
								steps.push( {
									'step' : 'runPHP',
									'code' : "<" + "?php require_once '/wordpress/wp-load.php'; $theme = wp_get_theme(); $term = get_term_by( 'slug', $theme->get_stylesheet(), 'wp_theme'); if ( ! $term) { $term = wp_insert_term( $theme->get_stylesheet(), 'wp_theme' ); $term_id = $term['term_id']; } else { $term_id = $term->term_id; } $post_id = wp_insert_post( array( 'post_type' => 'wp_global_styles', 'post_title' => '" + global_styles.dataset.post_title.replace( /'/g, "\\'" ) + "', 'post_name' => '" + global_styles.dataset.post_name.replace( /'/g, "\\'" ) + "', 'post_content' => '" + global_styles.dataset.post_content.replace( /'/g, "\\'" ).replace( /\\n/g, "\n" ) + "', 'post_status' => 'publish' ) ); wp_set_object_terms($post_id, $term_id, 'wp_theme');",
								} );
							}
						}
						steps.push( blueprint.steps[i] );
					}
					if ( ignore_plugins.length ) {
						localStorage.setItem( 'blueprint_extractor_ignore_plugins', JSON.stringify( ignore_plugins ) );
					} else {
						localStorage.removeItem( 'blueprint_extractor_ignore_plugins' );
					}

					document.querySelector( '#select-plugins span.checked' ).textContent = plugins.length ? ' (' + plugins.length + ')' : '';
					const users = [], passwords = [];
					document.querySelectorAll( '#select-users input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							users.push( checkbox.getAttribute('data-login') );
							const password = checkbox.parentNode.querySelector('.password input').value;
							passwords.push( password );
							steps.push( {
								'step' : 'runPHP',
								'code' : "<" + "?php require_once '/wordpress/wp-load.php'; $data = array( 'user_login' => '" + checkbox.dataset.login + "', 'display_name' => '" + checkbox.dataset.name.replace( /'/g, "\\'" ) + "', 'role' => '" + checkbox.dataset.role + "', 'user_pass' => '" + password.replace( /'/g, "\\'" ) + "' ); wp_insert_user( $data ); ?>",
							} );
						}
					} );
					if ( users.length ) {
						localStorage.setItem( 'blueprint_extractor_users', JSON.stringify( users ) );
						localStorage.setItem( 'blueprint_extractor_passwords', JSON.stringify( passwords ) );
					} else {
						localStorage.removeItem( 'blueprint_extractor_users' );
						localStorage.removeItem( 'blueprint_extractor_passwords' );
					}
					document.querySelector( '#select-users span.checked' ).textContent = users.length ? ' (' + users.length + ')' : '';

					const constants = {};
					document.querySelectorAll( '#select-constants input[name=key]' ).forEach( function ( checkbox ) {
						if ( checkbox.value ) {
							if ( checkbox.getAttribute('type') === 'checkbox' ) {
								checkbox.checked = true;
							} else if ( checkbox.nextElementSibling?.tagName === 'INPUT' ) {
								constants[checkbox.value] = checkbox.nextElementSibling.value;
							}
						}
					} );
					if ( Object.values(constants).length ) {
						localStorage.setItem( 'blueprint_extractor_constants', JSON.stringify( constants ) );
						steps.push( {
							'step' : 'defineWpConfigConsts',
							'consts' : constants,
						} );
					} else {
						localStorage.removeItem( 'blueprint_extractor_constants' );
					}
					document.querySelector( '#select-constants span.checked' ).textContent = Object.values(constants).length ? ' (' + Object.values(constants).length + ')' : '';
					document.querySelector( '#select-options span.checked' ).textContent = Object.values(additionalOptions).length ? ' (' + Object.values(additionalOptions).length + ')' : '';

					const pages = [];
					document.querySelectorAll( '#select-pages input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							pages.push( checkbox.getAttribute('data-id') );
							let code = "<" + "?php require_once '/wordpress/wp-load.php'; ";
							code += "$post_content = '" + checkbox.dataset.post_content.replace( /'/g, "\\'" ).replace( /\\n/g, "\n" ) + "';";
							code += " $post_content = str_replace( '" + home_url + "', home_url(), $post_content );";
							code += "wp_insert_post( array( 'post_type' => '" + checkbox.dataset.post_type.replace( /'/g, "\\'" ) + "', 'post_title' => '" + checkbox.dataset.post_title.replace( /'/g, "\\'" ) + "', 'post_content' => $post_content, 'post_name' => '" + checkbox.dataset.post_name.replace( /'/g, "\\'" ) + "',  'post_status' => 'publish' ) );",
							steps.push( {
								'step' : 'runPHP',
								'code' : code,
							});
						}
					} );
					if ( pages.length ) {
						localStorage.setItem( 'blueprint_extractor_pages', JSON.stringify( pages ) );
					} else{
						localStorage.removeItem( 'blueprint_extractor_pages' );
					}
					document.querySelector( '#select-pages span.checked' ).textContent = pages.length ? ' (' + pages.length + ')' : '';
					const template_parts = [];
					document.querySelectorAll( '#select-template-parts input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							template_parts.push( checkbox.getAttribute('data-id') );
							let code = "<" + "?php require_once '/wordpress/wp-load.php'; $theme = wp_get_theme(); $term = get_term_by( 'slug', $theme->get_stylesheet(), 'wp_theme'); if ( ! $term ) { $term = wp_insert_term( $theme->get_stylesheet(), 'wp_theme' ); $term_id = $term['term_id']; } else { $term_id = $term->term_id; } $template_part_content = '" + checkbox.dataset.post_content.replace( /'/g, "\\'" ).replace( /\\n/g, "\n" ) + "'; $nav_items = array(); ";
							const nav_items = JSON.parse( checkbox.dataset.navItems || '[]' );
							for ( const nav_item_id in nav_items ) {
								if ( nav_items.hasOwnProperty( nav_item_id ) ) {
									const nav_item = nav_items[nav_item_id];
									code += " $page = get_page_by_path( '" + nav_item[0].replace( /'/g, "\\'" ) + "', OBJECT, '" + nav_item[1] + "' );";
									code += "$nav_items['NAV_ITEM_" + nav_item_id + "'] = $page ? $page->ID : 0; ";
								}
							}
							const references = JSON.parse( checkbox.dataset.references || '[]' );
							for ( const reference_id in references ) {
								if ( references.hasOwnProperty( reference_id ) ) {
									const reference = references[reference_id];
									code += " $reference_post_content = str_replace( array_keys( $nav_items ), array_values( $nav_items ), '" + reference.post_content.replace( /'/g, "\\'" ).replace( /\\n/g, "\n" ) + "' );";
									code += " $reference_post_content = str_replace( '" + home_url + "', home_url(), $reference_post_content );";
									code += " $reference_id = wp_insert_post( array( 'post_type' => '" + reference.post_type + "', 'post_title' => '" + reference.post_title.replace( /'/g, "\\'" ) + "', 'post_content' => $reference_post_content, 'post_name' => '" + reference.post_name.replace( /'/g, "\\'" ) + "', 'post_status' => 'publish' ) ); wp_set_object_terms($reference_id, $term_id, 'wp_theme');";
									code += " $template_part_content = str_replace( 'REFERENCE_" + reference_id + "', $reference_id, $template_part_content );";
								}
							}
							code += " $template_part_content = str_replace( '" + home_url + "', home_url(), $template_part_content );";

							code += "$post_id = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_title' => '" + checkbox.dataset.post_title.replace( /'/g, "\\'" ) + "', 'post_name' => '" + checkbox.dataset.post_name.replace( /'/g, "\\'" ) + "', 'post_content' => $template_part_content, 'post_status' => 'publish' ) ); wp_set_object_terms($post_id, $term_id, 'wp_theme');"
							steps.push( {
								'step' : 'runPHP',
								'code' : code,
							} );
						}
					} );
					if ( template_parts.length ) {
						localStorage.setItem( 'blueprint_extractor_template_parts', JSON.stringify( template_parts ) );
					} else {
						localStorage.removeItem( 'blueprint_extractor_template_parts' );
					}
					document.querySelector( '#select-template-parts span.checked' ).textContent = template_parts.length ? ' (' + template_parts.length + ')' : '';
					const templates = [];
					document.querySelectorAll( '#select-templates input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							templates.push( checkbox.getAttribute('data-id') );
							steps.push( {
								'step' : 'runPHP',
								'code' : "<" + "?php require_once '/wordpress/wp-load.php'; $theme = wp_get_theme(); $term = get_term_by( 'slug', $theme->get_stylesheet(), 'wp_theme'); if ( ! $term) { $term = wp_insert_term( $theme->get_stylesheet(), 'wp_theme' ); $term_id = $term['term_id']; } else { $term_id = $term->term_id; } $post_id = wp_insert_post( array( 'post_type' => 'wp_template', 'post_title' => '" + checkbox.dataset.post_title.replace( /'/g, "\\'" ) + "', 'post_name' => '" + checkbox.dataset.post_name.replace( /'/g, "\\'" ) + "', 'post_content' => '" + checkbox.dataset.post_content.replace( /'/g, "\\'" ).replace( /\\n/g, "\n" ) + "', 'post_status' => 'publish' ) ); wp_set_object_terms($post_id, $term_id, 'wp_theme');",
							} );
						}
					} );
					if ( templates.length ) {
						localStorage.setItem( 'blueprint_extractor_templates', JSON.stringify( templates ) );
					} else {
						localStorage.removeItem( 'blueprint_extractor_templates' );
					}
					document.querySelector( '#select-templates span.checked' ).textContent = templates.length ? ' (' + templates.length + ')' : '';

					blueprint.steps = steps;
					document.getElementById('blueprint').value = JSON.stringify( blueprint, null, 4 );

					if ( includeBlueprintExtractor ) {
						blueprint.steps.push( {
							'step' : 'installPlugin',
							'pluginData' : {
								'resource' : 'url',
								'url'      : 'https://github-proxy.com/proxy/?repo=akirk/blueprint-extractor&branch=main',
							}
						} );
						blueprint.steps.push( {
							'step' : 'setSiteOptions',
							'options' : {
								'blueprint_extractor_initial_constants' : constants,
								'blueprint_extractor_initial_options' : additionalOptions,
								'blueprint_extractor_initial_landing_page' : document.getElementById('landing-page').value,
								'blueprint_extractor_default_checked': true,
								'blueprint_extractor_name': document.getElementById('blueprint-name').value,
							}
						} );
					}
					const query = 'blueprint-url=data:application/json;base64,' + encodeURIComponent( encodeStringAsBase64( JSON.stringify( blueprint, null, 4 ) ) );
					document.getElementById('playground-link').href = 'https://playground.wordpress.net/?' + query;

				}

				function updateOptionValue() {
					const optionName = document.getElementById('option-name').value;
					if ( optionName ) {
						const optionValue = document.querySelector( '#option-values option[label="' + optionName + '"]' );
						if ( optionValue ) {
							document.getElementById('option-value').textContent = optionValue.getAttribute('value');
							return optionValue.getAttribute('value');
						}
					}
					return false;
				}

				function removedOptionFromBlueprint( optionName ) {
					delete additionalOptions[optionName];
					localStorage.setItem( 'blueprint_extractor_additional_options', JSON.stringify( additionalOptions ) );
					const additionalOptionsList = document.getElementById('additionaloptions');
					additionalOptionsList.querySelectorAll('li input[name="key"]').forEach( function ( checkbox ) {
						if ( checkbox.value === optionName ) {
							additionalOptionsList.removeChild( checkbox.parentNode );
						}
					} );
					document.getElementById('option-name').value = '';
					document.getElementById('option-value').textContent = '';
					updateBlueprint();
				}

				function addOptionToBlueprint( optionName ) {
					const optionValue = document.querySelector( '#option-values option[label="' + optionName + '"]' );
					additionalOptions[optionName] = optionValue.value;
					localStorage.setItem( 'blueprint_extractor_additional_options', JSON.stringify( additionalOptions ) );
					const additionalOptionsList = document.getElementById('additionaloptions');
					const li = document.createElement('li');
					const key = document.createElement('input');
					key.type = 'text';
					key.name = 'key';
					key.placeholder = 'Key';
					key.value = optionName;
					li.appendChild(key);
					const value = document.createElement('input');
					value.type = 'text';
					value.name = 'value';
					value.placeholder = 'Value';
					value.value = additionalOptions[optionName];
					li.appendChild(value);
					additionalOptionsList.appendChild(li);
					document.getElementById('option-name').value = '';
					document.getElementById('option-value').textContent = '';
					document.getElementById('option-name').focus();
					updateBlueprint();
					return false;
				}

				function updateConstantValue() {
					const constantName = document.getElementById('constant-name').value;
					if ( constantName ) {
						const constantValue = document.querySelector( '#constant-values option[label="' + constantName + '"]' );
						if ( constantValue ) {
							document.getElementById('constant-value').textContent = constantValue.getAttribute('value');
							return constantValue.getAttribute('value');
						}
					}
					return false;
				}
				function addConstantToBlueprint() {
					const constantName = document.getElementById('constant-name').value;
					if ( constantName ) {
						constants[constantName] = updateConstantValue();
						localStorage.setItem( 'blueprint_extractor_constants', JSON.stringify( constants ) );
						if ( constants[constantName] ) {
							const checkbox = document.querySelector( '#select-constants input[name=key][value="' + constantName + '"]' );
							if ( checkbox ) {
								if ( checkbox.nextElementSibling?.tagName === 'INPUT' ) {
									checkbox.nextElementSibling.value = constants[constantName];
								} else {
									checkbox.checked = true;
								}
							} else {
								const li = document.createElement('li');
								const key = document.createElement('input');
								key.type = 'text';
								key.name = 'key';
								key.placeholder = 'Key';
								key.value = constantName;
								li.appendChild(key);
								const value = document.createElement('input');
								value.type = 'text';
								value.name = 'value';
								value.placeholder = 'Value';
								value.value = constants[constantName];
								li.appendChild(value);
								constantsList.appendChild(li);
							}
							document.getElementById('constant-name').value = '';
							document.getElementById('constant-value').textContent = '';
							document.getElementById('constant-name').focus();
							updateBlueprint();
						}
					}
				}
				document.getElementById('zip-url').addEventListener('keyup', updateBlueprint );

				document.addEventListener('change', function (event) {
					if ( event.target.matches('input') ) {
						updateBlueprint();
					}
				} );
				document.addEventListener('click', function (event) {
					if ( event.target.matches('.plugin-option') ) {
						if ( event.target.checked ) {
							addOptionToBlueprint( event.target.value );
						} else {
							removedOptionFromBlueprint( event.target.value );
						}
					} else if ( event.target.matches('#add-all-plugin-options') ) {
						event.preventDefault();
						const pluginoptions = event.target.closest('li').querySelectorAll('input.plugin-option');
						pluginoptions.forEach( function ( pluginoption ) {
							pluginoption.checked = true;
							addOptionToBlueprint( pluginoption.value );
						} );
					} else if ( event.target.matches('#remove-all-plugin-options') ) {
						event.preventDefault();
						const pluginoptions = event.target.closest('li').querySelectorAll('input.plugin-option');
						pluginoptions.forEach( function ( pluginoption ) {
							pluginoption.checked = false;
							removedOptionFromBlueprint( pluginoption.value );
						} );
					} else if ( event.target.matches('.add-option') ) {
						event.preventDefault();
						addOptionToBlueprint( document.getElementById('option-name').value );
					}
				} );
				document.addEventListener('keyup', function (event) {
					if ( event.target.matches('input') ) {
						if ( event.target.id === 'option-name' && event.key === 'Enter' ) {
							addOptionToBlueprint( event.target.value );
						} else if ( event.target.id === 'constant-name' && event.key === 'Enter' ) {
							addConstantToBlueprint();
						} else {
							updateBlueprint();
						}
					}
				} );

				document.getElementById('select-all-plugins').addEventListener('click', function (event) {
					event.preventDefault();
					document.querySelectorAll('#select-plugins input[type="checkbox"]').forEach(function (checkbox) {
						checkbox.checked = true;
						checkbox.closest('li').classList.add('checked');
					});
					const pluginoptions = document.querySelectorAll('#select-plugins input.plugin-option');
					pluginoptions.forEach( function ( pluginoption ) {
						pluginoption.checked = true;
						addOptionToBlueprint( pluginoption.value );
					} );

					updateBlueprint();
				});
				document.getElementById('select-none-plugins').addEventListener('click', function (event) {
					event.preventDefault();
					document.querySelectorAll('#select-plugins input[type="checkbox"]').forEach(function (checkbox) {
						checkbox.checked = false;
						checkbox.closest('li').classList.remove('checked');
					});
					const pluginoptions = document.querySelectorAll('#select-plugins input.plugin-option');
					pluginoptions.forEach( function ( pluginoption ) {
						pluginoption.checked = false;
						removedOptionFromBlueprint( pluginoption.value );
					} );
					updateBlueprint();
				});
				document.getElementById('copy-blueprint').addEventListener('click', function (event) {
					event.preventDefault();
					const blueprint = document.getElementById('blueprint');
					blueprint.select();
					document.execCommand('copy');
					event.target.textContent = 'Copied!';
					blueprint.setSelectionRange(0, 0);
					setTimeout(function () {
						event.target.textContent = 'Copy the blueprint to clipboard';
					}, 2000);
				});
				document.getElementById('clear-local-storage').addEventListener('click', function (event) {
					event.preventDefault();
					localStorage.removeItem( 'blueprint_extractor_additional_options' );
					localStorage.removeItem( 'blueprint_extractor_users' );
					localStorage.removeItem( 'blueprint_extractor_passwords' );
					localStorage.removeItem( 'blueprint_extractor_constants' );
					localStorage.removeItem( 'blueprint_extractor_template_parts' );
					localStorage.removeItem( 'blueprint_extractor_ignore_plugins' );
					localStorage.removeItem( 'blueprint_extractor_pages' );
					localStorage.removeItem( 'blueprint_extractor_zip_url' );
					location.reload();
				});
			</script>
		</div>
			<?php

			delete_option( 'blueprint_extractor_initial_options' );
			delete_option( 'blueprint_extractor_initial_constants' );
	}

	public function add_admin_menu() {
		add_menu_page(
			'Blueprint',       // Page title.
			'Blueprint',       // Menu title.
			'manage_options',  // Capability.
			'blueprint',       // Menu slug.
			array( $this, 'render_admin_page' ), // Callback.
			'dashicons-list-view'     // Icon.
		);
		add_action( 'load-toplevel_page_blueprint', array( $this, 'process_blueprint_admin' ) );
	}

	public function process_blueprint_admin() {
		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'blueprint' ) ) {
			return;
		}
		if ( isset( $_POST['stop-recording'] ) ) {
			update_option( 'blueprint_extractor_disabled', true );
		} elseif ( isset( $_POST['start-recording'] ) ) {
			delete_option( 'blueprint_extractor_disabled' );
		}
	}

	public function init() {
		if ( isset( $_GET['media_zip_download'] ) ) {
			$uploads = wp_upload_dir();
			$media_dir = $uploads['basedir'];
			$zip_file = 'media-files.zip';

			// Create a new ZipArchive instance
			$zip = new ZipArchive();
			if ( $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true ) {
				$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $media_dir ) );

				foreach ( $files as $file ) {
					if ( ! $file->isDir() ) {
						$zip->addFile( $file->getRealPath(), str_replace( $media_dir . '/', '', $file->getRealPath() ) );
					}
				}
				$zip->close();

				// Force download the ZIP file
				header( 'Content-Type: application/zip' );
				header( 'Content-disposition: attachment; filename=' . basename( $zip_file ) );
				header( 'Content-Length: ' . filesize( $zip_file ) );

				// Clear output buffer
				ob_clean();
				flush();

				readfile( $zip_file );

				// Delete the zip file from the server after download
				unlink( $zip_file );
				exit;
			} else {
				echo 'Failed to create ZIP file.';
			}
		}
	}

	public function add_admin_bar_button() {
		global $wp_admin_bar;
		$wp_admin_bar->add_menu( array(
			'id'    => 'blueprint-extractor',
			'title' => 'Blueprint',
			'href'  => admin_url( 'admin.php?page=blueprint' ),
		) );
	}
}

new BlueprintExtractor();
