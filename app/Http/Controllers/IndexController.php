<?php

namespace App\Http\Controllers;

use App\Helper\TwigHelper;
use App\Model\HistoryItem;

use GoodLinks\BuzzStreamFeed\Api;
use GoodLinks\BuzzStreamFeed\History;

class IndexController extends Controller
{
    public function index()
    {
        $projectData = $this->_getProjectData();
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $historyItems = HistoryItem::where('created_at', '>', '0000-00-00')
            ->limit(200)
            ->get();

        $historyForProject = array();

        /** @var HistoryItem $historyItem */
        foreach ($historyItems as $historyItem) {
            $buzzstreamHistoryItem = new \GoodLinks\BuzzStreamFeed\HistoryItem();
            $buzzstreamHistoryItem->load($historyItem->getBuzzstreamApiUrl());
            if ($buzzstreamHistoryItem->isInProject($projectData['buzzstream_api_url'])) {
                $historyForProject[] = $buzzstreamHistoryItem;
            }
        }

        $twig = TwigHelper::twig();

        return $twig->render('index.html.twig', array(
            "title"             => "BuzzStream Feed",
            "body_class"        => "home",
            "history"           => $historyForProject,
            "project_data"      => $projectData,
        ));
    }

    /**
     * Grab new history items since the last import
     *
     * @return string
     * @throws \Exception
     */
    public function importBuzzstreamIncremental()
    {
        $before = microtime(true);
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $projectData = $this->_getProjectData();

        $lastHistoryItem = HistoryItem::where('created_at', '>', '0000-00-00')
            ->first();

        $offset = isset($_GET['offset']) ? $_GET['offset'] : null;
        $size = isset($_GET['size']) ? $_GET['size'] : 50;
        $fromDate = isset($_GET['from']) ? $_GET['from'] : date("Y-m-d", time() - (60 * 60 * 24 * 30));
        $afterTimestampInMilliseconds = strtotime($fromDate) * 1000;

        $history = History::getList($afterTimestampInMilliseconds, null, $offset, $size);
        if (empty($history)) {
            return "Finished processing";
        }

        $results = array();
        foreach ($history as $item) {
            $buzzstreamId = $item->getBuzzstreamId();

            HistoryItem::create(array(
                'buzzstream_id'         => $buzzstreamId,
                'buzzstream_created_at' => $item->getCreatedAt(),
                'type'                  => $item->getType(),
                'summary'               => $item->getSummary(),
            ));
        }

        $newOffset = $offset + $size;
        $project = $projectData['id'];
        $key = $projectData['secret'];
        $nextProcessUrl = "/processFeed?project=$project&key=$key&size=$size&offset=$newOffset";

        return "
            From Date: $fromDate
            <br>Processing Time: " . number_format(microtime(true) - $before, 2) . "s
            <br>
            <br><a href='$nextProcessUrl'>$nextProcessUrl</a>
            <br><br>
            <pre>"
                . print_r($results, 1) .
            "</pre>
            ";
    }

    public function import()
    {
        $twig = TwigHelper::twig();
        $offset = isset($_GET['offset']) ? $_GET['offset'] : null;
        $size = isset($_GET['size']) ? $_GET['size'] : 50;

        return $twig->render('import.html.twig', array(
            "title"             => "Import from BuzzStream",
            "body_class"        => "home",
            "offset"            => $offset,
            "size"              => $size,
        ));
    }

    /**
     * Do an initial import starting at the most recent history item
     * and going back in time
     * 
     * @return array
     * @throws \Exception
     */
    public function importBuzzstreamInitial()
    {
        $before = microtime(true);
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $offset = isset($_GET['offset']) ? $_GET['offset'] : null;
        $size = isset($_GET['size']) ? $_GET['size'] : 50;

        $history = History::getList(null, null, $offset, $size);
        if (empty($history)) {
            return array(
                'success' => true,
                'complete'  => true,
            );
        }

        $results = array();
        $insertedCount = 0;

        foreach ($history as $item) {
            $buzzstreamId = $item->getBuzzstreamId();
            if (HistoryItem::findByBuzzstreamId($buzzstreamId)->getId()) {
                continue;
            }

            $item = HistoryItem::create(array(
                'buzzstream_id'         => $buzzstreamId,
                'buzzstream_created_at' => $item->getCreatedAt(),
                'type'                  => $item->getType(),
                'summary'               => $item->getSummary(),
            ));
            $results[] = $item->toArray();
            $insertedCount++;
        }

        $newOffset = $offset + $size;
        $nextProcessUrl = "/processFeedInitial?size=$size&offset=$newOffset";

        return array(
            'success'           => true,
            'offset'            => $offset,
            'size'              => $size,
            'next_url'          => $nextProcessUrl,
            'inserted_count'    => $insertedCount,
            'results'           => $results,
            'processing_time'   => number_format(microtime(true) - $before, 2) . "s",
        );
    }

    protected function _getProjectData()
    {
        if (! isset($_GET['project'])) {
            die("Missing 'project'");
        }

        $path = dirname(dirname(dirname(dirname(__FILE__)))) . "/.projects.json";
        $projects = json_decode(file_get_contents($path), true);
        if (! $projects) {
            die("Couldn't find projects json data");
        }

        $thisProjectData = array();
        foreach ($projects as $projectData) {
            if ($projectData['id'] == $_GET['project']) {
                $thisProjectData = $projectData;
            }
        }

        if (empty($thisProjectData)) {
            die("Couldn't find project data for: " . $_GET['project']);
        }

        $key = isset($_GET['key']) ? $_GET['key'] : null;
        if (!$key || $key != $thisProjectData['secret']) {
            die("Access denied");
        }

        return $thisProjectData;
    }
}