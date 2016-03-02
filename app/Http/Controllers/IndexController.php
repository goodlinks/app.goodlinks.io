<?php

namespace App\Http\Controllers;

use App\Helper\TwigHelper;
use App\Model\HistoryItem;
use App\Model\HistoryItemProject;
use App\Model\HistoryItemWebsite;

use GoodLinks\BuzzStreamFeed\Api;
use GoodLinks\BuzzStreamFeed\History;
use DB;

class IndexController extends Controller
{
    public function index()
    {
        $projectData = $this->_getProjectData();
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $excludedWebsiteIds = array(
            56013240,
            55896282
        );

        $historyItems = HistoryItem::where('buzzstream_created_at', '>', '2016-02-01')
            ->leftJoin('history_item_projects', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_projects.history_item_id', '=', 'history_items.id');
            })
            ->leftJoin('history_item_websites', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_websites.history_item_id', '=', 'history_items.id');
            })
            ->whereNotIn('history_item_websites.buzzstream_website_id', $excludedWebsiteIds)
            ->where('history_item_projects.buzzstream_project_id', '=', $projectData['buzzstream_project_id'])
            ->limit(2000)
            ->get();

        $websiteCount = HistoryItem::where('history_item_projects.buzzstream_project_id', '=', $projectData['buzzstream_project_id'])
            ->leftJoin('history_item_projects', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_projects.history_item_id', '=', 'history_items.id');
            })
            ->leftJoin('history_item_websites', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_websites.history_item_id', '=', 'history_items.id');
            })
            ->count(DB::raw('DISTINCT history_item_websites.buzzstream_website_id'));

        $placementCount = HistoryItem::where('type', '=', 'Stage')
            ->where('summary', 'like', '%Successful%')
            ->leftJoin('history_item_websites', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_websites.history_item_id', '=', 'history_items.id');
            })
            ->leftJoin('history_item_projects', function($join) {
                /** @var $join \Illuminate\Database\Query\JoinClause */
                $join->on('history_item_projects.history_item_id', '=', 'history_items.id');
            })
            ->where('history_item_projects.buzzstream_project_id', '=', $projectData['buzzstream_project_id'])
            ->count();

        $historyForProject = array();

        /** @var HistoryItem $historyItem */
        foreach ($historyItems as $historyItem) {
            $buzzstreamHistoryItem = new \GoodLinks\BuzzStreamFeed\HistoryItem();
            $buzzstreamHistoryItem->load($historyItem->getBuzzstreamApiUrl());
            $historyForProject[] = $buzzstreamHistoryItem;
        }

        $twig = TwigHelper::twig();

        return $twig->render('index.html.twig', array(
            "title"             => "BuzzStream Feed",
            "body_class"        => "home",
            "history"           => $historyForProject,
            "project_data"      => $projectData,
            "website_count"     => $websiteCount,
            "placement_count"   => $placementCount,
        ));
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

        foreach ($history as $buzzstreamHistoryItem) {
            $buzzstreamId = $buzzstreamHistoryItem->getBuzzstreamId();
            $item = HistoryItem::findByBuzzstreamId($buzzstreamId);

            if (! $item->getId()) {
                $item = HistoryItem::create(array(
                    'buzzstream_id'         => $buzzstreamId,
                    'buzzstream_created_at' => $buzzstreamHistoryItem->getCreatedAt(),
                    'type'                  => $buzzstreamHistoryItem->getType(),
                    'summary'               => $buzzstreamHistoryItem->getSummary(),
                ));
            }

            $buzzstreamProjectIds = $buzzstreamHistoryItem->getBuzzstreamProjectIds();
            DB::statement("DELETE FROM history_item_projects WHERE history_item_id = {$item->getId()}");

            foreach ($buzzstreamProjectIds as $projectId) {
                HistoryItemProject::create(array(
                    'history_item_id'       => $item->getId(),
                    'buzzstream_project_id' => $projectId,
                ));
            }

            $buzzstreamWebsiteIds = $buzzstreamHistoryItem->getBuzzstreamWebsiteIds();
            DB::statement("DELETE FROM history_item_websites WHERE history_item_id = {$item->getId()}");
            foreach ($buzzstreamWebsiteIds as $websiteId) {
                HistoryItemWebsite::create(array(
                    'history_item_id'       => $item->getId(),
                    'buzzstream_website_id' => $websiteId,
                ));
            }

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