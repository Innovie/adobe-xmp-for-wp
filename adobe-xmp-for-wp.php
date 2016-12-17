<?php
/*
 * Plugin Name: JSM's Adobe XMP / IPTC for WordPress
 * Text Domain: adobe-xmp-for-wp
 * Domain Path: /languages
 * Plugin URI: http://surniaulula.com/extend/plugins/adobe-xmp-for-wp/
 * Assets URI: https://jsmoriss.github.io/adobe-xmp-for-wp/assets/
 * Author: JS Morisset
 * Author URI: http://surniaulula.com/
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl.txt
 * Description: Read Adobe XMP / IPTC information from Media Library and NextGEN Gallery images, using a Shortcode or PHP Class Method.
 * Requires At Least: 3.7
 * Tested Up To: 4.7
 * Version: 1.3.1-1
 * 
 * Version Components: {major}.{minor}.{bugfix}-{stage}{level}
 *
 *	{major}		Major code changes / re-writes or significant feature changes.
 *	{minor}		New features / options were added or improved.
 *	{bugfix}	Bugfixes or minor improvements.
 *	{stage}{level}	dev < a (alpha) < b (beta) < rc (release candidate) < # (production).
 *
 * See PHP's version_compare() documentation at http://php.net/manual/en/function.version-compare.php.
 * 
 * This script is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 3 of the License, or (at your option) any later
 * version.
 * 
 * This script is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details at
 * http://www.gnu.org/licenses/.
 * 
 * Copyright 2012-2016 Jean-Sebastien Morisset (http://surniaulula.com/)
 */

if ( ! defined( 'ABSPATH' ) )
	die( 'Sorry, you cannot call this webpage directly.' );

