<?php

if ( ! class_exists( 'MUCD_Clone_DB' ) ) {

	class MUCD_Clone_DB {

		private static $to_site_id;
		private static $new_tables;

		/**
		 * Copy and Update tables from a site to another
		 * @since 0.2.0
		 * @param  int $from_site_id duplicated site id
		 * @param  int $to_site_id   new site id
		 */
		public static function copy_data( $from_site_id, $to_site_id = MUCD_PRIMARY_SITE_ID, $mod_save_options = false ) {

			self::$to_site_id = $to_site_id;

			// Copy
			$saved_options = self::db_copy_tables( $from_site_id, $to_site_id, $mod_save_options );

			// Update
			self::db_update_data( $from_site_id, $to_site_id, $saved_options );

		}

		/**
		 * Copy tables from a site to another
		 * @since 0.2.0
		 * @param  int $from_site_id duplicated site id
		 * @param  int $to_site_id   new site id
		 */
		public static function db_copy_tables( $from_site_id, $to_site_id, $mod_save_options = true ) {
			global $wpdb;

			// Source Site information
			$from_site_prefix = $wpdb->get_blog_prefix( $from_site_id );	// prefix
			$from_site_prefix_length = strlen( $from_site_prefix );			// prefix length
			$from_site_prefix_like = $wpdb->esc_like( $from_site_prefix );  // escape for SQL LIKE usage

			// Destination Site information
			$to_site_prefix = $wpdb->get_blog_prefix( $to_site_id );		// prefix
			$to_site_prefix_length = strlen( $to_site_prefix );				// prefix length
			$to_site_prefix_like = $wpdb->esc_like( $to_site_prefix );      // escape for SQL LIKE usage

			if( $mod_save_options ) {
				// Options that should be preserved in the new blog.
				$saved_options = MUCD_Option::get_saved_option();
				foreach ( $saved_options as $option_name => $option_value ) {
					$saved_options[ $option_name ] = get_blog_option( $to_site_id, $option_name );
				}
			}
			else {
				$saved_options = array();
			}			

			// SCHEMA - TO FIX for HyperDB
			$schema = DB_NAME;

			// Get sources Tables
			if ( $from_site_id == MUCD_PRIMARY_SITE_ID ) {
				$from_site_table = self::get_primary_tables( $from_site_prefix );
			}
			else {
				$sql_query = $wpdb->prepare( 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = \'%s\' AND TABLE_NAME LIKE \'%s\'', $schema, $from_site_prefix_like . '%' );
				$from_site_table = self::do_sql_query( $sql_query, 'col' );
			}

			self::$new_tables = array();

			foreach ( $from_site_table as $table ) {

				$table_shortname = substr( $table, $from_site_prefix_length );
				$table_name = $to_site_prefix . $table_shortname;
				self::$new_tables[$table_shortname] = array();

				// Drop table if exists
				self::do_sql_query( 'DROP TABLE IF EXISTS `' . $table_name . '`' );

				// Create new table from source table
				self::do_sql_query( 'CREATE TABLE IF NOT EXISTS `' . $table_name . '` LIKE `' . $schema . '`.`' . $table . '`' );

				// Populate database with data from source table
				self::do_sql_query( 'INSERT `' . $table_name . '` SELECT * FROM `' . $schema . '`.`' . $table . '`' );

			}

			self::db_restore_data( $to_site_id,  $saved_options );

		   	return $saved_options;
	   	}



		/**
		 * Get tables to copy if duplicated site is primary site
		 * @since 0.2.0
		 * @param  array of string $from_site_tables all tables of duplicated site
		 * @param  string $from_site_prefix db prefix of duplicated site
		 * @return array of strings : the tables
		 */
	   	public static function get_primary_tables( $from_site_prefix ) {

			$default_tables = MUCD_Option::get_primary_tables_to_copy();

			foreach ( $default_tables as $k => $default_table ) {
				$default_tables[ $k ] = $from_site_prefix . $default_table;
			}

			return $default_tables;
	   	}


		/**
		 * Updated tables from a site to another
		 * @since 0.2.0
		 * @param  int $from_site_id duplicated site id
		 * @param  int $to_site_id   new site id
		 */
		public static function db_update_data( $from_site_id, $to_site_id, $saved_options = array() ) {
			global $wpdb;

			// Looking for uploads dirs
			switch_to_blog( $from_site_id );
			$dir = wp_upload_dir();
			$from_upload_url = str_replace( network_site_url(), get_bloginfo( 'url' ) . '/', $dir['baseurl'] );
			$from_blog_url = get_blog_option( $from_site_id, 'siteurl' );
			$from_site_prefix = $wpdb->get_blog_prefix( $from_site_id );

			switch_to_blog( $to_site_id );
			$dir = wp_upload_dir();
			$to_upload_url = str_replace( network_site_url(), get_bloginfo( 'url' ) . '/', $dir['baseurl'] );
			$to_blog_url = get_blog_option( $to_site_id, 'siteurl' );
			$to_site_prefix = $wpdb->get_blog_prefix( $to_site_id );

			restore_current_blog();

			$tables = self::$new_tables;

			foreach ( $tables as $table => $col ) {
				$results = self::do_sql_query( 'SHOW COLUMNS FROM `' . $to_site_prefix . $table . '`', 'col', false );
				$columns = array();
				foreach ( $results as $k => $v ) {
					$columns[] = $v;
				}
				$tables[ $table ] = $columns;
			}

			$string_to_replace = array(
				$from_upload_url => $to_upload_url,
				$from_blog_url => $to_blog_url,
				$from_site_prefix => $to_site_prefix,
			);

			$string_to_replace = apply_filters( 'mucd_string_to_replace', $string_to_replace, $from_site_id, $to_site_id );

			foreach ( $tables as $table => $fields ) {
				foreach ( $string_to_replace as $from_string => $to_string ) {
					self::update( $to_site_prefix . $table, $fields, $from_string, $to_string );
				}
			}

			self::db_restore_data( $to_site_id,  $saved_options );
		}



		/**
		 * Restore options that should be preserved in the new blog
		 * @since 2.0.0.a.1
		 * @param  int $to_site_id   new site id
		 * @param  array $saved_options   the options
		 */
		public static function db_restore_data( $to_site_id, $saved_options ) {

			switch_to_blog( $to_site_id );

			foreach ( $saved_options as $option_name => $option_value ) {
				update_option( $option_name, $option_value );
			}

		   	restore_current_blog();
		}

		/**
		 * Updates a table
		 * @since 0.2.0
		 * @param  string $table to update
		 * @param  array of string $fields to update
		 * @param  string $from_string original string to replace
		 * @param  string $to_string new string
		 */
		public static function update( $table, $fields, $from_string, $to_string ) {

			if ( is_array( $fields ) || ! empty( $fields ) ) {
				global $wpdb;

				foreach ( $fields as $field ) {

					// Bugfix : escape '_' , '%' and '/' character for mysql 'like' queries
					$from_string_like = $wpdb->esc_like( $from_string );

					$sql_query = $wpdb->prepare( 'SELECT `' .$field. '` FROM `'.$table.'` WHERE `' .$field. '` LIKE "%s" ', '%' . $from_string_like . '%' );
					$results = self::do_sql_query( $sql_query, 'results', false );

					if ( $results ) {
						$update = 'UPDATE `'.$table.'` SET `'.$field.'` = "%s" WHERE `'.$field.'` = "%s"';

						foreach ( $results as $result => $row ) {
							$old_value = $row[ $field ];
							$new_value = self::try_replace( $row, $field, $from_string, $to_string );
							$sql_query = $wpdb->prepare( $update, $new_value, $old_value );
							$results = self::do_sql_query( $sql_query );
						}
					}
				}
			}
		}

		/**
		 * Replace $from_string with $to_string in $val
		 * Warning : if $to_string already in $val, no replacement is made
		 * @since 0.2.0
		 * @param  string $val
		 * @param  string $from_string
		 * @param  string $to_string
		 * @return string the new string
		 */
		public static function replace( $val, $from_string, $to_string ) {
			$new = $val;
			if ( is_string( $val ) ) {
				$pos = strpos( $val, $to_string );
				if ( false == $pos ) {
					$new = str_replace( $from_string, $to_string, $val );
				}
			}
			return $new;
		}

		/**
		 * Replace recursively $from_string with $to_string in $val
		 * @since 0.2.0
		 * @param  mixte (string|array) $val
		 * @param  string $from_string
		 * @param  string $to_string
		 * @return string the new string
		 */
		public static function replace_recursive( $val, $from_string, $to_string ) {
			$unset = array();
			if ( is_array( $val ) ) {
				foreach ( $val as $k => $v ) {
					$val[ $k ] = self::try_replace( $val, $k, $from_string, $to_string );
				}
			}
			else {
				$val = self::replace( $val, $from_string, $to_string );
			}

			foreach ( $unset as $k ) {
				unset( $val[ $k ] );
			}

			return $val;
		}

		/**
		 * Try to replace $from_string with $to_string in a row
		 * @since 0.2.0
		 * @param  array $row the row
		 * @param  array $field the field
		 * @param  string $from_string
		 * @param  string $to_string
		 * @return the new data
		 */
		function try_replace( $row, $field, $from_string, $to_string ) {
			if ( is_serialized( $row[ $field ] ) ) {
				$double_serialize = false;
				$row[ $field ] = @unserialize( $row[ $field ] );

				// FOR SERIALISED OPTIONS, like in wp_carousel plugin
				if ( is_serialized( $row[ $field ] ) ) {
					$row[ $field ] = @unserialize( $row[ $field ] );
					$double_serialize = true;
				}

				if ( is_array( $row[ $field ] ) ) {
					$row[ $field ] = self::replace_recursive( $row[ $field ], $from_string, $to_string );
				}
				else if ( is_object( $row[ $field ] ) || $row[ $field ] instanceof __PHP_Incomplete_Class ) { // Étrange fonctionnement avec Google Sitemap...
					$array_object = (array) $row[ $field ];
					$array_object = self::replace_recursive( $array_object, $from_string, $to_string );
					foreach ( $array_object as $key => $field ) {
						$row[ $field ]->$key = $field;
					}
				}
				else {
						$row[ $field ] = self::replace( $row[ $field ], $from_string, $to_string );
				}

				$row[ $field ] = serialize( $row[ $field ] );

				// Pour des options comme wp_carousel...
				if ( $double_serialize ) {
					$row[ $field ] = serialize( $row[ $field ] );
				}
			}
			else {
				if ( is_array( $row[ $field ] ) ) {
					$row[ $field ] = self::replace_recursive( $row[ $field ], $from_string, $to_string );
				}
				else {
					$row[ $field ] = self::replace( $row[ $field ], $from_string, $to_string );
				}
			}
			return $row[ $field ];
		}

		/**
		 * Runs a WPDB query
		 * @since 0.2.0
		 * @param  string  $sql_query the query
		 * @param  string  $type type of result
		 * @param  boolean $log log the query, or not
		 * @return $results of the query
		 */
		public static function do_sql_query( $sql_query, $type = '', $log = true ) {
			global $wpdb;
			//$wpdb->hide_errors();

			switch ( $type ) {
				case 'col':
					$results = $wpdb->get_col( $sql_query );
					break;
				case 'row':
					$results = $wpdb->get_row( $sql_query );
					break;
				case 'var':
					$results = $wpdb->get_var( $sql_query );
					break;
				case 'results':
					$results = $wpdb->get_results( $sql_query, ARRAY_A );
					break;
				default:
					$results = $wpdb->query( $sql_query );
					break;
			}

			if ( $log ) {
				MUCD_Log::write( 'SQL :' .$sql_query );
				MUCD_Log::write( 'Result :' . var_export( $results, true ) );
			}

			if ( $wpdb->last_error != '' ) {
				self::sql_error( $sql_query, $wpdb->last_error );
		   	}

			return $results;
		}

		/**
		 * Stop process on SQL Error, print and log error, removes the new blog
		 * @since 0.2.0
		 * @param  string  $sql_query the query
		 * @param  string  $sql_error the error
		 */
		public static function sql_error( $sql_query, $sql_error ) {
			$error_1 = 'ERROR SQL ON : ' . $sql_query;
			MUCD_Log::write( $error_1 );
			$error_2 = 'WPDB ERROR : ' . $sql_error;
			MUCD_Log::write( $error_2 );
			MUCD_Log::write( 'Duplication interrupted on SQL ERROR' );
			echo '<br />Duplication failed :<br /><br />' . $error_1 . '<br /><br />' . $error_2 . '<br /><br />';
			if ( $log_url = MUCD_Log::get_url() ) {
				echo '<a href="' . $log_url . '">' . __( 'View log', MUCD_DOMAIN ) . '</a>';
			}
			MUCD_Functions::remove_blog( self::$to_site_id );
			wp_die();
		}

	}
}
