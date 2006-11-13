// $Id$
//
//
//

<?php

// Database: to use this code you need to create a database table in mysql with:
// CREATE TABLE bibtex (id INT, key VARCHAR(255), val VARCHAR(65000), INDEX(id), INDEX(val));
// And set the following global variables:

$mysql_host = 'localhost';
$mysql_user = 'bibtex';
$mysql_pass = 'bibtex';
$mysql_db = 'bibtex';
$mysql_table = 'bibtex';

function select($key, $val) {
  if (!is_null($key) && !is_null($val))
    $query = "SELECT * FROM bibtex WHERE id IN (SELECT id FROM bibtex WHERE val='$val' AND key='$key');";
  elseif (!is_null($val))
    $query = "SELECT * FROM bibtex WHERE id IN (SELECT id FROM bibtex WHERE val LIKE '%$val%');";
  else
    return array();
  $link = mysql_connect('localhost', 'bibadmin', 'bibadmin')
    or die('Could not connect: ' . mysql_error());
  mysql_select_db('test') or die('Could not select database');
  $result = mysql_query($query) or die('Query failed: ' . mysql_error());
  $entries = array();
  while ($row = mysql_fetch_row($result)) {
    setval($entries, $row[0], $row[1], $row[2]);
  }  
  mysql_free_result($result);
  mysql_close($link);
  return $entries;
}

function setval(&$a, $b, $c, $d) {
  if (is_null($a[$b][$c])) {
    $a[$b][$c] = $d;
  } elseif (is_array($a[$b][$c])) {
    $a[$b][$c][] = $d;
  } else {
    $a[$b][$c] = array($a[$b][$c], $d);
  }
}

$ref_key = NULL;
$ref_url = NULL;
$ref_format = array( 'author' => '_ ',
		     'editor' => '_ ',
		     'year' => '(_). ',
		     'title' => '<b>_</b>. ',
		     'journal' => '<i>_</i>. ',
		     'booktitle' => 'In <i>_</i>. ',
		     'publisher' => '_. ',
		     'keywords' => '[_] ',
		     'url' => '_ ',
		     );

function key_string($val) {
  global $ref_key, $ref_url;
  if ($ref_key == 'author' && $val == 'others') {
    return "et.al.";
  } elseif ($ref_key == 'title') {
    return $ref_url ? "<a href=\"$ref_url\">$val</a>" : $val;
  } elseif ($ref_key == 'url') {
    return "<a href=\"$val\">URL</a>";
  } else {
    $keyurl = urlencode($ref_key);
    $valurl = urlencode($val);
    return "<a href=\"select.php?a=$keyurl&v=$valurl\">$val</a>";
  }
}

function ref_string(&$ref) {
  global $ref_format, $ref_key, $ref_url;
  $url = $ref['url'];
  if (is_array($url)) $ref_url = array_shift($url);
  else { $ref_url = $url; $url = NULL; }
  $str = NULL;
  foreach ($ref_format as $key => $fmt) {
    $val = ($key == 'url') ? $url : $ref[$key];
    if (!is_null($val)) {
      $ref_key = $key;
      $astr = is_array($val) ?
	implode(", ", array_map("key_string", $val)) :
	key_string($val);
      $str .= ($fmt ? preg_replace('/_/', $astr, $fmt) : $astr);
    }
  }
  return $str;
}

function ref_cmd($id) {
  $str = '';
  $str .= "<a href=\"update.php?id=$id\">Edit</a> ";
  $str .= "<a href=\"insert.php?id=$id\">Dup</a> ";
  $str .= "<a href=\"delete.php?id=$id\">Del</a> ";
  $str .= "<a href=\"export.php?id=$id\">BibTeX</a>";
  return $str;
}

function order_key(&$ref) {
  global $s;
  $ans = $ref[$s];
  if (is_array($ans)) $ans = $ans[0];
  return $ans;
}

$a = $_REQUEST['a'];
$v = $_REQUEST['v'];
$s = $_REQUEST['s'];
if (is_null($s)) $s = 'author';
$select = select($a, $v);
$order = array_map("order_key", $select);
asort($order);

foreach ($order as $id => $val) {
  $ref = $select[$id];
  echo "<input type=checkbox name=foo value=$id>&nbsp;";
  echo ref_string($ref);
  echo ref_cmd($id);
  echo "<br>\n";
}

?>
