<?php

$plugin['name'] = 'gbp_admin_library';
$plugin['version'] = '0.2';
$plugin['author'] = 'Graeme Porteous';
$plugin['author_uri'] = 'http://porteo.us/projects/textpattern/gbp_admin_library/';
$plugin['description'] = 'GBP\'s Admin-Side Library';
$plugin['type'] = 2;

$plugin['url'] = '$HeadURL$';
$plugin['date'] = '$LastChangedDate$';
$plugin['revision'] = '$LastChangedRevision$';

@include_once('../zem_tpl.php');

if (0) {
?>
<!-- CSS SECTION
# --- BEGIN PLUGIN CSS ---
<style type="text/css">
div#adminlib_help td { vertical-align:top; }
div#adminlib_help code { font-weight:bold; font: 105%/130% "Courier New", courier, monospace; background-color: #FFFFCC;}
div#adminlib_help code.code_tag { font-weight:normal; border:1px dotted #999; background-color: #f0e68c; display:block; margin:10px 10px 20px; padding:10px; }
div#adminlib_help a:link, div#adminlib_help a:visited { color: blue; text-decoration: none; border-bottom: 1px solid blue; padding-bottom:1px;}
div#adminlib_help a:hover, div#adminlib_help a:active { color: blue; text-decoration: none; border-bottom: 2px solid blue; padding-bottom:1px;}
div#adminlib_help h1 { color: #369; font: 20px Georgia, sans-serif; margin: 0; text-align: center; }
div#adminlib_help h2 { border-bottom: 1px solid black; padding:10px 0 0; color: #369; font: 17px Georgia, sans-serif; }
div#adminlib_help h3 { color: #693; font: bold 12px Arial, sans-serif; letter-spacing: 1px; margin: 10px 0 0;text-transform: uppercase;}
</style>
# --- END PLUGIN CSS ---
-->
<!-- HELP SECTION
# --- BEGIN PLUGIN HELP ---

<div id="adminlib_help">
 
h1(#top). Graeme Porteous' Admin Library.
 
Provides basic classes for building the admin side of your own, derived, plugins.

</div>

# --- END PLUGIN HELP ---
-->
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
	function GBPPlugin($title = '', $event = '', $parent_tab = '') {

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
			if ($event AND $title AND $parent_tab AND array_search($parent_tab, array('content', 'presentation', 'admin', 'extensions')) === false)
				$parent_tab = 'extensions';

			// Set up the get-post array
			$this->gp = array_merge(array('event', gbp_tab), $this->gp);

			// Check if our event is active, if so call preload()
			if (gps('event') == $event) {

				$this->load_preferences();

				$this->preload();

				// Tabs should be loaded by now
				if ($parent_tab && $this->use_tabs) {

					foreach (array_keys($this->tabs) as $key)
						{
						$tab = &$this->tabs[$key];
						$tab->php_4_fix();
						}

					// Let the active_tab know it's active and call it's preload()
					$tab = &$this->tabs[$this->active_tab];
					$tab->is_active = 1;
					$tab->preload();
				}
			}

			// Call txp functions to register this plugin
			if ($parent_tab)
			{
				register_tab($parent_tab, $event, $title);
				register_callback(array(&$this, 'render'), $event, null, 0);
			}
		}
		if (@txpinterface == 'public')
			$this->load_preferences();
	}

	function load_preferences()
		{
		/*
		Grab and store all preferences with event matching this plugin, combine gbp_partial
		rows and decode the value if it's of custom type.
		*/
		global $prefs;

		// Override the default values if the prefs have been stored in the preferences table.
		$preferences = safe_rows("name, html as type",
		'txp_prefs', "event = '{$this->event}' AND html <> 'gbp_partial'");

		// Add the default preferences which aren't saved in the db but defined in the plugin's source.
		foreach ($this->preferences as $key => $pref)
			{
			$db_pref = array('name' => $this->plugin_name.'_'.$key, 'type' => $pref['type']);
			if (array_search($db_pref, $preferences) === false)
				$preferences[] = $db_pref + array('default_value' => $pref['value']);
			}

		foreach ($preferences as $name => $pref)
			{
			// Extract the name and type.
			extract($pref);

			// The base name which gbp_partial preferences could share.
			$base_name = $name;

			// Combine the extended preferences, which go over two rows into one preference.
			$i = 0; $value = '';
			while (array_key_exists($name, $prefs))
				{
				$value .= $prefs[$name];
				unset($prefs[$name]);
				// Update name for the next array_key_exists check.
				$name = $base_name.'_'.++$i;
				}

			// If there is no value then revert to the default value if it exists.
			if ((!$value || (@!$value[0] && count($value) <= 1)) && isset($default_value))
				$value = $default_value;

			// Else if this a custom type (E.g. gbp_serialized OR gbp_array_text)
			// call it's db_get method to decode it's value.
			else if (is_callable(array(&$this, $type)))
				$value = call_user_func(array(&$this, $type), 'db_get', $value);

			// Re-set the combined and decoded value to the global prefs array.
			$prefs[$base_name] = $value;

			// If the preference exists in our preference array set the new value and correct type. 
			$base_name = substr($base_name, strlen($this->plugin_name.'_'));
			if (array_key_exists($base_name, $this->preferences))
				$this->preferences[$base_name] = array('value' => $value, 'type' => $type);
			}
		}

	function set_preference( $key, $value, $type='' )
		{
		global $prefs;

		// Set some standard db fields
		$base_name = $this->plugin_name.'_'.$key;
		$name = $base_name;
		$event = $this->event;

		// If a type hasn't been specified then look the key up in our preferences.
		// Else assume it's type is 'text_input'.
		if (empty($type) && array_key_exists($key, $this->preferences))
			$type = $this->preferences[$key]['type'];
		else if (empty($type))
			$type = 'text_input';

		// Set the new value to the global prefs array and if the preference exists 
		// to our own preference array.
		$prefs[$name] = $value;
		if (array_key_exists($key, $this->preferences))
			$this->preferences[$key] = array('value' => $value, 'type' => $type);

		// If this preference has a custom type (E.g. gbp_serialized OR gbp_array_text)
		// call it's db_set method to encode the value.
		if (is_callable(array(&$this, $type)))
			$value = call_user_func(array(&$this, $type), 'db_set', $value);

		// It is possible to leave old 'gbp_partial' perferences when reducing the
		// lenght of a preference. Remove them all.
		$this->remove_preference($name);

		// Make sure preferences which equal NULL are saved
		if (empty($value))
			set_pref($name, '', $event, 2, $type);

		$i = 0; $value = doSlash($value);
		// Limit preference to approximatly 4Kb of data. I hope this will be enough
		while ( strlen($value) && $i < 16 )
			{
			// Grab the first 255 chars from the value and strip any backward slashes which
			// cause the SQL to break. 
			$value_segment = rtrim(substr($value, 0, 255), '\\');

			// Set the preference and update name for the next array_key_exists check.
			set_pref($name, $value_segment, $event, 2, ($i ? 'gbp_partial' : $type));
			$name = $base_name.'_'.++$i;

			// Remove the segment of the value which has been saved.
			$value = substr_replace($value, '', 0, strlen($value_segment));
			}
		}

	function remove_preference( $key )
		{
		$event = $this->event;
		safe_delete('txp_prefs', "event = '$event' AND ((name LIKE '$key') OR (name LIKE '{$key}_%' AND html = 'gbp_partial'))");
		}

	function gbp_serialized( $step, $value, $item='' )
		{
		switch ( strtolower($step) )
			{
			default:
			case 'ui_in':
				if (!is_array($value)) $value = array($value);
				return text_input($item, implode(',', $value), 50);
			break;
			case 'ui_out':
				return explode(',', $value);
			break;
			case 'db_set':
				return serialize($value);
			break;
			case 'db_get':
				return unserialize($value);
			break;
			}
		return '';
		}

	function gbp_array_text( $step, $value, $item='' )
		{
		switch ( strtolower($step) )
			{
			default:
			case 'ui_in':
				if (!is_array($value)) $value = array($value);
				return text_input($item, implode(',', $value), 50);
			break;
			case 'ui_out':
				return explode(',', $value);
			break;
			case 'db_set':
				return implode(',', $value);
			break;
			case 'db_get':
				return explode(',', $value);
			break;
			}
		return '';
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

	function pref( $key )
		{
		global $prefs;
		$key = $this->plugin_name.'_'.$key;
		if (@$this->preferences[$key])
			return $this->preferences[$key]['value'];
		if (@$prefs[$key])
			return $prefs[$key];
		return NULL;
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

		$this->title = mb_convert_case( $title, MB_CASE_TITLE, "UTF-8" );
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

	function pref( $key )
	{
	return $this->parent->pref($key);
	}

	function set_preference( $key, $value, $type='' )
	{
	return $this->parent->set_preference($key, $value, $type);
	}

	function remove_preference( $key )
	{
	return $this->parent->remove_preference($key);
	}

	function url( $vars, $gp=false )
	{
	return $this->parent->url($vars, $gp);
	}

	function form_inputs()
	{
	return $this->parent->form_inputs();
	}
}

class GBPPreferenceTabView extends GBPAdminTabView {
	
	function preload()
		{
		if (ps('step') == 'prefs_save')
			{
			foreach ($this->parent->preferences as $key => $pref)
				{
				extract($pref);
				$value = ps($key);
				if (is_callable(array(&$this->parent, $type)))
					$value = call_user_func(array(&$this->parent, $type), 'ui_out', $value);
				$this->parent->set_preference($key, $value);
				}
			}
		}

	function main()
		{
		// Make txp_prefs.php happy :)
		global $event;
		$event = $this->parent->event;
	
		include_once txpath.'/include/txp_prefs.php';

		echo
		'<form action="index.php" method="post">',
		startTable('list');

		foreach ($this->parent->preferences as $key => $pref)
			{
			extract($pref);

			$out = tda(gTxt($key), ' style="text-align:right;vertical-align:middle"');
				
			switch ($type)
				{
				case 'text_input':
					$out .= td(pref_func('text_input', $key, $value, 20));
				break;
				default:
					if (is_callable(array(&$this->parent, $type)))
						$out .= td(call_user_func(array(&$this->parent, $type), 'ui_in', $value, $key));
					else
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

	function popHelp($helpvar)
		{
		return '<a href="'.serverSet('SCRIPT_NAME').'?event=plugin&step=plugin_help&name='.$this->parent->plugin_name.'#'.$helpvar.'" class="pophelp">?</a>';
		}
}

# --- END PLUGIN CODE ---

?>
