<?php // -*- mode: PHP; mode: Outline-minor; outline-regexp: "/[*][*]+"; -*-
define('rcsid', '$Id: bibtex.php,v 1.9 2006/12/03 20:52:05 dyuret Exp dyuret $');

/** MySQL parameters.
 * To use this program you need to create a database table in mysql with:
 * CREATE TABLE bibtex (entryid INT, field VARCHAR(255), value VARCHAR(65000), s SERIAL, INDEX(entryid), INDEX(value));
 * And set the following parameters:
 */
$mysql = array
(
 'host'  => 'localhost',
 'db'    => 'test',
 'table' => 'bibtex',
 'user'  => '',
 'pass'  => '',
 );
 
/** main() generates top level page structure. 
 * $_fn gives the name of the page generation function.
 * Variables starting with '_' are REQUEST variables.
 */
$logged_in = false;
$fn_output = array('search', 'select', 'show', 'index');
$fn_header = array('bibtex', 'login', 'help');
$fn_modify = array
('delete', 'addkey', 'delkey', 'edit_value', 'new_entry', 'edit_entry', 
 'copy_entry', 'entry', 'import');

function main() {
  // session_start();
  // error_reporting(E_ALL);
  global $html_header, $html_footer, $logged_in,
    $fn_output, $fn_header, $fn_modify;
  import_request_variables('gp', '_');
  global $_fn;
  sql_init();
  if (in_array($_fn, $fn_header)) {
    $_fn();
  } else {
    echo $html_header;
    echo navbar();
    if (in_array($_fn, $fn_output) or
	(in_array($_fn, $fn_modify) and $logged_in))
      $_fn();

    //echo '<pre>'; print_r($_REQUEST); echo '</pre>';
    //echo '<pre>'; print_r($_SERVER); echo '</pre>';
    //phpinfo();

    echo $html_footer;
  }
  sql_term();
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
	h('td', navbar_login())
	));
}

function navbar_search() {
  $attr['onclick'] = "if(value == 'Search'){value = ''}";
  return h_form(h_hidden('fn', 'search'),
		h_text('pattern', 'Search', $attr));
		
}

function navbar_index() {
  global $uniq_fields;
  if (!isset($uniq_fields)) $uniq_fields = sql_uniq(NULL);
  sort($uniq_fields);
  return h_form(h_hidden('fn', 'index'),
		h_select('field', $uniq_fields, 'Index', 'submit()'));
		
}

function navbar_new() {
  global $entry_types, $logged_in;
  if (!$logged_in) return;
  $uniq_types = array_keys($entry_types);
  sort($uniq_types);
  array_unshift($uniq_types, 'Import BibTeX');
  return h_form(h_hidden('fn', 'new_entry'),
		h_select('type', $uniq_types, 'New', 'submit()'));
}
 
function navbar_sort() {
  global $_fn, $_pattern, $_field, $_value, $uniq_fields;
  if (!isset($uniq_fields)) $uniq_fields = sql_uniq(NULL);
  if ($_fn == 'search')
    return h_form(h_hidden('fn', 'search'),
		  h_hidden('pattern', $_pattern),
		  h_select('sort', $uniq_fields, 'Sort', 'submit()'));
  elseif ($_fn == 'select')
    return h_form(h_hidden('fn', 'select'),
		  h_hidden('field', $_field),
		  h_hidden('value', $_value),
		  h_select('sort', $uniq_fields, 'Sort', 'submit()'));
}
 
function navbar_login() {
  global $logged_in;
  if (!$logged_in)
    return h_button('Login', "window.location.replace('$_SERVER[PHP_SELF]?fn=login')");
}

/** selection_form($select, $title) generates the entry selection form.
 * select is an array[entryid][field]=value(s)
 * title is the title of the page
 */
