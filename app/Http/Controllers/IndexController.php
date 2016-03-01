<?php

namespace App\Http\Controllers;

use App\Helper\TwigHelper;

class IndexController extends Controller
{
    public function index()
    {
        $twig = TwigHelper::twig();

        return $twig->render('index.html.twig', array(
            "title"             => "BuzzStream Feed",
            "body_class"        => "home",
1        ));
    }
}