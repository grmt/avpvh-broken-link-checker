<?php
/**
 * Utility class for printing the link listing table.
 *
 * @package Broken Link Checker
 * @access public
 */

if ( ! class_exists( 'Avpvh_Lnkc_Table_Printer' ) ) {

	class Avpvh_Lnkc_Table_Printer {

		var $current_filter;       //The current search filter. Also contains the list of links to display.
		var $page;                 //The current page number
		var $per_page;             //Max links per page
		/** @var Avpvh_Lnkc_Link_Checker */
		var $core;                 //A reference to the main plugin object
		var $neutral_current_url;  //The "safe" version of the current URL, for use in the bulk action form.

		var $bulk_actions_html  = '';
		var $pagination_html    = '';
		var $searched_link_type = '';

		var $columns;
		var $layouts;


		function __construct( $core ) {
			$this->core = $core;

			// Initialize layout and column definitions,
			$this->setup_columns();
			$this->setup_layouts();

			// Figure out what the "safe" URL to acccess the current page would be.
			// This is used by the bulk action form.
			$special_args              = array( '_wpnonce', '_wp_http_referer', 'action', 'selected_links' );
			$this->neutral_current_url = remove_query_arg( $special_args );

			// Register the filter for action links in the constructor so it's applied early,
			// allowing us to remove the default "Edit" link before it gets generated.
			add_filter( 'avpvh_lnkc_container_action_links', array( self::class, 'filter_action_links' ), 15, 2 );
		}

		/**
		 * Filter action links for the container.
		 *
		 * @param string[] $action_links The existing action links.
		 * @param Avpvh_Lnkc_Link  $link         The link object.
		 * @return string[]              The modified action links.
		 */
		public static function filter_action_links( $action_links, $link ) {
			if ( is_null( $link ) || ! $link instanceof Avpvh_Lnkc_Link ) {
				return $action_links;
			}

			$instances = $link->get_instances();
			if ( empty( $instances ) ) {
				return $action_links;
			}

			if ( 'custom_taxonomy' === $instances[0]->container_type ) {
				// Remove the default "Edit" link since we'll be adding a custom one in ui_get_action_links() that points to the post edit screen instead of the taxonomy edit screen.
				unset( $action_links['edit'] );
			}

			return $action_links;
		}

		/**
		 * Print the entire link table and associated navigation elements.
		 *
		 * @param array $current_filter
		 * @param string $layout
		 * @param array $visible_columns
		 * @param bool $compact
		 * @return void
		 */
		function print_table( $current_filter, $layout = 'flexible', $visible_columns = null, $compact = false ) {
			$this->current_filter = $current_filter;
			$this->page           = $current_filter['page'];
			$this->per_page       = $current_filter['per_page'];

			$current_layout = $this->layouts[ $layout ];
			if ( empty( $visible_columns ) ) {
				$visible_columns = $current_layout;
			}
			// Only allow columns actually present in this layout.
			$visible_columns = array_intersect( $visible_columns, $current_layout );

			echo '<form id="blc-bulk-action-form" action="' . esc_attr( $this->neutral_current_url ) . '" method="post">';
			wp_nonce_field( 'bulk-action' );

			// Top navigation.
			$this->prepare_nav_html();
			$this->navigation( $compact );

			// Table header.
			$table_classes = array( 'widefat' );
			if ( $compact ) {
				$table_classes[] = 'compact';
			};
			if ( $this->core->conf->options['table_color_code_status'] ) {
				$table_classes[] = 'color-code-link-status';
			};
			$table_classes[] = 'base-filter-' . esc_html( $current_filter['base_filter'] );
			printf(
				'<table class="%s" id="blc-links" aria-label="%s"><thead><tr>',
				esc_attr( implode( ' ', $table_classes ) ),
				esc_attr__( 'Broken links', 'avpvh-link-checker' )
			);

			// The select-all checkbox.
			echo '<th scope="col" class="column-checkbox check-column" id="cb"><input type="checkbox" aria-label="' . esc_attr__( 'Select all links', 'avpvh-link-checker' ) . '" /></th>';

			// Column headers.
			foreach ( $current_layout as $column_id ) {
				$column = $this->columns[ $column_id ];

				$column_classes = array( 'column-' . $column_id );
				if ( isset( $column['class'] ) ) {
					$column_classes[] = $column['class'];
				}
				if ( ! in_array( $column_id, $visible_columns ) ) {
					$column_classes[] = 'hidden';
				}

				$heading = $column['heading'];
				if ( isset( $column['sortable'] ) && $column['sortable'] ) {
					$orderby         = $column['orderby'];
					$current_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
					$current_order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc';
					if ( ! in_array( $current_order, array( 'asc', 'desc' ) ) ) {
						$current_order = 'asc';
					}

					if ( $orderby == $current_orderby ) {
						$column_classes[] = 'sorted';
						$column_classes[] = $current_order;
						$order            = ( 'asc' == $current_order ) ? 'desc' : 'asc';
						$aria_sort        = 'asc' === $current_order ? 'ascending' : 'descending';
					} else {
						$order            = 'asc';
						$column_classes[] = 'desc';
						$column_classes[] = 'sortable';
						$aria_sort        = 'none';
					}

					$heading = sprintf(
						'<a href="%s"><span>%s</span><span class="sorting-indicator"></span></a>',
						esc_attr(
							add_query_arg(
								array(
									'orderby' => $orderby,
									'order'   => $order,
								)
							)
						),
						$heading
					);
				} else {
					$aria_sort = null;
				}

				printf(
					'<th scope="col" class="%s"%s%s>%s</th>',
					esc_attr( implode( ' ', $column_classes ) ),
					isset( $column['id'] ) ? ' id="' . esc_attr( $column['id'] ) . '"' : '',
					$aria_sort ? ' aria-sort="' . esc_attr( $aria_sort ) . '"' : '',
					wp_kses_post( $heading )
				);
			}
			echo '</tr></thead>';

			// Table body.
			echo '<tbody id="the-list">';
			$this->bulk_edit_form( $visible_columns );
			$rownum = 0;
			foreach ( $this->current_filter['links'] as $link ) {
				$rownum++;
				$this->link_row( $link, $current_layout, $visible_columns, $rownum );
				$this->link_details_row( $link, $visible_columns, $rownum );
			}
			echo '</tbody></table>';

			// Bottom navigation.
			$this->navigation( $compact, '2' );
			echo '</form>';

			// Inline editor (hidden by default, JS will move it to the right place).
			$this->inline_editor( $visible_columns );
		}

		/**
		 * Print the "Bulk Actions" dropdown and navigation links
		 *
		 * @param bool $table_compact Whether to use the full or compact view.
		 * @param string $suffix Optional. Appended to ID and name attributes of the bulk action dropdown.
		 * @return void
		 */
		function navigation( $table_compact = false, $suffix = '' ) {
			// Display the "Bulk Actions" dropdown.
			echo '<div class="tablenav">
					<div class="alignleft actions">
						<select name="action' . esc_attr( $suffix ) . '" id="blc-bulk-action' . esc_attr( $suffix ) . '" aria-label="' . esc_attr__( 'Bulk actions', 'avpvh-link-checker' ) . '">' .
							wp_kses( $this->bulk_actions_html, array( 'option' => array( 'value' => true ) ) ) .
						'</select>
					<input type="submit" name="doaction' . esc_attr( $suffix ) . '" id="doaction' . esc_attr( $suffix ) . '" value="',
						esc_attr( __( 'Apply', 'avpvh-link-checker' ) ) .
						'" class="button-secondary action">
					</div>';

			// Display pagination links.
			if ( ! empty( $this->pagination_html ) ) {
				echo wp_kses_post( $this->pagination_html );
			}

			// Display the view switch (only in the top nav. area).
			if ( empty( $suffix ) ) {
				?>

			<div class="view-switch">
				<a
					href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'compact', '1' ), 'blc-toggle-compact' ) ); ?>"
					class="view-list <?php echo $table_compact ? 'current' : ''; ?>"
					title="<?php echo esc_attr( __( 'Compact View', 'avpvh-link-checker' ) ); ?>"
					aria-label="<?php echo esc_attr( __( 'Compact View', 'avpvh-link-checker' ) ); ?>">
				</a>
				<a
					href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'compact', '0' ), 'blc-toggle-compact' ) ); ?>"
					class="view-excerpt <?php echo ! $table_compact ? 'current' : ''; ?>"
					title="<?php echo esc_attr( __( 'Detailed View', 'avpvh-link-checker' ) ); ?>"
					aria-label="<?php echo esc_attr( __( 'Detailed View', 'avpvh-link-checker' ) ); ?>">
				</a>
			</div>

				<?php
			}

			echo '</div>';
		}

		/**
		* Initialize the internal list of available table columns.
		*
		* @return void
		*/
		function setup_columns() {
			$this->columns = array(
				'status'        => array(
					'heading'  => __( 'Status', 'avpvh-link-checker' ),
					'content'  => array( $this, 'column_status' ),
					'sortable' => true,
					'orderby'  => 'status',
				),

				'new-url'       => array(
					'heading'  => __( 'URL', 'avpvh-link-checker' ),
					'content'  => array( $this, 'column_new_url' ),
					'sortable' => true,
					'orderby'  => 'url',
				),

				'used-in'       => array(
					'heading' => __( 'Source', 'avpvh-link-checker' ),
					'class'   => 'column-title',
					'content' => array( $this, 'column_used_in' ),
				),

				'new-link-text' => array(
					'heading'  => __( 'Link Text', 'avpvh-link-checker' ),
					'content'  => array( $this, 'column_new_link_text' ),
					'sortable' => true,
					'orderby'  => 'link_text',
				),

				'redirect-url'  => array(
					'heading'  => __( 'Redirect URL', 'avpvh-link-checker' ),
					'content'  => array( $this, 'column_redirect_url' ),
					'sortable' => true,
					'orderby'  => 'redirect_url',
				),
			);
		}

		/**
		* Initialize the list of available layouts
		*
		* @return void
		*/
		function setup_layouts() {
			$this->layouts = array(
				'classic'  => array( 'used-in', 'new-link-text', 'new-url' ),
				'flexible' => array( 'new-url', 'status', 'new-link-text', 'redirect-url', 'used-in' ),
			);
		}

		/**
		* Get a list of columns available in a specific table layout.
		*
		* @param string $layout Layout ID.
		* @return array Associative array of column data indexed by column ID.
		*/
		function get_layout_columns( $layout ) {
			if ( isset( $this->layouts[ $layout ] ) ) {

				$result = array();
				foreach ( $this->layouts[ $layout ] as $column_id ) {
					if ( isset( $this->columns[ $column_id ] ) ) {
						$result[ $column_id ] = $this->columns[ $column_id ];
					}
				}
				return $result;

			} else {
				return null;
			}
		}

		/**
		* Pre-generate some HTML fragments used for both the top and bottom navigation/bulk action boxes.
		*
		* @return void
		*/
		function prepare_nav_html() {
			//Generate an <option> element for each possible bulk action. The list doesn't change,
			//so we can do it once and reuse the generated HTML.
			$bulk_actions = array(
				'-1'              => __( 'Bulk Actions', 'avpvh-link-checker' ),
				'bulk-edit'       => __( 'Edit URL', 'avpvh-link-checker' ),
				'bulk-recheck'    => __( 'Recheck', 'avpvh-link-checker' ),
				'bulk-deredirect' => __( 'Fix redirects', 'avpvh-link-checker' ),
				'bulk-not-broken' => __( 'Mark as not broken', 'avpvh-link-checker' ),
				'bulk-dismiss'    => __( 'Dismiss', 'avpvh-link-checker' ),
				'bulk-unlink'     => __( 'Unlink', 'avpvh-link-checker' ),
			);
			if ( EMPTY_TRASH_DAYS ) {
				$bulk_actions['bulk-trash-sources'] = __( 'Move sources to Trash', 'avpvh-link-checker' );
			} else {
				$bulk_actions['bulk-delete-sources'] = __( 'Delete sources', 'avpvh-link-checker' );
			}

			$bulk_actions_html = '';
			foreach ( $bulk_actions as $value => $name ) {
				$bulk_actions_html .= sprintf( '<option value="%s">%s</option>', esc_attr( $value ), esc_html( $name ) );
			}

			$this->bulk_actions_html = $bulk_actions_html;

			// Pagination links can also be pre-generated.
			// WP has a built-in function for pagination :).
			$page_links = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => __( '&laquo;', 'avpvh-link-checker' ),
					'next_text' => __( '&raquo;', 'avpvh-link-checker' ),
					'total'     => $this->current_filter['max_pages'],
					'current'   => $this->page,
				)
			);

			if ( $page_links ) {
				$this->pagination_html  = '<div class="tablenav-pages">';
				$this->pagination_html .= sprintf(
					'<span class="displaying-num">' . __( 'Displaying %1$s&#8211;%2$s of <span class="current-link-count">%3$s</span>', 'avpvh-link-checker' ) . '</span>%4$s',
					number_format_i18n( ( $this->page - 1 ) * $this->per_page + 1 ),
					number_format_i18n( min( $this->page * $this->per_page, $this->current_filter['count'] ) ),
					number_format_i18n( $this->current_filter['count'] ),
					$page_links
				);
				$this->pagination_html .= '</div>';
			} else {
				$this->pagination_html = '';
			}
		}

		/**
		* Print the bulk edit form.
		*
		* @param array $visible_columns List of visible columns.
		* @return void
		*/
		function bulk_edit_form( $visible_columns ) {
			?>
			<tr id="bulk-edit" class="inline-edit-rows"><td colspan="<?php echo count( $visible_columns ) + 1; ?>">
			<div id="bulk-edit-wrap">
			<fieldset>
				<h4><?php esc_html_e( 'Bulk Edit URLs', 'avpvh-link-checker' ); ?></h4>
				<label>
					<span class="title"><?php esc_html_e( 'Find', 'avpvh-link-checker' ); ?></span>
					<input type="text" name="search" class="text">
				</label>
				<label>
					<span class="title"><?php esc_html_e( 'Replace with', 'avpvh-link-checker' ); ?></span>
					<input type="text" name="replace" class="text">
				</label>

				<div id="bulk-edit-options">
					<span class="title">&nbsp;</span>
					<label>
						<input type="checkbox" name="case_sensitive">
						<?php esc_html_e( 'Case sensitive', 'avpvh-link-checker' ); ?>
					</label>
					<label>
						<input type="checkbox" name="regex">
						<?php esc_html_e( 'Regular expression', 'avpvh-link-checker' ); ?>
					</label>
				</div>
			</fieldset>

			<p class="submit inline-edit-save">
				<a href="#bulk-edit" class="button-secondary cancel alignleft" title="<?php echo esc_attr( __( 'Cancel', 'avpvh-link-checker' ) ); ?>" accesskey="c"><?php esc_html_e( 'Cancel', 'avpvh-link-checker' ); ?></a>
				<input type="submit" name="bulk_edit" class="button-primary alignright" value="
				<?php
					esc_html_e( 'Update', 'avpvh-link-checker' );
				?>
				" accesskey="s">
			</p>
			</div>
			</td></tr>
			<?php
		}

		/**
		* Print the link row.
		*
		* @param Avpvh_Lnkc_Link $link The link to display.
		* @param array $layout List of columns to output.
		* @param array $visible_columns List of visible columns.
		* @param integer $rownum Table row number.
		* @return void
		*/
		function link_row( $link, $layout, $visible_columns, $rownum = 0 ) {

			//Figure out what CSS classes the link row should have
			$rowclass = ( $rownum % 2 ) ? 'alternate' : '';

			$excluded = $this->core->is_excluded( $link->url );
			if ( $excluded ) {
				$rowclass .= ' blc-excluded-link';
			}

			if ( $link->redirect_count > 0 ) {
				$rowclass .= ' blc-redirect';
			}

			$days_broken = 0;
			if ( $link->broken ) {
				// Add a highlight to broken links that appear to be permanently broken.
				$days_broken = intval( ( time() - $link->first_failure ) / ( 3600 * 24 ) );
				if ( $days_broken >= $this->core->conf->options['failure_duration_threshold'] ) {
					$rowclass .= ' blc-permanently-broken';
					if ( $this->core->conf->options['highlight_permanent_failures'] ) {
						$rowclass .= ' blc-permanently-broken-hl';
					}
				}
			}

			$status    = $link->analyse_status();
			$rowclass .= ' link-status-' . $status['code'];

			// Retrieve link instances to display in the table.
			$instances = $link->get_instances();

			if ( ! empty( $instances ) ) {
				// Put instances that match the selected link type at the top. Makes search results look better.
				if ( ! empty( $this->current_filter['search_params']['s_link_type'] ) ) {
					$s_link_type = $this->current_filter['search_params']['s_link_type'];
				} else {
					$s_link_type = '';
				}
				$instances = $this->sort_instances_for_display( $instances, $s_link_type );
			}

			// For inline editing, we'll need to know if any instances have editable link text, and what it is.
			$can_edit_text       = false;
			$can_edit_url        = false;
			$editable_link_texts = array();
			$non_editable_link_texts = array();

			foreach ( $instances as $instance ) {
				if ( $instance->is_link_text_editable() ) {
					$can_edit_text                               = true;
					$editable_link_texts[ $instance->link_text ] = true;
				} else {
					$non_editable_link_texts[ $instance->link_text ] = true;
				}

				if ( $instance->is_url_editable() ) {
					$can_edit_url = true;
				}
			}

			$link_texts     = $can_edit_text ? $editable_link_texts : $non_editable_link_texts;
			$link_text = null;
			if ( 1 === count( $link_texts ) ) {
				// All instances have the same text - use it.
				$link_text = (string) key( $link_texts );
			}

			printf(
				'<tr id="blc-row-%s" class="blc-row %s" data-days-broken="%d" data-can-edit-url="%d" data-can-edit-text="%d"%s>',
				(int) $link->link_id,
				esc_attr( $rowclass ),
				(int) $days_broken,
				$can_edit_url ? 1 : 0,
				$can_edit_text ? 1 : 0,
				null === $link_text ? '' : ' data-link-text="' . esc_attr( $link_text ) . '"'
			);

			// The checkbox used to select links is automatically printed in all layouts
			// and can't be disabled. Without it, bulk actions wouldn't work.
			$this->column_checkbox( $link );

			foreach ( $layout as $column_id ) {
				$column = $this->columns[ $column_id ];

				printf(
					'<td class="column-%s%s">',
					esc_attr( $column_id ),
					in_array( $column_id, $visible_columns ) ? '' : ' hidden'
				);

				if ( isset( $column['content'] ) ) {
					if ( is_callable( $column['content'] ) ) {
						call_user_func( $column['content'], $link, $instances );
					} else {
						echo wp_kses_post( $column['content'] );
					}
				} else {
					echo '[', esc_html( $column_id ), ']';
				}

				echo '</td>';
			}

			echo '</tr>';
		}

		/**
		* Print the details row for a specific link.
		*
		* @uses Avpvh_Lnkc_Table_Printer::details_row_contents()
		*
		* @param Avpvh_Lnkc_Link $link The link to display.
		* @param array $visible_columns List of visible columns.
		* @param integer $rownum Table row number.
		* @return void
		*/
		function link_details_row( $link, $visible_columns, $rownum = 0 ) {
			printf(
				'<tr id="link-details-%d" class="blc-link-details"><td colspan="%d">',
				(int) $link->link_id,
				count( $visible_columns ) + 1
			);
			$this->details_row_contents( $link );
			echo '</td></tr>';
		}

		/**
		* Print the contents of the details row for a specific link.
		*
		* @param Avpvh_Lnkc_Link $link
		* @return void
		*/
		public static function details_row_contents( $link ) {
			?>
			<div class="blc-detail-container">
				<div class="blc-detail-block" style="float: left; width: 49%;">
					<ol style='list-style-type: none;'>
					<?php if ( ! empty( $link->post_date ) ) { ?>
					<li><strong><?php esc_html_e( 'Post published on', 'avpvh-link-checker' ); ?>:</strong>
					<span class='post_date'>
						<?php
						echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $link->post_date ) ) );
						?>
					</span></li>
					<?php } ?>
					<li><strong><?php esc_html_e( 'Link last checked', 'avpvh-link-checker' ); ?>:</strong>
					<span class='check_date'>
					<?php
						$last_check = $link->last_check;
					if ( $last_check < strtotime( '-10 years' ) ) {
						esc_html_e( 'Never', 'avpvh-link-checker' );
					} else {
						printf(
							'<time datetime="%s">%s</time>',
							esc_attr( date( 'c', $last_check ) ), //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
							esc_html( date_i18n( get_option( 'date_format' ), $last_check ) )
						);
					}
					?>
					</span></li>

					<li><strong><?php esc_html_e( 'HTTP code', 'avpvh-link-checker' ); ?>:</strong>
					<span class='http_code'>
					<?php
						print (int) $link->http_code;
					?>
					</span></li>

					<li><strong><?php esc_html_e( 'Response time', 'avpvh-link-checker' ); ?>:</strong>
					<span class='request_duration'>
					<?php
						printf( esc_html__( '%2.3f seconds', 'avpvh-link-checker' ), (float) $link->request_duration );
					?>
					</span></li>

					<li><strong><?php esc_html_e( 'Final URL', 'avpvh-link-checker' ); ?>:</strong>
					<span class='final_url'>
					<?php
						print esc_html( $link->final_url );
					?>
					</span></li>

					<li><strong><?php esc_html_e( 'Redirect count', 'avpvh-link-checker' ); ?>:</strong>
					<span class='redirect_count'>
					<?php
						print (int) $link->redirect_count;
					?>
					</span></li>

					<li><strong><?php esc_html_e( 'Instance count', 'avpvh-link-checker' ); ?>:</strong>
					<span class='instance_count'>
					<?php
						print count( $link->get_instances() );
					?>
					</span></li>

					<?php if ( ( $link->broken || $link->warning ) && ( intval( $link->check_count ) > 0 ) ) { ?>
					<li><br/>
						<?php
						printf(
							esc_html( _n( 'This link has failed %d time.', 'This link has failed %d times.', $link->check_count, 'avpvh-link-checker' ) ),
							(int) $link->check_count
						);

						echo '<br>';

						$delta = time() - $link->first_failure;
						printf(
							esc_html__( 'This link has been broken for %s.', 'avpvh-link-checker' ),
							esc_html( Avpvh_Lnkc_Utility::fuzzy_delta( $delta ) )
						);
						?>
					</li>
					<?php } ?>
					</ol>
				</div>

				<div class="blc-detail-block" style="float: right; width: 50%;">
					<ol style='list-style-type: none;'>
						<li><strong><?php esc_html_e( 'Log', 'avpvh-link-checker' ); ?>:</strong>
					<span class='avpvh_lnkc_log'>
					<?php
						// The log is stored as pre-escaped HTML: checkers escape untrusted data
						// before it is appended. Sanitise rather than esc_html() here so that content
						// escaped at write time is not double-escaped, while neutralising any unescaped
						// payload already stored by an earlier version.
						print nl2br(
							wp_kses( $link->log, array( 'em' => array(), 'strong' => array() ) )
						);
					?>
					</span></li>
					</ol>
				</div>

				<div style="clear:both;"> </div>
			</div>
			<?php
		}

		function column_checkbox( $link ) {
			?>
			<th scope="row" class="check-column"><input type="checkbox" name="selected_links[]" value="<?php echo (int) $link->link_id; ?>" aria-label="<?php printf( esc_attr__( 'Select link %s', 'avpvh-link-checker' ), esc_attr( $link->url ) ); ?>" /></th>
			<?php
		}

		/**
		* @param Avpvh_Lnkc_Link $link
		* @param Avpvh_Lnkc_Link_Instance[] $instances
		*/
		function column_status( $link, $instances ) {
			printf(
				'<table class="mini-status" aria-label="%s">',
				esc_attr( __( 'Show more info about this link', 'avpvh-link-checker' ) )
			);

			$status = $link->analyse_status();

			printf(
				'<tr class="link-status-row link-status-%s">
					<td>
						<span class="http-code">%s</span> <span class="status-text">%s</span>
					</td>
				</tr>',
				esc_attr( $status['code'] ),
				empty( $link->http_code ) ? '' : (int) $link->http_code,
				esc_html( $status['text'] )
			);

			// Last checked...
			if ( 0 != $link->last_check ) {
				$last_check  = _x( 'Checked', 'checked how long ago', 'avpvh-link-checker' ) . ' ';
				$last_check .= Avpvh_Lnkc_Utility::fuzzy_delta( time() - $link->last_check, 'ago' );

				printf(
					'<tr class="link-last-checked"><td>%s</td></tr>',
					esc_html( $last_check )
				);
			}

			// Broken for...
			if ( $link->broken ) {
				$delta      = time() - $link->first_failure;
				$broken_for = Avpvh_Lnkc_Utility::fuzzy_delta( $delta );
				printf(
					'<tr class="link-broken-for"><td>%s %s</td></tr>',
					esc_html__( 'Broken for', 'avpvh-link-checker' ),
					esc_html( $broken_for )
				);
			}

			echo '</table>';

			// "Details" link.
			echo '<div class="row-actions">';
			printf(
				'<span><a href="#" class="blc-details-button" title="%s">%s</a></span>',
				esc_attr( __( 'Show more info about this link', 'avpvh-link-checker' ) ),
				esc_html_x( 'Details', 'link in the "Status" column', 'avpvh-link-checker' )
			);
			echo '</div>';
		}


		/**
		* @param Avpvh_Lnkc_Link $link
		*/
		function column_new_url( $link ) {
			?>
			<a href="<?php print esc_url( $link->url ); ?>"
			target='_blank'
			class='blc-link-url'
			title="<?php echo esc_attr( $link->url ); ?>"
			data-editable-url="<?php echo esc_attr( $link->url ); ?>">
				<?php print esc_html( $link->url ); ?></a>
			<?php
			// Output inline action links for the link/URL.
			$actions = array();

			$actions['edit'] = "<a href='javascript:void(0)' class='blc-edit-button' title='" . esc_attr( __( 'Edit this link', 'avpvh-link-checker' ) ) . "'>" . esc_html__( 'Edit URL', 'avpvh-link-checker' ) . '</a>';

			$actions['delete'] = "<a class='submitdelete blc-unlink-button' title='" . esc_attr( __( 'Remove this link from all posts', 'avpvh-link-checker' ) ) . "' " .
				"href='javascript:void(0);'>" . esc_html__( 'Unlink', 'avpvh-link-checker' ) . '</a>';

			if ( $link->broken || $link->warning ) {
				$actions['blc-discard-action'] = sprintf(
					'<a href="#" title="%s" class="blc-discard-button">%s</a>',
					esc_attr( __( 'Remove this link from the list of broken links and mark it as valid', 'avpvh-link-checker' ) ),
					__( 'Not broken', 'avpvh-link-checker' )
				);
			}

			if ( ! $link->dismissed && ( $link->broken || $link->warning || ( $link->redirect_count > 0 ) ) ) {
				$actions['blc-dismiss-action'] = sprintf(
					'<a href="#" title="%s" class="blc-dismiss-button">%s</a>',
					esc_attr( __( 'Hide this link and do not report it again unless its status changes', 'avpvh-link-checker' ) ),
					__( 'Dismiss', 'avpvh-link-checker' )
				);
			} elseif ( $link->dismissed ) {
				$actions['blc-undismiss-action'] = sprintf(
					'<a href="#" title="%s" class="blc-undismiss-button">%s</a>',
					esc_attr( __( 'Undismiss this link', 'avpvh-link-checker' ) ),
					__( 'Undismiss', 'avpvh-link-checker' )
				);
			}

			$actions['blc-recheck-action'] = sprintf(
				'<a href="#" class="blc-recheck-button">%s</a>',
				__( 'Recheck', 'avpvh-link-checker' )
			);

			if ( $link->redirect_count > 0 && ! empty( $link->final_url ) && ( $link->url != $link->final_url ) ) {
				//TODO: Check if at least one instance has an editable URL. Otherwise this won't work.
				$actions['blc-deredirect-action'] = sprintf(
					'<a href="#" class="blc-deredirect-button" title="%s">%s</a>',
					__( 'Replace this redirect with a direct link', 'avpvh-link-checker' ),
					_x( 'Fix redirect', 'link action; replace one redirect with a direct link', 'avpvh-link-checker' )
				);
			}

			// Only show the enabled actions.
			$conf = avpvh_lnkc_get_configuration();
			foreach ( $conf->get( 'show_link_actions', $actions ) as $name => $enabled ) {
				if ( ! $enabled ) {
					unset( $actions[ $name ] );
				}
			}

			$actions = apply_filters( 'avpvh_lnkc_container_action_links', $actions, $link );

			// Wrap actions with <span></span> and separate them with | characters.
			// Basically, this emulates the HTML structure that WP uses for post actions under Posts -> All Posts.
			$spans           = array();
			$is_first_action = true;
			foreach ( $actions as $name => $html ) {
				$spans[]         = sprintf(
					'<span class="%s">%s%s</span>',
					esc_attr( $name ),
					$is_first_action ? '' : ' | ',
					$html
				);
				$is_first_action = false;
			}

			echo '<div class="row-actions">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action links are built with escaped parts by the containers; some carry onclick handlers wp_kses() would strip.
			echo implode( '', $spans );
			echo '</div>';

			?>
			<div class="blc-url-editor-buttons">
				<input type="button" class="button-secondary cancel alignleft blc-cancel-button" value="<?php echo esc_attr( __( 'Cancel', 'avpvh-link-checker' ) ); ?>" />
				<input type="button" class="button-primary save alignright blc-update-url-button" value="<?php echo esc_attr( __( 'Update URL', 'avpvh-link-checker' ) ); ?>" />
				<img class="waiting" style="display:none;" src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" />
			</div>
			<?php
		}

		/**
		* @param Avpvh_Lnkc_Link $link
		* @param Avpvh_Lnkc_Link_Instance[] $instances
		*/
		function column_used_in( $link, $instances ) {
			echo '<span class="blc-link-id" style="display:none;">',
					(int) $link->link_id,
				'</span>';

			if ( ! empty( $instances ) ) {
				/** @var $instance Avpvh_Lnkc_Link_Instance */
				$instance = reset( $instances );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the container escapes the source's title and URL.
				echo $instance->ui_get_source();

				$actions = $instance->ui_get_action_links();

				echo '<div class="row-actions">';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action links are built with escaped parts by the containers; some carry onclick handlers wp_kses() would strip.
				echo implode( ' | </span>', $actions );
				echo '</div>';

			} else {
				esc_html_e( '[An orphaned link! This is a bug.]', 'avpvh-link-checker' );
			}
		}

		/**
		* @param Avpvh_Lnkc_Link $link
		* @param Avpvh_Lnkc_Link_Instance[] $instances
		*/
		function column_new_link_text( $link, $instances ) {
			if ( empty( $instances ) ) {
				echo '<em>N/A</em>';
			} else {
				$instance = reset( $instances ); /** @var Avpvh_Lnkc_Link_Instance $instance */
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parsers escape the link text they return.
				echo $instance->ui_get_link_text();
			}
		}

		function column_redirect_url( $link, $instances ) {
			if ( $link->redirect_count > 0 ) {
				// esc_attr() encodes the delimiters but permits any scheme; an href needs esc_url().
				printf(
					'<a href="%1$s" target="_blank" class="blc-redirect-url" title="%2$s">%3$s</a>',
					esc_url( $link->final_url ),
					esc_attr( $link->final_url ),
					esc_html( $link->final_url )
				);
			}
		}

		/**
		* Sort a list of link instances to be displayed in the "Broken Links" page.
		*
		* Groups instances by container type and, if $search_link_type is specified,
		* puts instances that have a matching container type or parser type at the
		* beginning.
		*
		* @param array $instances An array of Avpvh_Lnkc_Link_Instance objects.
		* @param string $searched_link_type Optional. The required container/parser type.
		* @return array Sorted array.
		*/
		function sort_instances_for_display( $instances, $searched_link_type = '' ) {
			$this->searched_link_type = $searched_link_type;
			usort( $instances, array( $this, 'compare_link_instances' ) );
			return $instances;
		}

		/**
		* Callback function for sorting link instances.
		*
		* @see Avpvh_Lnkc_Table_Printer::sort_instances_for_display()
		*
		* @param Avpvh_Lnkc_Link_Instance $a
		* @param Avpvh_Lnkc_Link_Instance $b
		* @return int
		*/
		function compare_link_instances( $a, $b ) {
			if ( ! empty( $this->searched_link_type ) ) {
				if ( ( $a->container_type == $this->searched_link_type ) || ( $a->parser_type == $this->searched_link_type ) ) {
					if ( ( $b->container_type == $this->searched_link_type ) || ( $b->parser_type == $this->searched_link_type ) ) {
						return 0;
					} else {
						return -1;
					}
				} else {
					if ( ( $b->container_type == $this->searched_link_type ) || ( $b->parser_type == $this->searched_link_type ) ) {
						return 1;
					}
				}
			}

			return strcmp( $a->container_type, $b->container_type );
		}

		protected function inline_editor( $visible_columns ) {
			?>
			<table style="display: none;"><tbody>
				<tr id="blc-inline-edit-row" class="blc-inline-editor">
					<td class="blc-colspan-change" colspan="<?php echo count( $visible_columns ); ?>">
						<div class="blc-inline-editor-content">
							<h4><?php echo esc_html_x( 'Edit Link', 'inline editor title', 'avpvh-link-checker' ); ?></h4>

							<label>
								<span class="title"><?php echo esc_html_x( 'Text', 'inline link editor', 'avpvh-link-checker' ); ?></span>
								<span class="blc-input-text-wrap"><input type="text" name="link_text" value="" class="blc-link-text-field" /></span>
							</label>

							<label>
								<span class="title"><?php echo esc_html_x( 'URL', 'inline link editor', 'avpvh-link-checker' ); ?></span>
								<span class="blc-input-text-wrap"><input type="text" name="link_url" value="" class="blc-link-url-field" /></span>
							</label>

							<div class="blc-url-replacement-suggestions" style="display: none;">
								<h4><?php echo esc_html_x( 'Suggestions', 'inline link editor', 'avpvh-link-checker' ); ?></h4>
								<ul class="blc-suggestion-list">
									<li>...</li>
								</ul>
							</div>

							<div class="submit blc-inline-editor-buttons">
								<input type="button" class="button-secondary cancel alignleft blc-cancel-button" value="<?php echo esc_attr( __( 'Cancel', 'avpvh-link-checker' ) ); ?>" />
								<input type="button" class="button-primary save alignright blc-update-link-button" value="<?php echo esc_attr( __( 'Update', 'avpvh-link-checker' ) ); ?>" />

								<img class="waiting" style="display:none;" src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" />
								<div class="clear"></div>
							</div>
						</div>
					</td>
				</tr>
			</tbody></table>

			<ul id="blc-suggestion-template" style="display: none;">
				<li>
					<input type="button" class="button-secondary blc-use-url-button" value="<?php echo esc_attr( __( 'Use this URL', 'avpvh-link-checker' ) ); ?>" />

					<div class="blc-suggestion-details">
						<span class="blc-suggestion-name">
							<a href="http://example.com/" target="_blank">Suggestion name</a>
						</span>
						<code class="blc-suggestion-url">suggestion URL</code>
					</div>
				</li>
			</ul>
			<?php
		}

	}

} // class_exists.
