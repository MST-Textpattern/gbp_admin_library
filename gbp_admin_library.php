<?php

// This is a PLUGIN TEMPLATE.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Uncomment and edit this line to override:
# $plugin['name'] = 'abc_plugin';

$plugin['version'] = '0.2';
$plugin['author'] = 'Graeme Porteous';
$plugin['author_uri'] = 'http://porteo.us/projects/textpattern/gbp_admin_library/';
$plugin['description'] = 'GBP\'s Admin-Side Library';
$plugin['type'] = 2; 

@include_once('../zem_tpl.php');

if (0) {
?>
# --- BEGIN PLUGIN HELP ---

# --- END PLUGIN HELP ---
<?php
}
# --- BEGIN PLUGIN CODE ---

// Constants
define('gbp_tab', 'tab');
define('gbp_id', 'id');

class GBPPlugin {
	// Internal variables
	var $plugin_name;
	var $title;
	var $event;
	var $message = '';
	var $tabs = array();
	var $active_tab = 0;
	var $use_tabs = 0;
	var $gp = array();
	var $preferences = array();

	// Constructor
	function GBPPlugin($title, $event, $parent_tab = 'extensions') {

		global $txp_current_plugin;

		// Store a reference to this class so we can get PHP 4 to work
		if (version_compare(phpversion(),'5.0.0','<'))
			global $gbp_admin_lib_refs; $gbp_admin_lib_refs[$txp_current_plugin] = &$this;

		// Get the plugin_name from the global txp_current_plugin variable
		$this->plugin_name = $txp_current_plugin;

		// When making a GBPAdminView there must be event attributes
		$this->event = $event;

		if (@txpinterface == 'admin')
		{
			// We are admin-side.

			// There must be title and event attributes
			$this->title = $title;

			// The parent_tab can only be one of four things, make sure it is
			$parent_tab = ($parent_tab != 'content' AND $parent_tab != 'presentation' AND $parent_tab != 'admin' AND $parent_tab != 'extensions')
				? 'extensions' : $parent_tab;

			// Set up the get-post array
			$this->gp = array_merge(array('event', gbp_tab), $this->gp);

			// Check if our event is active, if so call preload()
			if (gps('event') == $event) {

				$this->load_preferences();

				$this->preload();

				// Tabs should be loaded by now
				if ($this->use_tabs) {

					// Let the active_tab know it's active and call it's preload()
					$tab = &$this->tabs[$this->active_tab];
					$tab->is_active = 1;
					$tab->php_4_fix();
					$tab->preload();
				}
			}

			// Call txp functions to register this plugin
			register_tab($parent_tab, $event, $title);
			register_callback(array($this, 'render'), $event, null, 0);
		}
		if (@txpinterface == 'public')
			$this->load_preferences();
	}

	function load_preferences() {
		
		// Override the default values if the prefs have been stored in the preferences table
		$prefs = safe_rows('SUBSTRING(`name` FROM LENGTH(\''.$this->plugin_name.'\')+2) as \'key\', val as value, html as type', 'txp_prefs', '`name` LIKE \''.$this->plugin_name.'_%\' AND `event` = \''.$this->event.'\'');
		foreach ($prefs as $pref) {

			extract($pref);

			switch ($type) {
				case 'gbp_array_text':
					$value = gbp_convert_pref('gbp_array_text', $value);
				break;
				case 'gbp_serialized':
					$value = gbp_convert_pref('gbp_serialized', $value);
				break;
			}

			if (array_key_exists($key, $this->preferences))
				$this->preferences[$key] = array('value' => $value, 'type' => $type);
		}
	}

