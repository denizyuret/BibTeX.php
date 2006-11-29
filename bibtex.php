<?php define('rcsid', '$Id: bibtex.php,v 1.4 2006/11/28 11:08:51 dyuret Exp dyuret $');

/** TODO: */
# add, remove keyword
# edit, copy
# editval
# export all
# authentication
# user, time, delete fields - not doing
# lost the sort function
 
/** Installation */
# To use this code you need to create a database table in mysql with:
# CREATE TABLE bibtex (entryid INT, field VARCHAR(255), value VARCHAR(65000), INDEX(entryid), INDEX(value));
# And set the following values:

$mysql = array
(
 'host'  => 'localhost',
 'user'  => 'bibadmin',
 'pass'  => 'bibadmin',
 'db'    => 'test',
 'table' => 'bibtex'
 );
 
/** main: generates top level page structure. */

function main() {
  // session_start();
  // error_reporting(E_ALL);

  import_request_variables('gp', '_');
  sql_init();
  if ($_REQUEST['select'] == 'bibtex') {
    // for export, we need to output text, not html.
    // so do this before we start generating html:
    bibtex();
  } else {
    html_header();
    navbar();
    foreach(array('search', 'field', 'select', 'editval', 'index', 'new') as $fn) {
      if ($_REQUEST[$fn]) { 
	// echo $fn;
	$fn(); $page = 1; break; 
      }
    }
    if (!$page) navbar();
    // print_r($_REQUEST);
    // phpinfo();
    html_footer();
  }
  sql_term();
}
 
/** navbar: generates the menu at the top. */
function navbar() {
  html_print('<table><tr valign="top">');
  navbar_search();
  navbar_index();
  navbar_new();
  navbar_sort();
  html_print('</tr></table>');
}

function navbar_search() {
  html_print('<td>');
  html('form', '', 'action', 'bibtex.php');
  html('input', NULL, 'type', 'text', 'name', 'search', 'value', 'Search', 
       'onclick', "if(value=='Search'){value='';}");
  html_print('</form></td>');
}

function navbar_index() {
  global $uniq_fields;
  if (!isset($uniq_fields)) $uniq_fields = sql_uniq(NULL);
  html_print('<td>');
  sort($uniq_fields);
  html_form('index', $uniq_fields, 'select');
  html_print('</td>');
}

function navbar_new() {
  global $entry_types;
  html_print('<td>');
  $uniq_types = array_keys($entry_types);
  sort($uniq_types);
  array_unshift($uniq_types, 'Import BibTeX');
  html_form('new', $uniq_types, 'select');
  html_print('</td>');
}
 
function navbar_sort() {
  global $_search, $_value, $_field, $uniq_fields;
  if (!isset($uniq_fields)) $uniq_fields = sql_uniq(NULL);
  html_print('<td>');
  if ($_search)
    html_form('search', $_search, 'hidden',
	      'sort', $uniq_fields, 'select');
	      
  elseif ($_value)
    html_form('field', $_field, 'hidden',
	      'value', $_value, 'hidden',
	      'sort', $uniq_fields, 'select');
  html_print('</td>');
}

/** selection_form(select, title) 
 * select is an array[entryid][field]=value(s)
 * title is the title of the page
 */
function selection_form($select, $title) {
  $nselect = count($select);
  if ($nselect == 0)
    return html_print('<p><b>$title</b></p>');

  html('form', '', 'action', 'bibtex.php', 
       'name', 'selection_form', 'method', 'get');
  html_input('select', 'show', 'hidden');
  html_input('nselect', $nselect, 'hidden');

  html_print('<p>');
  html('b', $title);
  html_print('&nbsp; Select: ');
  html('a', 'All', 'href', 'javascript:checkAll(true)'); 
  html('a', 'None', 'href', 'javascript:checkAll(false)');
  html('a', 'Show', 'href', 'javascript:show()');
  html('a', 'Delete', 'href', 'javascript:confirmDelete()');
  html('a', 'BibTeX', 'href', 'javascript:bibtex()');
  html('a', 'Addkey', 'href', 'javascript:keyword("addkey")');
  html('a', 'Delkey', 'href', 'javascript:keyword("delkey")');
  $uniq_keywords = sql_uniq('keywords');
  sort($uniq_keywords);
  html_select('keyword', 'Keyword', $uniq_keywords, '');
  html_print('</p><p>');

  $ordered = array_map("select_sort_field", $select);
  asort($ordered);
  $n = 0;
  foreach ($ordered as $entryid => $ignore) 
    print_entry($select[$entryid], $entryid, ++$n);
  html_print('</p></form>');
}

