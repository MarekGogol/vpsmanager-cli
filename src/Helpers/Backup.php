<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Backup extends Application
{
    private function getBackupPath()
    {
        return $this->config('backup_path') . '/' . date('Y-m-d_H');
    }

    /*
     * Send error email
     */
    private function sendError($message)
    {
        //Send mail if is in cron

        return $this->response()->error($message);
    }

    /*
     * Check if mysql connection works
     */
    private function testMysql()
    {
        exec('mysql -uroot -e "show databases;" -s --skip-column-names', $output, $return_var);

        return [
            'result' => $return_var == 0,
            'databases' => $output,
        ];
    }

    public function backupDatabases()
    {
        if ( ! ($test_result = $this->testMysql())['result'] )
            return $this->sendError('Could not connect to database.');

        $backup_path = $this->getBackupPath() . '/mysql';

        $backup_except_dbs = ['information_schema', 'performance_schema'];

        $bash = 'mkdir -p "'.$backup_path.'"
        databases=`mysql -uroot -e \'show databases\' -s --skip-column-names | grep -Ev "('.implode('|', $backup_except_dbs).')"`

        for DB in $databases; do
            mysqldump $DB | gzip > "'.$backup_path.'/$DB.sql.gz";
        done';

        //Backup databases
        exec($bash, $output, $return_var);

        //Check if is available at least one backup
        $backed_up = array_diff(scandir($backup_path), ['.', '..']);

        //Get just databases without unnecessary ones...
        $all_databases = array_map(function($item){
            return $item . '.sql.gz';
        }, array_diff($test_result['databases'], $backup_except_dbs));

        //Compane if some databases are missing from backup
        if ( count($missing = array_diff($backed_up, $all_databases)) > 0 )
            return $this->sendError('Databases could not be backed up: '.implode(' ', $missing));

        return $this->response()->success('Databases has been successfully backed up.');
    }

    /*
     * Run all types of backups
     */
    public function perform()
    {
        //Check errors
        if ( ($response = $this->backupDatabases()->writeln())->isError() )
            return $response;

        return $this->response()->success('Full backup has been successfullu performed.');
    }
}
?>