	function set_preference($key, $value) {

		global $prefs;

		// Variables to be saved
		$type = $this->preferences[$key]['type'];
		$name = $this->plugin_name."_".$key;
		$event = $this->event;

		// Check to see if a preference already exists, update or insert accordingly
		if (array_search($name, array_keys($prefs)) !== false)
			safe_update(
				'txp_prefs',
				"`val` = '".doSlash($value)."'", "`name` = '$name' AND `event` = '$event'"
			);
		else
			safe_insert(
				'txp_prefs',
				"`name` = '$name',`val` = '".doSlash($value)."', `event` = '$event', `html` = '$type', `prefs_id` = '1'"
			);

		// Converted value for the current prefs, so we don;t need to run load_preferences() again
		$converted_value = gbp_convert_pref($type, $value);
		$this->preferences[$key]['value'] = $converted_value;
		$prefs[$name] = $converted_value;
	}

	function &add_tab($tab, $is_default = NULL) {

		// Check to see if the tab is active
		if (($is_default && !gps(gbp_tab)) || (gps(gbp_tab) == $tab->event))
			$this->active_tab = count($this->tabs);

		// Store the tab
		$this->tabs[] = $tab;

		// We've got a tab, lets assume we want to use it
		$this->use_tabs = 1;
		
		return $this;
	}

	function preload() {

		// Override this function if you require sub tabs.
	}

	function render() {

		// render() gets called because it is specified in txp's register_callback()

		// After a callback we lose track of the current plugin in PHP 4
		global $txp_current_plugin;
		$txp_current_plugin = $this->plugin_name;

		$this->render_header();
		$this->main();

		if ($this->use_tabs) {

			$this->render_tabs();
			$this->render_tab_main();
		}

		$this->render_footer();
		$this->end();
	}

	function render_header() {

		// Render the pagetop, a txp function
		pagetop($this->title, $this->message);

		// Once a message has been used we discard it
		$this->message = '';
	}

	function render_tabs() {

		// This table, which contains the tags, will have to be changed if any improvements
		// happen to the admin interface
		$out[] = '<table cellpadding="0" cellspacing="0" width="100%" style="margin-top:-2em;margin-bottom:2em;">';
		$out[] = '<tr><td align="center" class="tabs">';
		$out[] = '<table cellpadding="0" cellspacing="0" align="center"><tr>';

		foreach (array_keys($this->tabs) as $key) {

			// Render each tab bu tkeep a reference to the tab so any changes made are store
			$tab = &$this->tabs[$key];
			$out[] = $tab->render_tab();
		}

		$out[] = '</tr></table>';
		$out[] = '</td></tr>';
		$out[] = '</table><div style="padding: 0 30px;">';

		echo join('', $out);
	}

	function main() {

		// Override this function
	}

	function render_tab_main() {

		// Call main() for the active_tab
		$tab = &$this->tabs[$this->active_tab];
		$tab->main();
	}

	function render_footer() {

		// A simple footer
		$out[] = '</div>';
		$out[] = '<div style="padding-top: 3em; text-align: center; clear: both;">';
		$out[] = $this->plugin_name;
		$out[] = '</div>';

		echo join('', $out);
	}

	function end() {

		// Override this function
	}

	function form_inputs() {

		$out[] = eInput($this->event);

		if ($this->use_tabs) {

			$tab = $this->tabs[$this->active_tab];
			$out[] = hInput(gbp_tab, $tab->event);
		}
		
		return join('', $out);
	}

	function url( $vars=array(), $gp=false )
		{
		/*
		Expands $vars into a get style url and redirects to that location. These can be 
		overriden with the current get, post, session variables defined in $this->gp 
		by setting $gp = true
		NOTE: If $vars is not an array or is empty then we assume $gp = true.
		*/

		if (!is_array($vars))
			$vars = gpsa($this->gp);
		else if ( $gp || !count($vars) )
			$vars = array_merge(gpsa($this->gp), $vars);
		
		foreach ($vars as $key => $value)
			{
			if ( !empty( $value ) )
				$out[] = $key.'='.$value;
			}
		
		return serverSet('SCRIPT_NAME') . ( isset( $out ) 
			? '?'.join('&', $out)
			: '' );
		}

