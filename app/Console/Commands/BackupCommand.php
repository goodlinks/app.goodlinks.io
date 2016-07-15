<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class BackupCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Backup database";

    protected function getOptions()
    {
        return array(
            array('silent', null, InputOption::VALUE_OPTIONAL, 'No output', 0),
        );
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        $this->_info("Running backup");
        $this->_dumpDatabase();
        $usage = $this->_getDiskUsage();
        $this->_info("Usage: $usage %");

        $this->_sendToS3();
        $this->_deleteDump();
        $this->_notify($usage);
    }

    protected function _dumpDatabase()
    {
        $username = env('DB_USERNAME');
        $dbName = env('DB_DATABASE');
        $password = env('DB_PASSWORD');

        $destinationFilePath = $this->_getDestinationFilePath();

        $this->_info("Backing up database $dbName to $destinationFilePath");
        shell_exec("mysqldump --single-transaction -u$username -p$password $dbName > $destinationFilePath");

        $this->_info("Compressing database $dbName to $destinationFilePath.gz");
        shell_exec("gzip $destinationFilePath");
    }

    protected function _getDestinationFilePath()
    {
        $directory = dirname(dirname(dirname(dirname(__FILE__)))) . '/';
        return $directory . $this->_getDumpFileName();
    }

    protected function _deleteDump()
    {
        $destinationFilePathCompressed = $this->_getDestinationFilePath() . ".gz";
        $this->_info("Deleting dump: $destinationFilePathCompressed");
        shell_exec("rm $destinationFilePathCompressed");

        return $this;
    }

    protected function _getDumpFileName()
    {
        if (isset($this->_dumpFileName)) {
            return $this->_dumpFileName;
        }

        $now = new \Carbon\Carbon();
        $nowAsFileName = $now->format('Y-M-j') . '-at-' . $now->format('g-i-A-T');
        $this->_dumpFileName = env('BACKUP_PREFIX') . $nowAsFileName . ".sql";

        return $this->_dumpFileName;
    }

    protected function _getDiskUsage()
    {
        $output = shell_exec("df");
        $lines = explode("\n", $output);
        if (!isset($lines[3])) {
            throw new \Exception("Couldn't find line 3");
        }

        $line = $lines[3];
        $parts = preg_split('/\s+/', $line);

        if (! isset($parts[4])) {
            throw new \Exception("Couldn't find percentage");
        }

        $percentage = $parts[4];
        $percentageNumber = substr($percentage, 0, strlen($percentage) - 1);

        return $percentageNumber;
    }

    protected function _sendToS3()
    {
        $s3 = S3Client::factory(array(
            'region'    => 'us-west-2',
            'version'   => '2006-03-01',
            'credentials' => array(
                'key'       => env('S3_API_KEY'),
                'secret'    => env('S3_API_SECRET'),
            ),
        ));

        $sourceFile = $this->_getDestinationFilePath() . ".gz";
        $this->_info("Sending to S3: " . $sourceFile);

        try {
            $s3->putObject(array(
                'Bucket'        => env('S3_API_BUCKET'),
                'Key'           => $this->_getDumpFileName() . ".gz",
                'SourceFile'    => $sourceFile,
                'ACL'           => 'private',
            ));
        } catch (S3Exception $e) {
            echo "There was an error uploading the file.\n";
            return $this;
        }

        return $this;
    }

    protected function _notify($usage)
    {
        $email = env('BACKUP_NOTIFICATION_EMAIL');
        $subject = env('BACKUP_NOTIFICATION_SUBJECT');
        mail($email, "$subject (Usage: $usage%)", "Backup complete - usage: $usage%", "From: cron@magemail.co");
    }

    protected function _info($message)
    {
        if (! $this->option('silent')) {
            $this->info($message);
        }
    }
}