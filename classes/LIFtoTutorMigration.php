<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LIFtoTutorMigration' ) ) {
	class LIFtoTutorMigration {

		public function __construct() {
			add_filter( 'tutor_tool_pages', array( $this, 'tutor_tool_pages' ) );

			add_action( 'wp_ajax_lif_migrate_all_data_to_tutor', array( $this, 'lif_migrate_all_data_to_tutor' ) );
			add_action( 'wp_ajax_tlmt_reset_migrated_items_count', array( $this, 'tlmt_reset_migrated_items_count' ) );

			add_action( 'wp_ajax__get_lif_live_progress_course_migrating_info', array( $this, '_get_lif_live_progress_course_migrating_info' ) );

			add_action( 'tutor_action_migrate_lif_orders', array( $this, 'migrate_lif_orders' ) );
			add_action( 'tutor_action_migrate_lif_reviews', array( $this, 'migrate_lif_reviews' ) );

			add_action( 'wp_ajax_tutor_import_from_xml', array( $this, 'tutor_import_from_xml' ) );
			add_action( 'tutor_action_tutor_lif_export_xml', array( $this, 'tutor_lif_export_xml' ) );
		}

		public function tutor_tool_pages( $pages ) {
			
			if ( defined( 'LLMS_VERSION' ) ) {
				$pages['migration_lif'] = array(
					'label'     => __( 'LifterLMS Migration', 'tutor' ),
					'slug'      => 'migration_lif',
					'desc'      => __( 'LifterLMS Migration', 'tutor' ),
					'template'  => 'migration_lifter',
					'view_path' => TLMT_PATH . 'views/',
					'icon'      => 'tutor-icon-brand-lifter',
					'blocks'    => array(
						'block' => array(),
					),
				);
			}

			return $pages;
		}

		/**
		 * Delete Item Count
		 */
		public function tlmt_reset_migrated_items_count() {
			delete_option( '_tutor_migrated_items_count' );
		}

		public function lif_migrate_all_data_to_tutor() {

			if ( isset( $_POST['migrate_type'] ) ) {
				$migrate_type = sanitize_text_field( $_POST['migrate_type'] );

				switch ( $migrate_type ) {
					case 'courses':
						$this->lif_migrate_course_to_tutor();
						break;
					case 'orders':
						$this->migrate_lif_orders();
						break;
					case 'reviews':
						$this->migrate_lif_reviews();
						break;
				}
				wp_send_json_success();
			}
			wp_send_json_error();
		}

		public function lif_migrate_course_to_tutor() {
			global $wpdb;

			$lif_courses = $wpdb->get_results( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'course';" );
			if ( tutils()->count( $lif_courses ) ) {
				$course_i = (int) get_option( '_tutor_migrated_items_count' );
				foreach ( $lif_courses as $lif_course ) {
					$course_i++;
					$this->migrate_course( $lif_course->ID );
					update_option( '_tutor_migrated_items_count', $course_i );
				}
			}
			wp_send_json_success();
		}

		/**
		 *
		 * Get Live Update about course migrating info
		 */

		public function _get_lif_live_progress_course_migrating_info() {
			$migrated_count = (int) get_option( '_tutor_migrated_items_count' );
			wp_send_json_success( array( 'migrated_count' => $migrated_count ) );
		}

		public function migrate_course( $course_id ) {
			global $wpdb;

			$course = llms_get_post( $course_id );
		
			if ( ! $course ) {
				return;
			}
		
		
			$course        = new LLMS_Course( $course_id );
			$sections      = $course->get_sections();

			//$curriculum = $course->get_lessons();
			
			$lesson_post_type = tutor()->lesson_post_type;
			$course_post_type = tutor()->course_post_type;

			$tutor_course = array();
			$i            = 0;
			if ( $sections ) {
				foreach ( $sections as $section ) {
					// die();
					$i++;
					/**
					 * @var \WP_Post $post
					 */
					
				
					
					$topic = array(
						'post_type'    => 'topics',
						'post_title'   => $section->post->post_title,
						'post_content' => $section->post->post_content,
						'post_status'  => 'publish',
						'post_author'  => $course->get_author,
						'post_parent'  => $course_id,
						'menu_order'   => $i,
						'items'        => array(),
					);

					
					$lessons = $section->get_lessons();
					
					foreach ($lessons as $lesson){
					$item_post_type = get_post_type( $lesson->id );
					
						// if ( $item_post_type !== 'lesson' ) {
							if ( $lesson->has_quiz() ) {
							// if ( $item_post_type === 'llms_quiz' ) {
								$lesson_post_type = 'tutor_quiz';

								$quiz = $lesson->get_quiz();
								$questions = $quiz->get_questions();
								
								
							//}
						}else{
							$lesson_post_type = tutor()->lesson_post_type;
						}
						$vd= $lesson->get_video();
						$tutor_lessons = array(
							'ID'          => $lesson->id,
							'post_type'   => $lesson_post_type,
							'post_title'  => $lesson->post->post_title,
							'post_content'=>$lesson->get_video(),
							'post_parent' => '{topic_id}',
						);

						$topic['items'][] = $tutor_lessons;
					}
					
					$tutor_course[] = $topic;
				}
				
			}

			if ( tutils()->count( $tutor_course ) ) {
				foreach ( $tutor_course as $course_topic ) {
					//var_dump($course_topic);
					// Remove items from this topic
					$lessons = $course_topic['items'];
					//$lessons = $section->get_lessons();

					unset( $course_topic['items'] );

					// Insert Topic post type
					$topic_id = wp_insert_post( $course_topic );

					// Update lesson from lifter to TutorLMS
					foreach ( $lessons as $lesson ) {
						//var_dump($lesson);
						if ( $lesson['post_type'] === 'tutor_quiz' ) {
							$quiz_id = tutils()->array_get( 'ID', $lesson );

							// $questions = $wpdb->get_results(
							// 	"SELECT q.ID question_id,q.menu_order question_order,q.post_title,q.post_content,
							// 	(SELECT qm.meta_value FROM {$wpdb->postmeta} qm WHERE qm.meta_key='_llms_question_type' AND qm.post_id=q.ID) question_type
							// 	FROM {$wpdb->posts} q 
							// 	LEFT JOIN {$wpdb->postmeta} pm on pm.post_id = q.ID
							// 	WHERE post_type ='llms_question' AND pm.meta_key='_llms_parent_id' AND pm.post_id={$quiz_id}   "
							// );
							
							
								
							if ( tutils()->count( $questions ) ) {
								foreach ( $questions as $question ) {
									$ques_id= $question->id;
									$meta_key = '_llms_question_type';
									//$ques_type_query = $wpdb->get_results("SELECT *  FROM {$wpdb->postmeta} pm WHERE pm.meta_key = '_llms_question_type' AND pm.post_id={$ques_id} ");
									
									$ques_type =  get_post_meta($ques_id, $meta_key, true);;
									$question_type = null;
									if ( $ques_type === 'true_false' ) {
										$question_type = 'true_false';
									}
									if ( $ques_type === 'choice' ) {
										$question_type = 'choice';
									}
									if ( $ques_type === 'multiple_choice' ) {
										$question_type = 'multi_choice';
									}

									if ( $question_type ) {

										$new_question_data = array(
											'quiz_id' => $quiz_id,
											'question_title' => $question->post->post_title,
											'question_description' => $question->post->post_content,
											'question_type' => $question_type,
											'question_mark' => $question->post->question_mark,
											'question_settings' => maybe_serialize( array() ),
											'question_order' => $question->post->menu_order,
										);
										

										$wpdb->insert( $wpdb->prefix . 'tutor_quiz_questions', $new_question_data );
										$question_id = $wpdb->insert_id;

										$answer_items = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}lifterlms_quiz_attempts where question_id ={$quiz_id}  " );

										if ( tutils()->count( $answer_items ) ) {
											foreach ( $answer_items as $answer_item ) {
												$answer_data = maybe_unserialize( $answer_item->answer_data );

												$answer_data = array(
													'belongs_question_id'   => $question_id,
													'belongs_question_type' => $question_type,
													'answer_title'          => tutils()->array_get( 'text', $answer_data ),
													'is_correct'            => tutils()->array_get( 'is_true', $answer_data ) == 'yes' ? 1 : 0,
													'answer_order'          => $answer_item->answer_order,
												);

												$wpdb->insert( $wpdb->prefix . 'tutor_quiz_question_answers', $answer_data );
											}
										}
									}
								}
							}
						}

						$lesson['post_parent'] = $topic_id;
						wp_update_post( $lesson );

						$lesson_id = tutils()->array_get( 'ID', $lesson );
						if ( $lesson_id ) {
							update_post_meta( $lesson_id, '_tutor_course_id_for_lesson', $course_id );
						}

						$_lif_preview = get_post_meta( $lesson_id, '_is_preview', true );
						if ( $_lif_preview === 'yes' ) {
							update_post_meta( $lesson_id, '_is_preview', 1 );
						} else {
							delete_post_meta( $lesson_id, '_is_preview' );
						}
					}
				}
			}

			// Migrate Course
			$tutor_course = array(
				'ID'        => $course_id,
				'post_type' => $course_post_type,
			);
			wp_update_post( $tutor_course );
			update_post_meta( $course_id, '_was_lif_course', true );

			/**
			 * Create WC Product and attaching it with course
			 */
			update_post_meta( $course_id, '_tutor_course_price_type', 'free' );
			$tutor_monetize_by = tutils()->get_option( 'monetize_by' );

			if ( tutils()->has_wc() && $tutor_monetize_by == 'wc' || $tutor_monetize_by == '-1' || $tutor_monetize_by == 'free' ) {

				$_llms_price      = get_post_meta( $course_id, '_llms_price', true );
				$_llms_sale_price = get_post_meta( $course_id, '_llms_sale_price', true );

				if ( $_llms_price ) {

					update_post_meta( $course_id, '_tutor_course_price_type', 'paid' );

					$product_id = wp_insert_post(
						array(
							'post_title'   => $course->get_title() . ' Product',
							'post_content' => '',
							'post_status'  => 'publish',
							'post_type'    => 'product',
						)
					);

					if ( $product_id ) {

						$product_metas = array(
							'_stock_status'      => 'instock',
							'total_sales'        => '0',
							'_regular_price'     => $_llms_price,
							'_sale_price'        => $_llms_sale_price,
							'_price'             => $_llms_price,
							'_sold_individually' => 'no',
							'_manage_stock'      => 'no',
							'_backorders'        => 'no',
							'_stock'             => '',
							'_virtual'           => 'yes',
							'_tutor_product'     => 'yes',
						);

						foreach ( $product_metas as $key => $value ) {
							update_post_meta( $product_id, $key, $value );
						}
					}

					/**
					 * Attaching product to course
					 */
					update_post_meta( $course_id, '_tutor_course_product_id', $product_id );
					$coursePostThumbnail = get_post_meta( $course_id, '_thumbnail_id', true );
					if ( $coursePostThumbnail ) {
						set_post_thumbnail( $product_id, $coursePostThumbnail );
					}
				} else {
					update_post_meta( $course_id, '_tutor_course_price_type', 'free' );
				}
			}

			/**
			 * Create EDD Product and linked with the course
			 */
			if ( tutils()->has_edd() && $tutor_monetize_by == 'edd' ) {
				$_llms_price      = get_post_meta( $course_id, '_llms_price', true );
				$_llms_sale_price = get_post_meta( $course_id, '_llms_sale_price', true );

				if ( $_llms_price ) {
					update_post_meta( $course_id, '_tutor_course_price_type', 'paid' );
					$product_id    = wp_insert_post(
						array(
							'post_title'   => $course->get_title() . ' Product',
							'post_content' => '',
							'post_status'  => 'publish',
							'post_type'    => 'download',
						)
					);
					$product_metas = array(
						'edd_price'                        => $_llms_price,
						'edd_variable_prices'              => array(),
						'edd_download_files'               => array(),
						'_edd_bundled_products'            => array( '0' ),
						'_edd_bundled_products_conditions' => array( 'all' ),
					);
					foreach ( $product_metas as $key => $value ) {
						update_post_meta( $product_id, $key, $value );
					}
					update_post_meta( $course_id, '_tutor_course_product_id', $product_id );
					$coursePostThumbnail = get_post_meta( $course_id, '_thumbnail_id', true );
					if ( $coursePostThumbnail ) {
						set_post_thumbnail( $product_id, $coursePostThumbnail );
					}
				} else {
					update_post_meta( $course_id, '_tutor_course_price_type', 'free' );
				}
			}

			/**
			 * Course Complete Status Migration
			 */

			$lif_course_complete_datas = $wpdb->get_results(
				"
				SELECT * FROM {$wpdb->prefix}lifterlms_user_postmeta lifuer 
			WHERE lifuer.post_id = {$course_id} AND lifuer.meta_key='_is_complete' AND lifuer.meta_value='yes'"
			);

			foreach ( $lif_course_complete_datas as $lif_course_complete_data ) {
				$user_id = $lif_course_complete_data->user_id;

				if ( ! tutils()->is_enrolled( $course_id, $user_id ) ) {

					$date = date( 'Y-m-d H:i:s', tutor_time() );

					do {
						$hash    = substr( md5( wp_generate_password( 32 ) . $date . $course_id . $user_id ), 0, 16 );
						$hasHash = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(comment_ID) from {$wpdb->comments}
								WHERE comment_agent = 'TutorLMSPlugin' AND comment_type = 'course_completed' AND comment_content = %s ",
								$hash
							)
						);

					} while ( $hasHash > 0 );

					$tutor_course_complete_data = array(
						'comment_type'     => 'course_completed',
						'comment_agent'    => 'TutorLMSPlugin',
						'comment_approved' => 'approved',
						'comment_content'  => $hash,
						'user_id'          => $user_id,
						'comment_author'   => $user_id,
						'comment_post_ID'  => $course_id,
					);

					$isEnrolled = wp_insert_comment( $tutor_course_complete_data );

				}
			}

			/**
			 * Enrollment Migration to this course
			 */
			$lif_enrollments = $wpdb->get_results(
				"SELECT * FROM {$wpdb->prefix}lifterlms_user_postmeta lifuer 
				WHERE lifuer.post_id = {$course_id} AND lifuer.meta_key='_is_complete' AND lifuer.meta_value='yes'"
			);

			foreach ( $lif_enrollments as $lif_enrollment ) {
				$user_id = $lif_enrollment->user_id;

				if ( ! tutils()->is_enrolled( $course_id, $user_id ) ) {
					$order_time = strtotime( $lif_enrollment->updated_date );

					$title                 = __( 'Course Enrolled', 'tutor' ) . ' &ndash; ' . date( get_option( 'date_format' ), $order_time ) . ' @ ' . date( get_option( 'time_format' ), $order_time );
					$tutor_enrollment_data = array(
						'post_type'   => 'tutor_enrolled',
						'post_title'  => $title,
						'post_status' => 'completed',
						'post_author' => $user_id,
						'post_parent' => $course_id,
					);

					$isEnrolled = wp_insert_post( $tutor_enrollment_data );

					if ( $isEnrolled ) {
						// Mark Current User as Students with user meta data
						update_user_meta( $user_id, '_is_tutor_student', $order_time );
					}
				}
			}
		}

		/*
		* Lifter LMS  order migrate to WC
		*/
		public function migrate_lif_orders() {
			global $wpdb;

			$lif_orders = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type = 'llms_order' AND post_status = 'llms-completed' " );

			$item_i = (int) get_option( '_tutor_migrated_items_count' );
			foreach ( $lif_orders as $lif_order ) {
				$item_i++;
				update_option( '_tutor_migrated_items_count', $item_i );

				$order_id           = $lif_order->ID;
				$migrate_order_data = array(
					'ID'          => $order_id,
					'post_status' => 'wc-completed',
					'post_type'   => 'shop_order',
				);

				wp_update_post( $migrate_order_data );

				$_items = $this->get_lif_order_items( $order_id );

				foreach ( $_items as $item ) {

					$item_data = array(
						'order_item_name' => $item->name,
						'order_item_type' => 'line_item',
						'order_id'        => $order_id,
					);

					$wpdb->insert( $wpdb->prefix . 'woocommerce_order_items', $item_data );
					$order_item_id = (int) $wpdb->insert_id;

					$lif_item_metas = $wpdb->get_results( "
					SELECT meta_key, meta_value 
						FROM {$wpdb->postmeta}
						WHERE meta_key in ('_llms_product_id','_llms_order_type','_llms_original_total','_llms_total')  AND post_id = {$item->id}
					" );

					$lif_formatted_metas = array();
					foreach ( $lif_item_metas as $item_meta ) {
						$lif_formatted_metas[ $item_meta->meta_key ] = $item_meta->meta_value;
					}

					$_course_id = tutils()->array_get( '_llms_product_id', $lif_formatted_metas );
					$_quantity  = tutils()->array_get( '_llms_order_type', $lif_formatted_metas );
					$_subtotal  = tutils()->array_get( '_llms_original_total', $lif_formatted_metas );
					$_total     = tutils()->array_get( '_llms_total', $lif_formatted_metas );

					$wc_item_metas = array(
						'_product_id'        => $_course_id,
						'_variation_id'      => 0,
						'_qty'               => $_quantity,
						'_tax_class'         => '',
						'_line_subtotal'     => $_subtotal,
						'_line_subtotal_tax' => 0,
						'_line_total'        => $_total,
						'_line_tax'          => 0,
						'_line_tax_data'     => maybe_serialize(
							array(
								'total'    => array(),
								'subtotal' => array(),
							)
						),
					);

					foreach ( $wc_item_metas as $wc_item_meta_key => $wc_item_meta_value ) {
						$wc_item_metas = array(
							'order_item_id' => $order_item_id,
							'meta_key'      => $wc_item_meta_key,
							'meta_value'    => $wc_item_meta_value,
						);
						$wpdb->insert( $wpdb->prefix . 'woocommerce_order_itemmeta', $wc_item_metas );
					}
				}

				update_post_meta( $order_id, '_customer_user', get_post_meta( $order_id, '_user_id', true ) );
				update_post_meta( $order_id, '_customer_ip_address', get_post_meta( $order_id, '_user_ip_address', true ) );
				update_post_meta( $order_id, '_customer_user_agent', get_post_meta( $order_id, '_user_agent', true ) );

				$user_email = $wpdb->get_var( "SELECT user_email from {$wpdb->users} WHERE ID = {$lif_order->post_author} " );
				update_post_meta( $order_id, '_billing_address_index', $user_email );
				update_post_meta( $order_id, '_billing_email', $user_email );
			}

		}

		/*
		* Lifter Review migrate to Tutor
		*/
		public function migrate_lif_reviews() {
			global $wpdb;

			$lif_review_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='llms_review';" );

			if ( tutils()->count( $lif_review_ids ) ) {
				$item_i = (int) get_option( '_tutor_migrated_items_count' );
				foreach ( $lif_review_ids as $lif_review_id ) {
					$item_i++;
					update_option( '_tutor_migrated_items_count', $item_i );

					$review_migrate_data = array(
						'comment_approved' => 'approved',
						'comment_type'     => 'tutor_course_rating',
						'comment_agent'    => 'TutorLMSPlugin',
					);

					$wpdb->update( $wpdb->comments, $review_migrate_data, array( 'comment_ID' => $lif_review_id ) );
					$wpdb->update(
						$wpdb->commentmeta,
						array( 'meta_key' => 'tutor_rating' ),
						array(
							'comment_id' => $lif_review_id,
							'meta_key'   => '_lif_rating',
						)
					);
				}
			}

		}


		public function get_lif_order_items( $order_id ) {
			global $wpdb;

			$query = $wpdb->prepare(
				"
				SELECT orders.id as order_id, 
				(SELECT meta_value as course_id FROM $wpdb->postmeta WHERE post_id=orders.id AND meta_key='_llms_product_id') as course_id,
				(SELECT meta_value as course_id FROM $wpdb->postmeta WHERE post_id=orders.id AND meta_key='_llms_product_title') as course_title
				FROM $wpdb->posts as orders
				WHERE orders.post_type='llms_order' AND id=%d ",
				$order_id
			);

			return $wpdb->get_results( $query );
		}


		/**
		 *
		 * Import From XML
		 */
		public function tutor_import_from_xml() {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );
			$error = true;
			if ( isset( $_FILES['tutor_import_file'] ) ) {
				$course_post_type = tutor()->course_post_type;

				$xmlContent = file_get_contents( $_FILES['tutor_import_file']['tmp_name'] );
				libxml_use_internal_errors( true );
				$replacer   = array(
					'&'                => '&amp;',
					' allowfullscreen' => ' allowfullscreen="allowfullscreen"', // don't remove space
					' disabled'        => ' disabled="disabled"',
				);
				$xmlContent = str_replace( array_keys( $replacer ), array_values( $replacer ), $xmlContent );
				$xml_data   = simplexml_load_string( $xmlContent, null, LIBXML_NOCDATA );
				if ( $xml_data == false ) {
					$errors        = libxml_get_errors();
					$error_message = '';
					if ( is_array( $errors ) ) {
						$error_message = $errors[0]->message . 'on line number ' . $errors[0]->line;
					}
					wp_send_json(
						array(
							'success' => false,
							'message' => $error_message,
						)
					);
				}

				$xml_data = simplexml_load_string( $xmlContent );
				if ( $xml_data == false ) {
					wp_send_json(
						array(
							'success' => false,
							'message' => 'Migration not successfull',
						)
					);
				}
				$courses = $xml_data->courses;
				if ( $courses == false ) {
					wp_send_json(
						array(
							'success' => false,
							'message' => 'Migration not successfull',
						)
					);
				}
				foreach ( $courses as $course ) {

					$course_data = array(
						'post_author'   => (string) $course->post_author,
						'post_date'     => (string) $course->post_date,
						'post_date_gmt' => (string) $course->post_date_gmt,
						'post_content'  => (string) $course->post_content,
						'post_title'    => (string) $course->post_title,
						'post_status'   => 'publish',
						'post_type'     => $course_post_type,
					);

					// Inserting Course
					$course_id = wp_insert_post( $course_data );

					$course_meta = json_decode( json_encode( $course->course_meta ), true );
					foreach ( $course_meta as $course_meta_key => $course_meta_value ) {
						if ( is_array( $course_meta_value ) ) {
							$course_meta_value = json_encode( $course_meta_value );
						}
						if ( $course_meta_key == '_thumbnail_id' ) {
							$thumbnail_post = $wpdb->get_results(
								$wpdb->prepare(
									"SELECT  * FROM {$wpdb->posts}
									WHERE `ID` = %d AND `post_type` = %s
									LIMIT %d",
									$course_meta_value,
									'attachment',
									1
								)
							);
							if ( count( $thumbnail_post ) ) {
								$wpdb->insert(
									$wpdb->postmeta,
									array(
										'post_id'    => $course_id,
										'meta_key'   => $course_meta_key,
										'meta_value' => $course_meta_value,
									)
								);
							}
						} else {
							$wpdb->insert(
								$wpdb->postmeta,
								array(
									'post_id'    => $course_id,
									'meta_key'   => $course_meta_key,
									'meta_value' => $course_meta_value,
								)
							);
						}
					}

					foreach ( $course->topics as $topic ) {
						$topic_data = array(
							'post_type'    => 'topics',
							'post_title'   => (string) $topic->post_title,
							'post_content' => (string) $topic->post_content,
							'post_status'  => 'publish',
							'post_author'  => (string) $topic->post_author,
							'post_parent'  => $course_id,
							'menu_order'   => (string) $topic->menu_order,
						);

						// Inserting Topics
						$topic_id = wp_insert_post( $topic_data );

						$item_i = 0;
						foreach ( $topic->items as $item ) {
							$item_i++;

							$item_data = array(
								'post_type'    => (string) $item->post_type,
								'post_title'   => (string) $item->post_title,
								'post_content' => (string) $item->post_content,
								'post_status'  => 'publish',
								'post_author'  => (string) $item->post_author,
								'post_parent'  => $topic_id,
								'menu_order'   => $item_i,
							);

							$item_id = wp_insert_post( $item_data );

							$item_metas = json_decode( json_encode( $item->item_meta ), true );
							foreach ( $item_metas as $item_meta_key => $item_meta_value ) {
								if ( is_array( $item_meta_value ) ) {
									$item_meta_value = json_encode( $item_meta_value );
								}
								$wpdb->insert(
									$wpdb->postmeta,
									array(
										'post_id'    => $item_id,
										'meta_key'   => $item_meta_key,
										'meta_value' => (string) $item_meta_value,
									)
								);
							}

							if ( isset( $item->questions ) && is_object( $item->questions ) && count( $item->questions ) ) {
								foreach ( $item->questions as $question ) {
									$answers = $question->answers;

									$question                         = (array) $question;
									$question['quiz_id']              = $item_id;
									$question['question_description'] = (string) $question['question_description'];
									unset( $question['answers'] );

									$wpdb->insert( $wpdb->prefix . 'tutor_quiz_questions', $question );
									$question_id = $wpdb->insert_id;

									foreach ( $answers as $answer ) {
										$answer                        = (array) $answer;
										$answer['belongs_question_id'] = $question_id;
										$wpdb->insert( $wpdb->prefix . 'tutor_quiz_question_answers', $answer );
									}
								}
							}
						}
					}

					if ( isset( $course->reviews ) && is_object( $course->reviews ) && count( $course->reviews ) ) {
						foreach ( $course->reviews as $review ) {
							$rating_data = array(
								'comment_post_ID'  => $course_id,
								'comment_approved' => 'approved',
								'comment_type'     => 'tutor_course_rating',
								'comment_date'     => (string) $review->comment_date,
								'comment_date_gmt' => (string) $review->comment_date,
								'comment_content'  => (string) $review->comment_content,
								'user_id'          => (string) $review->user_id,
								'comment_author'   => (string) $review->comment_author,
								'comment_agent'    => 'TutorLMSPlugin',
							);

							$wpdb->insert( $wpdb->comments, $rating_data );
							$comment_id = (int) $wpdb->insert_id;

							$rating_meta_data = array(
								'comment_id' => $comment_id,
								'meta_key'   => 'tutor_rating',
								'meta_value' => (string) $review->tutor_rating,
							);
							$wpdb->insert( $wpdb->commentmeta, $rating_meta_data );
						}
					}
				}
				$error = false;
			}
			if ( $error ) {
				$wpdb->query( 'ROLLBACK' );
				wp_send_json(
					array(
						'success' => false,
						'message' => 'LIF Migration not successfull',
					)
				);
			} else {
				$wpdb->query( 'COMMIT' );
				wp_send_json(
					array(
						'success' => true,
						'message' => 'LIF Migration successfull',
					)
				);
			}
		}


		public function tutor_lif_export_xml() {
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename=lifter_data_for_tutor.xml' );
			header( 'Expires: 0' );

			echo $this->generate_xml_data();
			exit;
		}


		public function generate_xml_data() {
			global $wpdb;

			$xml  = '<?xml version="1.0" encoding="' . get_bloginfo( 'charset' ) . "\" ?>\n";
			$xml .= $this->start_element( 'channel' );
			ob_start();
			?>
				<title><?php bloginfo_rss( 'name' ); ?></title>
				<link><?php bloginfo_rss( 'url' ); ?></link>
				<description><?php bloginfo_rss( 'description' ); ?></description>
				<pubDate><?php echo date( 'D, d M Y H:i:s +0000' ); ?></pubDate>
				<language><?php bloginfo_rss( 'language' ); ?></language>
				<tlmt_version><?php echo TLMT_VERSION; ?></tlmt_version>
				<?php
				$xml .= ob_get_clean();

				$lif_courses = $wpdb->get_results( "SELECT ID, post_author, post_date, post_content, post_title, post_excerpt, post_status  FROM {$wpdb->posts} WHERE post_type = 'course' AND post_status = 'publish';" );

				if ( tutils()->count( $lif_courses ) ) {
					$course_i = 0;
					foreach ( $lif_courses as $lif_course ) {
						$course_i++;

						$course_id = $lif_course->ID;

						$xml .= $this->start_element( 'courses' );

						$course_arr = (array) $lif_course;
						foreach ( $course_arr as $course_col => $course_col_value ) {
							$xml .= "<{$course_col}>{$course_col_value}</{$course_col}>\n";
						}

						$course_metas = $wpdb->get_results( "SELECT meta_key, meta_value from {$wpdb->postmeta} where post_id = {$course_id}" );

						$xml .= $this->start_element( 'course_meta' );
						foreach ( $course_metas as $course_meta ) {
							$xml .= "<{$course_meta->meta_key}>{$course_meta->meta_value}</{$course_meta->meta_key}>\n";
						}
						$xml .= $this->close_element( 'course_meta' );

						$course = llms_get_post( $course_id );

						$lesson_post_type = tutor()->lesson_post_type;
						$course_post_type = tutor()->course_post_type;

						$lif_reviews = $wpdb->get_results(
							"SELECT 
								ID,
								post_author,
								post_date,
								post_date_gmt,
								post_content,
								post_author,
								post_content as tutor_rating
                   
							FROM {$wpdb->posts} WHERE post_type = 'llms_review';",
							ARRAY_A
						);

						if ( tutils()->count( $lif_reviews ) ) {
							foreach ( $lif_reviews as $lif_review ) {
								$lif_review['comment_approved'] = 'approved';
								$lif_review['comment_agent']    = 'TutorLMSPlugin';
								$lif_review['comment_type']     = 'tutor_course_rating';

								$xml .= $this->start_element( 'reviews' );
								foreach ( $lif_review as $lif_review_key => $lif_review_value ) {
									$xml .= "<{$lif_review_key}>{$this->xml_cdata($lif_review_value)}</{$lif_review_key}>\n";
								}
								$xml .= $this->close_element( 'reviews' );
							}
						}

						$xml .= $this->close_element( 'courses' );
					}
				}

				$xml .= $this->close_element( 'channel' );
				return $xml;
		}

		public function start_element( $element = '' ) {
			return "\n<{$element}>\n";
		}
		public function close_element( $element = '' ) {
			return "\n</{$element}>\n";
		}

		function xml_cdata( $str ) {
			if ( ! seems_utf8( $str ) ) {
				$str = utf8_encode( $str );
			}
			$str = '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $str ) . ']]>';

			return $str;
		}


	}
}