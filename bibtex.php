<?php // -*- mode: PHP; mode: Outline-minor; outline-regexp: "/[*][*]+"; -*-
define('rcsid', 'x$Id: bibtex.php,v 1.51 2014/04/17 16:37:13 dyuret Exp dyuret $');

/** MySQL parameters.
 * To use this program you need to create a database table in mysql with:
 * CREATE TABLE bibtex (entryid INT, field VARCHAR(255), value VARCHAR(65000), user VARCHAR(255), s SERIAL, INDEX(entryid), INDEX(value));
 *
 * Authentication and privileges are managed by mysql.  bibtex.php
 * just sends the login information to mysql.  Here is the scheme
 * I use for privileges:
 *
 * GRANT SELECT ON bibtex.bibtex TO bibtex@localhost;
 * GRANT SELECT, INSERT ON bibtex.bibtex TO user@localhost;
 * SET PASSWORD FOR user@localhost = OLD_PASSWORD('abcdef');
 * GRANT ALL PRIVILEGES ON bibtex.bibtex TO root@localhost;
 *
 * That way only root can alter or delete entries, regular users can
 * select and insert anonymous user (bibtex) can only select.  The SET
 * PASSWORD command needs to use the OLD_PASSWORD function because of an
 * incompatibility between php and mysql.
 *
 * TODO: find the right way to deal with OLD_PASSWORD incompatibility.
 * TODO: auto-fill from google scholar or google books, quick create entry by search
 * TODO: check multiple bibtex import
 * TODO: escape single quote in search string o'shea
 *
 * The following array should have the login information for the anonymous user:
 */
$mysql = array
(
 'db'    => 'bibtex',
 'table' => 'bibtex',
 'user'  => 'bibtex',
 'host'  => 'localhost',
 'pass'  => 'bibtex',
 );
 
/** main($_fn) generates top level page structure. 
 * $_fn gives the name of the page generation function.
 * Variables starting with '_' are REQUEST variables.
 * TODO: implement backups
 */
$fn_header = array('bibtex', 'login', 'help', 'download');
$fn_select = array('search', 'select', 'show', 'index');
$fn_insert = array('addkey', 'new_entry', 'copy_entry', 'entry', 'import');
$fn_delete = array('delete', 'delkey', 'edit_value', 'edit_entry');
$fn_all = array_merge($fn_header, $fn_select, $fn_insert, $fn_delete);
$rvars = array('field', 'fn', 'id', 'keyword', 'newval', 'nfield',
	       'nocheck', 'nselect', 'pattern', 'sort', 'type',
	       'user', 'value');
foreach($rvars as $v) {
    $GLOBALS['_'.$v] = $_REQUEST[$v];
}

function main() {
  // session_start();
  // error_reporting(E_ALL);
  global $html_header, $html_footer, $fn_header, $fn_all;
  // This is deprecated:
  // import_request_variables('gp', '_');
  // We do this manually upstairs instead.

  global $_fn;
  sql_init();
  if (isset($_fn) and in_array($_fn, $fn_header)) $_fn();
  else {
    echo $html_header;
    echo navbar();
    if (isset($_fn) and in_array($_fn, $fn_all)) $_fn();
    echo $html_footer;
  }
  sql_term();
    
  //echo '<pre>'; print_r($_REQUEST); echo '</pre>';
  //echo '<pre>'; print_r($_SERVER); echo '</pre>';
  //phpinfo();
}
 
/** navbar() generates the menu at the top. 
 */
function navbar() {
  return
    h('table',
      h('tr', array('valign' => 'top'),
	h('td', navbar_search()).
	h('td', navbar_index()).
	h('td', navbar_new()).
	h('td', navbar_sort()).
	h('td', navbar_login()).
	h('td', navbar_help())
	));
}

/* navbar_search()
 * TODO: implement advanced search.
 */
function navbar_search() {
  $attr['onclick'] = 'if(value == "Search"){value = ""}';
  return h_form(h_hidden('fn', 'search'),
		h_text('pattern', 'Search', $attr));
		
}

/* navbar_index()
 * TODO: restrict the fields that can be indexed?
 */
function navbar_index() {
  global $index_fields;
  if (!isset($index_fields)) $index_fields = sql_uniq(NULL);
  natcasesort($index_fields);
  return h_form(h_hidden('fn', 'index'),
		h_select('field', $index_fields, 'Index', 'submit()'));
		
}

function navbar_new() {
  global $entry_types, $sql_priv;
  if (!isset($sql_priv['INSERT'])) return;
  $uniq_types = array_keys($entry_types);
  natcasesort($uniq_types);
  array_unshift($uniq_types, 'Import BibTeX');
  return h_form(h_hidden('fn', 'new_entry'),
		h_select('type', $uniq_types, 'New', 'submit()'));
}
 
function navbar_sort() {
  global $_fn, $_pattern, $_field, $_value, $sort_fields;
  if (!isset($sort_fields)) $sort_fields = sql_uniq(NULL);
  $sort_fields[] = 'entryid';
  natcasesort($sort_fields);
  if ($_fn == 'search')
    return h_form(h_hidden('fn', 'search'),
		  h_hidden('pattern', $_pattern),
		  h_select('sort', $sort_fields, 'Sort', 'submit()'));
  elseif ($_fn == 'select')
    return h_form(h_hidden('fn', 'select'),
		  h_hidden('field', $_field),
		  h_hidden('value', $_value),
		  h_select('sort', $sort_fields, 'Sort', 'submit()'));
}
 
function navbar_login() {
  global $sql_user;
  $php_user = isset($_SERVER['PHP_AUTH_USER']) ? 
    $_SERVER['PHP_AUTH_USER'] : NULL;
  return ($php_user && ($php_user == $sql_user)) ?
    h_get($php_user, array('fn' => 'login', 'user' => $php_user)) :
    h_get('Login', array('fn' => 'login'));
}

function navbar_help() {
  return h_help('Help');
}
 
/** selection_form($select, $title) generates the entry selection form.
 * select is an array[entryid][field]=value(s)
 * title is the title of the page
 */
function selection_form($select, $title) {
  global $sql_priv, $_sort;
  $nselect = count($select);
  if ($nselect == 0) {
    echo h('p', h('strong', $title));
    return;
  }
  echo h_start('form', array('action' => $_SERVER['PHP_SELF'],
			     'name' => 'selection_form',
			     'method' => 'post'));
  echo h_hidden('fn', 'show');
  echo h_hidden('nselect', $nselect);

  echo h_start('p');
  echo h('strong', $title);
  echo '&nbsp; Select: ';
  echo h_a('All', 'javascript:checkAll(true)')."\n"; 
  echo h_a('None', 'javascript:checkAll(false)')."\n";
  echo '&nbsp; Action: ';
  echo h_a('Show', 'javascript:show()')."\n";
  echo h_a('BibTeX', 'javascript:bibtex()')."\n";
  if (isset($sql_priv['DELETE']))
    echo h_a('Delete', 'javascript:confirmDelete()')."\n&nbsp;\n";
  if (isset($sql_priv['INSERT']) or isset($sql_priv['DELETE'])) {
    $uniq_keywords = sql_uniq('keywords');
    natcasesort($uniq_keywords);
    echo h_select('keyword', $uniq_keywords, 'Keyword');
    if (isset($sql_priv['INSERT']))
      echo h_a('Addkey', 'javascript:keyword("addkey")')."\n";
    if (isset($sql_priv['DELETE']))
      echo h_a('Delkey', 'javascript:keyword("delkey")')."\n";
  }
  echo h_end('p');

  if ($_sort == 'entryid') {
      foreach ($select as $entryid => $ignore)
	  $ordered[$entryid] = 1000000000 - $entryid;
  } else {
      $ordered = array_map("select_sort_field", $select);
  }
  natcasesort($ordered);
  $n = 0;
  echo h_start('p');
  foreach ($ordered as $entryid => $ignore) 
    print_entry($select[$entryid], $entryid, ++$n);
  echo h_end('p');
  echo h_end('form');
}
 
function select_sort_field(&$entry) {
  global $_sort, $_field;
  if ($_sort) $key = $_sort;
  # elseif (isset($_field) and $_field == 'author') $key = 'year';
  # else $key = 'author';
  else $key = 'year';
  $ans = isset($entry[$key]) ? $entry[$key] : NULL;
  if (!isset($ans) and $key == 'author')
    $ans = $entry['editor'];
  if (is_array($ans)) $ans = $ans[0];
  if ($key == 'year') { 
    // Add the month for sorting
    $m = (isset($entry['month'])) ? monthno($entry['month']) : 0;
    $ans = 100*$ans + $m;
    // Reverse sorting so newer appears at top
    $ans = 1000000000 - $ans;
  }
  if ($key == 'author' || key == 'editor') {
    $ans = find_last_name($ans);
  }
  if ($key == 'citations') {
    $ans = 1000000000 - $ans;
  } 
  return $ans;
}

function find_last_name($str) {
  if (!preg_match('/,/', $str)) {
    if (preg_match('/\s(\S+)$/', $str, $m)) {
      $str = $m[1];
    }
  }
  return $str;
}

function monthno($str) {
  $mon = array('jan', 'feb', 'mar', 'apr', 'may', 'jun', 
	      'jul', 'aug', 'sep', 'oct', 'nov', 'dec');
  $str = substr(strtolower($str), 0, 3);
  $key = array_search($str, $mon);
  $key = $key ? ($key + 1) : 0;
  return $key;
}
 
/** search($_pattern) generates result of a search request. 
 * Request: fn=search&pattern=yuret
 */
function search() {
  global $_pattern;
  if (!isset($_pattern)) 
    return;
  $select = sql_search($_pattern);
  $nselect = count($select);
  selection_form($select, "Search = $_pattern ($nselect entries)");
}
 
/** select($_field, $_value) finds entries with field = value.
 * Request: fn=select&field=author&value=Katz%2C+Boris
 */
function select() {
  global $_value, $_field;
  if (!isset($_value) or !isset($_field)) 
    return;
  $select = sql_select($_field, $_value);
  $nselect = count($select);
  selection_form($select, "$_field = $_value ($nselect entries)");
}
 
/** show($ids) shows the subset of entries which the user has selected.
 * Request: fn=show&nselect=9&keyword=&e1=3722&e3=3714&e5=3553
 * Uses get_selection to collect the selected entries.
 */
function show($ids = NULL) {
  if (!$ids) $ids = get_selection();
  if (!$ids) return;
  $select = sql_select_list($ids);
  $nselect = count($select);
  selection_form($select, "$nselect entries");
}
 
function get_selection() {
  global $_nselect;
  if (!isset($_nselect)) return;
  $entryids = array();
  for ($i = 1; $i <= $_nselect; $i++) {
    if (isset($_REQUEST["e$i"]))
      $entryids[] = $_REQUEST["e$i"];
  }
  return $entryids;
}
 
/** bibtex($ids) TODO exports selected entries to bibtex format.
 * Request: fn=bibtex&nselect=3&keyword=&e1=3722&e2=3714&e3=3553
 * TODO: implement export all.
 * TODO: file save as does not work.
 * BUG: The quotes in values are not escaped.
 * BUG: Only lowercase versions of the fields are expected.
 */
function bibtex($ids = NULL) {
  if (!$ids) $ids = get_selection();
  if (!$ids) return;
  header('Content-type: text/plain');
  //echo "BibTeX not implemented yet, here are the entries you selected:\n\n";
  //print_r($_REQUEST);
  $entries = sql_select_list($ids);
  foreach ($entries as $entry) {
    printf("@%s{%s,\n", $entry['entrytype'], $entry['citekey']);
    foreach ($entry as $key => $val) {
      if ($key == 'entrytype') { continue; }
      if ($key == 'citekey') { continue; }
      if (is_array($val)) {
	if (($key == 'author') || ($key == 'editor')) {
	  $valstr = implode(" and ", $val);
	} else {
	  $valstr = implode(",", $val);
	}
	$val = $valstr;
      }
      printf("    %s = \"%s\",\n", $key, utf2latex($val));
     }
    printf("}\n\n");
  }
}
 
/** delete($ids) deletes the selected entries.
 * Request: select=delete&nselect=3&keyword=&e1=3722&e2=3714&e3=3553
 */
function delete($ids = NULL) {
  if (!$ids) $ids = get_selection();
  if (!$ids) return;
  sql_delete_list($ids);
  echo h_script
    ("window.location.replace('$_SERVER[HTTP_REFERER]')");
}
 