/*** search: generates result of a search request. */
function search() {
  global $_search;
  if (!isset($_search)) 
    return navbar();
  $select = sql_search($_search);
  $nselect = count($select);
  selection_form($select, "Search = $_search ($nselect results)");
}

/*** field: finds entries with field = value */
function field() {
  global $_value, $_field;
  if (!isset($_value) or !isset($_field)) 
    return navbar();
  $select = sql_select($_field, $_value);
  $nselect = count($select);
  selection_form($select, "$_field = $_value ($nselect results)");
}

/*** select: processes entries which the user has selected */
function select() {
  global $_select, $_nselect;
  if (!isset($_nselect) or !isset($_select)) return navbar();
  $ids = get_selection($_nselect);
  if (!count($ids)) return navbar();
  $_select($ids);
}

function get_selection($n) {
  $entryids = array();
  for ($i = 1; $i <= $n; $i++) {
    $entryid = $_REQUEST["e$i"];
    if ($entryid) $entryids[] = $entryid;
  }
  return $entryids;
}

function show($ids) {
  $select = sql_list($ids);
  $nselect = count($select);
  selection_form($select, "$nselect entries selected");
}

/** index: prints a set of unique values for a given field.
 * _Index: contains the name of the field.
 * The user can pick a value to select a list of entries,
 * or click on the adjacent asterisk to edit the value.
 */
function index() {
  global $_index;
  $plural = $_index;
  if ($plural[strlen($plural)-1] != 's') $plural .= 's';
  html_print("<p><b>Index of $plural</b> &nbsp; ");
  html_print('(Click on an asterisk to edit a value)');
  html_print('</p><p>');
  $uniq_vals = sql_uniq($_index);
  sort($uniq_vals);
  foreach ($uniq_vals as $v) {
    $vn = urlencode($v);
    html_a($v, "?field=$_index&value=$vn"); echo ' ';
    html_a('*', "?editval=$vn&in=$_index"); echo ' ';
  }
  html_print('</p>');
}
 
/** editval */
function editval() {
  global $_editval, $_in;
  html_print('<p><form action="bibtex.php">');
  html_input('SubVal', 'Replace', 'submit');
  html_print(" all <b>$_in = $_editval</b> with <b>$_in =</b> ");
  html_input('newval', '', 'text');
  html_input('in', $_in, 'hidden');
  html_input('oldval', $_editval, 'hidden');
  html_print('</form></p><p><form action="bibtex.php">');
  html_input('DelVal', 'Delete', 'submit');
  html_input('in', $_in, 'hidden');
  html_input('oldval', $_editval, 'hidden');
  html_print(" all fields where <b>$_in = $_editval</b>.");
  html_print('</form></p>');
}
 
/** delval */
function delval() {
  global $_in, $_oldval;
  if (!isset($_in) or !isset($_oldval)) return;
  $select = sql_select($_in, $_oldval);
  $nselect = count($select);
  if ($nselect == 0) {
    html_print("<p>There are no entries with <b>$_in = $_oldval</b></p>");
    return;
  }
  html_print("<p>Are you sure you want to remove the field <b>$_in = $_oldval</b> from the following $nselect entries?</p>");
  html('form', '', 'action', 'bibtex.php');
  html_input('DoDelVal', 'Delete', 'submit');
  html_input('Cancel', 'Cancel', 'submit');
  html_input('in', $_in, 'hidden');
  html_input('oldval', $_oldval, 'hidden');
  html_print('<p>');
  foreach ($select as $entry)
    print_entry($entry);
  html_print('</p></form>');
}