function selection_form($select, $title) {
  $nselect = count($select);
  if ($nselect == 0) {
    echo h('p', h('b', $title));
    return;
  }
  echo h_start('form', array('action' => $_SERVER['PHP_SELF'],
			     'name' => 'selection_form',
			     'method' => 'get'));
  echo h_hidden('fn', 'show');
  echo h_hidden('nselect', $nselect);

  echo h_start('p');
  echo h('b', $title);
  echo '&nbsp; Select: ';
  echo h_a('All', 'javascript:checkAll(true)')."\n"; 
  echo h_a('None', 'javascript:checkAll(false)')."\n";
  echo '&nbsp; Action: ';
  echo h_a('Show', 'javascript:show()')."\n";
  echo h_a('BibTeX', 'javascript:bibtex()')."\n";
  if ($logged_in) {
    echo h_a('Delete', 'javascript:confirmDelete()')."\n&nbsp;\n";
    $uniq_keywords = sql_uniq('keywords');
    sort($uniq_keywords);
    echo h_select('keyword', $uniq_keywords, 'Keyword');
    echo h_a('Addkey', "javascript:keyword('addkey')")."\n";
    echo h_a('Delkey', "javascript:keyword('delkey')")."\n";
  }
  echo h_end('p');

  echo h_start('p');
  $ordered = array_map("select_sort_field", $select);
  asort($ordered);
  $n = 0;
  foreach ($ordered as $entryid => $ignore) 
    print_entry($select[$entryid], $entryid, ++$n);
  echo h_end('p');
  echo h_end('form');
}
 
function select_sort_field(&$entry) {
  global $_sort;
  if ($_sort) $key = $_sort;
  else $key = 'author';
  $ans = $entry[$key];
  if (!isset($ans) and $key == 'author')
    $ans = $entry['editor'];
  if (is_array($ans)) $ans = $ans[0];
  return $ans;
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
function show($ids) {
  if (!isset($ids)) $ids = get_selection();
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
    $entryid = $_REQUEST["e$i"];
    if ($entryid) $entryids[] = $entryid;
  }
  return $entryids;
}
 
/** bibtex() exports selected entries to bibtex format.
 * Request: select=bibtex&nselect=3&keyword=&e1=3722&e2=3714&e3=3553
 * TODO: implement bibtex format output.
 * TODO: implement export all.
 */
function bibtex() {
  $ids = get_selection();
  if (!$ids) return;
  header('Content-type: text/plain');
  print_r($_REQUEST);
  $entries = sql_select_list($ids);
  foreach ($entries as $entry) {
    print_r($entry);
  }
}
 
/** delete() deletes the selected entries.
 * Request: select=delete&nselect=3&keyword=&e1=3722&e2=3714&e3=3553
 */
function delete() {
  $ids = get_selection();
  if (!$ids) return;
  sql_delete_list($ids);
  echo h_script
    ("window.location.replace('$_SERVER[HTTP_REFERER]')");
}
 
/** addkey($_keyword): adds the keyword to selected entries.
 * Request: select=addkey&nselect=3&keyword=AI&e1=419&e2=561&e3=903
 * TODO: do google style single list for add/del keyword.
 */
function addkey() {
  global $_keyword;
  $ids = get_selection();
  if (!$ids) return;
  $entries = sql_select_list($ids);
  foreach ($entries as $id => $entry)
    if (!has_keyword($entry, $_keyword))
      sql_insert_field($id, 'keywords', $_keyword);
  show($ids);
}
 
function has_keyword($entry, $keyword) {
  $keys = $entry['keywords'];
  if (is_array($keys)) return in_array($keyword, $keys);
  else return ($keyword == $keys);
}
 
/** delkey($_keyword): deletes the keyword from selected entries.
 * Request: select=delkey&nselect=3&keyword=AI&e1=419&e2=561&e3=903
 */
function delkey() {
  global $_keyword;
  $ids = get_selection();
  if (!$ids) return;
  $entries = sql_select_list($ids);
  foreach ($entries as $id => $entry)
    if (has_keyword($entry, $_keyword))
      sql_delete_field($id, 'keywords', $_keyword);
  show($ids);
}
 
/** index($_field): prints a set of unique values for a given field.
 * Request: fn=index&field=keywords
 * The user can pick a value to select a list of entries,
 * or click on the box to modify a value.
 */
