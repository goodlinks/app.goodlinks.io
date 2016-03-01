<?php

namespace App\Http\Controllers;

use App\Helper\TwigHelper;
use GoodLinks\BuzzStreamFeed\Api;
use GoodLinks\BuzzStreamFeed\History;
use GoodLinks\BuzzStreamFeed\HistoryItem;

class IndexController extends Controller
{
    public function index()
    {
        Api::setConsumerKey(getenv('BUZZSTREAM_CONSUMER_KEY'));
        Api::setConsumerSecret(getenv('BUZZSTREAM_CONSUMER_SECRET'));

        $key = isset($_GET['key']) ? $_GET['key'] : null;
        if (!$key || $key != getenv('WEB_KEY')) {
            die("Access denied");
        }

        $offset = isset($_GET['offset']) ? $_GET['offset'] : null;
        $size = isset($_GET['size']) ? $_GET['size'] : 50;

        $history = History::getList($offset, $size);

        $twig = TwigHelper::twig();

        return $twig->render('index.html.twig', array(
            "title"             => "BuzzStream Feed",
            "body_class"        => "home",
            "history"           => $history,
        ));
    }
}