/** dodelval */
function dodelval() {
  global $_in, $_oldval, $mysql;
  if (!isset($_in) or !isset($_oldval)) return;
  $select = sql_select($_in, $_oldval);
  $nselect = count($select);
  if ($nselect == 0) {
    html_print("<p>There are no entries with <b>$_in = $_oldval</b></p>");
    return;
  }
  $entryids = array_keys($select);
  $in = mysql_real_escape_string($_in);
  $value = mysql_real_escape_string($_oldval);
  $table = $mysql['table'];
  $query = "DELETE FROM $table WHERE field='$in' AND value='$value'";
  $result = sql_query($query);
  print_r($entryids);
}

/** new_entry: Creates a new entry
 * _New: gives the type of entry
 */
function new_entry() {
  global $entry_types, $_New, $extra_fields;

  $fields = $entry_types[$_New];
  if (is_null($fields)) 
    return import();
  html_print('<form method="post" action="bibtex.php">');
  html_print("<p><b>New $_New entry &nbsp; ");
  html_input('Insert', 'Submit', 'submit');
  html_print('</b></p><p>');

  html('b', 'Required fields'); html('br');
  $nf = 1;
  html_input("f$nf", 'entrytype', 'hidden');
  html_input("v$nf", $_New, 'hidden');
  _field('citekey', NULL, ++$nf);
  foreach ($fields['required'] as $f)
    _field($f, NULL, ++$nf);
  html_print('</p><p>');

  html('b', 'Extra fields'); html('br');
  foreach ($extra_fields as $f)
    _field($f, NULL, ++$nf);
  html_print('</p><p>');

  html('b', 'Additional authors, keywords, urls or other fields'); html('br');
  html('b', '(Please enter multiple authors etc. on separate lines)'); html('br');
  for ($i = 1; $i <= 5; $i++)
    _field(NULL, NULL, $nf++);
  html_print('</p><p>');

  html('b', 'Optional fields'); html('br');
  foreach ($fields['optional'] as $f)
    _field($f, NULL, ++$nf);
  html_print('</p></form>');
}

function _field($field, $value, $index) {
  if (!is_array($field)) html_input("f$index", $field, 'text', array('size' => 10));
  else {
    html_print("<select name=\"f$index\" size=\"1\">");
    foreach ($field as $f)
      html_print("<option>$f</option>");
    html_print('</select>');
  }
  html_input("v$index", $value, 'text', array('size' => 40));
  html_print('<br/>');
}
  
/** import */
function import() {

}
 
/** addkey */
function addkey() {

}
 
/** delkey */
function delkey() {

}
 
/** bibtex */
function bibtex() {
  global $_nselect;
  header('Content-type: text/plain');
  print_r($_REQUEST);
  $entryids = array();
  for ($i = 1; $i <= $_nselect; $i++) {
    $entryid = $_REQUEST["e$i"];
    if ($entryid) $entryids[] = $entryid;
  }
  print_r($entryids);
  $entries = sql_list($entryids);
  foreach ($entries as $e)
    print_r($e);
}
 
/** delete */
function delete() {

}
 
/** edit */
function edit() {

}
 
/** copy */
/*
function copy() {

}
*/
 
/** print_entry: prints a single bibliography entry
 *   entry: is an array containing all fields and values
 *   entryid: is the uniq id for entry in the database
 *   n: used for generating the checkbox number
 */
