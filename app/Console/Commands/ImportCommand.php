<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class ImportCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Import feed from BuzzStream";

    protected function getOptions()
    {
        return array(
            array('silent', null, InputOption::VALUE_OPTIONAL, 'No output', 0),
            array('offset', null, InputOption::VALUE_OPTIONAL, 'Offset', 0),
            array('size', null, InputOption::VALUE_OPTIONAL, 'Size', 1),
        );
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        $this->_success("Importing...");
        $offset = $this->option('offset');
        $size = $this->option('size');

        list($insertedCount, $results) = \App\Model\Importer::import($offset, $size);
        $this->_success("Inserted $insertedCount");

        foreach ($results as $result) {
            $this->_success("ID: " . $result['buzzstream_id']);
            $this->_success("Summary: " . $result['summary']);
        }

        $this->info("Running");
    }

    protected function _success($message)
    {
        if (! $this->option('silent')) {
            $this->output->writeln("<info>$message</info>");
        }
    }
}