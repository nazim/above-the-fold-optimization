<?php

/**
 * Build Tool admin controller
 *
 * @since      2.6.0
 * @package    abovethefold
 * @subpackage abovethefold/admin
 * @author     PageSpeed.pro <info@pagespeed.pro>
 */

class Abovethefold_Admin_BuildTool { 

	/**
	 * Above the fold controller
	 */
	public $CTRL;

	/**
	 * Options
	 */
	public $options;

	/**
	 * Initialize the class and set its properties.
	 */
	public function __construct( &$CTRL ) {

		$this->CTRL =& $CTRL;
		$this->options =& $CTRL->options;

		/**
		 * Admin panel specific
		 */
		if (is_admin()) {

			/**
			 * Handle form submissions
			 */
			$this->CTRL->loader->add_action('admin_post_abtf_create_critical_package', $this,  'create_critical_package');
			//$this->CTRL->loader->add_action('admin_init', $this, 'create_critical_package');

		}

	}

    /**
	 * Create critical package
	 */
	public function create_critical_package() {

		check_admin_referer('abovethefold');

		// @link https://codex.wordpress.org/Function_Reference/stripslashes_deep
		$_POST = array_map( 'stripslashes_deep', $_POST );

		$options = get_option('abovethefold');
		if (!is_array($options)) { $options = array(); }

		// download package
		if (isset($_POST['download_package'])) {

			$this->download_package();
			exit;
		}

		// install package
		if (isset($_POST['install_package'])) {

			$this->install_package();
			exit;
		}

		$url = (isset($_POST['url'])) ? trim($_POST['url']) : '';
		$taskname = (isset($_POST['taskname'])) ? trim($_POST['taskname']) : '';
		$dimensions = (isset($_POST['dimensions'])) ? $this->CTRL->admin->newline_array(trim($_POST['dimensions'])) : '';
		$extra = (isset($_POST['extra']) && intval($_POST['extra']) === 1) ? true : false;
		$update = (isset($_POST['update']) && trim($_POST['update']) !== '') ? trim($_POST['update']) : false;

		if ($url === '') {
			$this->CTRL->admin->set_notice('You did not select a page.', 'ERROR');
			wp_redirect( add_query_arg( array( 'page' => 'abovethefold', 'tab' => 'build-tool','taskname' => $taskname, 'dimensions' => $dimensions, 'extra' => $extra, 'update' => $update ), admin_url( 'admin.php' ) ) );
			exit;
		}
		if ($taskname === '' || !preg_match('|^critical-[a-z0-9\-]+$|Ui',$taskname)) {
			$this->CTRL->admin->set_notice('You did not enter a valid task name.', 'ERROR');
			wp_redirect( add_query_arg( array( 'page' => 'abovethefold', 'tab' => 'build-tool','taskname' => $taskname, 'dimensions' => $dimensions, 'extra' => $extra, 'update' => $update ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$originalDimensions = $dimensions;
		if (!empty($dimensions)) {
			foreach ($dimensions as $n => $dim) {
				$dimparts = explode('x',$dim);
				if (count($dimparts) !== 2 || !is_numeric($dimparts[0]) || !is_numeric($dimparts[1]) || intval($dimparts[0]) <= 0 || intval($dimparts[1]) <= 0) {
					$this->CTRL->admin->set_notice('Dimension <strong>'.htmlentities($dim,ENT_COMPAT,'utf-8').'</strong> is not valid.', 'ERROR');
					wp_redirect( add_query_arg( array( 'page' => 'abovethefold', 'tab' => 'build-tool','taskname' => $taskname, 'dimensions' => $dimensions, 'extra' => $extra, 'update' => $update ), admin_url( 'admin.php' ) ) );
					exit;			
				}
				$dimensions[$n] = $dimparts;
			}
		}

		// Update
		if ($update) {
			if ($update === 'global') {

				// update global critical CSS
			} else {

				$conditionalcss_enabled = (isset($options['conditionalcss_enabled']) && intval($options['conditionalcss_enabled']) === 1) ? true : false;

				// conditional critical CSS
				if ($conditionalcss_enabled && !empty($options['conditional_css']) && isset($options['conditional_css'][$update])) {
					$key = $update;
					$update = $options['conditional_css'][$update];
					$update['key'] = $key;
				} else {
					$update = false;
				}
			}
		}

		// get page JSON
		$pagejson = $this->get_page_json($url);
		if (!$pagejson) {
			wp_die('Failed to retrieve page JSON for critical CSS generator.');
		}

		$settings = array(
			'dimensions' => $dimensions,
			'extra' => $extra,
			'update' => $update
		);

		// Update default build tool settings
		

		$default = get_option('abtf-build-tool-default');
		if (!is_array($default)) { $default = array(); }

		$default['taskname'] = $taskname;
		$default['url'] = $url;
		$default['dimensions'] = $originalDimensions;
		$default['extra'] = $extra;
		$default['update'] = $update;

		// update settings
		update_option('abtf-build-tool-default', $default, false);

		// download
		if (isset($_POST['download'])) {

			$this->download_critical_task_package($pagejson, $url, $taskname, $settings);
			exit;
		}

		/**
		 * Initialize
		 */
		$gulpdir = get_stylesheet_directory() . '/abovethefold/';
		if (!is_dir($gulpdir)) {
			if (!@mkdir( $gulpdir, $this->CTRL->CHMOD_DIR )) {
				wp_die( 'Failed to create ' . $gulpdir );
			}
		}

		$gulptaskdir = $gulpdir . $taskname . '/';

		if (is_dir($gulptaskdir)) {

			// remove existing
			function __rmdir_recursive($dir,$delete=true) {
				$files = array_diff(scandir($dir), array('.','..')); 
				foreach ($files as $file) { 
					(is_dir("$dir/$file")) ? __rmdir_recursive("$dir/$file") : @unlink("$dir/$file"); 
				} 
				return ($delete) ? @rmdir($dir) : false; 
			}
			__rmdir_recursive($gulptaskdir,false);
		}

		if (!is_dir($gulptaskdir)) {
			if (!@mkdir( $gulptaskdir, $this->CTRL->CHMOD_DIR )) {
				wp_die( 'Failed to create ' . $gulptaskdir );
			}
		}

		// css dir
		if (!is_dir($gulptaskdir . 'css/')) {
			if (!@mkdir( $gulptaskdir . 'css/', $this->CTRL->CHMOD_DIR )) {
				wp_die( 'Failed to create ' . $gulptaskdir . 'css/' );
			}
		}

		// copy package.json if it does not exist
		if (!file_exists($gulpdir . 'package.json')) {
			copy( WPABTF_PATH . 'modules/critical-css-build-tool/package.json', $gulpdir . 'package.json' );
			chmod( $gulpdir . 'package.json', $this->CTRL->CHMOD_FILE );
		}
		// copy gulpfile.js if it does not exist
		if (!file_exists($gulpdir . 'gulpfile.js')) {
			copy( WPABTF_PATH . 'modules/critical-css-build-tool/gulpfile.js', $gulpdir . 'gulpfile.js' );
			chmod( $gulpdir . 'gulpfile.js', $this->CTRL->CHMOD_FILE );
		}

		// add html file
		file_put_contents($gulptaskdir . '/page.html',$pagejson['html']);
		chmod( $gulptaskdir . '/page.html', $this->CTRL->CHMOD_FILE );

		$fullcss = '';
		$taskjs_cssfiles = array();

		# add css files
		foreach($pagejson['css'] as $file){

		    #add it to the zip
			file_put_contents($gulptaskdir . '/css/' . $file['file'],$file['code']);
			chmod( $gulptaskdir . '/css/' . $file['file'], $this->CTRL->CHMOD_FILE );

		    $fullcss .= $file['code'];

		    $taskjs_cssfiles[] = 'TASKPATH/css/' . $file['file'];
		}

		// add full css file
		file_put_contents($gulptaskdir . '/full.css',$fullcss);
		chmod( $gulptaskdir . '/full.css', $this->CTRL->CHMOD_FILE );

		// add extra css file
		if ($extra) {
			file_put_contents($gulptaskdir . '/extra.css','/** 
 * Use this file to append extra CSS to critical.css. 
 * This CSS code is not processed by the Critical CSS generator to enable correction of the output of the Critical CSS generator.
 */');
			chmod( $gulptaskdir . '/extra.css', $this->CTRL->CHMOD_FILE );
		}

		/**
		 * Create gulp-critical-task.js
		 */
		$taskjs = false;
		include (WPABTF_PATH . 'modules/critical-css-build-tool/gulp-critical-task.php');
		if (empty($taskjs)) {
			wp_die('Failed to load gulp-critical.task.js');
		}

		// add full css file
		file_put_contents($gulptaskdir . '/gulp-critical-task.js',$taskjs);
		chmod( $gulptaskdir . '/gulp-critical-task.js', $this->CTRL->CHMOD_FILE );

		$gulp_installed = is_dir($gulpdir . 'node_modules/');

		// add notice
		$this->CTRL->admin->set_notice('<div style="font-size:18px;line-height:20px;margin:0px;">The package has been installed in <strong>'.str_replace(home_url(),'',get_stylesheet_directory_uri()).'abovethefold/</strong>
		<br /><br />
		Run <code>gulp '.$taskname.'</code> to generate critical CSS.
		<br /><br />
		<textarea class="abtfcmd" onfocus="jQuery(this).select();">cd '.trailingslashit(get_stylesheet_directory()).'abovethefold/;' . "\n" . ((!$gulp_installed) ? 'npm install; ' : '') . 'gulp '.$taskname.'</textarea></div>', 'NOTICE');

		wp_redirect( add_query_arg( array( 'page' => 'abovethefold', 'tab' => 'build-tool' ), admin_url( 'admin.php' ) ) );
		exit;
    }

    /**
     * Download critical package zip
     */
    public function download_critical_task_package($pagejson, $url, $taskname, $settings) {

    	// ZipArchive requires PHP v5.2+
    	if (!version_compare(PHP_VERSION, '5.2.0', '>=')) {
			wp_die('Creating zipfiles requires PHP v5.2+.');
		}

		/**
		 * Create zip object
		 */
		$zip = new ZipArchive();

		# create a temp file & open it
		$tmp_file = tempnam('.','');
		if ($zip->open($tmp_file, ZipArchive::CREATE) !== true) {
			wp_die('Failed to create zip archive. Please check PHP ZipArchive permissions.');
		}

		// add html file
		$zip->addFromString($taskname . '/page.html',$pagejson['html']);

		$fullcss = '';
		$taskjs_cssfiles = array();

		# add css files
		foreach($pagejson['css'] as $file){

		    #add it to the zip
		    $zip->addFromString($taskname . '/css/' . $file['file'],$file['code']);
		    $fullcss .= $file['code'];

		    $taskjs_cssfiles[] = 'TASKPATH/css/' . $file['file'];
		}

		// add full css file
		$zip->addFromString($taskname . '/full.css',$fullcss);

		if ($settings['extra']) {

			// add extra css file
			$zip->addFromString($taskname . '/extra.css','/** 
 * Use this file to append extra CSS to critical.css. 
 * This CSS code is not processed by the Critical CSS generator to enable correction of the output of the Critical CSS generator.
 */');
		}

		/**
		 * Create gulp-critical-task.js
		 */
		$taskjs = false;
		include (WPABTF_PATH . 'modules/critical-css-build-tool/gulp-critical-task.php');
		if (empty($taskjs)) {
			wp_die('Failed to load gulp-critical.task.js');
		}

		// add full css file
		$zip->addFromString($taskname . '/gulp-critical-task.js',$taskjs);

		// add package.json
		$data = file_get_contents(WPABTF_PATH . 'modules/critical-css-build-tool/package.json');

		// add package.json
		$zip->addFromString('package.json',$data);

		// add package.json
		$data = file_get_contents(WPABTF_PATH . 'modules/critical-css-build-tool/gulpfile.js');

		// add package.json
		$zip->addFromString('gulpfile.js',$data);


		$zip->close();

		/**
		 * Download zipfile
		 */
		header("Content-type: application/zip"); 
		header("Content-Disposition: attachment; filename=".$taskname.".zip");
		header("Content-length: " . filesize($tmp_file));
		header("Pragma: no-cache"); 
		header("Expires: 0"); 
		readfile($tmp_file);

		exit;
    }

    /**
     * Download package.json and gulpfile.js
     */
    public function download_package() {

    	// ZipArchive requires PHP v5.2+
    	if (!version_compare(PHP_VERSION, '5.2.0', '>=')) {
			wp_die('Creating zipfiles requires PHP v5.2+.');
		}

		/**
		 * Create zip object
		 */
		$zip = new ZipArchive();

		# create a temp file & open it
		$tmp_file = tempnam('.','');
		if ($zip->open($tmp_file, ZipArchive::CREATE) !== true) {
			wp_die('Failed to create zip archive. Please check PHP ZipArchive permissions.');
		}

		// add package.json
		$data = file_get_contents(WPABTF_PATH . 'modules/critical-css-build-tool/package.json');

		// add package.json
		$zip->addFromString('package.json',$data);

		// add package.json
		$data = file_get_contents(WPABTF_PATH . 'modules/critical-css-build-tool/gulpfile.js');

		// add package.json
		$zip->addFromString('gulpfile.js',$data);


		$zip->close();

		/**
		 * Download zipfile
		 */
		header("Content-type: application/zip"); 
		header("Content-Disposition: attachment; filename=wp-abtf-gulp-critical-css.zip");
		header("Content-length: " . filesize($tmp_file));
		header("Pragma: no-cache"); 
		header("Expires: 0"); 
		readfile($tmp_file);

		exit;
    }

    /**
     * Install package.json and gulpfile.js
     */
    public function install_package() {

    	$gulpdir = get_stylesheet_directory() . '/abovethefold/';
		if (!is_dir($gulpdir)) {
			if (!@mkdir( $gulpdir, $this->CTRL->CHMOD_DIR )) {
				wp_die( 'Failed to create ' . $gulpdir );
			}
		}

		// copy package.json if it does not exist
		if (!file_exists($gulpdir . 'package.json')) {
			copy( WPABTF_PATH . 'modules/critical-css-build-tool/package.json', $gulpdir . 'package.json' );
			chmod( $gulpdir . 'package.json', $this->CTRL->CHMOD_FILE );
		}
		// copy gulpfile.js if it does not exist
		if (!file_exists($gulpdir . 'gulpfile.js')) {
			copy( WPABTF_PATH . 'modules/critical-css-build-tool/gulpfile.js', $gulpdir . 'gulpfile.js' );
			chmod( $gulpdir . 'gulpfile.js', $this->CTRL->CHMOD_FILE );
		}

		// add notice
		$this->CTRL->admin->set_notice('<div style="font-size:18px;line-height:20px;margin:0px;">The Gulp.js Critical CSS Generator files have been installed in <strong>'.trailingslashit(str_replace(home_url(),'',get_stylesheet_directory_uri())).'abovethefold/</strong>
		<br /><br />
		Run <code><strong>npm install</strong></code> to install the dependencies.
		<br /><br />
		<textarea class="abtfcmd" onfocus="jQuery(this).select();">cd '.trailingslashit(get_stylesheet_directory()).'abovethefold/;' . "\n" . 'npm install</textarea></div>', 'NOTICE');

		wp_redirect( add_query_arg( array( 'page' => 'abovethefold', 'tab' => 'build-tool' ), admin_url( 'admin.php' ) ) );
		exit;
    }

    /**
     * Retrieve HTML and CSS JSON
     */
    public function get_page_json($url) {

    	// get HTML without CSS
		$html = trim($this->CTRL->remote_get($this->CTRL->view_url('abtf-buildtool-html', false, $url)));
		if ($html === '') {

			// no HTML
			return false;
		}

		// extract full CSS
		$css = @json_decode(trim(preg_replace('#^.*--FULL-CSS-JSON--(.*)--FULL-CSS-JSON--.*$#is','$1',$this->CTRL->remote_get($this->CTRL->view_url('abtf-buildtool-css', false, $url)))),true);
		if (!is_array($css)) {

			// no CSS
			return false;
		}

		$json_result = array(
			'html' => $html,
			'css' => array()
		);

		if (is_array($css) && !empty($css)) {

			$file_number = 0;
			foreach ($css as $file) {
				$file_number++;
				if (!isset($file['media'])) {
					$file['media'] = array('all');
				}

				if (!isset($file['inline']) || intval($file['inline']) !== 1) {
					$cssfilehost = parse_url($file['src'], PHP_URL_HOST);
					$filename = preg_replace(array('|[^a-z0-9\-]+|is','|-+|is'),array('-','-'),$cssfilehost) . '-' . preg_replace('|\?.*$|Ui','',basename($file['src']));
				}

				if (in_array('all',$file['media'])) {
					if (isset($file['inline']) && intval($file['inline']) === 1) {

						$header = "/**\n * Inline CSS exported from ".$url."\n *\n * @inline " . $file['src'] . "\n * @size " . $this->human_filesize(strlen($file['inlinecode'])) . "\n * @media ".implode(', ',$file['media']) . "\n * @position ".$file_number."\n */\n\n";

						$json_result['css'][] = array(
							'file' => $file_number . '-' . $file['src'] . '.css',
							'code' => $header . $file['inlinecode']
						);
					} else {

						$header = "/**\n * CSS file exported from ".$url."\n *\n * @file " . $file['src'] . "\n * @size " . $this->human_filesize(strlen($file['code'])) . "\n * @media ".implode(', ',$file['media']) . "\n * @position ".$file_number."\n  */\n\n";

						$json_result['css'][] = array(
							'file' => $file_number . '-' . $filename,
							'code' => $header . $file['code']
						);
					}
				} else {
					if (isset($file['inline']) && intval($file['inline']) === 1) {

						$header = "/**\n * Inline CSS exported from ".$url."\n *\n * @inline " . $file['src'] . "\n * @size " . $this->human_filesize(strlen($file['inlinecode'])) . "\n * @media ".implode(', ',$file['media']) . "\n * @position ".$file_number."\n */\n\n";

						$json_result['css'][] = array(
							'file' => $file_number . '-' . $file['src'] . '.css',
							'code' => $header . '@media '.implode(', ',$file['media']).' { ' . $file['inlinecode'] . ' }'
						);
					} else {

						$header = "/**\n * CSS file exported from ".$url."\n *\n * @file " . $file['src'] . "\n * @size " . $this->human_filesize(strlen($file['code'])) . "\n * @media ".implode(', ',$file['media']) . "\n * @position ".$file_number."\n  */\n\n";

						// media query
						$json_result['css'][] = array(
							'file' => $file_number . '-' . $filename,
							'code' => $header . '@media '.implode(', ',$file['media']).' { ' . $file['code'] . ' }'
						);
					}
				}
			}
		}

		return $json_result;
    }


    /**
     * File size
     */
    public function human_filesize($bytes, $decimals = 2) {
	    $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
	    $factor = floor((strlen($bytes) - 1) / 3);
	    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
	}

	/**
	 * Installed
	 */
	public function is_installed() {

    	$gulpdir = get_stylesheet_directory() . '/abovethefold/';
    	if (!is_dir($gulpdir)) {
    		return false;
    	}
    	
		if (!file_exists($gulpdir . 'package.json')) {
			return false;
		}
		// copy gulpfile.js if it does not exist
		if (!file_exists($gulpdir . 'gulpfile.js')) {
			return false;
		}

		return true;
	}
    

}