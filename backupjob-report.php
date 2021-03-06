<?php
/*
  +-------------------------------------------------------------------------+
  | Copyright 2010-2017, Davide Franco			                               |
  |                                                                         |
  | This program is free software; you can redistribute it and/or           |
  | modify it under the terms of the GNU General Public License             |
  | as published by the Free Software Foundation; either version 2          |
  | of the License, or (at your option) any later version.                  |
  |                                                                         |
  | This program is distributed in the hope that it will be useful,         |
  | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
  | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
  | GNU General Public License for more details.                            |
  +-------------------------------------------------------------------------+
 */

session_start();
include_once( 'core/global.inc.php' );

// Initialise view and model
$view = new CView();
$dbSql = new Bweb($view);
    
require_once('core/const.inc.php');
    
$backupjob_name = "";
$backupjob_bytes = 0;
$backupjob_files = 0;
$interval = array();
$interval[1] = NOW;
   
$days = array();
$days_stored_bytes = array();
$days_stored_files = array();

// Period list
$periods_list = array( '7' => "Last week", '14' => "Last 2 weeks", '30' => "Last month");
$view->assign('periods_list', $periods_list);

try {
   // Stored Bytes on the defined period
   $jobs = new Jobs_Model();

   // Get backup job(s) list
   $jobslist = $jobs->get_Jobs_List(null, 'B');
   $view->assign('jobs_list', $jobslist);

   // Check backup job name from $_POST request
   $backupjob_name = CHttpRequest::get_value('backupjob_name');

   if (($backupjob_name === NULL) && (empty($backupjob_name)) ) {
      $view->assign( 'no_report_options', 'true');

      // Set selected period
      $view->assign( 'selected_period', 7);
   }else{
      $view->assign( 'no_report_options', 'false');

      // Make sure provided backupjob_name exist
      if( !in_array( $backupjob_name, $jobslist)) {
         throw new Exception("Critical: provided backupjob_name is not valid");
      }

      $view->assign( 'selected_jobname', $backupjob_name);

      // Generate Backup Job report period string
      $backupjob_period = CHttpRequest::get_value('period');

      if (!is_null($backupjob_period)) {
         
         // Set selected period
         $view->assign( 'selected_period', $backupjob_period);

         switch( $backupjob_period ) {
         case '7':
            $backupjob_period = "From " . date( $dbSql->datetime_format_short, (NOW - WEEK)) . " to " . date( $dbSql->datetime_format_short, NOW);
            $interval[0] = NOW-WEEK;
            break;
         case '14':
            $backupjob_period = "From " . date( $dbSql->datetime_format_short, (NOW - (2 * WEEK))) . " to " . date( $dbSql->datetime_format_short, NOW);
            $interval[0] = NOW-(2*WEEK);
            break;
         case '30':
            $backupjob_period = "From " . date( $dbSql->datetime_format_short, (NOW - MONTH)) . " to " . date( $dbSql->datetime_format_short, NOW);
            $interval[0] = NOW-MONTH;
         }
      } // end if
    
      // Get start and end datetime for backup jobs report and charts
      $periods = CDBQuery::get_Timestamp_Interval($jobs->get_driver_name(), $interval);
    
      $backupjob_bytes = $jobs->getStoredBytes( $interval, $backupjob_name);
      $backupjob_bytes = CUtils::Get_Human_Size($backupjob_bytes);
    
      // Stored files on the defined period
      $backupjob_files = $jobs->getStoredFiles( $interval, $backupjob_name);
      $backupjob_files = CUtils::format_Number($backupjob_files);
    
      // Get the last 7 days interval (start and end)
      $days = DateTimeUtil::getLastDaysIntervals(CHttpRequest::get_value('period'));
    
      // Last 7 days stored files chart  
      foreach ($days as $day) {
         $stored_files = $jobs->getStoredFiles(array($day['start'], $day['end']), $backupjob_name);
         $days_stored_files[] = array(date("m-d", $day['start']), $stored_files);
      }
    
      $stored_files_chart = new Chart( array( 'type' => 'bar', 
         'name' => 'chart_storedfiles',
         'data' => $days_stored_files, 
         'ylabel' => 'Files' ) 
      );
    
      $view->assign('stored_files_chart_id', $stored_files_chart->name);
      $view->assign('stored_files_chart', $stored_files_chart->render());
    
      unset($stored_files_chart);   
    
      // Last 7 days stored bytes chart  
      foreach ($days as $day) {
         $stored_bytes = $jobs->getStoredBytes(array($day['start'], $day['end']), $backupjob_name);
         $days_stored_bytes[] = array(date("m-d", $day['start']), $stored_bytes);
      }
    
      $stored_bytes_chart = new Chart( array( 'type' => 'bar', 
         'name' => 'chart_storedbytes', 
         'uniformize_data' => true,
         'data' => $days_stored_bytes, 
         'ylabel' => 'Bytes' ) 
      );
    
      $view->assign('stored_bytes_chart_id', $stored_bytes_chart->name);
      $view->assign('stored_bytes_chart', $stored_bytes_chart->render());
      unset($stored_bytes_chart);   
    
      // Get last jobs list
      $query = "SELECT JobId, Level, JobFiles, JobBytes, ReadBytes, JobStatus, StartTime, EndTime, Name ";
      $query .= "FROM Job ";
      $query .= "WHERE Name = '$backupjob_name' AND Type = 'B' AND ";
      $query .= '(EndTime BETWEEN ' . $periods['starttime'] . ' AND ' . $periods['endtime'] . ') ';
      $query .= "ORDER BY EndTime DESC ";
    
      $joblist      = array();
      $joblevel     = array('I' => 'Incr', 'D' => 'Diff', 'F' => 'Full');
      $result = $jobs->run_query($query);
    
      foreach ($result->fetchAll() as $job) {
         // Job level description
         $job['joblevel'] = $joblevel[$job['level']];
         
         // Job execution execution time
         $job['elapsedtime'] = DateTimeUtil::Get_Elapsed_Time($job['starttime'], $job['endtime']);
       
         // Compression
         if ($job['jobbytes'] > 0) {
            $compression = (1-($job['jobbytes'] / $job['readbytes']));
            $job['compression'] = number_format($compression, 2);
         }else{
            $job['compression'] = 'N/A';
         }
       
         // Job speed
         $start = $job['starttime'];
         $end = $job['endtime'];
         $seconds = DateTimeUtil::get_ElaspedSeconds($end, $start);
       
         if ($seconds !== false && $seconds > 0) {
            $speed = $job['jobbytes'] / $seconds;
            $job['speed'] = CUtils::Get_Human_Size($speed, 2) . '/s';
         } else {
            $job['speed'] = 'N/A';
         }
       
         // Job bytes more easy to read
         $job['jobbytes'] = CUtils::Get_Human_Size($job['jobbytes']);
         $job['jobfiles'] = CUtils::format_Number($job['jobfiles']);
       
         // Format date/time
         $job['starttime'] = date( $dbSql->datetime_format, strtotime($job['starttime']));
         $job['endtime'] = date( $dbSql->datetime_format, strtotime($job['endtime']));
       
         $joblist[] = $job;
      } // end while
    
      $view->assign('jobs', $joblist);
      $view->assign('backupjob_name', $backupjob_name);
      $view->assign('backupjob_period', $backupjob_period);
      $view->assign('backupjob_bytes', $backupjob_bytes);
      $view->assign('backupjob_files', $backupjob_files);
   } // end else

}catch(Exception $e) {
   CErrorHandler::displayError($e);
}

// Set page name
$view->assign('page_name', 'Backup job report');
 
// Process and display the template
$view->display('backupjob-report.tpl');
