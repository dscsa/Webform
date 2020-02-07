<?php

function export_cp_patient_save_medications_other($mssql, $patient, $live = false) {

  $medications_other = str_replace("'", "''", $patient['medications_other']);

  /*
  $select = "
    SELECT
      DATALENGTH(cmt) as cmt_length,
      CHARINDEX(CHAR(10), cmt) as char10_index,
      CHARINDEX(CHAR(13), cmt) as char13_index,
      CHARINDEX(CHAR(10)+'___', cmt) as divider_index,
      LEN(SUBSTRING(cmt, 0, ISNULL(NULLIF(CHARINDEX(CHAR(10)+'___', cmt), 0), 9999))) as first_length,
      SUBSTRING(cmt, 0, ISNULL(NULLIF(CHARINDEX(CHAR(10)+'___', cmt), 0), 9999)) as first,
      cmt
    FROM cppat
    WHERE pat_id = $patient[patient_id_cp]
  ";
  */

  $sql = "
    UPDATE cppat
    SET cmt =
      SUBSTRING(cmt, 0, ISNULL(NULLIF(CHARINDEX(CHAR(10)+'___', cmt), 0), 9999))+
      CHAR(10)+'______________________________________________'+CHAR(13)+
      '$medications_other'
    WHERE pat_id = $patient[patient_id_cp]
  ";

  //$res1 = $mssql->run("$select");
  $mssql->run("$sql");
  //$res2 = $mssql->run("$select");

  //echo "
  //live:$live $patient[first_name] $patient[last_name] $sql ".json_encode($res1, JSON_PRETTY_PRINT)." ".json_encode($res2, JSON_PRETTY_PRINT);
}

function export_cp_patient_save_patient_note($mssql, $patient, $live = false) {

  $sql = "NOT IMPLEMENTED";

  echo "
  live:$live $sql";

  //$mssql->run("$sql");
}

function upsert_patient_cp($mssql, $sql) {
  //echo "
  //$sql";

  $mssql->run("$sql");
}