$entry_format = array
(
 array('author', '_ ', 'print_author_field'),
 array('editor', '_ (ed.) ', 'print_author_field'),
 array('year', '(_). ', NULL),
 array('title', '_. ', 'print_title_field'),
 array('journal', '<i>_</i>. ', NULL),
 array('booktitle', 'In <i>_</i>. ', NULL),
 array('publisher', '_. ', NULL),
 array('keywords', '[_] ', NULL),
 array('url', '_ ', 'print_url_field')
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
    if (!isset($print_fn)) $print_fn = 'print_field';
    $print_fn($entry, $field, $value);
    echo $pattern[1];
  }
  if (isset($n)) {
    html_get('<u>Edit</u>', 'Edit', $entryid); echo ' ';
    html_get('<u>Copy</u>', 'Copy', $entryid);
  }
  html_print('<br/>');
}

function name_flip($str) {
  $parts = explode(', ', $str);
  $first = array_pop($parts);
  return $first . ' '. implode(', ', $parts);
}

function print_author_field(&$entry, $field, $value) {
  if (!is_array($value)) html_get($value, 'field', $field, 'value', $value);
  else {
    $n = count($value);
    $v = $value[0];
    html_get($v, 'field', $field, 'value', $v);
    for($i = 1; $i < $n - 1; $i++) {
      $v = $value[$i]; echo ', ';
      html_get(name_flip($v), 'field', $field, 'value', $v);
    }
    $v = $value[$n - 1];
    if ($v == 'others') echo ', et.al.';
    else {
      echo ' and ';
      html_get(name_flip($v), 'field', $field, 'value', $v);
    }
  }
}

function print_title_field(&$entry, $field, $value) {
  $url = $entry['url'];
  if (is_array($url)) $url = $url[0];
  if (isset($url)) html_a("$value", $url);
  else echo $value;
}

function print_url_field(&$entry, $field, $value) {
  if (is_array($value)) {
    array_shift($value);
    foreach($value as $url) {
      if ($url != $value[0]) echo ', ';
      html_a('URL', $url);
    }
  }
}

function print_field(&$entry, $field, $value) {
  if (!is_array($value)) html_get($value, 'field', $field, 'value', $value);
  else foreach($value as $v) {
    if ($v != $value[0]) echo ', ';
    html_get($v, 'field', $field, 'value', $v);
  }
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
 
/** html functions */

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

function sql_list($entryids) {
  global $mysql;
  if (!$entryids) return;
  $query = sprintf("SELECT * FROM %s WHERE entryid IN (%s)",
		   $mysql['table'], implode(', ', $entryids));
  return sql_entries($query);
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
 
/** entry_types is the official specification for bibtex.  
 * In addition to these fields we have fields that are required for
 * every type: entrytype and citekey.  We have extra optional fields
 * key, crossref and annote.  We also two extra fields: url and
 * keywords, not specified in bibtex standard.  Finally the program
 * will accept any new field typed by the user.
 */
$extra_fields = array('keywords', 'url');

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

function html_header() {
  echo '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html 
     PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
     "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<title>BibTeX</title>
<style type="text/css">
<!--
A.local { text-decoration:none; color:black }
P.tiny { font-size:xx-small }
-->
</style>
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
    select.value = "show";
    submit();
  }
}

function confirmDelete() {
  var checked = checkCnt();
  if (checked == 0) {
    alert("Please select some entries first.");
  } else with(document.selection_form) {
    if (confirm("Are you sure you want to delete these " + checked + " entries?")) {
      select.value = "delete";
      submit();
    }
  }
}

function bibtex() {
  var checked = checkCnt();
  if ((checked > 0) ||
      (confirm("You have not made a selection.\nWould you like to export all entries in the database?")))
    with(document.selection_form) {
      select.value = "bibtex";
      submit();
    }
}

function keyword(fn) {
  var checked = checkCnt();
  if (checked == 0) {
    alert("Please select some entries first.");
  } else with(document.selection_form) {
    if (keyword.value == "") {
       alert("Please select a keyword.");
    } else {
       select.value = fn;
       submit();
    }
  }
}

-->
</script>
</head><body>
';
}

function html_footer() {
  echo '<p class="tiny">'.rcsid.'</p>
</body></html>
';
}

main();

?>
