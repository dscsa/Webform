<?php

require_once 'helpers/helper_imports.php';

function set_parsed_sig($rx_number, $parsed, $mysql) {

  if ( ! $parsed['qty_per_day']) {
    log_error("update_rxs_single created: sig could not be parsed", $parsed);
    return;
  }

  $mysql->run("
    UPDATE gp_rxs_single SET
      sig_initial                = '$parsed[sig_actual]',
      sig_clean                  = '$parsed[sig_clean]',
      sig_qty                    = $parsed[sig_qty],
      sig_days                   = ".($parsed['sig_days'] ?: 'NULL').",
      sig_qty_per_day_default    = $parsed[qty_per_day],
      sig_durations              = ',".implode(',', $parsed['durations']).",',
      sig_qtys_per_time          = ',".implode(',', $parsed['qtys_per_time']).",',
      sig_frequencies            = ',".implode(',', $parsed['frequencies']).",',
      sig_frequency_numerators   = ',".implode(',', $parsed['frequency_numerators']).",',
      sig_frequency_denominators = ',".implode(',', $parsed['frequency_denominators']).",'
    WHERE
      rx_number = $rx_number
  ");
}


function get_parsed_sig($sig_actual, $drug_name, $correct = null) {

  //1 Clean sig
  //2 Split into Durations
  //3 Split into Parts
  //4 Parse each part
  //5 Combine parts into total

  $parsed = [];
  $parsed['sig_actual'] = preg_replace('/\'/', "''", $sig_actual);   //escape single quotes (latter for sql savings errors);
  $parsed['sig_clean'] = clean_sig($parsed['sig_actual']);
  $parsed['durations'] = durations($parsed['sig_clean'], $correct);
  $parsed['qtys_per_time'] = qtys_per_time($parsed['durations'], $drug_name, $correct);
  $parsed['frequency_numerators'] = frequency_numerators($parsed['durations'], $correct);
  $parsed['frequency_denominators'] = frequency_denominators($parsed['durations'], $correct);
  $parsed['frequencies'] = frequencies($parsed['durations'], $correct);

  if ( ! preg_match('/ INH(?!.*SOLN|.*SOLUTION)/i', $drug_name) AND stripos($drug_name, ' CREAM') === false AND stripos($parsed['sig_clean'], ' puff') === false) {
    $parsed['sig_days']    = array_sum($parsed['durations']);
    $parsed['sig_qty']     = sig_qty($parsed);
    $parsed['qty_per_day'] = qty_per_day($parsed);
  } else {
    $parsed['sig_days']    = 60;
    $parsed['sig_qty']     = 2;
    $parsed['qty_per_day'] = 2/60;
  }

  //log_notice("parsed sig", $parsed);

  return $parsed;
}

function clean_sig($sig) {

  //Cleanup
  $sig = preg_replace('/\(.*?\)/', '', $sig); //get rid of parenthesis // "Take 1 capsule (300 mg total) by mouth 3 (three) times daily."
  $sig = preg_replace('/\\\/', '', $sig);   //get rid of backslashes and single quotes (latter for sql savings errors)
  $sig = preg_replace('/\\band\\b/i', '&', $sig);   // & is easier tp search in regex than "and"
  $sig = preg_replace('/\\bDr\./i', '', $sig);   // The period in Dr. will mess up our split for durations
  $sig = preg_replace('/\\bthen call 911/i', '', $sig); //"then call 911" is not a duration
  $sig = preg_replace('/\\bas directed/i', '', $sig); //< Diriection. As Directed was triggering a 2nd duration

  $sig = preg_replace('/ +(mc?g)\\b| +(ml)\\b/i', '$1$2', $sig);   //get rid of extra spaces
  $sig = preg_replace('/[\w ]*replaces[\w ]*/i', '$1', $sig); //Take 2 tablets (250 mcg total) by mouth daily. This medication REPLACES Levothyroxine 112 mcg",

  //Interpretting as 93 qty per day. Not sure if its best to just get rid of it here or fix the issue further down
  $sig = preg_replace('/ 90 days?$/i', '', $sig); //TAKE 1 CAPSULE(S) 3 TIMES A DAY BY ORAL ROUTE AS NEEDED. 90 days

  //echo "1 $sig";

  //Spanish
  $sig = preg_replace('/\\btomar\\b/i', 'take', $sig);
  $sig = preg_replace('/\\bcada\\b/i', 'each', $sig);
  $sig = preg_replace('/\\bhoras\\b/i', 'hours', $sig);

  //Abreviations
  $sig = preg_replace('/\\bhrs\\b/i', 'hours', $sig);
  $sig = preg_replace('/\\b(prn|at onset|when)\\b/i', 'as needed', $sig);
  $sig = preg_replace('/\\bdays per week\\b/i', 'times per week', $sig);

  //echo "2 $sig";
  //Substitute Integers
  $sig = preg_replace('/\\b(one|uno)\\b/i', '1', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(two|dos)\\b/i', '2', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(three|tres)\\b/i', '3', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(four|quatro)\\b/i', '4', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(five|cinco)\\b/i', '5', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(six|seis)\\b/i', '6', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(seven|siete)\\b/i', '7', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(eight|ocho)\\b/i', '8', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(nine|nueve)\\b/i', '9', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(ten|diez)\\b/i', '10', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\beleven\\b/i', '11', $sig); // once for 11 is both spanish and english
  $sig = preg_replace('/\\b(twelve|doce)\\b/i', '12', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(twenty|veinte)\\b/i', '20', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(thirty|treinta)\\b/i', '30', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(forty|cuarenta)\\b/i', '40', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(fifty|cincuenta)\\b/i', '50', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(sixty|sesenta)\\b/i', '60', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(seventy|setenta)\\b/i', '70', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(eighty|ochenta)\\b/i', '80', $sig); // \\b is for space or start of line
  $sig = preg_replace('/\\b(ninety|noventa)\\b/i', '90', $sig); // \\b is for space or start of line

  //echo "3 $sig";
  //Substitute fractions
  $sig = preg_replace('/\\b(\d+) (& )?(\.5|1\/2|1-half|1 half)\\b/i', '$1.5', $sig); //Take 1 1/2 tablets
  $sig = preg_replace('/(^| )(\.5|1\/2|1-half|1 half|half a)\\b/i', ' 0.5', $sig);

  //Take First (Min?) of Numeric Ranges, except 0.5 to 1 in which case we use 1
  $sig = preg_replace('/\\b0.5 *(or|to|-) *1\\b/i', '1', $sig); //Special case of the below where we want to round up rather than down
  $sig = preg_replace('/\\b([0-9]*\.[0-9]+|[1-9][0-9]*) *(or|to|-) *([0-9]*\.[0-9]+|[1-9][0-9]*)\\b/i', '$1', $sig); //Take 1 or 2 every 3 or 4 hours. Let's convert that to Take 1 every 3 hours (no global flag).  //Take 1 capsule by mouth twice a day as needed Take one or two twice a day as needed for anxiety

  //echo "4 $sig";
  //Duration
  $sig = preg_replace('/\\bx ?(\d+)\\b/i', 'for $1', $sig); // X7 Days == for 7 days

  $sig = preg_replace('/\\bon the (first|second|third|fourth|fifth|sixth|seventh) day/i', 'for 1 days', $sig);
  $sig = preg_replace('/\\bfor 1 dose/i', 'for 1 days', $sig);
  $sig = preg_replace('/\\bfor 1 months?|months? \d+/i', 'for 30 days', $sig);
  $sig = preg_replace('/\\bfor 2 months?/i', 'for 60 days', $sig);
  $sig = preg_replace('/\\bfor 3 months?/i', 'for 90 days', $sig);
  $sig = preg_replace('/\\bfor 1 weeks?|weeks? \d+/i', 'for 7 days', $sig);
  $sig = preg_replace('/\\bfor 1 weeks?/i', 'for 7 days', $sig);
  $sig = preg_replace('/\\bfor 2 weeks?/i', 'for 14 days', $sig);
  $sig = preg_replace('/\\bfor 3 weeks?/i', 'for 21 days', $sig);
  $sig = preg_replace('/\\bfor 4 weeks?/i', 'for 28 days', $sig);
  $sig = preg_replace('/\\bfor 5 weeks?/i', 'for 35 days', $sig);
  $sig = preg_replace('/\\bfor 6 weeks?/i', 'for 42 days', $sig);
  $sig = preg_replace('/\\bfor 7 weeks?/i', 'for 49 days', $sig);
  $sig = preg_replace('/\\bfor 8 weeks?/i', 'for 56 days', $sig);
  $sig = preg_replace('/\\bfor 9 weeks?/i', 'for 63 days', $sig);
  $sig = preg_replace('/\\bfor 10 weeks?/i', 'for 70 days', $sig);
  $sig = preg_replace('/\\bfor 11 weeks?/i', 'for 77 days', $sig);
  $sig = preg_replace('/\\bfor 12 weeks?/i', 'for 84 days', $sig);

  //Get rid of superflous "durations" e.g 'Take 1 tablet by mouth 2 times a day. Do not crush or chew.' -> 'Take 1 tablet by mouth 2 times a day do not crush or chew.'
  //TODO probably need to add a lot more of these.  Eg ". For 90 days."
  $sig = preg_replace('/\\b[.;\/] *(?=do not)/i', ' ', $sig);

  //Frequency Denominator
  $sig = preg_replace('/\\bq\\b/i', 'every', $sig); //take 1 tablet by oral route q 12 hrs
  $sig = preg_replace('/ *24 hours?/i', ' 1 day', $sig);
  $sig = preg_replace('/ *48 hours?/i', ' 2 days', $sig);
  $sig = preg_replace('/ *72 hours?/i', ' 3 days', $sig);
  $sig = preg_replace('/ *96 hours?/i', ' 4 days', $sig);

  //echo "5 $sig";
  //Alternative frequency numerator wordings
  $sig = preg_replace('/(?<!all )(other|otra)\\b/i', '2', $sig); //Exclude: Take 4mg 1 time per week, Wed; 2mg all other days or as directed.
  $sig = preg_replace('/\\bonce\\b/i', '1 time', $sig);
  $sig = preg_replace('/\\btwice\\b/i', '2 times', $sig);
  $sig = preg_replace('/\\bhourly\\b/i', 'per hour', $sig);
  $sig = preg_replace('/\\bdaily\\b/i', 'per day', $sig);
  $sig = preg_replace('/\\bweekly\\b/i', 'per week', $sig);
  $sig = preg_replace('/\\bmonthly\\b/i', 'per month', $sig);

  $sig = preg_replace('/\\b(\d) days? a week\\b/i', '$1 times per week', $sig);
  $sig = preg_replace('/\\bon (tuesdays?|tu?e?s?)[, &]*(thursdays?|th?u?r?s?)\\b/i', '2 times per week', $sig);
  $sig = preg_replace('/\\bon (mondays?|mo?n?)[, &]*(wednesdays?|we?d?)[, &]*(fridays?|fr?i?)\\b/i', '3 times per week', $sig);
  $sig = preg_replace('/\\bon (sundays?|sun|mondays?|mon|tuesdays?|tues?|wednesdays?|wed|thursdays?|thur?s?|fridays?|fri|saturdays?|sat)\\b/i', '1 time per week', $sig);

  //echo "6 $sig";

  $sig = preg_replace('/\\bmonthly\\b/i', 'per month', $sig);
  $sig = preg_replace('/\\bmonthly\\b/i', 'per month', $sig);
  $sig = preg_replace('/\\bmonthly\\b/i', 'per month', $sig);

  $sig = preg_replace('/\\b(breakfast|mornings?)[, &]*(dinner|night|evenings?)\\b/i', '2 times per day', $sig);
  $sig = preg_replace('/\\b(1 (in|at) )?\d* ?(am|pm)[, &]*(1 (in|at) )?\d* ?(am|pm)\\b/i', '2 times per day', $sig); // Take 1 tablet by mouth twice a day 1 in am and 1 at 3pm was causing issues
  $sig = preg_replace('/\\b(in|at) \d\d\d\d?[, &]*(in|at)?\d\d\d\d?\\b/i', '2 times per day', $sig); //'Take 2 tablets by mouth twice a day at 0800 and 1700'
  $sig = preg_replace('/\\b(with)?in (a )?\d+ (minutes?|days?|weeks?|months?)\\b|/i', '', $sig); // Remove "in 5 days|hours|weeks" so that we don't confuse frequencies
  $sig = preg_replace('/\\bevery +5 +min\w*/i', '3 times per day', $sig); //Nitroglycerin

  //echo "7 $sig";
  //Latin and Appreviations
  $sig = preg_replace('/\\bSUB-Q\\b/i', 'subcutaneous', $sig);
  $sig = preg_replace('/\\bBID\\b/i', '2 times per day', $sig);
  $sig = preg_replace('/\\bTID\\b/i', '3 times per day', $sig);
  $sig = preg_replace('/\\b(QAM|QPM)\\b/i', '1 time per day', $sig);
  $sig = preg_replace('/\\b(q12.*?h)\\b/i', 'every 12 hours', $sig);
  $sig = preg_replace('/\\b(q8.*?h)\\b/i', 'every 8 hours', $sig);
  $sig = preg_replace('/\\b(q6.*?h)\\b/i', 'every 6 hours', $sig);
  $sig = preg_replace('/\\b(q4.*?h)\\b/i', 'every 4 hours', $sig);
  $sig = preg_replace('/\\b(q3.*?h)\\b/i', 'every 3 hours', $sig);
  $sig = preg_replace('/\\b(q2.*?h)\\b/i', 'every 2 hours', $sig);
  $sig = preg_replace('/\\b(q1.*?h|every hour)\\b/i', 'every 1 hours', $sig);

  //Alternate units of measure
  $sig = preg_replace('/\\b4,?000ml\\b/i', '1', $sig); //We count Polyethylene Gylcol (PEG) as 1 unit not 4000ml.  TODO Maybe replace this rule with a more generalized rule?
  $sig = preg_replace('/\\b1 vial\\b/i', '3ml', $sig); // vials for inhalation are 2.5 or 3ml, so use 3ml to be conservative
  $sig = preg_replace('/\\b2 vials?\\b/i', '6ml', $sig); // vials for inhalation are 2.5 or 3ml, so use 3ml to be conservative
  $sig = preg_replace('/\\b3 vials?\\b/i', '9ml', $sig); // vials for inhalation are 2.5 or 3ml, so use 3ml to be conservative
  $sig = preg_replace('/\\b4 vials?\\b/i', '12ml', $sig); // vials for inhalation are 2.5 or 3ml, so use 3ml to be conservative
  $sig = preg_replace('/\\b5 vials?\\b/i', '15ml', $sig); // vials for inhalation are 2.5 or 3ml, so use 3ml to be conservative
  $sig = preg_replace('/\\b6 vials?\\b/i', '18ml', $sig); // vials for inhalation are 2.5 or 3ml, so use 3ml to be conservative

  //echo "9 $sig";

  //Alternative Wordings
  $sig = preg_replace('/\\bin (an|\d) hours?/i', '', $sig); //Don't catch "in an hour" from "Take 2 tablets by mouth as needed of gout & 1 more in an hour as needed"
  $sig = preg_replace('/\\bin \d+ minutes?/i', '', $sig);   //Don't use "in 10 minutes" for the frequency
  $sig = preg_replace('/\\b(an|\d) hours? later/i', '', $sig); //Don't catch "in an hour" from "Take 2 tablets by mouth as needed of gout & 1 more in an hour as needed"
  $sig = preg_replace('/\\b\d+ minutes? later/i', '', $sig);   //Don't use "in 10 minutes" for the frequency

  $sig = preg_replace('/\\bInject \d+ units?\\b/i', 'Inject 1', $sig); //INJECT 18 UNITS
  $sig = preg_replace('/\\bInject [.\d]+ *ml?\\b/i', 'Inject 1', $sig); //Inject 0.4 mL (40 mg total) under the skin daily for 18 days.
  $sig = preg_replace('/\\b\d+ units?(.*?subcutan)|\\b(subcutan.*?)\d+ units?\\b/i', 'Inject 1 $1$2', $sig); // "15 units at bedtime 1 time per day Subcutaneous 90 days":

  //Cleanup
  $sig = preg_replace('/  +/i', ' ', $sig); //Remove double spaces for aesthetics


  return trim($sig);
}

function durations($cleaned, $correct) {

    $durations = [];
    $remaining_days = DAYS_STD;

    //Separate natural subclauses within the sig (so that we can parse them separetly  "add them up" later)
    $increase = "(may|can) increase(.*?\/ *(month|week))?";
    $sentence = "(?<=[a-z ])[.;\/] *(?=\w)";  //Sentence ending in . ; or / e.g. "Take 1 tablet by mouth once daily / take 1/2 tablet on sundays"
    $then     = " then[ ,]+";
    $and_at   = " & +at ";
    $and_verb = " &[ ,]+(?=\d|use +|take +|inhale +|chew +|inject +|oral +)";

    $durations_regex = "/$increase|$sentence|$then|$and_at|$and_verb/i";
    $splits = preg_split($durations_regex, $cleaned);

    foreach ($splits as $split) {
      preg_match('/(?<!every) (\d+) day/i', $split, $match);

      if ($match AND $match[1]) {

        $remaining_days   -= $match[1];
        $durations[$split] = $match[1];

      } else if ($remaining_days != DAYS_STD) {

        $durations[$split] = $remaining_days;

      } else {

        $durations[$split] = 0;

      }
    }

    if ($correct AND implode(',', $durations) != $correct['durations']) {
      log_error("test_parse_sig incorrect duration: $correct[sig_actual]", ['cleaned' => $cleaned, 'correct' => $correct['durations'], 'current' => $durations]);
    }

    return $durations;
}

function qtys_per_time($durations, $drug_name, $correct) {

  $qtys_per_time = [];

  foreach ($durations as $sig_part => $duration) {

    //If MAX then use max and ignore preceeding sig e.g TAKE 1 TABLET BY MOUTH AS NEEDED FOR MIGRAINE, MAY REPEAT IN 2 HRS IF NEEDED, MAX 2TABS\/24 HRS
    //Put this after inject because max 350 units may become 1 injection
    //Get rid of "max" qtys in sig because they are redundant and could accidentally be added in.  Don't put in clean sig or it will get rid of "for chest pain" and the like which will mess up frequencies
    $cleaned_sig_part = preg_replace('/.*(exceed +(more +than +)?|exceeding +|totaling +|up *to +|total +of +|not +to +|no +more +than +|max(imum)? +(of +|per +day +(of +)?|per +day +dose( +|: *)|daily +dose( +|: *))?)([0-9]*\.[0-9]+|[1-9][0-9]*)/i', 'Max $8', $sig_part);

    //"Use daily with lantus"  won't match the RegEx below
    $starts_with_a_number = "^([0-9]*\.[0-9]+|[1-9][0-9]*)\\b"; //Take 1 tablet by mouth  three times per day for 14 days then once daily for 16 days. \\b due to "20 mg PO qDay"
    $includes_dosage_form = "([0-9]*\.[0-9]+|[1-9][0-9]*) ?(ml|tab|cap|pill|softgel|patch|injection|each|dose)";
    $includes_active_verb = "(^|use +|take +|inhale +|chew +|inject +|oral +)([0-9]*\.[0-9]+|[1-9][0-9]*)(?!\d| *\.| *mg| *time| *min| *hour| *day| *week| *month)";
    $count = preg_match_all("/$starts_with_a_number|$includes_dosage_form|$includes_active_verb/i", $cleaned_sig_part, $match);

    //print_r(['duration' => $duration, 'match' => $match, 'count' => $count]);

    if ($count) {
      //Avoid issues of dupliced Sigs. e.g Take 1 tablet by mouth 1 time a day Take 1 tablet per day
      if (count($match[1]) == 2 AND $match[1][0] == $match[1][1])
        unset($match[1][1]); //$starts_with_a_number

      if (count($match[2]) == 2 AND $match[2][0] == $match[2][1])
        unset($match[2][1]); //$includes_dosage_form

      if (count($match[5]) == 2 AND $match[5][0] == $match[5][1])
        unset($match[5][1]); //$includes_active_verb

      $qtys_per_time[$sig_part] = array_sum($match[1])+array_sum($match[2])+array_sum($match[5]);
      continue;
    }

    $regex_match = '/([0-9]*\.[0-9]+|[1-9][0-9]*) ?mc?g\\b/i';
    preg_match($regex_match, $drug_name, $drug_match);
    preg_match($regex_match, $cleaned_sig_part, $sig_match);

    //print_r(['duration' => $duration, 'drug_name' => $drug_name, 'drug_match' => $drug_match, 'sig_match' => $sig_match]);

    if ( ! $drug_match OR ! $sig_match) {
      //If its the first duration assume 1 qty per time, but if this is a 2nd sentence don't assume anything
      //e.g ifgnore 2nd sentence within "Take 1 tablet by oral route every day swallowing whole with water. Do not crush, chew and\/or divide"
      $qtys_per_time[$sig_part] = count($qtys_per_time) ? 0 : 1;
      continue;
    }

    $qtys_per_time[$sig_part] = $sig_match[1]/$drug_match[1];
    //log_notice("qtys_per_time: cleaning milligrams: $sig_part", ['sig_match' => $sig_match, 'drug_match' => $drug_match]);
  }

  if ($correct AND implode(',', $qtys_per_time) != $correct['qtys_per_time']) {
    log_error("test_parse_sig incorrect qtys_per_time: $correct[sig_actual]", ['durations' => $durations, 'correct' => $correct['qtys_per_time'], 'current' => $qtys_per_time]);
  }

  return $qtys_per_time;
}

function frequency_numerators($durations, $correct) {

  $frequency_numerators = [];

  foreach ($durations as $sig_part => $duration) {

    //"Take 1 tablet by mouth at bedtime after meals for cholesterol" should be 1 time per day not 3.
    //"Take 1 tablet by mouth every morning after meals" should be 1 time per day not 3
    if (preg_match('/\\bat|every\\b/i', $sig_part))
      $default = 1;
    else if (preg_match('/\\b(before|with|after) meals & at bedtime\\b/i', $sig_part))
      $default = 4;
    else if (preg_match('/\\b(before|with|after) meals\\b/i', $sig_part))
      $default = 3;
    else
      $default = 1;

    preg_match('/([1-9]\\b|10|11|12) +time/i', $sig_part, $match);
    $frequency_numerators[$sig_part] = $match ? $match[1] : $default;

  }

  if ($correct AND implode(',', $frequency_numerators) != $correct['frequency_numerators']) {
    log_error("test_parse_sig incorrect frequency_numerators: $correct[sig_actual]", ['durations' => $durations, 'correct' => $correct['frequency_numerators'], 'current' => $frequency_numerators]);
  }

  return $frequency_numerators;
}


function frequency_denominators($durations, $correct) {

  $frequency_denominators = [];

  foreach ($durations as $sig_part => $duration) {

    preg_match('/every ([1-9]\\b|10|11|12)(?! +time)/i', $sig_part, $match);
    $frequency_denominators[$sig_part] = $match ? $match[1] : 1;
  }

  if ($correct AND implode(',', $frequency_denominators) != $correct['frequency_denominators']) {
    log_error("test_parse_sig incorrect frequency_denominators: $correct[sig_actual]", ['durations' => $durations, 'correct' => $correct['frequency_denominators'], 'current' => $frequency_denominators]);
  }

  return $frequency_denominators;
}

//Returns frequency in number of days (e.g, weekly means 7 days)
function frequencies($durations, $correct) {

  $frequencies = [];

  foreach ($durations as $sig_part => $duration) {

    $as_needed = preg_match('/(^| )(prn|as needed)/i', $sig_part);

    if (preg_match('/(@|at) (1st |first )?sign|when|at onset|(if|for) chest|(if|for) CP/i', $sig_part)) //CP is shorthand for Chest Pain
      $freq = '30/25'; //Most common is Nitroglycerin: max 3 doses for chest pain.  Since comes in boxes of 25 each, Cindy likes dispensing 75 for 90 days (90*3/3.6 = 75)

    else if (preg_match('/ week\\b/i', $sig_part))
      $freq = '30/4'; //rather than 7 days, calculate as 1/4th a month so we get 45/90 days rather than 42/84 days

    else if (preg_match('/ day\\b/i', $sig_part))
      $freq = '1';

    else if (preg_match('/ month\\b/i', $sig_part))
      $freq = '30';

    else if (preg_match('/ hour(?!s? +before|s? +after|s? +prior to)/i', $sig_part)) //put this last so less likely to match thinks like "2 hours before (meals|bedtime) every day"
      $freq = $as_needed ? '2/24' : '1/24'; // One 24th of a day

    else if (preg_match('/ minute(?!s? +before|s? +after|s? +prior to)/i', $sig_part)) //put this last so less likely to match thinks like "2 hours before (meals|bedtime) every day"
      $freq = $as_needed ? '90/25/3/5' : '1/24/60'; //Most common is Nitroglycerin: 1 tab every 5 minutes for chest pain.  Since comes in boxes of 25 each, Cindy likes dispensing 75 for 90 days

    else
      $freq = $as_needed ? '2' : '1'; //defaults to daily if no matches

    //Default to daily Example 1 tablet by mouth at bedtime
    $frequencies[$sig_part] = $freq;
  }

  if ($correct AND implode(',', $frequencies) != $correct['frequencies']) {
    log_error("test_parse_sig incorrect frequencies: $correct[sig_actual]", ['as_needed' => $as_needed, 'durations' => $durations, 'correct' => $correct['frequencies'], 'current' => $frequencies]);
  }

  return $frequencies;
}

function qty_per_day($parsed) {

  $qty_per_day = $parsed['sig_qty']/($parsed['sig_days'] ?: DAYS_STD);

  $qty_per_day = round($qty_per_day, 3);

  return $qty_per_day > MAX_QTY_PER_DAY ? 1 : $qty_per_day; //Until we get more accurate let's only shop/pack a max of 8 per day (90*8 = ~720)
}

function sig_qty($parsed) {

  $qty = 0;

  //eval converts string fractions to decimals https://stackoverflow.com/questions/7142657/convert-fraction-string-to-decimal
  foreach ($parsed['frequencies'] as $i => $frequency) {
    //"For 30 days" in "take 1 tablet by Oral route 4 times per day 1 hour before meals & at bedtime for 30 days"
    //was being applied to the 2nd duration but not the first, so now we default a 0 duration to the last element
    //of the array and only default it to DAYS_STD if the last element is also 0
    //print_r($parsed['durations']);
    //print_r($parsed['durations'][$i]);
    //print_r(end($parsed['durations']));
    $duration = $parsed['durations'][$i] ?: end($parsed['durations']);
    $qty += ($duration ?: DAYS_STD) * $parsed['qtys_per_time'][$i] * $parsed['frequency_numerators'][$i] / $parsed['frequency_denominators'][$i] / eval("return $frequency;");
  }

  return $qty;
}
