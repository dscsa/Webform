<?php

require_once 'dbs/mysql_webform.php';

function changes_to_patients() {
  $mysql = new Mysql_Webform();

  //Get Upserts (Updates & Inserts)
  $upserts = $mysql->run("
    SELECT new.*
    FROM
      gp_patients_grx as new
    LEFT JOIN gp_patients as old ON
      old.patient_id_grx <=> new.patient_id_grx AND
      old.first_name <=> new.first_name AND
      old.last_name <=> new.last_name AND
      old.birth_date <=> new.birth_date AND
      old.phone1 <=> new.phone1 AND
      old.phone2 <=> new.phone2 AND
      old.email <=> new.email AND
      old.patient_autofill <=> new.patient_autofill AND
      -- old.user_def1 <=> new.user_def1 AND
      -- old.user_def2 <=> new.user_def2 AND
      -- old.user_def3 <=> new.user_def3 AND
      -- old.user_def4 <=> new.user_def4 AND
      old.patient_address1 <=> new.patient_address1 AND
      old.patient_address2 <=> new.patient_address2 AND
      old.patient_city <=> new.patient_city AND
      old.patient_state <=> new.patient_state AND
      old.patient_zip <=> new.patient_zip AND
      -- old.total_fills <=> new.total_fills AND
      old.patient_status <=> new.patient_status AND
      old.lang <=> new.lang
      -- old.patient_date_added <=> new.patient_date_added AND
      -- old.patient_date_changed <=> new.patient_date_changed
    WHERE
      old.patient_id_grx IS NULL
  ");

  //Get Removals
  $removals = $mysql->run("
    SELECT old.*
    FROM
      gp_patients_grx as new
    RIGHT JOIN gp_patients as old ON
      old.patient_id_grx <=> new.patient_id_grx
      -- old.first_name <=> new.first_name AND
      -- old.last_name <=> new.last_name AND
      -- old.birth_date <=> new.birth_date AND
      -- old.phone1 <=> new.phone1 AND
      -- old.phone2 <=> new.phone2 AND
      -- old.email <=> new.email AND
      -- old.patient_autofill <=> new.patient_autofill AND
      -- old.user_def1 <=> new.user_def1 AND
      -- old.user_def2 <=> new.user_def2 AND
      -- old.user_def3 <=> new.user_def3 AND
      -- old.user_def4 <=> new.user_def4 AND
      -- old.patient_address1 <=> new.patient_address1 AND
      -- old.patient_address2 <=> new.patient_address2 AND
      -- old.patient_city <=> new.patient_city AND
      -- old.patient_state <=> new.patient_state AND
      -- old.patient_zip <=> new.patient_zip AND
      -- old.total_fills <=> new.total_fills AND
      -- old.patient_status <=> new.patient_status AND
      -- old.lang <=> new.lang AND
      -- old.patient_date_added <=> new.patient_date_added AND
      -- old.patient_date_changed <=> new.patient_date_changed
    WHERE
      new.patient_id_grx IS NULL
  ");

  //Do Upserts
  $mysql->run("
    INSERT INTO gp_patients
    SELECT new.*
    FROM
      gp_patients_grx as new
    LEFT JOIN gp_patients as old ON
      old.patient_id_grx <=> new.patient_id_grx AND
      old.first_name <=> new.first_name AND
      old.last_name <=> new.last_name AND
      old.birth_date <=> new.birth_date AND
      old.phone1 <=> new.phone1 AND
      old.phone2 <=> new.phone2 AND
      old.email <=> new.email AND
      old.patient_autofill <=> new.patient_autofill AND
      -- old.user_def1 <=> new.user_def1 AND
      -- old.user_def2 <=> new.user_def2 AND
      -- old.user_def3 <=> new.user_def3 AND
      -- old.user_def4 <=> new.user_def4 AND
      old.patient_address1 <=> new.patient_address1 AND
      old.patient_address2 <=> new.patient_address2 AND
      old.patient_city <=> new.patient_city AND
      old.patient_state <=> new.patient_state AND
      old.patient_zip <=> new.patient_zip AND
      -- old.total_fills <=> new.total_fills AND
      old.patient_status <=> new.patient_status AND
      old.lang <=> new.lang
      -- old.patient_date_added <=> new.patient_date_added AND
      -- old.patient_date_changed <=> new.patient_date_changed
    WHERE
      old.patient_id_grx IS NULL
    ON DUPLICATE KEY UPDATE
      patient_id_grx = new.patient_id_grx,
      first_name = new.first_name,
      last_name = new.last_name,
      birth_date = new.birth_date,
      phone1 = new.phone1,
      phone2 = new.phone2,
      email = new.email,
      patient_autofill = new.patient_autofill,
      user_def1 = new.user_def1,
      user_def2 = new.user_def2,
      user_def3 = new.user_def3,
      user_def4 = new.user_def4,
      patient_address1 = new.patient_address1,
      patient_address2 = new.patient_address2,
      patient_city = new.patient_city,
      patient_state = new.patient_state,
      patient_zip = new.patient_zip,
      total_fills = new.total_fills,
      patient_status = new.patient_status,
      lang = new.lang,
      patient_date_added = new.patient_date_added,
      patient_date_changed = new.patient_date_changed

  ");

  //Do Removals
  $mysql->run("
    DELETE old
    FROM gp_patients_grx as new
    RIGHT JOIN gp_patients as old ON
      old.patient_id_grx <=> new.patient_id_grx
      -- old.first_name <=> new.first_name AND
      -- old.last_name <=> new.last_name AND
      -- old.birth_date <=> new.birth_date AND
      -- old.phone1 <=> new.phone1 AND
      -- old.phone2 <=> new.phone2 AND
      -- old.email <=> new.email AND
      -- old.patient_autofill <=> new.patient_autofill AND
      -- old.user_def1 <=> new.user_def1 AND
      -- old.user_def2 <=> new.user_def2 AND
      -- old.user_def3 <=> new.user_def3 AND
      -- old.user_def4 <=> new.user_def4 AND
      -- old.patient_address1 <=> new.patient_address1 AND
      -- old.patient_address2 <=> new.patient_address2 AND
      -- old.patient_city <=> new.patient_city AND
      -- old.patient_state <=> new.patient_state AND
      -- old.patient_zip <=> new.patient_zip AND
      -- old.total_fills <=> new.total_fills AND
      -- old.patient_status <=> new.patient_status AND
      -- old.lang <=> new.lang AND
      -- old.patient_date_added <=> new.patient_date_added AND
      -- old.patient_date_changed <=> new.patient_date_changed
    WHERE
      new.patient_id_grx IS NULL
  ");

  return ['upserts' => $upserts[0], 'removals' => $removals[0]];
}