	function redirect( $vars='' ) 
		{
		/*
		If $vars is an array, using url() to expand as an GET style url and redirects to 
		that location.
		*/

		header('HTTP/1.1 303 See Other');
		header('Status: 303');
		header('Location: '.$this->url( $vars ) );
		header('Connection: close');
		}
}

class GBPAdminTabView {
	//	Internal variables
	var $title;
	var $event;
	var $is_active;
	var $parent;

	//	Constructor
	function GBPAdminTabView($title, $event, &$parent, $is_default = NULL) {

		$this->title = $title;
		$this->event = $event;
		
		// Note: $this->parent only gets set correctly for PHP 5
		$this->parent =& $parent->add_tab($this, $is_default);
	}
	
	function php_4_fix() {
		
		// Fix references in PHP 4 so sub tabs can access their parent tab
		if (version_compare(phpversion(),'5.0.0','<')) { 
			global $txp_current_plugin, $gbp_admin_lib_refs;
			$this->parent =& $gbp_admin_lib_refs[$txp_current_plugin];
		}
	}
	
	function preload() {
		
		// Override this function
	}

	function render_tab() {

		$this->php_4_fix();

		// Grab the url to this tab
		$url = $this->parent->url(array(gbp_tab => $this->event), true);

		// Will need updating if any improvements happen to the admin interface
		$out[] = '<td class="' . ($this->is_active ? 'tabup' : 'tabdown2');
		$out[] = '" onclick="window.location.href=\'' .$url. '\'">';
		$out[] = '<a href="' .$url. '" class="plain">' .$this->title. '</a></td>';

		return join('', $out);
	}

	function main() {

		// Override this function
	}
}

class GBPPreferenceTabView extends GBPAdminTabView {
	
	function preload() {

		if (ps('step') == 'prefs_save') {

			foreach (array_keys($this->parent->preferences) as $key)
				$this->parent->set_preference($key, ps($key));

		}
	}

	function main() {

		// Make txp_prefs.php happy :)
		global $event;
		$event = $this->parent->event;

		include_once txpath.'/include/txp_prefs.php';

		echo
		'<form action="index.php" method="post">',
		startTable('list');

		foreach ($this->parent->preferences as $key => $pref) {

			extract($pref);

			$out = tda(gTxt($key), ' style="text-align:right;vertical-align:middle"');
				
			switch ($type) {
				case 'text_input':
					$out .= td(pref_func('text_input', $key, $value, 20));
				break;
				default:
					$out .= td(pref_func($type, $key, $value, 50));
				break;
			}
			
			$out.= tda($this->popHelp($key), ' style="vertical-align:middle"');			
			echo tr($out);
		}

		echo
		tr(tda(fInput('submit', 'Submit', gTxt('save_button'), 'publish'), ' colspan="3" class="noline"')),
		endTable(),
		$this->parent->form_inputs(),
		sInput('prefs_save'),
		'</form>';
	}

	function popHelp($helpvar) {

		return '<a href="'.serverSet('SCRIPT_NAME').'?event=plugin&step=plugin_help&name='.$this->parent->plugin_name.'#'.$helpvar.'" class="pophelp">?</a>';
	}
}

function gbp_convert_pref($type, $value, $encode = NULL) {

	// Basic converting/encoding functions, so we can store the data correctly is the db
	switch ($type) {
		case 'gbp_array_text':
			return ($encode)
				? implode(',', $value)
				: explode(',', $value);
		case 'gbp_serialized':
			return ($encode)
				? serialize($value)
				: unserialize($value);
		break;
	}
	return $value;
}

function gbp_array_text($item, $var, $size = '') {

	return text_input($item, gbp_convert_pref('gbp_array_text', $var, 1), $size);
}

function gbp_serialized($item, $var, $size = '') {

	return text_input($item, gbp_convert_pref('gbp_serialized', $var, 1), $size);
}

# --- END PLUGIN CODE ---

?>
