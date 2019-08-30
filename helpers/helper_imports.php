<?php

// Convert empty string to null or CP's <Not Specified> to NULL
function clean_val($val) {
  $val = mysql_real_escape_string(trim($val));
  return ($val === '' OR $val === '<Not Specified>' OR $val === 'NULL') ? 'NULL' : "'$val'";
}

//2d array map
function result_map(&$rows, $callback = null) {

  foreach( $rows as $i => $row ) {

    foreach( $row as $key => $val ) {
      $row[$key] = clean_val($val);
    }

    $new = $callback
      ? ($callback($row, $i) ?: $row)
      : $new = $row;

    //If we added new columns we need to save the keys
    //WARNING We must save the same columns every time (no ifs) otherwise key / val pairs will be mismatched
    $keys = isset($keys) ? $keys : array_keys($new);

    $rows[$i] = array_string($new);
  }

  $rows = implode(',', $rows);

  return array_string($keys);
}

function array_string($arr) {
  return "(".implode(', ', $arr).")";
}

function assert_length(&$field, $min, $max = null) {

  if ($field == 'NULL') return;
  
  $len = strlen($field);
  $max = $max ?: $min;

  if ($len >= $min AND $len <= $max) return;

  echo "
    Assert Length: $field has length of $len but needs to be between $min and $max
  ";

  $field = 'NULL';
}