/** addkey($ids, $_keyword) adds the keyword to selected entries.
 * Request: fn=addkey&nselect=3&keyword=AI&e1=419&e2=561&e3=903
 * TODO: do google style single list for add/del keyword.
 * TODO: add a new keyword without having to edit an entry first.
 * TODO: forget google, just have a popup that asks for the keyword.
 * too impractical when too many keywords.
 */
function addkey($ids = NULL) {
  global $_keyword;
  if (!$ids) $ids = get_selection();
  if (!$ids) return;
  $entries = sql_select_list($ids);
  foreach ($entries as $id => $entry)
    if (!has_keyword($entry, $_keyword))
      sql_insert_field($id, 'keywords', $_keyword);
  show($ids);
}
 
function has_keyword(&$entry, $keyword) {
  $keys = isset($entry['keywords']) ? $entry['keywords'] : NULL;
  if (is_array($keys)) return in_array($keyword, $keys);
  else return ($keyword == $keys);
}
 
/** delkey($ids, $_keyword) deletes the keyword from selected entries.
 * Request: select=delkey&nselect=3&keyword=AI&e1=419&e2=561&e3=903
 */
function delkey($ids = NULL) {
  global $_keyword;
  if (!$ids) $ids = get_selection();
  if (!$ids) return;
  $entries = sql_select_list($ids);
  foreach ($entries as $id => $entry)
    if (has_keyword($entry, $_keyword))
      sql_delete_field($id, 'keywords', $_keyword);
  show($ids);
}
 
/** index($_field) prints a set of unique values for a given field.
 * Request: fn=index&field=keywords
 * The user can pick a value to select a list of entries,
 * or click on the box to modify a value.
 */
function index() {
  global $_field, $sql_priv;
  $can_edit = isset($sql_priv['DELETE']);
  $uniq_vals = sql_uniq($_field);
  natcasesort($uniq_vals);
  $nvals = count($uniq_vals);
  if ($can_edit) {
    echo h('p', h('strong', "$_field index ($nvals values) &nbsp;").
	   h('small', "(Click on a box to edit, click on a value to select)"));
    echo h_start('form', array('action' => $_SERVER['PHP_SELF'], 'name' => 'index_form'));
    echo h_hidden('fn', 'edit_value');
    echo h_hidden('field', $_field);
    echo h_hidden('newval', '');
  } else echo h('p', h('strong', "$_field index ($nvals values)"));
  foreach ($uniq_vals as $v) {
    $vv = addslashes($v);
    if ($can_edit) echo h_radio('value', $v, "edit_value('$vv')");
    print_field($_field, $v);
    echo h('br');
  }
  if ($can_edit) echo h_end('form');
}
 
/** edit_value($_field, $_value, $_newval) replace value with newval in field
 * If newval == '' the value is deleted (second example).
 * Request: fn=edit_value&field=editor&newval=AAA&value=Beal%2C+D.+F.
 * Request: fn=edit_value&field=editor&newval=&value=Beal%2C+D.+F.
 */
function edit_value() {
  global $_field, $_value, $_newval;
  if ($_newval == '') {
    sql_delete_value($_field, $_value);
  } else {
    sql_update_value($_field, $_value, $_newval);
  }
  index();
}
 
/** entry_form($entry, $title, $id) form to create, copy or edit an entry.
 * $entry must be an array with a valid entrytype defined.
 */
function entry_form(&$entry, $title = NULL, $id = NULL) {
  global $entry_types, $extra_index_fields, $extra_urlkey_fields, $extra_textarea_fields,
    $entry_field_index, $entry_field_printed;
  if (!isset($entry)) return;
  $type = strtolower($entry['entrytype']);
  if (!$type) return;
  $fields = $entry_types[$type];
  if (!$fields) return;
  echo h_start('form', array('action' => $_SERVER['PHP_SELF'],
			     'name' => 'entry_form',
			     'method' => 'post',
			     'enctype' => 'multipart/form-data'));
  echo h('p',
	 ($title ? h('strong', $title).' &nbsp; ' : '').
	 h_hidden('fn', 'entry').
	 ($id ? h_hidden('id', $id) : '').
	 h_submit('Submit').
	 h_button('Cancel', 'window.back()').
	 h_submit('Don\'t check errors', 'nocheck').
	 h_file().
	 h('br').'If there are multiple authors, editors, urls, or keywords please enter them on separate lines.'
	 );
  echo h_start('p');
  echo h('strong', 'Required fields ').h_help('?', 'required').h('br');
  foreach ($extra_index_fields as $f)
    entry_field($entry, $f);
  foreach ($fields['required'] as $f)
    entry_field($entry, $f);
  for ($i = 1; $i <= 3; $i++)
    entry_field($entry, NULL);
  echo h('strong', 'Optional fields ').h_help('?', 'optional').h('br');
  foreach ($extra_urlkey_fields as $f)
    entry_field($entry, $f);
  foreach ($fields['optional'] as $f)
    entry_field($entry, $f);
  foreach ($entry as $f => $v)
    if (!$entry_field_printed[$f] && !in_array($f, $extra_textarea_fields))
      entry_field($entry, $f);
  for ($i = 1; $i <= 3; $i++)
    entry_field($entry, NULL);
  foreach ($extra_textarea_fields as $f)
    entry_field($entry, $f);
  echo h_hidden('nfield', $entry_field_index);
  echo h_end('p');
  echo h_end('form');
}

/* entry_field()
 * complication: both the field and the value could be arrays.
 * if field is array, value is null => print selection for field.
 * if field is array, value is defined => print each value.
 * if field is scalar, value is array => print multiple fields.
 */
$entry_field_index = 0;
$entry_field_printed = array();
$field_input_size = 10;
$value_input_size = 40;
$textarea_rows = 3;

function entry_field(&$entry, $field) {
  global $entry_field_index;
  if (is_array($field)) {
    $i0 = $entry_field_index;
    foreach ($field as $f) 
      if (isset($entry[$f]))
	entry_field($entry, $f);
    if ($i0 == $entry_field_index) 
      print_entry_field($field, NULL);
  } elseif (isset($field)) {
    $val = isset($entry[$field]) ? $entry[$field] : NULL;
    if (is_array($val))
      foreach ($val as $v) 
	print_entry_field($field, $v);
    else print_entry_field($field, $val);
  } else {
    print_entry_field(NULL, NULL);
  }
}

function print_entry_field($field, $value) {
  global $field_input_size, $value_input_size, $textarea_rows,
    $entry_field_index, $entry_field_printed, $extra_textarea_fields;
  $n = ++$entry_field_index;
  $field_name = "f$n";
  $value_name = "v$n";

  if (is_array($field)) echo h_select($field_name, $field);
  else echo h_text($field_name, $field, array('size' => $field_input_size));

  if (is_array($value)) echo h_select($value_name, $value);
  elseif (in_array($field, $extra_textarea_fields)) 
    echo h_textarea($value_name, $value, $textarea_rows, $value_input_size);
  else echo h_text($value_name, $value, array('size' => $value_input_size));

  if (isset($field)) {
    if (is_array($field)) {
      foreach ($field as $f)
	$entry_field_printed[$f] = 1;
      echo h_help('?', $field[0]);
    } else {
      $entry_field_printed[$field] = 1;
      if ($field == 'entrytype' and isset($value))
	echo h_help('?', $value);
      else echo h_help('?', $field);
    }
  }
  echo h('br');
}
 
/** new_entry($_type) creates a new entry of a given type.
 * $_type: gives the type of entry
 * if type == "Import BibTeX" then do import.
 */
function new_entry() {
  global $_type;
  if ($_type == 'Import BibTeX') return import_form();
  $entry = array('entrytype' => $_type);
  entry_form($entry, 'New entry');
}
 
/** edit_entry() modifies an existing entry.
 * TODO: go back to previous page like delete, after edit complete.
 */
function edit_entry() {
  global $_id;
  $entry = sql_select_entry($_id);
  entry_form($entry, 'Edit entry', $_id);
}
 
/** copy_entry() clones an existing entry.
 * TODO: create a new citekey.
 */
function copy_entry() {
  global $_id;
  $entry = sql_select_entry($_id);
  entry_form($entry, 'Copy entry');
}
 
/** entry($fields, $_id, $_nocheck) possibly checks and inserts an entry
 */