if ( ! class_exists( 'adobeXMPforWP' ) ) {

	class adobeXMPforWP {

		public $use_cache = true;
		public $max_size = 512000;	// maximum size read
		public $chunk_size = 65536;	// read 64k at a time

		private $is_avail = array();	// assoc array for function/class/method checks
		private $cache_dir = '';
		private $cache_xmp = array();

		private static $instance = null;

		public static function &get_instance() {
			if ( self::$instance === null )
				self::$instance = new self;
			return self::$instance;
		}

		public function __construct() {
			add_action( 'init', array( &$this, 'init_plugin' ) );
			load_plugin_textdomain( 'adobe-xmp-for-wp', false, 'adobe-xmp-for-wp/languages/' );
		}

		public function init_plugin() {
			$this->is_avail = $this->get_avail();
			$this->cache_dir = trailingslashit( apply_filters( 'adobe_xmp_cache_dir', 
				dirname ( __FILE__ ).'/cache/' ) );
			require_once ( dirname ( __FILE__ ).'/lib/shortcode.php' );
		}

		public function get_avail() {
			return array(
				'ngg' => class_exists( 'nggdb' ) && 
					method_exists( 'nggdb', 'find_image' ) ? 
						true : false,
			);
		}

		public function get_xmp( $pid ) {
			if ( isset( $this->cache_xmp[$pid] ) )
				return $this->cache_xmp[$pid];
			if ( is_string( $pid ) && substr( $pid, 0, 4 ) == 'ngg-' )
				return $this->cache_xmp[$pid] = $this->get_ngg_xmp( substr( $pid, 4 ), false );
			else return $this->cache_xmp[$pid] = $this->get_media_xmp( $pid, false );
		}

		public function get_ngg_xmp( $pid ) {
			$xmp_arr = array();
			if ( ! empty( $this->is_avail['ngg'] ) ) {
				global $nggdb;
				$image = $nggdb->find_image( $pid );
				if ( ! empty( $image->imagePath ) ) {
					$xmp_raw = $this->get_xmp_raw( $image->imagePath );
					if ( ! empty( $xmp_raw ) ) 
						$xmp_arr = $this->get_xmp_array( $xmp_raw );
				}
			}
			return $xmp_arr;
		}

		public function get_media_xmp( $pid ) {
			$xmp_arr = array();
			if ( $filepath = get_attached_file( $pid ) ) {
				$xmp_raw = $this->get_xmp_raw( get_attached_file( $pid ) );
				if ( ! empty( $xmp_raw ) ) 
					$xmp_arr = $this->get_xmp_array( $xmp_raw );
			}
			return $xmp_arr;
		}

		public function get_xmp_raw( $filepath ) {

			$start_tag = '<x:xmpmeta';
			$end_tag = '</x:xmpmeta>';
			$cache_file = $this->cache_dir.md5( $filepath ).'.xml';
			$xmp_raw = null; 

			if ( $this->use_cache && 
				file_exists( $cache_file ) && 
				filemtime( $cache_file ) > filemtime( $filepath ) && 
				$cache_fh = fopen( $cache_file, 'rb' ) ) {

				$xmp_raw = fread( $cache_fh, filesize( $cache_file ) );
				fclose( $cache_fh );

			} elseif ( $file_fh = fopen( $filepath, 'rb' ) ) {

				$chunk = '';
				$file_size = filesize( $filepath );

				while ( ( $file_pos = ftell( $file_fh ) ) < $file_size  && $file_pos < $this->max_size ) {

					$chunk .= fread( $file_fh, $this->chunk_size );

					if ( ( $end_pos = strpos( $chunk, $end_tag ) ) !== false ) {

						if ( ( $start_pos = strpos( $chunk, $start_tag ) ) !== false ) {

							$xmp_raw = substr( $chunk, $start_pos, 
								$end_pos - $start_pos + strlen( $end_tag ) );

							if ( $this->use_cache && 
								$cache_fh = fopen( $cache_file, 'wb' ) ) {

								fwrite( $cache_fh, $xmp_raw );
								fclose( $cache_fh );
							}
						}
						break;	// stop reading after finding the xmp data
					}
				}
				fclose( $file_fh );
			}
			return $xmp_raw;
		}

		public function get_xmp_array( $xmp_raw ) {
			$xmp_arr = array();
			foreach ( array(
				'Creator Email'		=> '<Iptc4xmpCore:CreatorContactInfo[^>]+?CiEmailWork="([^"]*)"',
				'Owner Name'		=> '<rdf:Description[^>]+?aux:OwnerName="([^"]*)"',
				'Creation Date'		=> '<rdf:Description[^>]+?xmp:CreateDate="([^"]*)"',
				'Modification Date'	=> '<rdf:Description[^>]+?xmp:ModifyDate="([^"]*)"',
				'Label'			=> '<rdf:Description[^>]+?xmp:Label="([^"]*)"',
				'Credit'		=> '<rdf:Description[^>]+?photoshop:Credit="([^"]*)"',
				'Source'		=> '<rdf:Description[^>]+?photoshop:Source="([^"]*)"',
				'Headline'		=> '<rdf:Description[^>]+?photoshop:Headline="([^"]*)"',
				'City'			=> '<rdf:Description[^>]+?photoshop:City="([^"]*)"',
				'State'			=> '<rdf:Description[^>]+?photoshop:State="([^"]*)"',
				'Country'		=> '<rdf:Description[^>]+?photoshop:Country="([^"]*)"',
				'Country Code'		=> '<rdf:Description[^>]+?Iptc4xmpCore:CountryCode="([^"]*)"',
				'Location'		=> '<rdf:Description[^>]+?Iptc4xmpCore:Location="([^"]*)"',
				'Title'			=> '<dc:title>\s*<rdf:Alt>\s*(.*?)\s*<\/rdf:Alt>\s*<\/dc:title>',
				'Description'		=> '<dc:description>\s*<rdf:Alt>\s*(.*?)\s*<\/rdf:Alt>\s*<\/dc:description>',
				'Creator'		=> '<dc:creator>\s*<rdf:Seq>\s*(.*?)\s*<\/rdf:Seq>\s*<\/dc:creator>',
				'Keywords'		=> '<dc:subject>\s*<rdf:Bag>\s*(.*?)\s*<\/rdf:Bag>\s*<\/dc:subject>',
				'Hierarchical Keywords'	=> '<lr:hierarchicalSubject>\s*<rdf:Bag>\s*(.*?)\s*<\/rdf:Bag>\s*<\/lr:hierarchicalSubject>'
			) as $key => $regex ) {

				// get a single text string
				$xmp_arr[$key] = preg_match( "/$regex/is", $xmp_raw, $match ) ? $match[1] : '';

				// if string contains a list, then re-assign the variable as an array with the list elements
				$xmp_arr[$key] = preg_match_all( "/<rdf:li[^>]*>([^>]*)<\/rdf:li>/is", $xmp_arr[$key], $match ) ? $match[1] : $xmp_arr[$key];

				// hierarchical keywords need to be split into a third dimension
				if ( ! empty( $xmp_arr[$key] ) && $key == 'Hierarchical Keywords' ) {
					foreach ( $xmp_arr[$key] as $li => $val ) $xmp_arr[$key][$li] = explode( '|', $val );
					unset ( $li, $val );
				}
			}
			return $xmp_arr;
		}
	}

        global $adobeXMP;
	$adobeXMP =& adobeXMPforWP::get_instance();
}

?>