function index() {
  global $_field, $logged_in;
  if ($logged_in) {
    echo h('p', h('b', "$_field index &nbsp;").
	   h('small', "(Click on a value to select, click on a box to edit)"));
    echo h_start('form', array('action' => $_SERVER['PHP_SELF'], 'name' => 'index_form'));
    echo h_hidden('fn', 'edit_value');
    echo h_hidden('field', $_field);
    echo h_hidden('newval', '');
  } else echo h('p', h('b', "$_field index"));
  $uniq_vals = sql_uniq($_field);
  natcasesort($uniq_vals);
  foreach ($uniq_vals as $v) {
    if ($logged_in) echo h_checkbox('value', $v, "edit_value('$v')");
    print_field($_field, $v);
    echo h('br');
  }
  if ($logged_in) echo h_end('form');
}
 
/** edit_value($_field, $_value, $_newval): replace value with newval in field
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
 
/** entry_form($entry, $id, $title): Form to create, copy or edit an entry.
 * $entry must be an array with a valid entrytype defined.
 * $id must be a valid entryid.
 */
function entry_form($entry, $title, $id) {
  global $entry_types, $index_fields, $extra_fields, 
    $entry_field_index, $entry_field_printed;
  if (!isset($entry)) return;
  $type = $entry['entrytype'];
  if (!$type) return;
  $fields = $entry_types[$type];
  if (!$fields) return;
  echo h_start('form', array('action' => $_SERVER['PHP_SELF'],
			     'name' => 'entry_form',
			     'method' => 'get'));
  echo h('p',
	 ($title ? h('b', $title).' &nbsp; ' : '').
	 h_hidden('fn', 'entry').
	 ($id ? h_hidden('id', $id) : '').
	 h_submit('Submit').
	 h_button('Cancel', 'window.back()').
	 h_submit('Don\'t check errors', 'nocheck').
	 h('br').'Please enter additional authors, editors, urls, and keywords on separate lines.'
	 );
  echo h_start('p');
  echo h('b', 'Required fields') . h('br');
  $printed = array();
  foreach ($index_fields as $f)
    entry_field($entry, $f);
  foreach ($fields['required'] as $f)
    entry_field($entry, $f);
  for ($i = 1; $i <= 3; $i++)
    entry_field($entry, NULL, $printed);
  echo h('b', 'Optional fields') . h('br');
  foreach ($extra_fields as $f)
    entry_field($entry, $f);
  foreach ($fields['optional'] as $f)
    entry_field($entry, $f);
  foreach ($entry as $f => $v)
    if (!$entry_field_printed[$f])
      entry_field($entry, $f);
  for ($i = 1; $i <= 3; $i++)
    entry_field($entry, NULL);
  echo h_hidden('nfield', $entry_field_index);
  h_end('p');
  h_end('form');
}

/* entry_field()
 * complication: both the field and the value could be arrays.
 * if field is array, value is null => print selection for field.
 * if field is array, value is defined => print each value.
 * if field is scalar, value is array => print multiple fields.
 */
$entry_field_index = 0;
$entry_field_printed = array();
function entry_field(&$entry, $field) {
  global $entry_field_index, $entry_field_printed;
  if (is_null($field)) {
    $i = ++$entry_field_index;
    entry_field_name("f$i", '');
    entry_field_value("v$i", '');
  } elseif (is_array($field)) {
    $i0 = $entry_field_index;
    foreach ($field as $f) {
      if (isset($entry[$f])) {
	entry_field($entry, $f);
      }
    }
    if ($i0 == $entry_field_index) {
      $i = ++$entry_field_index;
      entry_field_name("f$i", $field);
      entry_field_value("v$i", '');
      $entry_field_printed[$field] = 1;
    }
  } elseif (isset($entry[$field])) {
    $val = $entry[$field];
    if (!is_array($val)) $val = array($val);
    foreach ($val as $v) {
      $i = ++$entry_field_index;
      entry_field_name("f$i", $field);
      entry_field_value("v$i", $v);
    }
    $entry_field_printed[$field] = 1;
  } else {
    $i = ++$entry_field_index;
    entry_field_name("f$i", $field);
    entry_field_value("v$i", '');
    $entry_field_printed[$field] = 1;
  }
}