function entry($fields = NULL) {
  global $_id, $_nocheck, $file_error_types;
  if (!$fields) $fields = get_fields();
  if (!$fields) return;
  $err = $_nocheck ? NULL : entry_errors($fields, $_id);
  if ($err) {
    echo h('p', h('strong', 'Entry errors'));
    echo h('ol', h('li', implode("\n</li><li>", $err)));
    echo h('p', 'Please go back to fix the errors or 
submit with the "Don\'t check errors" button.');
    echo "<pre>Entry: ";
    print_r($fields);
    echo "\$_FILES: ";
    print_r($_FILES["file"]);
    echo "\$_REQUEST: ";
    print_r($_REQUEST);
    echo "\$_SERVER: ";
    print_r($_SERVER);
    echo "</pre>\n";
  } else {
    insert($fields, $_id);
  }
}

$file_error_types = array
  ('There is no error, the file uploaded with success.',
   'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 
   'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 
   'The uploaded file was only partially uploaded.', 
   'No file was uploaded.', 
   'Unknown error.',
   'Missing a temporary folder.', 
   'Failed to write file to disk.', 
   'A PHP extension stopped the file upload.' 
   ); 

/* entry_errors
 * TODO: give more info if duplicate citekey.
 */
function entry_errors(&$entry, $editid) {
  global $entry_types, $extra_fields;
  $err = array();
  $type = isset($entry['entrytype']) ? strtolower($entry['entrytype']) : NULL;
  if (!$type) $err[] = 'entrytype: not set. '.h_help('?', 'entrytype');
  $fields = isset($entry_types[$type]) ? $entry_types[$type] : NULL;
  if (!$fields) $err[] = $type . ': not a valid entrytype. '.
                         h_help('?', 'entrytype'); 
  $citekey = isset($entry['citekey']) ? $entry['citekey'] : NULL;
  $others = isset($citekey) ? sql_select('citekey', $citekey) : NULL;
  if ($others) {
    foreach ($others as $id => $e) {
      if ($id == $editid) continue;
      $err[] = $citekey . ': not a unique citekey. '.
               h_help('?', 'citekey');
    }
  }
  if ($fields) {
    foreach ($fields['required'] as $f) {
      if (!is_array($f)) {
	if (!isset($entry[$f]))
	  $err[] = $f . ': required field missing. ' . h_help('?', $f);
      } else {
	$found = false;
	foreach($f as $ff)
	  if (isset($entry[$ff])) $found = true;
	if (!$found)
	  $err[] = implode(' or ', $f) . ': required field missing. '.
                   h_help('?', $type);
      }
    }
  }
  $allfields = array_merge($fields, $extra_fields);
  foreach ($entry as $f => $v) {
    if (!deep_in_array($f, $allfields))
      $err[] = $f . ': not a recognized field for the ' . 
	$type . ' entrytype. ' . h_help('?', 'ignored');

    // deniz 20110506: We are adding experimental support for non-ascii characters...
    //foreach ((is_array($v) ? $v : array($v)) as $vv)
    //if (preg_match('/[^\000-\177]/', $vv))
    //$err[] = $vv . ': contains non-ascii characters. '.
    //h_help('?', 'nonenglish');
  }

  // deniz 20110506: We should try moving uploaded files here when we can warn on errors
  if (isset($_FILES["file"])) {
    if ($_FILES["file"]["error"] == UPLOAD_ERR_OK) {
      $bib = $_SERVER["DOCUMENT_ROOT"] . "/bib";
      $dir = upload_dir($entry);
      if (!file_exists("$bib/$dir") && !mkdir("$bib/$dir", 0775, true)) {
	$err[] = "Failed to create directory $bib/$dir";
      } else {
	$name = $_FILES["file"]["name"];
	$tmp = $_FILES["file"]["tmp_name"];
	if (!move_uploaded_file($tmp, "$bib/$dir/$name")) {
	  $err[] = "Cannot move uploaded file to $bib/$dir/$name";
	} else {
	  file_put_contents("$bib/$dir/.htaccess", 'AuthType Basic
AuthName "Password Required"
AuthUserFile /etc/.htpasswd
AuthGroupFile /dev/null
Require valid-user
');
	  $url = "/bib/$dir/" . urlencode($name);
	  if (!isset($entry['url'])) {
	    $entry['url'] = $url;
	  } elseif (!is_array($entry['url'])) {
	    $entry['url'] = array($entry['url'], $url);
	  } else {
	    $entry['url'][] = $url;
	  }
	}
      }
    } elseif ($_FILES["file"]["error"] != UPLOAD_ERR_NO_FILE) {
      $err[] = $file_error_types[$_FILES["file"]["error"]];
    } 
  }

  // anything else illegal in bibtex specs?
  return $err;
}

function upload_dir($entry) {
  $citekey = isset($entry['citekey']) ? $entry['citekey'] : 'unknown';
  $author = 'unknown'; 
  if (isset($entry['author'])) {
    if (is_array($entry['author'])) {
      $author = $entry['author'][0]; 
    } else {
      $author = $entry['author']; 
    }
    $author = str_replace('{\\i}', 'i', $author); 
    $author = preg_replace('/\\\\./', '', $author); 
    $author = preg_replace('/,.*/', '', $author); 
    $author = str_replace(array('{','}'), '', $author); 
    $author = asciify($author); 
    $author = strtolower($author); 
    $author = str_replace(' ', '', $author); 
    if ($author == '') { $author = 'unknown'; } 
  }
  return urlencode($author) . "/" . urlencode($citekey);
}

function asciify($str) {
  static $from = array( 'Á', 'À', 'Â', 'Ä', 'Ǎ', 'Ă', 'Ā', 'Ã', 'Å', 'Ǻ', 'Ą', 'Ɓ', 'Ć', 'Ċ', 'Ĉ', 'Č', 'Ç', 'Ď', 'Ḍ', 'Ɗ', 'É', 'È', 'Ė', 'Ê', 'Ë', 'Ě', 'Ĕ', 'Ē', 'Ę', 'Ẹ', 'Ǝ', 'Ə', 'Ɛ', 'Ġ', 'Ĝ', 'Ǧ', 'Ğ', 'Ģ', 'Ɣ', 'Ĥ', 'Ḥ', 'Ħ', 'I', 'Í', 'Ì', 'İ', 'Î', 'Ï', 'Ǐ', 'Ĭ', 'Ī', 'Ĩ', 'Į', 'Ị', 'Ĵ', 'Ķ', 'Ƙ', 'Ĺ', 'Ļ', 'Ł', 'Ľ', 'Ŀ', 'Ń', 'Ň', 'Ñ', 'Ņ', 'Ó', 'Ò', 'Ô', 'Ö', 'Ǒ', 'Ŏ', 'Ō', 'Õ', 'Ő', 'Ọ', 'Ø', 'Ǿ', 'Ơ', 'Ŕ', 'Ř', 'Ŗ', 'Ś', 'Ŝ', 'Š', 'Ş', 'Ș', 'Ṣ', 'Ť', 'Ţ', 'Ṭ', 'Ú', 'Ù', 'Û', 'Ü', 'Ǔ', 'Ŭ', 'Ū', 'Ũ', 'Ű', 'Ů', 'Ų', 'Ụ', 'Ư', 'Ẃ', 'Ẁ', 'Ŵ', 'Ẅ', 'Ƿ', 'Ý', 'Ỳ', 'Ŷ', 'Ÿ', 'Ȳ', 'Ỹ', 'Ƴ', 'Ź', 'Ż', 'Ž', 'Ẓ', 'á', 'à', 'â', 'ä', 'ǎ', 'ă', 'ā', 'ã', 'å', 'ǻ', 'ą', 'ɓ', 'ć', 'ċ', 'ĉ', 'č', 'ç', 'ď', 'ḍ', 'ɗ', 'é', 'è', 'ė', 'ê', 'ë', 'ě', 'ĕ', 'ē', 'ę', 'ẹ', 'ǝ', 'ə', 'ɛ', 'ġ', 'ĝ', 'ǧ', 'ğ', 'ģ', 'ɣ', 'ĥ', 'ḥ', 'ħ', 'ı', 'í', 'ì', 'i', 'î', 'ï', 'ǐ', 'ĭ', 'ī', 'ĩ', 'į', 'ị', 'ĵ', 'ķ', 'ƙ', 'ĸ', 'ĺ', 'ļ', 'ł', 'ľ', 'ŀ', 'ŉ', 'ń', 'ň', 'ñ', 'ņ', 'ó', 'ò', 'ô', 'ö', 'ǒ', 'ŏ', 'ō', 'õ', 'ő', 'ọ', 'ø', 'ǿ', 'ơ', 'ŕ', 'ř', 'ŗ', 'ś', 'ŝ', 'š', 'ş', 'ș', 'ṣ', 'ſ', 'ť', 'ţ', 'ṭ', 'ú', 'ù', 'û', 'ü', 'ǔ', 'ŭ', 'ū', 'ũ', 'ű', 'ů', 'ų', 'ụ', 'ư', 'ẃ', 'ẁ', 'ŵ', 'ẅ', 'ƿ', 'ý', 'ỳ', 'ŷ', 'ÿ', 'ȳ', 'ỹ', 'ƴ', 'ź', 'ż', 'ž', 'ẓ', 'Α', 'Ά', 'Β', 'Γ', 'Δ', 'Ε', 'Έ', 'Ζ', 'Η', 'Ή', 'Θ', 'Ι', 'Ί', 'Ϊ', 'Κ', 'Λ', 'Μ', 'Ν', 'Ξ', 'Ο', 'Ό', 'Π', 'Ρ', 'Σ', 'Τ', 'Υ', 'Ύ', 'Ϋ', 'Φ', 'Χ', 'Ψ', 'Ω', 'Ώ', 'α', 'ά', 'β', 'γ', 'δ', 'ε', 'έ', 'ζ', 'η', 'ή', 'θ', 'ι', 'ί', 'ϊ', 'ΐ', 'κ', 'λ', 'μ', 'ν', 'ξ', 'ο', 'ό', 'π', 'ρ', 'σ', 'ς', 'τ', 'υ', 'ύ', 'ϋ', 'ΰ', 'φ', 'χ', 'ψ', 'ω', 'ώ', 'Æ', 'Ǽ', 'Ǣ', 'Ð', 'Đ', 'Ĳ', 'Ŋ', 'Œ', 'Þ', 'Ŧ', 'æ', 'ǽ', 'ǣ', 'ð', 'đ', 'ĳ', 'ŋ', 'œ', 'ß', 'þ', 'ŧ' );
  static $to = array( 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'B', 'C', 'C', 'C', 'C', 'C', 'D', 'D', 'D', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'G', 'G', 'G', 'G', 'G', 'G', 'H', 'H', 'H', 'I', 'I', 'I', 'I', 'I', 'I', 'I', 'I', 'I', 'I', 'I', 'I', 'J', 'K', 'K', 'L', 'L', 'L', 'L', 'L', 'N', 'N', 'N', 'N', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'R', 'R', 'R', 'S', 'S', 'S', 'S', 'S', 'S', 'T', 'T', 'T', 'U', 'U', 'U', 'U', 'U', 'U', 'U', 'U', 'U', 'U', 'U', 'U', 'U', 'W', 'W', 'W', 'W', 'W', 'Y', 'Y', 'Y', 'Y', 'Y', 'Y', 'Y', 'Z', 'Z', 'Z', 'Z', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'b', 'c', 'c', 'c', 'c', 'c', 'd', 'd', 'd', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'g', 'g', 'g', 'g', 'g', 'g', 'h', 'h', 'h', 'i', 'i', 'i', 'i', 'i', 'i', 'i', 'i', 'i', 'i', 'i', 'i', 'j', 'k', 'k', 'q', 'l', 'l', 'l', 'l', 'l', 'n', 'n', 'n', 'n', 'n', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'r', 'r', 'r', 's', 's', 's', 's', 's', 's', 's', 't', 't', 't', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'w', 'w', 'w', 'w', 'w', 'y', 'y', 'y', 'y', 'y', 'y', 'y', 'z', 'z', 'z', 'z', 'A', 'A', 'V', 'G', 'D', 'E', 'E', 'Z', 'I', 'I', 'Th', 'I', 'I', 'I', 'K', 'L', 'M', 'N', 'X', 'O', 'O', 'P', 'R', 'S', 'T', 'Y', 'Y', 'Y', 'F', 'Ch', 'Ps', 'O', 'O', 'a', 'a', 'v', 'g', 'd', 'e', 'e', 'z', 'i', 'i', 'th', 'i', 'i', 'i', 'i', 'k', 'l', 'm', 'n', 'x', 'o', 'o', 'p', 'r', 's', 's', 't', 'y', 'y', 'y', 'y', 'f', 'ch', 'ps', 'o', 'o', 'Ae', 'Ae', 'Ae', 'Dh', 'Dj', 'Ij', 'Ng', 'Oe', 'Th', 'Th', 'ae', 'ae', 'ae', 'dh', 'dj', 'ij', 'ng', 'oe', 'ss', 'th', 'th' );
  return str_replace($from, $to, $str);
}

function get_fields() {
  global $_nfield;
  if (!isset($_nfield)) return;
  $fields = array();
  for ($i = 1; $i <= $_nfield; $i++) {
    if (isset($_REQUEST["f$i"]) and
	isset($_REQUEST["v$i"]) and
	($_REQUEST["f$i"] != '') and
	($_REQUEST["v$i"] != '')) {

      // dyuret 20110505:
      // stripcslashes is a bug here, when you edit and resubmit an
      // entry without any changes, you lose slashes.  I don't
      // remember why I did it this way in the first place, so I
      // will leave this code here in case:
      //$fi = stripcslashes($_REQUEST["f$i"]);
      //$vi = stripcslashes($_REQUEST["v$i"]);
      //echo "<pre>get_fields: [$fi][$vi]</pre>\n";
      //array_set_values($fields, $fi, $vi);

      array_set_values($fields, $_REQUEST["f$i"], $_REQUEST["v$i"]);
    }
  }
  return $fields;
}

function array_set_values(&$fields, $f, $v) {
  if (!isset($fields[$f])) {
    $fields[$f] = $v;
  } elseif (is_array($fields[$f])) {
    $fields[$f][] = $v;
  } else {
    $fields[$f] = array($fields[$f], $v);
  }
}

function deep_in_array($value, $array) {
  foreach ($array as $item) {
    if (($item == $value) ||
	(is_array($item) &&
	 deep_in_array($value, $item)))
      return true;
  }
  return false;
}
 
/** insert($entry, $id) inserts or replaces an entry
 */
function insert(&$entry, $id = NULL) {
  if (!isset($id)) {
    $id = sql_newid();
    foreach ($entry as $f => $v) {
      foreach ((is_array($v)?$v:array($v)) as $val) {
	sql_insert_field($id, $f, $val);
      }
    }
  } else {
    $old = sql_select_entry($id);
    //echo '<pre>'; print_r($old); print_r($entry); echo '</pre>';
    foreach ($old as $f => $v) {
      if (isset($entry[$f]) and field_equal($v, $entry[$f])) continue;
      foreach ((is_array($v)?$v:array($v)) as $val) {
	//print "Deleting [$f] = [$val] <br/>\n";
	sql_delete_field($id, $f, $val);
      }
      unset($old[$f]);
    }
    //echo '<pre>'; print_r($old); print_r($entry); echo '</pre>';
    foreach ($entry as $f => $v) {
      if (isset($old[$f])) {
	field_equal($v, $old[$f]) or exit("Array diff error");
      } else {
	foreach ((is_array($v)?$v:array($v)) as $val) {
	  //print "Inserting [$f] = [$val] <br/>\n";
	  sql_insert_field($id, $f, $val);
	}
      }
    }
    //echo '<pre>'; print_r($old); print_r($entry); echo '</pre>';
  }
  show(array($id));
}

function field_equal(&$a1, &$a2) {
  if (is_array($a1) and is_array($a2)) {
    foreach ($a1 as $f => $v)
      if (!isset($a2[$f]) or !field_equal($v, $a2[$f]))
	return false;
    foreach ($a2 as $f => $v)
      if (!isset($a1[$f]) or !field_equal($v, $a1[$f]))
	return false;
    return true;
  } else {
    return ($a1 == $a2);
  }
}
 
/** import()
 */
function import() {
  global $array_fields, $_nocheck;
  $entries = array();
  $errors = '';
  $fields = array();
  $citekeys = array();
  $nline = 0;
  foreach(explode("\n", $_REQUEST["text"]) as $line) {
    $nline++;
    $line = trim($line);
    $line = preg_replace('/=\s*\\\\"/', '="', $line);
    $line = preg_replace('/\\\\"\s*,/', '",', $line);
    if ($line == '') {
      continue;
    } elseif ($line == '}') {
      $entries[] = $fields;
      $errors .= implode("\n", entry_errors($fields,-1));
      $fields = array();
    } elseif (preg_match('/^\s*@(\S+?)\s*{\s*(\S+)\s*,\s*$/', $line, $m)) {
      $type = strtolower($m[1]); $key = $m[2];
      if (isset($fields['entrytype'])) {
	$errors .= "$nline: Duplicate header: [$line]\n";
      }
      if (isset($citekeys[$key])) {
	$errors .= "$nline: Duplicate citekey: [$line]\n";
      }
      $citekeys[$key] = 1;
      $fields['entrytype'] = trim($type);
      $fields['citekey'] = trim($key);
    } elseif (preg_match('/^\s*(\S+?)\s*=\s*[{"](.+)["}]\s*,?\s*$/', $line, $m)) {
      $key = strtolower(trim($m[1])); $val = trim($m[2]);
      if (isset($fields[$key])) {
	$errors .= "$nline: Duplicate $key: [$line]\n";
      }
      if (isset($array_fields[$key])) {
	  $vals = explode($array_fields[$key], $val);
	  if (count($vals) > 1) {
	    for ($i = 0; $i < count($vals); $i++) {
	      $vals[$i] = trim($vals[$i]);
	    }
	    $fields[$key] = $vals;
	  } else {
	    $fields[$key] = $val;
	  }
      } else {
	$fields[$key] = trim($m[2]);
      }
    } else {
      $errors .= "$nline: Bad line: [$line]\n";
    }
  }
  if (count($fields) > 0) {
    $entries[] = $fields;
    $errors .= implode("\n", entry_errors($fields,-1));
  }
  if ($errors && !$_nocheck) {
    echo h('p', h('strong', 'Import errors'));
    echo "<pre>\n$errors\nEntry:\n";
    echo $_REQUEST["text"];
    echo "\$_FILES: ";
    print_r($_FILES["file"]);
    echo "\$_REQUEST: ";
    print_r($_REQUEST);
    echo "\$_SERVER: ";
    print_r($_SERVER);
    echo "\n</pre>\n";
  } else {
    foreach ($entries as $e) {
      entry($e);
    }
  }
}

function import_form() {
  echo h_start('form', array('action' => $_SERVER['PHP_SELF'],
			     'name' => 'import_form',
			     'method' => 'post'));
  echo h('p',
	 h('strong', 'Import BibTeX').' &nbsp; '.
	 h_hidden('fn', 'import').
	 h_submit('Submit').
	 h_button('Cancel', 'window.back()').
	 h_submit('Don\'t check errors', 'nocheck')
	 );
  echo h_textarea('text', '', 20, 60);
  echo h_end('form');
}
 
/** help()
 * TODO: write first few sections of help.
 */
function help() {
  //echo h('strong', 'help not implemented yet.');
  //print_r($_REQUEST);
  global $html_help, $html_header, $html_footer;
  echo $html_header;
  echo $html_help;
  echo $html_footer;
}
 
/** download() TODO prints out the source code.
 */
function download() {
  header('Content-type: text/plain');
  $filename = $_SERVER['SCRIPT_FILENAME'];
  $handle = fopen($filename, 'r');
  echo fread($handle, filesize($filename));
  fclose($handle);
}
 
/** login() presents the user with a login prompt
 */
function login() {
  global $_user, $sql_user;		// sql_init sets this.
  $php_user = isset($_SERVER['PHP_AUTH_USER']) ?
    $_SERVER['PHP_AUTH_USER'] : NULL;
  $target = isset($_SERVER['HTTP_REFERER']) ?
    $_SERVER['HTTP_REFERER'] :
    $_SERVER['PHP_SELF'];
  if (!isset($php_user)
      || ($sql_user != $php_user)
      || (isset($_user) && ($_user == $php_user))) {
    header( 'WWW-Authenticate: Basic realm="BibTeX"' );
    header( 'HTTP/1.0 401 Unauthorized' );
    //echo "Failed: sql_user=$sql_user, PHP_AUTH_USER=$_SERVER[PHP_AUTH_USER]\n";
    echo h_script("window.location.replace('$target')");
  } else {
    //echo "Success: sql_user=$sql_user, PHP_AUTH_USER=$_SERVER[PHP_AUTH_USER]\n";
    header("Location: $target");
  }
}
 
/** print_entry($entry, $id, $n) prints a single bibliography entry.
 *   entry: is an array containing all fields and values
 *   entryid: is the uniq id for entry in the database
 *   n: used for generating the checkbox number
 */
$entry_format = array
(
 array('author', '_', 'print_author_field'),
 array('editor', '_', 'print_author_field'),

 array('year', '. _', NULL),

 array('title', '. _', 'print_title_field'),

 array('journal', '. <i>_</i>', NULL),
 array('booktitle', '. In <i>_</i>', NULL),
 array('type', '. _', NULL),

 array('volume', ', vol _', NULL),
 array('number', ', no _', NULL),
 array('pages',  ', pp _', NULL),

 array('address', ', _', NULL),
 array('month', ', _', NULL),

 array('school', '. _', NULL),
 array('institution', '. _', NULL),
 array('howpublished', '. _' , NULL),
 array('organization', '. _', NULL),
 array('publisher', '. _', NULL),

 array('note', '. _', NULL),
 array('citations', '. cit _', NULL),

 array('keywords', '. [<small>_</small>]', NULL),
 array('url', ' _', 'print_url_field'),

# array('abstract', '<blockquote><small><b>Abstract:</b> _ </small></blockquote>', NULL),
# array('annote', '<blockquote><small><b>Notes:</b> _ </small></blockquote>', NULL),
# array('entrytype', '[_:', NULL),
# array('citekey', '_] ', NULL),
);

function print_entry(&$entry, $entryid = NULL, $n = NULL) {
  global $entry_format, $sql_priv;

  if (isset($entry['note']) && preg_match('/in preparation/', $entry['note'])) {
    echo h('div', array('style' => 'background-color:yellow;'), true);
  } else if (isset($entry['note']) && preg_match('/submitted/', $entry['note'])) {
    echo h('div', array('style' => 'background-color:palegreen;'), true);
  } else if (isset($entry['note']) && preg_match('/to appear/', $entry['note'])) {
    echo h('div', array('style' => 'background-color:wheat;'), true);
  } else if ($n & 1) {
#    echo h('div', array('style' => 'background-color:paleturquoise;'), true);
    echo h('div', true);
  } else {
    echo h('div', true);
  } 
  if (isset($entryid) and isset($n)) {
    echo h_checkbox("e$n", $entryid);
  }
  foreach ($entry_format as $fmt) {
    $field = $fmt[0];
    if (!isset($entry[$field])) continue;
    if ($field == 'editor' and isset($entry['author'])) continue;
    $value = $entry[$field];
    $pattern = $fmt[1];
    $pattern = explode('_', $pattern);
    echo $pattern[0];
    $print_fn = $fmt[2];
    if (!isset($print_fn)) print_field($field, $value);
    else $print_fn($entry, $field, $value);
    echo $pattern[1];
  }
  print_extra_fields($entry, $entryid);
  echo h('div', false);
}

function print_field($field, $value, $txt=NULL) {
  if (is_array($value)) {
    foreach($value as $v) {
      if ($v != $value[0]) echo ', ';
      print_field($field, $v, $txt);
    }
  } else {
    if (!isset($txt)) $txt = $value;
    //$txt = htmlspecialchars($txt);
    $txt = latex2html($txt);
    echo h_get($txt, 
	       array('fn' => 'select', 
		     'field' => $field, 
		     'value' => $value),
	       array('class' => 'local'));
  }
}
 
function print_author_field(&$entry, $field, $value) {
  if (!is_array($value)) {
    print_field($field, $value, name_flip($value));
    if ($field == 'editor') echo ', editor';
  } else {
    $n = count($value);
    for ($i = 0; $i < $n; $i++) {
      $v = $value[$i];
      $txt = name_flip($v);
      if ($v == 'others') {
	echo ', et al';
      } else {
	if ($i == $n - 1) echo ' and ';
	elseif ($i > 0) echo ', ';
	print_field($field, $v, $txt);
      }
    }
    if ($field == 'editor') echo ', editors';
  }
}

function print_title_field(&$entry, $field, $value) {
  $type = $entry['entrytype'];
  $value = latex2html($value);
  if (in_array($type, array('book', 'inbook', 'manual', 'proceedings', 'phdthesis')))
    $txt = "<i>$value</i>";
  else $txt = $value;
  $url = isset($entry['url']) ? $entry['url'] : NULL;
  if (is_array($url)) $url = $url[0];
  if (isset($url)) {
    echo h_a($txt, $url);
  } else {
    echo h_a($txt, google_url($entry), array('class' => 'google0'));
  }
}

function print_url_field(&$entry, $field, $value) {
  $attr['class'] = 'url';
  if (isset($value)) {
      if (!is_array($value)) $value = array($value);
      for ($i = 0; $i < count($value); $i++) {
	$url = $value[$i];
	$attr['href'] = htmlspecialchars($url);
	$text = 'url';
	if (preg_match('/\.pdf$/i', $url)) { $text = 'pdf'; }
	elseif (preg_match('/\.ps(\.gz)?$/i', $url)) { $text = 'ps'; }
	elseif (preg_match('/\.ppt$/i', $url)) { $text = 'ppt'; }
	elseif (preg_match('/\.doc$/i', $url)) { $text = 'doc'; }
	elseif (preg_match('/\.mobi$/i', $url)) { $text = 'mobi'; }
	elseif (preg_match('/\.epub/i', $url)) { $text = 'epub'; }
	elseif (preg_match('/\.djvu$/i', $url)) { $text = 'djvu'; }
	echo ' '.h('a', $attr, $text);
      }
   }
}

function print_extra_fields($entry, $entryid) {
  /* add abstract and annote if exist */
  $attr1['class'] = 'abstract';
  $attr1['href'] = "";
  if (isset($entry['abstract'])) {
    $attr1['onclick'] = "toggleVisible('abstract$entryid');return(false);";
    echo ' '.h('a', $attr1, 'abstract');
  }
  if (isset($entry['annote'])) {
    $attr1['onclick'] = "toggleVisible('annote$entryid');return(false);";
    echo ' '.h('a', $attr1, 'notes');
  }

  /* always add the standard google urls */
  $attr['class'] = 'google';
  $attr['href'] = google_url($entry);
  echo ' '.h('a', $attr, 'google');
  $attr['href'] = scholar_url($entry);
  echo ' '.h('a', $attr, 'scholar');
  if ($entry['entrytype'] == 'book') {
    $attr['href'] = books_url($entry);
    echo ' '.h('a', $attr, 'books');
  }

  /* edit urls if user has privilege */
  global $sql_priv;
  if (isset($sql_priv['DELETE']))
    echo ' '.h_get('edit',
		   array('fn' => 'edit_entry',
			 'id' => $entryid),
		   array('class' => 'edit'));
  if (isset($sql_priv['INSERT']))
    echo ' '.h_get('copy',
		   array('fn' => 'copy_entry',
			 'id' => $entryid),
		   array('class' => 'edit'));

  /* finally add the initially invisible abstract and annote */
  $attr2['class'] = "abstract";
  $attr2['style'] = "display:none";
  if (isset($entry['abstract'])) {
    $attr2['id'] = "abstract$entryid";
    echo ' '.h('div', $attr2, h('pre', htmlspecialchars($entry['abstract'])));
  }
  if (isset($entry['annote'])) {
    $attr2['id'] = "annote$entryid";
    echo ' '.h('div', $attr2, h('pre', htmlspecialchars($entry['annote'])));
  }
}

function name_flip($str) {
  $parts = explode(', ', $str);
  $first = array_pop($parts);
  return $first . ' '. implode(', ', $parts);
}

function google_url($entry) {
  return 'http://www.google.com/search?q='.google_key($entry);
}

function scholar_url($entry) {
  return 'http://scholar.google.com/scholar?q='.google_key($entry);
}

function books_url($entry) {
  return 'http://books.google.com/books?q='.google_key($entry);
}

function google_key($entry) {
    $author = isset($entry['author']) ? $entry['author'] :
      (isset($entry['editor']) ? $entry['editor'] : '');
    if (is_array($author)) $author = $author[0];
    $title = $entry['title'];
    return urlencode("\"$title\" $author");
}


/** utf2latex($txt) converts utf8 characters to latex sequences.
 * BUG: It only handles Turkish characters.
 */
function utf2latex($txt) {
  $txt = str_replace(array('ç','Ç','ğ','Ğ','ı','İ','ö','Ö','ş','Ş','ü','Ü'),
		     array('{\c{c}}','{\c{C}}','{\u{g}}','{\u{G}}','{\i}','{\.I}','{\"o}','{\"O}','{\c{s}}','{\c{S}}','{\"u}','{\"U}'),
		     $txt);
  return $txt;
}

/** latex2html($txt) converts latex sequences to html entities in txt.
 * htmlspecialchars: only ampersand, double and (optionally) single quotes,
 * < and > characters.
 * BUG: It does not get rid of {} characters.
 */
function latex2html($txt) {
  $txt = str_replace('&', '&amp;', $txt);
  $txt = str_replace('\\&amp;', '&amp;', $txt);
  $txt = str_replace('!`', '&iexcl', $txt);
  $txt = str_replace('<', '&iexcl', $txt);
  $txt = str_replace('?`', '&iquest', $txt);
  $txt = str_replace('>', '&iquest', $txt);
  $txt = preg_replace('/{?\\\\([#$%&_{}])}?/', '$1', $txt);
  $txt = preg_replace_callback('/{?(\\\\(\w+))}?/', 'latex2html_callback', $txt);
  $txt = preg_replace_callback('/{?(\\\\.{?.}?)}?/', 'latex2html_callback', $txt);
  $txt = str_replace('"', '&quot;', $txt);
  $txt = preg_replace('/{([^{}]+)}/', '$1', $txt);
  return $txt;
}

function latex2html_callback($m) {
  global $latex2html;
  $str = $m[1];
  if (isset($latex2html[$str])) return $latex2html[$str];
  $str = str_replace('{', '', $str);
  $str = str_replace('}', '', $str);
  if (isset($latex2html[$str])) return $latex2html[$str];
  return $m[0];
}

$latex2html = array
(
# Seven latex symbols
 '\#' => '#',
 '\$' => '$',
 '\%' => '%',
 '\&' => '&amp;',
 '\_' => '_',
 '\{' => '&#x7B;',
 '\}' => '&#x7D;',

# Latin-1 characters 160-255
# '' => '&nbsp;',		// 160
 '!`' => '&iexcl;',  '<' => '&iexcl;',
# '' => '&cent;',
 '\pounds' => '&pound',
# '' => '&curren;',
# '' => '&yen;',
# '' => '&brvbar;',
 '\S' => '&sect;',
# '' => '&uml;',
 '\copyright' => '&copy;',
# '' => '&ordf;',		// 170
# '' => '&laquo;',
# '' => '&not;',
# '' => '&shy;',
# '' => '&reg;',
# '' => '&macr;',
# '' => '&deg;',
# '' => '&plusmn;',
# '' => '&sup2;',
# '' => '&sup3;',
# '' => '&acute;',		// 180
# '' => '&micro;',
 '\P' => '&para;',
# '' => '&middot;',
# '' => '&cedil;',
# '' => '&sup1;',
# '' => '&ordm;',
# '' => '&raquo;',
# '' => '&frac14;',
# '' => '&frac12;',
# '' => '&frac34;',		// 190
 '?`' => '&iquest;', '>' => '&iquest;',
 '\`A' => '&Agrave;',
 "\'A" => '&Aacute;',
 '\^A' => '&Acirc;',
 '\~A' => '&Atilde;',
 '\"A' => '&Auml;',
 '\AA' => '&Aring;',
 '\AE' => '&AElig;',
 '\c{C}' => '&Ccedil;',
 '\`E' => '&Egrave;',		// 200
 "\'E" => '&Eacute;',
 '\^E' => '&Ecirc;',
 '\"E' => '&Euml;',
 '\`I' => '&Igrave;',
 "\'I" => '&Iacute;',
 '\^I' => '&Icirc;',
 '\"I' => '&Iuml;',
# '' => '&ETH;',
 '\~N' => '&Ntilde;',
 '\`O' => '&Ograve;',		// 210
 "\'O" => '&Oacute;',
 '\^O' => '&Ocirc;',
 '\~O' => '&Otilde;',
 '\"O' => '&Ouml;',
# '' => '&times;',
 '\O' => '&Oslash;',
 '\`U' => '&Ugrave;',
 "\'U" => '&Uacute;',
 '\^U' => '&Ucirc;',
 '\"U' => '&Uuml;',		// 220
 "\'Y" => '&Yacute;',
# '' => '&THORN;',
 '\ss' => '&szlig;',
 '\`a' => '&agrave;',
 "\'a" => '&aacute;',
 '\^a' => '&acirc;',
 '\~a' => '&atilde;',
 '\"a' => '&auml;',
 '\aa' => '&aring;',
 '\ae' => '&aelig;',		// 230
 '\c{c}' => '&ccedil;',
 '\`e' => '&egrave;',
 "\'e" => '&eacute;',
 '\^e' => '&ecirc;',
 '\"e' => '&euml;',
 '\`i' => '&igrave;',
 "\'i" => '&iacute;',
 '\^i' => '&icirc;',
 '\"i' => '&iuml;',
# '' => '&eth;',		// 240
 '\~n' => '&ntilde;',
 '\`o' => '&ograve;',
 "\'o" => '&oacute;',
 '\^o' => '&ocirc;',
 '\~o' => '&otilde;',
 '\"o' => '&ouml;',
# '' => '&divide;',
 '\o' => '&oslash;',
 '\`u' => '&ugrave;',
 "\'u" => '&uacute;',		// 250
 '\^u' => '&ucirc;',
 '\"u' => '&uuml;',
 "\'y" => '&yacute;',
# '' => '&thorn;',
 '\"y' => '&yuml;',

# Selected Latin Extended A
 '\u{G}' => '&#x011E;',
 '\u{g}' => '&#x011F;',
 '\.I'   => '&#x0130;',
 '\i'    => '&#x0131;',
 '\c{S}' => '&#x015E;',
 '\c{s}' => '&#x015F;',
 '\v{C}' => '&#x010C;',
 '\v{c}' => '&#x010D;',

# Other
 '\texttrademark' => '&trade;',
 '\textregistered' => '&reg;',

);
 
/** html functions */

/* h() creates an html element string. 
 * h($name): creates an empty element (e.g. <br/>)
 * h($name, $array): creates an empty element with attr = array
 * h($name, $string) creates element with content = string
 * h($name, $array, $string) creates element with attr and content
 * h($name, $array, true): creates a start tag with attributes
 * h($name, true): creates a start tag
 * h($name, false): creates an end tag
 */
function h($name, $attr=NULL, $content=NULL) {
  if (!is_array($attr)) {
    $content = $attr;
    unset($attr);
  }
  $vals = '';
  if (isset($attr)) {
    foreach ($attr as $aname => $value) {
      $esc = htmlspecialchars($value);
      //$esc = $value;
      $vals .= " $aname=\"$esc\"";
    }
  }
  if (!isset($content)) 
    return "<$name$vals/>\n";
  elseif (is_bool($content))
    return $content ? "<$name$vals>\n" : "</$name>\n";
  else
    return in_array($name, array('a', 'b', 'i', 'u', 'option', 'strong')) ?
      "<$name$vals>$content</$name>" :
      "<$name$vals>\n$content</$name>\n";
}

function h_start($name, $attr=NULL) {
  return isset($attr) ?
    h($name, $attr, true) :
    h($name, true);
}

function h_end($name) {
  return h($name, false);
}

function h_hidden($name, $value) {
  $attr['type'] = 'hidden';
  $attr['name'] = $name;
  $attr['value'] = $value;
  return h('input', $attr);
}

function h_file() {
  $attr['type'] = 'file';
  $attr['name'] = 'file';
  $attr['id'] = 'file';
  return h('input', $attr);
}

function h_text($name, $value=NULL, $attr=NULL) {
  $attr['type'] = 'text';
  $attr['name'] = $name;
  if (isset($value)) $attr['value'] = $value;
  return h('input', $attr);
}

function h_textarea($name, $value=NULL, $rows=NULL, $cols=NULL) {
  $attr['name'] = $name;
  if (isset($rows)) $attr['rows'] = $rows;
  if (isset($cols)) $attr['cols'] = $cols;
  if (isset($value)) return h('textarea', $attr, $value);
  else return h_start('textarea', $attr).h_end('textarea');
}

function h_submit($value=NULL, $name=NULL, $onclick=NULL) {
  $attr['type'] = 'submit';
  if (isset($value)) $attr['value'] = $value;
  if (isset($name)) $attr['name'] = $name;
  if (isset($onclick)) $attr['onclick'] = $onclick;
  return h('input', $attr);
}

function h_button($value, $onclick) {
  return h('input',
	   array('type' => 'button', 
		 'value' => $value, 
		 'onclick' => $onclick));
}

function h_script($script) {
  echo "<script><!--\n$script\n--></script>\n";
}

function h_checkbox($name, $value, $onchange = NULL) {
  $attr = array
    ('type' => 'checkbox', 'name' => $name, 'value' => $value);
  if (isset($onchange)) $attr['onchange'] = $onchange;
  return h('input', $attr);
}

function h_radio($name, $value, $onchange = NULL) {
  $attr = array
    ('type' => 'radio', 'name' => $name, 'value' => $value);
  if (isset($onchange)) $attr['onchange'] = $onchange;
  return h('input', $attr);
}

function h_select($name, $values, $title=NULL, $onchange=NULL) {
  if (!$title) $opts = '';
  else $opts = h('option', array('value' => ''), $title)."\n";
  foreach ($values as $value) {
    $option = strlen($value) < 16 ? $value : substr($value, 0, 15) . '.';
    $option = htmlspecialchars($option);
    $opts .= h('option', array('value' => $value), $option)."\n";
  }
  $attr = array('name' => $name, 'size' => 1);
  if ($onchange) $attr['onchange'] = $onchange;
  return h('select', $attr, $opts);
}

function h_form() {
  $n = func_num_args();
  $input = '';
  for ($i = 0; $i < $n; $i++) {
    $arg = func_get_arg($i);
    if ($i == 0 && is_array($arg)) $attr = $arg;
    else $input .= $arg;
  }
  $attr['action'] = $_SERVER['PHP_SELF'];
  return h('form', $attr, $input);
}

function h_get($txt, $vars, $attr = NULL) {
  $url = "$_SERVER[PHP_SELF]?";
  foreach($vars as $name => $value) {
    if ($url[strlen($url)-1] != '?') $url .= '&';
    $url .= urlencode($name) . '=' . urlencode($value);
  }
  return h_a($txt, $url, $attr);
}

function h_a($txt, $url, $attr = NULL) {
  $attr['href'] = $url;
  return h('a', $attr, $txt);
}

function h_help($txt, $section = NULL) {
  if (isset($section)) 
    return h_a($txt, "$_SERVER[PHP_SELF]?fn=help#$section");
  else return h_a($txt, "$_SERVER[PHP_SELF]?fn=help");
} 
 
/** sql functions 
 * TODO: check each sql statement for sql injection
 */

$sql_link = NULL;
$sql_user = NULL;
$sql_priv = NULL;

function sql_init() {
  global $mysql, $sql_link, $sql_user, $sql_priv;
  if (isset($sql_link)) sql_term();
  if (isset($_SERVER['PHP_AUTH_USER']) and
      isset($_SERVER['PHP_AUTH_PW']) and
      ($_SERVER['PHP_AUTH_USER'] != $mysql['user']))
    $sql_link = mysql_connect($mysql['host'], 
			      $_SERVER['PHP_AUTH_USER'],
			      $_SERVER['PHP_AUTH_PW']);
  if ($sql_link) $sql_user = $_SERVER['PHP_AUTH_USER'];
  else {
    $sql_link = mysql_connect($mysql['host'], $mysql['user'], $mysql['pass']);
    if ($sql_link) $sql_user = $mysql['user'];
    else sql_error('connect');
  }
  mysql_select_db($mysql['db']) 
    or sql_error('select_db');
  $sql_priv = sql_priv();
  if (!isset($sql_priv['SELECT']))
    sql_error('select');
}

function sql_term() {
  global $sql_link, $sql_user, $sql_priv;
  mysql_close($sql_link);
  unset($sql_link, $sql_user, $sql_priv);
}

/* issue ineffective statements to test for privileges */
function sql_priv() {
  global $mysql;
  $priv = array();
  $table = $mysql['table'];
  $q = "SELECT * FROM $table WHERE entryid = '-1'";
  $r = mysql_query($q);
  if ($r) $priv['SELECT'] = true;
  $q = "INSERT INTO $table SELECT * FROM $table WHERE entryid = '-1'";
  $r = mysql_query($q);
  if ($r) $priv['INSERT'] = true;
  $q = "UPDATE $table SET entryid = '0' WHERE entryid = '-1'";
  $r = mysql_query($q);
  if ($r) $priv['UPDATE'] = true;
  $q = "DELETE FROM $table WHERE entryid = '-1'";
  $r = mysql_query($q);
  if ($r) $priv['DELETE'] = true;
  return $priv;
}

function sql_query($q) {
  $result = mysql_query($q);
  if ($result) return $result;
  else sql_error($q);
}

function sql_error($q) {
  global $mysql;
  $err = mysql_error();
  echo "<b>mysql error: $err</b><br/>
In $q
<ol><li> Please create a table in your mysql database using: <br/>
<tt> CREATE TABLE $mysql[table] (entryid INT, field VARCHAR(255), value VARCHAR(65000), user VARCHAR(255), s SERIAL, INDEX(entryid), INDEX(value)); </tt>
</li><li> Check the following mysql parameters and correct them if necessary in $_SERVER[PHP_SELF]:
<ul><li>host = $mysql[host]
</li><li>user = $mysql[user]
</li><li>pass = $mysql[pass]
</li><li>db = $mysql[db]
</li><li>table = $mysql[table]
</li></ul></li></ol>
";
  die();
}

function sql_select_list(&$entryids) {
  global $mysql;
  if (!$entryids) return;
  $query = sprintf("SELECT * FROM %s WHERE entryid IN (%s) ORDER BY s",
		   $mysql['table'], implode(', ', $entryids));
  return sql_entries($query);
}

function sql_delete_list(&$entryids) {
  global $mysql;
  if (!$entryids) return;
  sql_query(sprintf("DELETE FROM %s WHERE entryid IN (%s)",
		    $mysql['table'], implode(', ', $entryids)));
}

function sql_select_entry($entryid) {
  $ids = array($entryid);
  $entries = sql_select_list($ids);
  return $entries[$entryid];
}

function sql_delete_entry($entryid) {
  $ids = array($entryid);
  sql_delete_list($ids);
}

function sql_search($string) {
  global $mysql;
  if (!isset($string)) return;
  $table = $mysql['table'];
  $ids = NULL;
  foreach (explode(" ", $string) as $value) {
    $query = "SELECT entryid FROM $table WHERE value LIKE '%$value%'";
    $result = sql_query($query);
    $newids = array();
    if (!isset($ids)) {
      while ($row = mysql_fetch_row($result))
	$ids[$row[0]] = 1;
    } else {
      while ($row = mysql_fetch_row($result)) {
	$id = $row[0];
	if (isset($ids[$id])) {
	  $newids[$id] = 1;
	}
      }
      $ids = $newids;
    }
    mysql_free_result($result);
    if (empty($ids)) {
      break;
    }
  }
  if (empty($ids)) return;
  return sql_select_list(array_keys($ids));
}

function sql_search1($value) {	// deprecated
  global $mysql;
  if (!isset($value)) return;
  $table = $mysql['table'];
  $value = mysql_real_escape_string($value);
  $query = "SELECT * FROM $table WHERE entryid IN (SELECT entryid FROM $table WHERE value LIKE '%$value%') ORDER BY s";
  return sql_entries($query);
}

function sql_select($field, $value) {
  global $mysql;
  if (!isset($value)) return;
  if (!isset($field)) return;
  $value = mysql_real_escape_string($value);
  $field = mysql_real_escape_string($field);
  $table = $mysql['table'];
  $query = "SELECT * FROM $table WHERE entryid IN (SELECT entryid FROM $table WHERE value='$value' AND field='$field') ORDER BY s";
  return sql_entries($query);
}

function sql_entries($query) {
  $result = sql_query($query);
  $e = array();
  while ($row = mysql_fetch_row($result))
    array_set_values($e[$row[0]], $row[1], $row[2]);
  mysql_free_result($result);
  return $e;
}
 
function sql_uniq($field) {
  global $mysql;
  $table = $mysql['table'];
  if ($field) {
    $field = mysql_real_escape_string($field);
    $query = "SELECT DISTINCT value FROM $table WHERE field='$field'";
  } else $query = "SELECT DISTINCT field FROM $table";
  $result = sql_query($query);
  while ($row = mysql_fetch_row($result)) 
    $answer[] = $row[0];
  mysql_free_result($result);
  return $answer;
}
 
function sql_insert_field($id, $field, $value) {
  global $mysql;
  $table = $mysql['table'];
  $id = mysql_real_escape_string($id);
  $field = mysql_real_escape_string($field);
  //echo "<pre>Before escape:[$value]</pre>";
  $value = mysql_real_escape_string($value);
  //echo "<pre>After escape:[$value]</pre>";
  sql_query("INSERT INTO $table (entryid, field, value, user) VALUES ('$id', '$field', '$value', CURRENT_USER)");
}

function sql_delete_field($id, $field, $value) {
  global $mysql;
  $table = $mysql['table'];
  $id = mysql_real_escape_string($id);
  $field = mysql_real_escape_string($field);
  $value = mysql_real_escape_string($value);
  sql_query("DELETE FROM $table WHERE entryid = '$id' AND field = '$field' AND value = '$value'");
}
 
function sql_delete_value($field, $value) {
  global $mysql;
  $table = $mysql['table'];
  $field = mysql_real_escape_string($field);
  $value = mysql_real_escape_string($value);
  sql_query("DELETE FROM $table WHERE field = '$field' AND value = '$value'");
}

function sql_update_value($field, $value, $newval) {
  global $mysql;
  $table = $mysql['table'];
  $field = mysql_real_escape_string($field);
  $value = mysql_real_escape_string($value);
  $newval = mysql_real_escape_string($newval);
  sql_query("UPDATE $table SET value = '$newval' WHERE field = '$field' AND value = '$value'");
}
 
function sql_newid() {
  global $mysql;
  $table = $mysql['table'];
  $query = "SELECT MAX(entryid) FROM $table";
  $result = sql_query($query);
  $row = mysql_fetch_row($result);
  $answer = $row[0] + 1;
  mysql_free_result($result);
  return $answer;
}
 
/** entry_types is the official specification for bibtex.  In addition
 * to these fields we have fields that are required for every type:
 * entrytype and citekey.  We have extra optional fields key, crossref
 * and annote.  We also added extra fields: url, keywords, doi, isbn,
 * issn, lccn, abstract: not specified in bibtex standard.  Finally the
 * program will accept any new field typed by the user.
 */
$extra_index_fields = array('entrytype', 'citekey');
$extra_urlkey_fields = array('url', 'keywords');
$extra_optional_fields = array('key', 'crossref', 'doi', 'isbn', 'issn', 'lccn', 'citations');
$extra_textarea_fields = array('abstract', 'annote');
$extra_fields = array_merge($extra_index_fields, $extra_optional_fields, $extra_textarea_fields, $extra_urlkey_fields);
$array_fields = array('author' => ' and ', 'editor' => ' and ', 'keywords' => ',', 'url' => ',');

$entry_types = array
(
 'article' => array
 ('required' => array('author', 'title', 'journal', 'year'),
  'optional' => array('volume', 'number', 'pages', 'month', 'note', 'publisher')),

 'book' => array
 ('required' => array(array('author', 'editor'), 'title', 'publisher', 'year'), 
  'optional' => array(array('volume', 'number'), 'series', 'address', 'edition', 'month', 'note')),

 'booklet' => array
 ('required' => array('title'), 
  'optional' => array('author', 'howpublished', 'address', 'month', 'year', 'note')),
 
 'conference' => array
 ('required' => array('author', 'title', 'booktitle', 'year'), 
  'optional' => array('editor', array('volume', 'number'), 'series', 'pages', 'address', 'month', 'organization', 'publisher', 'note')),

 'inbook' => array
 ('required' => array(array('author', 'editor'), 'title', array('chapter', 'pages'), 'publisher', 'year'), 
  'optional' => array(array('volume', 'number'), 'series', 'type', 'address', 'edition', 'month', 'note')),
 
 'incollection' => array
 ('required' => array('author', 'title', 'booktitle', 'publisher', 'year'), 
  'optional' => array('editor', array('volume', 'number'), 'series', 'type', 'chapter', 'pages', 'address', 'edition', 'month', 'note')),
 
 'inproceedings' => array
 ('required' => array('author', 'title', 'booktitle', 'year'), 
  'optional' => array('editor', array('volume', 'number'), 'series', 'pages', 'address', 'month', 'organization', 'publisher', 'note')),
 
 'manual' => array
 ('required' => array('title'), 
  'optional' => array('author', 'organization', 'address', 'edition', 'month', 'year', 'note')),
 
 'mastersthesis' => array
 ('required' => array('author', 'title', 'school', 'year'),
  'optional' => array('type', 'address', 'month', 'note')),
 
 'misc' => array
 ('required' => array(),
  'optional' => array('author', 'title', 'howpublished', 'month', 'year', 'note')),
 
 'phdthesis' => array
 ('required' => array('author', 'title', 'school', 'year'), 
  'optional' => array('type', 'address', 'month', 'note')),
 
 'proceedings' => array
 ('required' => array('title', 'year'),
  'optional' => array('editor', array('volume', 'number'), 'series', 'address', 'month', 'organization', 'publisher', 'note')),
 
 'techreport' => array
 ('required' => array('author', 'title', 'institution', 'year'), 
  'optional' => array('type', 'number', 'address', 'month', 'note')),
 
 'unpublished' => array
 ('required' => array('author', 'title', 'note'), 
  'optional' => array('month', 'year'))
 );
 
/** html_header, html_footer */

$html_header = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html 
     PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
     "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<title>BibTeX</title>
<script type="text/javascript">
<!--
function checkAll(val) {
  with(document.selection_form) {
    for (var i = 0; i < elements.length; i++){
      var elem = elements[i];
      if (elem.type == "checkbox") {
        elem.checked = val;
      }
    }
  }
}

function checkCnt() {
  var checked = 0;
  with(document.selection_form) {
    for (var i = 0; i < elements.length; i++){
      var elem = elements[i];
      if ((elem.type == "checkbox") && elem.checked) {
        checked++;
      }
    }
  }
  return checked;
}

function show() {
  var checked = checkCnt();
  if (checked == 0) {
    alert("Please select some entries first.");
  } else with(document.selection_form) {
    fn.value = "show";
    submit();
  }
}

function confirmDelete() {
  var checked = checkCnt();
  if (checked == 0) {
    alert("Please select some entries first.");
  } else with(document.selection_form) {
    if (confirm("Are you sure you want to delete these " + checked + " entries?")) {
      fn.value = "delete";
      submit();
    }
  }
}

function bibtex() {
  var checked = checkCnt();
  if ((checked > 0) ||
      (confirm("You have not made a selection.\nWould you like to export all entries in the database?")))
    with(document.selection_form) {
      fn.value = "bibtex";
      submit();
    }
}

function keyword(func) {
  var checked = checkCnt();
  if (checked == 0) {
    alert("Please select some entries first.");
  } else with(document.selection_form) {
    if (keyword.value == "") {
       alert("Please select a keyword.");
    } else {
       fn.value = func;
       submit();
    }
  }
}

function edit_value(v) {
  with(document.index_form) {
    nv = prompt("Enter the value to use instead of "
                + field.value + " = " + v +
                "\n(leave blank to delete the value)\n");
    if (nv != null) {
      if (nv == "") {
       msg = "Are you sure you want to delete all " + field.value +
             " fields with value = " + v + "?";
      } else {
       msg = "Are you sure you want to replace all " + field.value +
             "=\"" + v + "\" fields with " + field.value +
             "=\"" + nv + "\"?";
      }
      if (confirm(msg)) {
        newval.value = nv;
        submit();
      }
    }
  }
}

function toggleVisible(arg)
{
  var targetElement = document.getElementById(arg);
  if (targetElement.style.display == "none") {
    targetElement.style.display = ""; 
  } else {
    targetElement.style.display = "none";
  }
}
-->
</script>
<style type="text/css">
<!--
a.local { text-decoration:none; color:black }
a.google0 { text-decoration:none; color:black }
a.url { font-variant:small-caps }
a.edit { font-variant:small-caps; color:black }
a.google { font-variant:small-caps; color:black }
a.abstract { font-variant:small-caps; color:black }
div.abstract { font-style:oblique }
p.rcsid { font-size:xx-small }
input { vertical-align:top }
a.top { vertical-align:top }
-->
</style>
</head>
<body>
';
 
$html_footer = '<p class="rcsid">'.rcsid. 
'&nbsp;&nbsp; <a href="?fn=download">download</a> </p>
</body>
</html>
';

 
/** html_help */

$html_help = '
<h3><a name="entryformat">Entry Format</a></h3>

<p>BibTeX database files (extension <tt>.bib</tt>) are plain text
 files.  They consist of entries of various kinds. Each entry
 describes a book, an article, a manual, etc. Example:</p>
<a name="citekey" />

<pre>  @BOOK{kn:gnus,
     AUTHOR = "Donald E. Knudson",
     TITLE = "1966 World Gnus Almanac",
     PUBLISHER = {Permafrost Press},
     ADDRESS = {Novosibirsk}
  }</pre>

<p>The <tt>@BOOK</tt> states that this is an entry of type
 book. Various entry types are described below. The <tt>kn:gnus</tt>
 is the <em>cite key</em>, as it appears in the argument of a
 <tt>\cite</tt> command referring to the entry.</p>

<p>This entry has four <em>fields</em>, named <tt>AUTHOR</tt>,
 <tt>TITLE</tt>, <tt>PUBLISHER</tt>, and <tt>ADDRESS</tt>. The
 meanings of these and other fields are described below. A field
 consists of the name, followed by an "<tt>=</tt>" character with
 optional space around it, followed by its text. The text of a field
 is a string of characters, with no unmatched braces, surrounded by
 either a pair of braces or a pair of <tt>"</tt> characters. Entry
 fields are separated from one another, and from the key, by commas. A
 comma may have optional space around it.</p>

<p>The outermost braces that surround the entire entry may be replaced
 by parentheses. An end-of-line character counts as a space and one
 space is equivalent to one hundred (as in LaTeX). BibTeX ignores the
 case of letters in the entry type, cite key, and field names.</p>

<p>The quotes or braces can be omitted around text consisting entirely
 of numerals: <nobr><tt>Volume = "27"</nobr></tt> is equivalent to
 <nobr><tt>Volume = 27</tt>.</nobr></p>

<h3><a name="entryfields">Entry Fields</a></h3>

<p> When entering a reference in the database, the first thing to
decide is what type of entry it is.  No fixed classification scheme
can be complete, but BibTeX provides enough entry types to handle
almost any reference reasonably well. </p>

<p> References to different types of publications contain different
information; a reference to a journal article might include the volume
and number of the journal, which is usually not meaningful for a book.
Therefore, database entries of different types have different fields.
For each entry type, the fields are divided into three classes: </p>

<dl><dt><strong><a name="required">required</a></strong></dt>

<dd>Omitting the field will produce a warning message and, rarely, a
badly formatted bibliography entry.  If the required information is
not meaningful, you are using the wrong entry type.  However, if the
required information is meaningful but, say, already included is some
other field, simply ignore the warning.  </dd>

<dt><strong><a name="optional">optional</a></strong></dt>

<dd>The field\'s information will be used if present, but can be
omitted without causing any formatting problems.  You should include
the optional field if it will help the reader.  </dd>

<dt><strong><a name="ignored">ignored</a></strong></dt>

<dd>The field is ignored.  BibTeX ignores any field that is not
required or optional, so you can include any fields you want in a
<tt>bib</tt> file entry.  It\'s a good idea to put all relevant
information about a reference in its <tt>bib</tt> file entry--even
information that may never appear in the bibliography.  For example,
if you want to keep an abstract of a paper in a computer file, put it
in an <tt>abstract</tt> field in the paper\'s <tt>bib</tt> file entry.
The <tt>bib</tt> file is likely to be as good a place as any for the
abstract, and it is possible to design a bibliography style for
printing selected abstracts.  Note: Misspelling a field name will
result in its being ignored, so watch out for typos (especially for
optional fields, since BibTeX won\'t warn you when those are missing).
</dd> </dl>

<h3><a name="entrytype">Entry Types</a></h3>

<p> The following are the standard entry types, along with their
required and optional fields, that are used by the standard
bibliography styles.  The fields within each class (required or
optional) are listed in order of occurrence in the output, except that
a few entry types may perturb the order slightly, depending on what
fields are missing.  These entry types are similar to those adapted by
Brian Reid from the classification scheme of van&nbsp;Leunen&nbsp; for
use in the <em>Scribe</em> system.  The meanings of the individual
fields are explained in the next section.  Some nonstandard
bibliography styles may ignore some optional fields in creating the
reference.  Remember that, when used in the <tt>bib</tt> file, the
entry-type name is preceded by an <tt>@</tt> character.  </p>

<dl>

<dt><strong><a name="article">article</a></strong></dt>

<dd>An article from a journal or magazine.  
Required fields: 
<a href="#author">author</a>, 
<a href="#title">title</a>, 
<a href="#journal">journal</a>, 
<a href="#year">year</a>.
Optional fields: 
<a href="#volume">volume</a>, 
<a href="#number">number</a>,
<a href="#pages">pages</a>, 
<a href="#month">month</a>, 
<a href="#note">note</a>.
</dd>

<dt><strong><a name="book">book</a></strong></dt>

<dd>A book with an explicit publisher.  
Required fields: 
<a href="#author">author</a> or 
<a href="#editor">editor</a>, 
<a href="#title">title</a>, 
<a href="#publisher">publisher</a>, 
<a href="#year">year</a>.
Optional fields: 
<a href="#volume">volume</a> or 
<a href="#number">number</a>, 
<a href="#series">series</a>, 
<a href="#address">address</a>, 
<a href="#edition">edition</a>, 
<a href="#month">month</a>, 
<a href="#note">note</a>. 
</dd>

<dt><strong><a name="booklet">booklet</a></strong></dt>

<dd>A work that is printed and bound, but without a named publisher or
sponsoring institution.  
Required field: 
<a href="#title">title</a>.
Optional fields: 
<a href="#author">author</a>, 
<a href="#howpublished">howpublished</a>, 
<a href="#address">address</a>, 
<a href="#month">month</a>, 
<a href="#year">year</a>,
<a href="#note">note</a>. 
</dd>

<dt><strong><a name="conference">conference</a></strong></dt>

<dd>The same as <a href="#inproceedings">inproceedings</a>, included
for <em>Scribe</em> compatibility. </dd>

<dt><strong><a name="inbook">inbook</a></strong></dt>

<dd>A part of a book, which may be a chapter (or section or whatever)
and/or a range of pages.  
Required fields: 
<a href="#author">author</a> or 
<a href="#editor">editor</a>, 
<a href="#title">title</a>, 
<a href="#chapter">chapter</a> and/or 
<a href="#pages">pages</a>, 
<a href="#publisher">publisher</a>, 
<a href="#year">year</a>.  
Optional fields: 
<a href="#volume">volume</a> or 
<a href="#number">number</a>, 
<a href="#series">series</a>, 
<a href="#type">type</a>, 
<a href="#address">address</a>, 
<a href="#edition">edition</a>, 
<a href="#month">month</a>, 
<a href="#note">note</a>.
</dd>

<dt><strong><a name="incollection">incollection</a></strong></dt>

<dd>A part of a book having its own title.  
Required fields: 
<a href="#author">author</a>, 
<a href="#title">title</a>, 
<a href="#booktitle">booktitle</a>, 
<a href="#publisher">publisher</a>,
<a href="#year">year</a>.  
Optional fields: 
<a href="#editor">editor</a>, 
<a href="#volume">volume</a> or 
<a href="#number">number</a>, 
<a href="#series">series</a>, 
<a href="#type">type</a>,
<a href="#chapter">chapter</a>, 
<a href="#pages">pages</a>, 
<a href="#address">address</a>, 
<a href="#edition">edition</a>, 
<a href="#month">month</a>, 
<a href="#note">note</a>. 
</dd>

<dt><strong><a name="inproceedings">inproceedings</a></strong></dt>

<dd>An article in a conference proceedings.  
Required fields: 
<a href="#author">author</a>, 
<a href="#title">title</a>, 
<a href="#booktitle">booktitle</a>, 
<a href="#year">year</a>.  
Optional fields: 
<a href="#editor">editor</a>, 
<a href="#volume">volume</a> or 
<a href="#number">number</a>, 
<a href="#series">series</a>, 
<a href="#pages">pages</a>, 
<a href="#address">address</a>, 
<a href="#month">month</a>, 
<a href="#organization">organization</a>,
<a href="#publisher">publisher</a>, 
<a href="#note">note</a>. 
</dd>

<dt><strong><a name="manual">manual</a></strong></dt>

<dd>Technical documentation.  
Required field: 
<a href="#title">title</a>.  
Optional fields: 
<a href="#author">author</a>, 
<a href="#organization">organization</a>, 
<a href="#address">address</a>, 
<a href="#edition">edition</a>, 
<a href="#month">month</a>, 
<a href="#year">year</a>, 
<a href="#note">note</a>.
</dd>

<dt><strong><a name="mastersthesis">mastersthesis</a></strong></dt>

<dd>A Master\'s thesis.  
Required fields: 
<a href="#author">author</a>, 
<a href="#title">title</a>, 
<a href="#school">school</a>, 
<a href="#year">year</a>.  
Optional fields: 
<a href="#type">type</a>, 
<a href="#address">address</a>, 
<a href="#month">month</a>, 
<a href="#note">note</a>. 
</dd>

<dt><strong><a name="misc">misc</a></strong></dt>

<dd>Use this type when nothing else fits.  
Required fields: 
none.
Optional fields: 
<a href="#author">author</a>, 
<a href="#title">title</a>, 
<a href="#howpublished">howpublished</a>, 
<a href="#month">month</a>, 
<a href="#year">year</a>, 
<a href="#note">note</a>. 
</dd>

<dt><strong><a name="phdthesis">phdthesis</a></strong></dt>

<dd>A PhD thesis.  
Required fields: 
<a href="#author">author</a>, 
<a href="#title">title</a>, 
<a href="#school">school</a>, 
<a href="#year">year</a>.  
Optional fields: 
<a href="#type">type</a>,
<a href="#address">address</a>, 
<a href="#month">month</a>, 
<a href="#note">note</a>. 
</dd>

<dt><strong><a name="proceedings">proceedings</a></strong></dt>

<dd>The proceedings of a conference.  
Required fields: 
<a href="#title">title</a>, 
<a href="#year">year</a>.  
Optional fields:
<a href="#editor">editor</a>, 
<a href="#volume">volume</a> or 
<a href="#number">number</a>, 
<a href="#series">series</a>, 
<a href="#address">address</a>, 
<a href="#month">month</a>, 
<a href="#organization">organization</a>, 
<a href="#publisher">publisher</a>, 
<a href="#note">note</a>. 
</dd>

<dt><strong><a name="techreport">techreport</a></strong></dt>

<dd>A report published by a school or other institution, usually
numbered within a series.  
Required fields: 
<a href="#author">author</a>, 
<a href="#title">title</a>, 
<a href="#institution">institution</a>, 
<a href="#year">year</a>.  
Optional fields: 
<a href="#type">type</a>, 
<a href="#number">number</a>, 
<a href="#address">address</a>, 
<a href="#month">month</a>, 
<a href="#note">note</a>. 
</dd>

<dt><strong><a name="unpublished">unpublished</a></strong></dt>

<dd>A document having an author and title, but not formally published.
Required fields: 
<a href="#author">author</a>, 
<a href="#title">title</a>, 
<a href="#note">note</a>.  
Optional fields: 
<a href="#month">month</a>, 
<a href="#year">year</a>. 
</dd>

</dl>

<p>In addition to the fields listed above, each entry type also has an
optional <a href="#key">key</a> field, used in some styles for
alphabetizing, for cross referencing, or for forming a
<code>\bibitem</code> label.  You should include a <a href="#key">
key</a> field for any entry whose "author" information is missing; the
"author" information is usually the <a href="#author">
author</a> field, but for some entry types it can be the <a
href="#editor"> editor</a> or even the <a href="#organization">
organization</a> field.  Do not confuse the <a href="#key"> key</a>
field with the <a href="citekey"> citekey</a> that appears in the
<code>\cite</code> command and at the beginning of the database entry;
this field is named "key" only for compatibility with <i>Scribe</i>.
</p>

<p>BibTeX recognizes two more fields not mentioned above.  The <a
href="#annote"> annote</a> field can be used to produce an annotated
bibliography.  The <a href="#crossref"> crossref</a> field can be used
for cross-referencing between entries; see the LaTeX manual for
details. </p>

<a name="keywords"/> <a name="url"/> <a name="abstract"/>
<p>Bibtex.php uses some extra fields.  The <strong> keywords </strong>
field is used to assign one or more keywords to the entry that can be
used to group entries into subject areas or bibliographies.  The
<strong> url </strong> field is typically used to point to the actual
paper on the web.  Both keywords and url can have multiple values,
which must be entered on separate lines in the bibtex.php interface,
or comma separated in the bib file.  The <strong> abstract </strong>
field can be used to keep the abstract of a paper.  You can include
any other fields you want in an entry, bibtex.php will keep them in
its database, and BibTeX will ignore them when typesetting a
bibliography. </p>

<p>Finally, <a href="#entrytype"> entrytype</a>, which is used to
classify entries, and <a href="#citekey"> citekey</a>, which is used
for referencing an entry, are not fields in the above sense, but
appear as such in the bibtex.php interface. </p>


<h3><a name="fields">Fields</a></h3>

<p>Below is a description of all fields recognized by the standard
bibliography styles.  An entry can also contain other fields, which
are ignored by those styles.  </p>

<dl>

<dt><strong><a name="address">address</a></strong></dt>

<dd>Usually the address of the <a href="#publisher">publisher</a> or
other type of institution.  For major publishing houses,
van&nbsp;Leunen recommends omitting the information entirely.  For
small publishers, on the other hand, you can help the reader by giving
the complete address.  </dd>

<dt><strong><a name="annote">annote</a></strong></dt>

<dd>An annotation.  It is not used by the standard bibliography
styles, but may be used by others that produce an annotated
bibliography. </dd>

<dt><strong><a name="author">author</a></strong></dt>

<dd>The name(s) of the author(s), in the format described in the <a
href="#names"> Names</a> section.  </dd>

<dt><strong><a name="booktitle">booktitle</a></strong></dt>

<dd>Title of a book, part of which is being cited.  See the <a
href="#titles">Titles</a> section for how to type titles.  For book
entries, use the <a href="#title"> title</a> field
instead. </dd>

<dt><strong><a name="chapter">chapter</a></strong></dt>

<dd>A chapter (or section or whatever) number. </dd>

<dt><strong><a name="crossref">crossref</a></strong></dt>

<dd>The database key of the entry being cross referenced. </dd>

<dt><strong><a name="edition">edition</a></strong></dt>

<dd>The edition of a book--for example, "Second".  This should be an
ordinal, and should have the first letter capitalized, as shown here;
the standard styles convert to lower case when necessary. </dd>

<dt><strong><a name="editor">editor</a></strong></dt>

<dd>Name(s) of editor(s), typed as indicated in the <a href="#names">
Names</a> section.  If there is also an <a href="#author">
author</a> field, then the <a href="#editor"> editor</a> field
gives the editor of the book or collection in which the reference
appears. </dd>

<dt><strong><a name="howpublished">howpublished</a></strong></dt>

<dd>How something strange has been published.  The first word should
be capitalized. </dd>

<dt><strong><a name="institution">institution</a></strong></dt>

<dd>The sponsoring institution of a technical report. </dd>

<dt><strong><a name="journal">journal</a></strong></dt>

<dd>A journal name.</dd>

<dt><strong><a name="key">key</a></strong></dt>

<dd>Used for alphabetizing, cross referencing, and creating a label
when the "author" information is missing.  This field should not be
confused with the key that appears in the <code>\cite</code> command
and at the beginning of the database entry. </dd>

<dt><strong><a name="month">month</a></strong></dt>

<dd>The month in which the work was published or, for an unpublished
work, in which it was written.  You should use the standard
three-letter abbreviation, as described in Appendix B.1.3 of the LaTeX
book. </dd>

<dt><strong><a name="note">note</a></strong></dt>

<dd>Any additional information that can help the reader.  The first
word should be capitalized. </dd>

<dt><strong><a name="number">number</a></strong></dt>

<dd>The number of a journal, magazine, technical report, or of a work
in a series.  An issue of a journal or magazine is usually identified
by its volume and number; the organization that issues a technical
report usually gives it a number; and sometimes books are given
numbers in a named series. </dd>

<dt><strong><a name="organization">organization</a></strong></dt>

<dd>The organization that sponsors a conference or that publishes a
manual. </dd>

<dt><strong><a name="pages">pages</a></strong></dt>

<dd>One or more page numbers or range of numbers, such as
<tt>42-111</tt> or <tt>7,41,73-97</tt> or <tt>43+</tt> (the
"<tt>+</tt>" in this last example indicates pages following that
don\'t form a simple range).  To make it easier to maintain
<em>Scribe</em>-compatible databases, the standard styles convert a
single dash (as in <tt>7-33</tt>) to the double dash used in
T<small>E</small>X to denote number ranges (as in
<tt>7-33</tt>). </dd>

<dt><strong><a name="publisher">publisher</a></strong></dt>

<dd>The publisher\'s name. </dd>

<dt><strong><a name="school">school</a></strong></dt>

<dd>The name of the school where a thesis was written. </dd>

<dt><strong><a name="series">series</a></strong></dt>

<dd>The name of a series or set of books.  When citing an entire book,
the the <a href="#title"> title</a> field gives its title and an
optional <a href="#series"> series</a> field gives the name of a
series or multi-volume set in which the book is published. </dd>

<dt><strong><a name="title">title</a></strong></dt>

<dd>The work\'s title, typed as explained in the <a href="#titles">
Titles</a> section. </dd>

<dt><strong><a name="type">type</a></strong></dt>

<dd>The type of a technical report--for example, "Research Note". </dd>

<dt><strong><a name="volume">volume</a></strong></dt>

<dd>The volume of a journal or multivolume book. </dd>

<dt><strong><a name="year">year</a></strong></dt>

<dd>The year of publication or, for an unpublished work, the year it
was written.  Generally it should consist of four numerals, such as
<tt>1984</tt>, although the standard styles can handle any year whose
last four nonpunctuation characters are numerals, such as "(about
1984)". </dd>

</dl>

<h3><a name="names">Names</a></h3>

<p>The text of an <tt>author</tt> or <tt>editor</tt> field represents
 a name.  In bibtex.php, multiple names should be entered on separate
 lines.  They will be joined using "and" in the BibTeX output. The
 bibliography style determines how the names are printed: whether the
 first name or last name appears first, if the full first name or just
 the first initial is used, etc. Most names can be entered in the
 obvious way, i.e., <tt>"John Paul Jones"</tt> or <tt>"Jones, John
 Paul"</tt>. However, only the second form, with a comma, should be
 used for people who have last names with multiple parts that are
 capitalized. People with a "Jr." in their name should be entered as
 <tt>"Ford, Jr., Henry"</tt>.</p>

<p>If an entry has more names than you want to type, just end the list
 of names with "others"; the standard styles convert this to the
 conventional <i>et al.</i> For foreign names with accented
 characters, please refer to the <a href="#nonenglish"> Non-English
 characters</a> section.</p>

<h3><a name="titles">Titles</a></h3>

<p>The bibliography style determines whether or not a title is
 capitalized; the titles of books usually are, the titles of articles
 usually are not.  You type a title the way it should appear if it is
 capitalized.</p>

<pre>     title = "The Agony and the Ecstasy"</pre>

<p>You should capitalize the first word of the title, the first word
 after a colon, and all other words except articles and unstressed
 conjunctions and prepositions.  BibTeX will change uppercase letters
 to lowercase if appropriate.  Uppercase letters that should not be
 changed are enclosed in braces.  The following two titles are
 equivalent; the A of Africa will not be made lowercase.</p>

<pre>
     "The Gnats and Gnus of {Africa}"
     "The Gnats and Gnus of {A}frica"
</pre>

<h3><a name="nonenglish">Non-English Characters</a></h3>

<p>Bibtex.php does not currently support non-ascii characters.  To
 enter foreign characters you need to use LaTeX escape sequences
 described below.  BibTeX is sometimes confused by these sequences,
 but it will do the right thing if you put curly braces immediately
 around the sequence, e.g. {\"{o}} or {\"o} will work. </p>

<p>The following accents may be placed on letters.  Although "o" is
 used in most of the examples, the accents may be placed on any
 letter.  Accents may even be placed above a "missing" letter; for
 example, <tt>\~{}</tt> produces a tilde over a blank space.</p>

<table border="1"><tr>
<td> \`{o}: &ograve;</td>
<td> \^{o}: &ocirc;</td>
<td> \"{o}: &ouml;</td>
<td> \={o}: &#x014D;</td>
<td> \.{c}: &#x010B;</td>
<td> \i:    &#x0131;</td>
<td> \~{o}: &otilde;</td>
<td> \u{o}: &#x014F;</td>
</tr><tr>
<td> \\\'{o}: &oacute;</td>
<td> \v{s}: &scaron; </td>
<td> \H{o}: &#x0151;</td>
<td> \b{o}: <u>o</u></td>
<td> \d{s}: &#x1E63;</td>
<td> \.{I}: &#x0130;</td>
<td> \c{c}: &ccedil; </td>
<td> \t{oo}: o&#x0361;o</td>
</tr></table>

<p>Note that the letters "i" and "j" require special treatment when
 they are given accents because it is often desirable to replace the
 dot with the accent.  For this purpose, the commands <tt>\i</tt> and
 <tt>\j</tt> can be used to produce dotless letters.  For example,
 <tt>\^{\i}</tt> should be used for i circumflex: &icirc;, and
 <tt>\"{\i}</tt> should be used for i umlaut: &iuml;. </p>

<p> Other characters and symbols: </p>

<table border="1"><tr>
<td>\ae: &aelig;</td>
<td>\oe: &oelig;</td>
<td>\aa: &aring; </td>
<td>\o: &oslash; </td> 
<td>\l: &#x0142; </td>
<td>?` or &gt;: &iquest; </td>
<td>\dag: &dagger; </td>
<td>\S: &sect;</td>
<td>\copyright: &copy; </td>
<td>\ss: &szlig; </td>
</tr><tr>
<td>\AE: &AElig;</td>
<td>\OE: &OElig;</td>
<td>\AA: &Aring; </td>
<td>\O: &Oslash; </td> 
<td>\L: &#x0141; </td>
<td>!` or &lt;: &iexcl; </td>
<td>\ddag: &Dagger; </td>
<td>\P: &para; </td>
<td>\pounds: &pound; </td>
</tr></table>

<p>In addition, the following seven symbols need to be escaped with a
 backslash: \\#, \\$, \\%, \\&amp;, \\_, \\{, \\}. </p>

<h3>References</h3>

<p>Sheldon Green 1995: <i>Hypertext Help with LaTeX</i>.
 <a href="http://www.giss.nasa.gov/tools/latex/">
 http://www.giss.nasa.gov/tools/latex</a>.</p>

<p>Dana Jacobsen 1996: <i>BibTeX</i>.
 <a href="http://www.ecst.csuchico.edu/~jacobsd/bib/formats/bibtex.html">
 http://www.ecst.csuchico.edu/~jacobsd/bib/formats/bibtex.html</a>.</p>

<p>Leslie Lamport 1994: <i>LaTeX: A Document Preparation System.
 User\'s Guide and Reference Manual</i>. Second Edition.
 Addison-Wesley, November 1994. Appendix B.</p>

<p>Oren Patashnik 1988: <i>BibTeXing</i>. The documentation for
 BibTeX version 0.99b.
 <a href="http://www.denizyuret.com/ref/patashnik/btxdoc.html">
 http://www.denizyuret.com/ref/patashnik/btxdoc.html</a>.</p>

<p>Oren Patashnik 1988: <i>Designing BibTeX Styles</i>. Documentatio
 for bibliography style writers.
 <a href="http://www.denizyuret.com/ref/patashnik/btxhak.html">
 http://www.denizyuret.com/ref/patashnik/btxhak.html</a>.</p>

<p>Urs-Jakob Rüetschi 2003: <i>The BibTeX Bibliography Database</i>.
 <a href="http://www.geo.unizh.ch/~uruetsch/varia/bibtex.html">
 http://www.geo.unizh.ch/~uruetsch/varia/bibtex.html</a>.</p>

<p>CTAN, the <i>Comprehensive TeX Archive Network</i>, URL
 <a href="http://www.ctan.org/">http://www.ctan.org/</a>.</p>
';

main();

?>
