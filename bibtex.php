<?php // -*- mode: PHP; mode: Outline-minor; outline-regexp: "/[*][*]+"; -*-
define('rcsid', '$Id: bibtex.php,v 1.5 2006/11/29 04:22:54 dyuret Exp dyuret $');

/** Installation instructions.
 * To use this program you need to create a database table in mysql with:
 * CREATE TABLE bibtex (entryid INT, field VARCHAR(255), value VARCHAR(65000), INDEX(entryid), INDEX(value));
 * And set the following parameters:
 */
$mysql = array
(
 'host'  => 'localhost',
 'user'  => 'bibadmin',
 'pass'  => 'bibadmin',
 'db'    => 'test',
 'table' => 'bibtex'
 );
 
/** main() generates top level page structure. 
 * $_fn gives the name of the page generation function.
 * Variables starting with '_' are REQUEST variables.
 */
function main() {
  // session_start();
  // error_reporting(E_ALL);

  import_request_variables('gp', '_');
  global $_fn, $html_header, $html_footer;
  sql_init();
  if ($_fn == 'bibtex') {
    // for export, we need to output text, not html.
    // so do this before we start generating html:
    $_fn();
  } else {
    echo $html_header;
    echo navbar();
    if ($_fn) $_fn();
    //print_r($_REQUEST);
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
	h('td', navbar_sort())));
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
  global $entry_types;
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
 
/** selection_form($select, $title) generates the entry selection form.
 * select is an array[entryid][field]=value(s)
 * title is the title of the page
 * TODO: convert to new html routines.
 */