function entry_field_name($name, $value) {
  if (is_array($value))
    echo h_select($name, $value);
  else echo h_text($name, $value, array('size' => 10));
}

function entry_field_value($name, $value) {
  echo h_text($name, $value, array('size' => 40)).h('br');
}
 
/*** new_entry($_type): Creates a new entry of a given type.
 * $_type: gives the type of entry
 * if type == "Import BibTeX" then do import.
 */
function new_entry() {
  global $_type;
  if ($_type == 'Import BibTeX') return import();
  entry_form(array('entrytype' => $_type), 'New entry');
}
 
/*** edit_entry() Modifies an existing entry.
 */
function edit_entry() {
  global $_id;
  $entry = sql_select_entry($_id);
  entry_form($entry, 'Edit entry', $_id);
}
 
/*** copy_entry() Clones an existing entry.
 */
function copy_entry() {
  global $_id;
  $entry = sql_select_entry($_id);
  entry_form($entry, 'Copy entry');
}
 
/** entry() */
function entry() {
  global $_id, $_nocheck;
  $entry = get_fields();
  if (!$entry) return;
  if (!$_nocheck) $err = entry_errors($entry, $_id);
  if ($err) {
    echo h('p', h('b', 'Entry errors'));
    echo h('ol', h('li', implode("\n</li><li>", $err)));
    echo h('p', 'Please go back to fix the errors or 
submit with the "Don\'t check errors" button.');
    echo "<pre>Entry: ";
    print_r($entry);
    echo "</pre>\n";
  } else {
    insert($entry, $_id);
  }
}

function entry_errors(&$entry, $editid) {
  global $entry_types, $index_fields, 
    $extra_fields, $extra_optional_fields;
  $type = $entry['entrytype'];
  if (!$type) $err[] = 'entrytype: not set.';
  $fields = $entry_types[$type];
  if (!$fields) $err[] = $type . ': not a valid entrytype.';
  $citekey = $entry['citekey'];
  $others = sql_select('citekey', $citekey);
  foreach ($others as $id => $e) {
    if ($id == $editid) continue;
    $err[] = $citekey . ': not a unique citekey.';
  }
  foreach ($fields['required'] as $f) {
    if (!is_array($f)) {
      if (!isset($entry[$f]))
	$err[] = $f . ': required field missing.';
    } else {
      $found = false;
      foreach($f as $ff)
	if (isset($entry[$ff])) $found = true;
      if (!$found)
	$err[] = implode(' or ', $f) . ': required field missing.';
    }
  }
  $allfields = array_merge($fields, $index_fields, $extra_fields, 
			   $extra_optional_fields);
  foreach ($entry as $f => $v) {
    if (!deep_in_array($f, $allfields))
      $err[] = $f . ': not a recognized field for the ' . 
	$type . ' entrytype.';
    foreach ((is_array($v) ? $v : array($v)) as $vv)
      if (preg_match('/[^\000-\177]/', $vv))
	$err[] = $vv . ': contains non-ascii characters.';
  }
  // anything else illegal in bibtex specs?
  return $err;
}

function get_fields() {
  global $_nfield;
  if (!isset($_nfield)) return;
  $fields = array();
  for ($i = 1; $i <= $_nfield; $i++) {
    $f = $_REQUEST["f$i"];
    $v = $_REQUEST["v$i"];
    if ($f != '' and $v != '') {
      array_set_values($fields, $f, $v);
    }
  }
  return $fields;
}

function array_set_values(&$fields, $f, $v) {
  if (is_null($fields[$f])) {
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
 
/** insert($entry, $id) 
 */
function insert($entry, $id) {
  //echo '<pre>Before '; print_r($entry); echo '</pre>';
  if (isset($id)) sql_delete_entry($id);
  else $id = sql_newid();
  foreach ($entry as $f => $v) {
    foreach ((is_array($v)?$v:array($v)) as $val) {
      sql_insert_field($id, $f, $val);
    }
  }
  $e = sql_select_entry($id);
  //echo '<pre>After '; print_r($e); echo '</pre>';
  show(array($id));
}
 
/** import() 
 * TODO. implement import
 */
function import() {
  echo h('b', 'import not implemented yet.');
  print_r($_REQUEST);
}
 
/** help() 
 * TODO: implement help.
 */
function help() {
  echo h('b', 'help not implemented yet.');
  print_r($_REQUEST);
}
 
/** login() 
 */
function login() {
  global $logged_in;		// sql_init sets this.
  if (!isset($_SERVER['PHP_AUTH_USER']) || 
      !isset($_SERVER['PHP_AUTH_PW']) ||
      !$logged_in) {
    header( 'WWW-Authenticate: Basic realm="BibTeX"' );
    header( 'HTTP/1.0 401 Unauthorized' );
    echo h_script
      ("window.location.replace('$_SERVER[PHP_SELF]')");
  } else {
    header("Location: $_SERVER[PHP_SELF]");
  }
}
 
/** print_entry($entry, $id, $n) prints a single bibliography entry.
 *   entry: is an array containing all fields and values
 *   entryid: is the uniq id for entry in the database
 *   n: used for generating the checkbox number
 */
$entry_format = array
(
 array('author', '_. ', 'print_author_field'),
 array('editor', '_. ', 'print_author_field'),
 array('title', '_. ', 'print_title_field'),
 array('url', '_ ', 'print_url_field'),

 array('journal', '<i>_</i>, ', NULL),
 array('booktitle', 'In <i>_</i>, ', NULL),
 array('organization', '_, ', NULL),
 array('school', '_, ', NULL),
 array('institution', '_, ', NULL),
 array('howpublished', '_, ' , NULL),
 array('publisher', '_, ', NULL),

 array('year', '_. ', NULL),
 array('keywords', '[_] ', NULL),
# array('entrytype', '[_:', NULL),
# array('citekey', '_] ', NULL),
);

function print_entry(&$entry, $entryid, $n) {
  global $entry_format, $logged_in;
  if (isset($entryid) and isset($n)) {
    html_input("e$n", $entryid, 'checkbox');
  }
  foreach ($entry_format as $fmt) {
    $field = $fmt[0];
    $value = $entry[$field];
    if (!isset($value)) continue;
    if ($field == 'url' and !is_array($value)) continue;
    if ($field == 'editor' and isset($entry['author'])) continue;
    $pattern = $fmt[1];
    $pattern = explode('_', $pattern);
    echo $pattern[0];
    $print_fn = $fmt[2];
    if (!isset($print_fn)) print_field($field, $value);
    else $print_fn($entry, $field, $value);
    echo $pattern[1];
  }
  if ($logged_in and isset($n)) {
    echo h_get('edit',
	       array('fn' => 'edit_entry',
		     'id' => $entryid),
	       array('class' => 'edit'));
    echo ' ';
    echo h_get('copy',
	       array('fn' => 'copy_entry',
		     'id' => $entryid),
	       array('class' => 'edit'));
  }
  html_print('<br/>');
}

function name_flip($str) {
  $parts = explode(', ', $str);
  $first = array_pop($parts);
  return $first . ' '. implode(', ', $parts);
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
	echo ', et.al';
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
  if (in_array($type, array('book', 'inbook', 'manual', 'proceedings', 'phdthesis'))) {
    $value = "<i>$value</i>";
  }
  $url = $entry['url'];
  if (is_array($url)) $url = $url[0];
  if (isset($url)) html_a("$value", $url);
  else echo $value;
}

function print_url_field(&$entry, $field, $value) {
  if (!is_array($value)) return;
  for ($i = 1; $i < count($value); $i++) {
    $attr['href'] = htmlspecialchars($value[$i]);
    $attr['class'] = 'url';
    echo ' '.h('a', $attr, 'url');
  }
}

function print_field($field, $value, $txt, $attr) {
  if (is_array($value)) {
    foreach($value as $v) {
      if ($v != $value[0]) echo ', ';
      print_field($field, $v, $txt, $attr);
    }
  } else {
    if (!isset($txt)) $txt = $value;
    $txt = htmlspecialchars($txt);
    echo h_get($txt, 
	       array('fn' => 'select', 
		     'field' => $field, 
		     'value' => $value),
	       array('class' => 'local'));
  }
}
 
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
function h($name, $attr, $content) {
  if (!is_array($attr)) {
    $content = $attr;
    unset($attr);
  }
  $vals = '';
  foreach ($attr as $aname => $value) {
    $esc = htmlspecialchars($value);
    $vals .= " $aname=\"$esc\"";
  }
  if (!isset($content)) 
    return "<$name$vals/>\n";
  elseif (is_bool($content))
    return $content ? "<$name$vals>\n" : "</$name>\n";
  else
    return in_array($name, array('a', 'b', 'i', 'u', 'option')) ?
      "<$name$vals>$content</$name>" :
      "<$name$vals>\n$content</$name>\n";
}

function h_start($name, $attr) {
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

function h_text($name, $value, $attr) {
  $attr['type'] = 'text';
  $attr['name'] = $name;
  $attr['value'] = $value;
  return h('input', $attr);
}

function h_submit($value, $name, $onclick) {
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

function h_checkbox($name, $value, $onchange) {
  $attr = array
    ('type' => 'checkbox', 'name' => $name, 'value' => $value);
  if (isset($onchange)) $attr['onchange'] = $onchange;
  return h('input', $attr);
}

function h_select($name, $values, $title, $onchange) {
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
  for ($i = 0; $i < $n; $i++) {
    $arg = func_get_arg($i);
    if ($i == 0 && is_array($arg)) $attr = $arg;
    else $input .= $arg;
  }
  $attr['action'] = $_SERVER['PHP_SELF'];
  return h('form', $attr, $input);
}

function h_get($txt, $vars, $attr) {
  $url = "$_SERVER[PHP_SELF]?";
  foreach($vars as $name => $value) {
    if ($url[strlen($url)-1] != '?') $url .= '&';
    $url .= urlencode($name) . '=' . urlencode($value);
  }
  return h_a($txt, $url, $attr);
}

function h_a($txt, $url, $attr) {
  $attr['href'] = $url;
  return h('a', $attr, $txt);
}

function html_print($str) { echo "$str\n"; }

function html_get() {  # content, name1, val1, name2, val2, ...
  $n = func_num_args();
  if ($n < 1) return;
  $url='?';
  for ($i = 1; $i < $n; $i+=2) {
    $name = func_get_arg($i);
    $val = func_get_arg($i+1);
    if ($i > 1) $url .= '&';
    $url .= urlencode($name) . '=' . urlencode($val);
  }
  $content = func_get_arg(0);
  echo "<a class=\"local\" href=\"$url\">$content</a>";
}

function html_a($content, $url) {
  echo "<a href=\"$url\">$content</a>";
}

function html_form() {  # input: name, value, type triples
  html_print("<form action='$_SERVER[PHP_SELF]'>");
  $n = func_num_args();
  for ($i = 0; $i < $n; $i += 3) {
    $name = func_get_arg($i);
    $value = func_get_arg($i+1);
    $type = func_get_arg($i+2);
    switch($type) {
    case 'select': html_select($name, ucfirst($name), $value); break;
    default: html_input($name, $value, $type); break;
    }
  }
  html_print('</form>');
}

function html_select($name, $title, $values, $fn) {
  if (!isset($fn)) $fn = 'submit()';
  html_print("<select name=\"$name\" size=\"1\" onchange=\"$fn\">");
  html_print("<option value=''>$title</option>");
  foreach ($values as $v) {
    $vshort = substr($v, 0, 14);
    html_print("<option value=\"$v\">$vshort</option>");
  }
  html_print('</select>');
}

function html_input($name, $value, $type, $options) {
  echo '<input type="'.$type;
  if (isset($name)) echo '" name="'.$name;
  if (isset($value)) echo '" value="'.$value;
  foreach ($options as $n => $v)
    echo "\" $n=\"$v";
  html_print('" />');
}

function html() {  # name, content, attr, val, attr, val, ...
  $argc = func_num_args();
  if ($argc == 0) return;
  $name = func_get_arg(0);
  echo "<$name";
  for ($i = 2; $i < $argc; $i += 2) {
    $attr = func_get_arg($i);
    $val = func_get_arg($i+1);
    $esc = str_replace('"', '&quot;', $val);
    echo " $attr=\"$esc\"";
  }
  $content = func_get_arg(1);
  if (is_null($content)) echo '/>';
  elseif ($content == '') echo '>';
  else echo '>' . htmlspecialchars($content) . "</$name>";
  echo "\n";
}
 
/** sql functions 
 * TODO: check each sql statement for sql injection
 */

$sql_link = NULL;

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
<tt> CREATE TABLE $mysql[table] (entryid INT, field VARCHAR(255), value VARCHAR(65000), s SERIAL, INDEX(entryid), INDEX(value)); </tt>
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

function sql_init() {
  global $mysql, $sql_link, $logged_in;
  if (isset($sql_link)) sql_term();
  if (isset($_SERVER['PHP_AUTH_USER']) and
      isset($_SERVER['PHP_AUTH_PW']) and
      ($_SERVER['PHP_AUTH_USER'] != $mysql['user']))
    $sql_link = mysql_connect($mysql['host'], 
			      $_SERVER['PHP_AUTH_USER'],
			      $_SERVER['PHP_AUTH_PW']);
  if ($sql_link) $logged_in = true;
  else $sql_link = mysql_connect($mysql['host'], $mysql['user'], $mysql['pass']);
  if (!$sql_link) sql_error('connect');
  mysql_select_db($mysql['db']) 
    or sql_error('select_db');
  mysql_query("SELECT COUNT(*) FROM $mysql[table];") 
    or sql_error('select');
}

function sql_term() {
  global $sql_link;
  mysql_close($sql_link);
  unset($sql_link);
}

function sql_select_list($entryids) {
  global $mysql;
  if (!$entryids) return;
  $query = sprintf("SELECT * FROM %s WHERE entryid IN (%s) ORDER BY s",
		   $mysql['table'], implode(', ', $entryids));
  return sql_entries($query);
}

function sql_delete_list($entryids) {
  global $mysql;
  if (!$entryids) return;
  sql_query(sprintf("DELETE FROM %s WHERE entryid IN (%s)",
		    $mysql['table'], implode(', ', $entryids)));
}

function sql_select_entry($entryid) {
  $entries = sql_select_list(array($entryid));
  return $entries[$entryid];
}

function sql_delete_entry($entryid) {
  sql_delete_list(array($entryid));
}

function sql_search($value) {
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
  $value = mysql_real_escape_string($value);
  sql_query("INSERT INTO $table (entryid, field, value) VALUES ('$id', '$field', '$value')");
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
 
/** entry_types is the official specification for bibtex.  
 * In addition to these fields we have fields that are required for
 * every type: entrytype and citekey.  We have extra optional fields
 * key, crossref and annote.  We also two extra fields: url and
 * keywords, not specified in bibtex standard.  Finally the program
 * will accept any new field typed by the user.
 */
$index_fields = array('entrytype', 'citekey');
$extra_fields = array('url', 'keywords');
$extra_optional_fields = array('key', 'crossref', 'annote');

$entry_types = array
(
 'article' => array
 ('required' => array('author', 'title', 'journal', 'year'),
  'optional' => array('volume', 'number', 'pages', 'month', 'note')),

 'book' => array
 ('required' => array(array('author', 'editor'), 'title', 'publisher', 'year'), 
  'optional' => array(array('volume', 'number'), 'series', 'address', 'edition', 'month', 'note')),

 'booklet' => array
 ('required' => array('title'), 
  'optional' => array('author', 'howpublished', 'address', 'month', 'year', 'note')),
 
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
 ('optional' => array('author', 'title', 'howpublished', 'month', 'year', 'note')),
 
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
 
/** html_header */

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

-->
</script>
<style type="text/css">
<!--
A.local { text-decoration:none; color:black }
A.url { font-variant:small-caps }
A.edit { font-variant:small-caps; color:black }
P.rcsid { font-size:xx-small }
-->
</style>
</head>
<body>
';

$html_footer = '<p class="rcsid">'.rcsid.'</p>
</body>
</html>
';

main();

?>
