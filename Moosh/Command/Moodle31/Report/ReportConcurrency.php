<?php
/**
 * moosh - Moodle Shell
 * TBA
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Moodle31\Report;
use Moosh\MooshCommand;

class ReportConcurrency extends MooshCommand
{

    public function __construct()
    {
        parent::__construct('concurrency', 'report');

        $this->addOption('f|from:', 
                         'from date in YYYYMMDD or YYYY-MM-DD format (default is 30 days backwards)', 
                         '-30 days'
        );
        $this->addOption('t|to:', 
                         'to date in YYYYMMDD or YYYY-MM-DD format (default is today)');
        $this->addOption('p|period:', 'period of time (in minutes) during which unique users will be counted as concurrent.', 5);
        $this->addOption('z|time-zone:', 'timezone used to display the dates. Possible values on https://secure.php.net/manual/en/timezones.php.', "UTC");
    }

    public function execute()
    {
    	global $DB, $CFG;

        $options = $this->expandedOptions;

        $from_date = strtotime($options['from']);
        if ($options['to']) {
            $to_date = strtotime($options['to']);
        }
        else {
            $to_date = time();
        }
        
        if ($from_date === false) {
            cli_error('invalid from date');
        }

        if ($to_date === false) {
            cli_error('invalid to date');
        }

        if ($to_date < $from_date) {
            cli_error('to date must be higher than from date');
        }

        $timezone = new \DateTimeZone($options['time-zone']);

        $period = $options['period'] * 60;
        
        

        // get the number of concurrent users for each period (hidden)
        $sql = "SELECT (FROM_UNIXTIME(period * ( $period ))) AS Date,
                DAYNAME( FROM_UNIXTIME( period * ( $period ) ) ) AS DAY,
                DATE_FORMAT( FROM_UNIXTIME( period * ( $period ) ) , '%M %d, %Y' ) AS DayDate,
                TIME( FROM_UNIXTIME( period * ( $period ) ) ) AS Timecreated,
                period * ( $period )  AS unixtime,
				online_users FROM 
				
				(SELECT ROUND( timecreated / ( $period ) ) AS period,
				COUNT( DISTINCT userid ) AS online_users
				FROM {logstore_standard_log}
				WHERE timecreated BETWEEN $from_date AND $to_date
				AND origin = 'web'
				GROUP BY period
				) AS concurrent_users_report";
        $query = $DB->get_records_sql($sql);

        $fulldata = [];
        $previoustime = null;
        foreach ($query as $k => $v) {
            $date = date_create('@' . $v->unixtime, $timezone);
            if (!$date) {
                die("Invalid date for " . $v->unixtime);
            }
            $fulldata[$v->unixtime] = ['date' => $date->format("Y-m-d H:i:s"), 'users' => $v->online_users];

            if ($previoustime && $v->unixtime - $previoustime != $period * 60) {
                // Insert missing records - per period.
                $missing = ($v->unixtime - $previoustime) / ($period * 60) - 1;
                $missing = (int) $missing;
                //echo "Missing $missing records, the difference is " . ($v->unixtime - $previoustime) . "\n";
                for ($i = 1; $i <= $missing; $i++) {
                    $tempdate = date_create('@' . ($v->unixtime + $i * $period), $timezone);
                    $fulldata[$v->unixtime + $i * $period] =
                            ['date' => $tempdate->format("Y-m-d H:i:s"), 'users' => 0];
                }
            }
            $previoustime = $v->unixtime;
        }

        foreach($fulldata as $k=>$row) {
            echo $row['date'] . ',' . $row['users'] . "\n";
        }

        // display the instance name
        echo "Name: ".$CFG->wwwroot."\n";

        // display the size of the data folder
        $dataroot = run_external_command("du -bs $CFG->dataroot", "Couldn't find dataroot directory");
        $pattern = '/\d*/';
        preg_match($pattern, $dataroot[0], $matches);

        echo "Data Size: ".round($matches[0]/1024/1024, 2)." (MB)\n";

        // display database size
        $sql = "SELECT table_name AS 'Table',
                  ROUND(((data_length + index_length))) AS 'Size(Bytes)'
                  FROM information_schema.TABLES
                  WHERE table_schema = '".$CFG->dbname."'
                  ORDER BY (data_length + index_length) DESC";
        $results = $DB->get_records_sql($sql);

        $databasesize = 0;
        foreach ($results as $result) {
            $databasesize += $result->{'size(bytes)'};
        }
        echo "Database Size: ".round($databasesize / 1024 / 1024, 2) ." (MB)\n";

        // display active users during specified period
        $sql = "SELECT COUNT( DISTINCT userid ) AS NumberOfActiveUsers
				FROM {logstore_standard_log}
				WHERE timecreated BETWEEN $from_date AND $to_date";
        $result = $DB->get_record_sql($sql);

        echo "Active Users: ".$result->{'numberofactiveusers'}."\n";

        // get the max concurrent users during any period
        $sql = "SELECT MAX( concurrent_users_report.online_users ) AS maxusercount
                FROM (SELECT ROUND( timecreated / ( $period ) ) AS period,
				COUNT( DISTINCT userid ) AS online_users
				FROM {logstore_standard_log}
				WHERE timecreated BETWEEN $from_date AND $to_date
				GROUP BY period) AS concurrent_users_report";
        $result = $DB->get_record_sql($sql);

        echo "Max Concurrent Users: ".$result->maxusercount."\n";

        // Get the average concurrent users last 12 months
        $todayminustwelvemonths =  strtotime('-1 years');
        $totalusersinpastyear = 0;
        $periodsoveryear = 0;

        $sql = "SELECT (FROM_UNIXTIME(period * ( $period ))) AS Date,
                DAYNAME( FROM_UNIXTIME( period * ( $period ) ) ) AS DAY,
                DATE_FORMAT( FROM_UNIXTIME( period * ( $period ) ) , '%M %d, %Y' ) AS DayDate,
                TIME( FROM_UNIXTIME( period * ( $period ) ) ) AS Timecreated,
				online_users FROM 
				(SELECT ROUND( timecreated / ( $period ) ) AS period,
				COUNT( DISTINCT userid ) AS online_users
				FROM {logstore_standard_log}
				WHERE timecreated > $todayminustwelvemonths
				GROUP BY period) AS concurrent_users_report";
        $results = $DB->get_records_sql($sql);

        foreach ($results as $result) {
            $totalusersinpastyear += $result->online_users;
            $periodsoveryear++;
        }
        if($periodsoveryear == 0){
            $periodsoveryear=1;
        }

        echo "Average concurrent users past 12 months: ".round($totalusersinpastyear/$periodsoveryear,2)."\n";
    }
}