function selection_form($select, $title) {
  $nselect = count($select);
  if ($nselect == 0) {
    echo h('p', h('b', $title));
    return;
  }
  echo h_start('form', array('action' => 'bibtex.php',
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
  echo h_a('Delete', 'javascript:confirmDelete()')."\n&nbsp;\n";
  $uniq_keywords = sql_uniq('keywords');
  sort($uniq_keywords);
  echo h_select('keyword', $uniq_keywords, 'Keyword');
  echo h_a('Addkey', "javascript:keyword('addkey')")."\n";
  echo h_a('Delkey', "javascript:keyword('delkey')")."\n";
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
  selection_form($select, "Search = $_pattern ($nselect results)");
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
  selection_form($select, "$_field = $_value ($nselect results)");
}
 
/** show() shows the subset of entries which the user has selected.
 * Request: fn=show&nselect=9&keyword=&e1=3722&e3=3714&e5=3553
 * Uses get_selection to collect the selected entries.
 */
function show($ids) {
  $ids = get_selection();
  if (!$ids) return;
  $select = sql_select_list($ids);
  $nselect = count($select);
  selection_form($select, "$nselect entries selected");
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
  echo h_script('window.location.replace("' .
		$_SERVER["HTTP_REFERER"] . '");');
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
  global $_field;
  $plural = $_field;
  if ($plural[strlen($plural)-1] != 's') $plural .= 's';
  echo h('p', h('b', "Index of $plural &nbsp;").
	 h('small', "(Click on a value to select, click on a box to edit)"));
  echo h_start('form', array('action' => 'bibtex.php', 'name' => 'index_form'));
  echo h_hidden('fn', 'edit_value');
  echo h_hidden('field', $_field);
  echo h_hidden('newval', '');
  $uniq_vals = sql_uniq($_field);
  natcasesort($uniq_vals);
  foreach ($uniq_vals as $v) {
    echo h_checkbox('value', $v, "edit_value('$v')");
    print_field($_field, $v);
    echo h('br');
  }
  h_end('form');
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
function entry_form($entry, $id, $title) {
  global $entry_types, $index_fields, $extra_fields, 
    $entry_field_index, $entry_field_printed;
  if (!isset($entry) or !isset($id)) return;
  $type = $entry['entrytype'];
  if (!$type) return;
  $fields = $entry_types[$type];
  if (!$fields) return;
  echo h_start('form', array('action' => 'bibtex.php',
			     'name' => 'entry_form',
			     'method' => 'get'));
  echo h('p',
	 ($title ? h('b', $title).' &nbsp; ' : '').
	 h_submit('Submit').
	 h_button('Cancel', 'window.back()').
	 h('br').'Please enter additional authors, keywords, etc. on separate lines.'.
	 h_hidden('fn', 'insert').
	 h_hidden('id', $id)
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
 
/** new_entry($_type): Creates a new entry of a given type.
 * $_type: gives the type of entry
 * if type == "Import BibTeX" then do import.
 */
function new_entry() {
  global $_type;
  if ($_type == 'Import BibTeX') return import();
  $id = sql_newid();
  entry_form(array('entrytype' => $_type), $id, 'New entry');
}
 
/** edit_entry() Modifies an existing entry.
 */
function edit_entry() {
  global $_id;
  $entries = sql_select_list(array($_id));
  entry_form($entries[$_id], $_id, 'Edit entry');
}
 
/** copy_entry() Clones an existing entry.
 */
function copy_entry() {
  global $_id;
  $entries = sql_select_list(array($_id));
  $newid = sql_newid();
  entry_form($entries[$_id], $newid, 'Copy entry');
}
 
/** insert() */
function insert() {
  echo h('b', 'insert not implemented yet.');
  print_r($_REQUEST);
}
 
/** import() */
function import() {
  echo h('b', 'import not implemented yet.');
  print_r($_REQUEST);
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
  global $entry_format;
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
  if (isset($n)) {
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
  $attr['action'] = 'bibtex.php';
  return h('form', $attr, $input);
}

function h_get($txt, $vars, $attr) {
  $url = '?';
  foreach($vars as $name => $value) {
    if ($url != '?') $url .= '&';
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
  html_print('<form action="bibtex.php">');
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
 
/** sql functions */

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
<tt> CREATE TABLE $mysql[table] (entryid INT, field VARCHAR(255), value VARCHAR(65000), INDEX(entryid), INDEX(value)); </tt>
</li><li> Check the following mysql parameters and correct them if necessary in bibtex.php:
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
  global $mysql, $sql_link;
  $sql_link = mysql_connect($mysql['host'], $mysql['user'], $mysql['pass'])
    or sql_error('connect');
  mysql_select_db($mysql['db']) 
    or sql_error('select_db');
  mysql_query("SELECT COUNT(*) FROM $mysql[table];") 
    or sql_error('select');
}

function sql_term() {
  global $sql_link;
  mysql_close($sql_link);
}

function sql_select_list($entryids) {
  global $mysql;
  if (!$entryids) return;
  $query = sprintf("SELECT * FROM %s WHERE entryid IN (%s)",
		   $mysql['table'], implode(', ', $entryids));
  return sql_entries($query);
}

function sql_delete_list($entryids) {
  global $mysql;
  if (!$entryids) return;
  sql_query(sprintf("DELETE FROM %s WHERE entryid IN (%s)",
		    $mysql['table'], implode(', ', $entryids)));
}

function sql_search($value) {
  global $mysql;
  if (!isset($value)) return;
  $table = $mysql['table'];
  $value = mysql_real_escape_string($value);
  $query = "SELECT * FROM $table WHERE entryid IN (SELECT entryid FROM $table WHERE value LIKE '%$value%')";
  return sql_entries($query);
}

function sql_select($field, $value) {
  global $mysql;
  if (!isset($value)) return;
  if (!isset($field)) return;
  $value = mysql_real_escape_string($value);
  $field = mysql_real_escape_string($field);
  $table = $mysql['table'];
  $query = "SELECT * FROM $table WHERE entryid IN (SELECT entryid FROM $table WHERE value='$value' AND field='$field')";
  return sql_entries($query);
}

function sql_entries($query) {
  $result = sql_query($query);
  while ($row = mysql_fetch_row($result)) {
    $i = $row[0];
    $f = $row[1];
    $v = $row[2];
    if (is_null($e[$i][$f])) {
      $e[$i][$f] = $v;
    } elseif (is_array($e[$i][$f])) {
      $e[$i][$f][] = $v;
    } else {
      $e[$i][$f] = array($e[$i][$f], $v);
    }
  }
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
  sql_query("INSERT INTO $table VALUES ('$id', '$field', '$value')");
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
