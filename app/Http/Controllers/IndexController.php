<?php

namespace App\Http\Controllers;

use App\Helper\TwigHelper;
use GoodLinks\BuzzStreamFeed\Api;
use GoodLinks\BuzzStreamFeed\History;
use GoodLinks\BuzzStreamFeed\HistoryItem;
use GoodLinks\BuzzStreamFeed\Project;

class IndexController extends Controller
{
    public function index()
    {
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        if (! isset($_GET['project'])) {
            die("Missing 'project'");
        }

        $offset = isset($_GET['offset']) ? $_GET['offset'] : null;
        $size = isset($_GET['size']) ? $_GET['size'] : 200;

        $after = strtotime("2016-02-01") * 1000;
        $history = History::getList($after, null, $offset, $size);
        $historyForProject = array();

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

        foreach ($history as & $item) {
            $item->getProjectName();
            if ($item->isInProject($thisProjectData['buzzstream_api_url'])) {
                $historyForProject[] = $item;
            }
        }

        $twig = TwigHelper::twig();

        return $twig->render('index.html.twig', array(
            "title"             => "BuzzStream Feed",
            "body_class"        => "home",
            "history"           => $historyForProject,
            "project_data"      => $thisProjectData,
        ));
    }
}